<?php

namespace Tests\Feature;

use App\Http\Controllers\DiditWebhookController;
use App\Models\Deal;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Permission;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class IdentityCheckTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private const SESSION = '11111111-2222-3333-4444-555555555555';

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();

        config(['services.didit.api_key' => 'sk_test_key', 'services.didit.workflow_id' => 'wf-1', 'services.didit.webhook_secret' => 'whsec_didit']);

        $this->car = $this->makeDealer()->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 3999500, 'condition' => 'USED', 'is_published' => true,
        ]);
    }

    public function test_demo_mode_verifies_without_calling_didit(): void
    {
        $buyer = User::factory()->create();
        $this->actingAs($buyer);

        $this->atIdStep()->assertSet('identityDemo', true)->assertSee('Demo mode — no ID needed')
            ->call('verifyIdentity')
            ->assertSet('identityVerified', true)
            ->assertNoRedirect();

        $this->assertSame(IdentityVerification::PROVIDER_DEMO, IdentityVerification::sole()->provider);
        Http::assertNothingSent();

        // Reserving links the deal to the demo check.
        $this->checkout()->call('preparePayment')->call('reserve');
        $deal = Deal::withoutGlobalScopes()->sole();
        $this->assertSame(IdentityVerification::sole()->id, $deal->identity_verification_id);
        $this->assertNotNull($deal->identity_verified_at);
    }

    public function test_live_mode_sends_the_buyer_to_didit_and_reads_the_decision_on_return(): void
    {
        $this->goLive();
        $buyer = User::factory()->create();
        $this->actingAs($buyer);

        Http::fake([
            'verification.didit.me/v3/session/' => Http::response([
                'session_id' => self::SESSION, 'url' => 'https://verify.didit.me/en/session/abc', 'status' => 'Not Started',
            ], 201),
            'verification.didit.me/v3/session/' . self::SESSION . '/decision/' => Http::response($this->decision('Approved')),
        ]);

        $this->checkout()->assertSet('identityDemo', false)
            ->call('verifyIdentity')
            ->assertRedirect('https://verify.didit.me/en/session/abc');

        Http::assertSent(fn ($request) => $request->url() === 'https://verification.didit.me/v3/session/'
            && $request->hasHeader('x-api-key', 'sk_test_key')
            && $request['workflow_id'] === 'wf-1'
            && $request['vendor_data'] === 'user-' . $buyer->id
            && $request['callback'] === route('identity.return', $this->car));

        // Back from Didit: the query string is only a cue; Didit's API is asked.
        $this->get(route('identity.return', $this->car) . '?verificationSessionId=' . self::SESSION . '&status=Approved')
            ->assertRedirect(route('checkout', $this->car));

        $check = IdentityVerification::sole();
        $this->assertSame('Approved', $check->status);
        $this->assertSame('Rivera', $check->last_name);
        $this->assertSame('1990-04-02', $check->date_of_birth->toDateString());
        $this->assertNotNull($check->decided_at);

        $this->atIdStep()->assertSet('identityVerified', true)->assertSee('You\'re verified');
    }

    public function test_a_declined_check_blocks_the_reservation_and_offers_a_retry(): void
    {
        $this->goLive();
        $buyer = User::factory()->create();
        $this->actingAs($buyer);
        IdentityVerification::create(['user_id' => $buyer->id, 'provider' => 'didit', 'session_id' => self::SESSION, 'status' => 'Declined']);

        $this->atIdStep()->assertSet('identityVerified', false)->assertSee('Try again')
            ->call('preparePayment')->assertSet('paymentClientSecret', null)
            ->assertSet('paymentError', 'We still need to confirm your ID before you pay. Go back to the ID check step.')
            ->call('reserve');

        $this->assertSame(0, Deal::withoutGlobalScopes()->count());
    }

    public function test_a_demo_approval_does_not_count_once_checks_are_live(): void
    {
        $buyer = User::factory()->create();
        $this->actingAs($buyer);
        $this->checkout()->call('verifyIdentity')->assertSet('identityVerified', true);

        $this->goLive();
        $this->checkout()->assertSet('identityVerified', false);
    }

    public function test_in_review_may_reserve_and_becomes_verified_when_didit_approves(): void
    {
        $this->goLive();
        $buyer = User::factory()->create();
        $this->actingAs($buyer);
        IdentityVerification::create(['user_id' => $buyer->id, 'provider' => 'didit', 'session_id' => self::SESSION, 'status' => 'In Review']);

        $this->atIdStep()->assertSet('identityVerified', true)->assertSee('manual review')
            ->call('preparePayment')->call('reserve');

        $deal = Deal::withoutGlobalScopes()->sole();
        $this->assertNull($deal->identity_verified_at);

        // Didit's reviewer approves: the signed webhook updates the check and the deal.
        Http::fake(['verification.didit.me/*' => Http::response($this->decision('Approved'))]);
        $this->webhook(['session_id' => self::SESSION, 'status' => 'Approved', 'webhook_type' => 'status.updated'])->assertOk();

        $this->assertSame('Approved', IdentityVerification::sole()->status);
        $this->assertNotNull($deal->fresh()->identity_verified_at);
        $this->assertNotSame('', PlatformSettings::get(PlatformSettings::DIDIT_WEBHOOK_LAST_SEEN));
    }

    public function test_the_webhook_refuses_bad_signatures_and_stale_timestamps(): void
    {
        $body = ['session_id' => self::SESSION, 'status' => 'Approved'];

        $this->webhook($body, signature: str_repeat('0', 64))->assertStatus(401);
        $this->webhook($body, timestamp: time() - 600)->assertStatus(401);

        // The raw-body signature (X-Signature) is accepted too; unknown sessions are acknowledged.
        $raw = json_encode($body);
        $this->call('POST', route('didit.webhook'), [], [], [], [
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $raw, 'whsec_didit'), 'HTTP_X_TIMESTAMP' => (string) time(), 'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertOk();
    }

    public function test_canonical_json_sorts_keys_and_writes_whole_floats_as_integers(): void
    {
        $this->assertSame(
            '{"a":{"x":1,"y":[2,{"b":1.5,"c":"é/ü"}]},"b":{},"z":10}',
            DiditWebhookController::canonical(json_decode('{"z":10.0,"b":{},"a":{"y":[2,{"c":"é/ü","b":1.5}],"x":1}}'))
        );
    }

    public function test_switching_id_checks_mode(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        PlatformSettings::put(PlatformSettings::IDENTITY_MODE, 'demo', null);

        config(['services.didit.api_key' => '']);
        Livewire::test('pages::admin-settings')->call('setIdentityMode', 'live')->assertSee('Live ID checks need the Didit keys');
        $this->assertSame('demo', PlatformSettings::get(PlatformSettings::IDENTITY_MODE));

        config(['services.didit.api_key' => 'sk_test_key']);
        Livewire::test('pages::admin-settings')->call('setIdentityMode', 'live');
        $this->assertSame('live', PlatformSettings::get(PlatformSettings::IDENTITY_MODE));

        Http::fake(['verification.didit.me/*' => Http::response(['detail' => 'Not found.'], 404)]);
        Livewire::test('pages::admin-settings')->call('checkIdentityConnection')->assertSee('API key accepted.');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [Permission::RESERVATIONS_VIEW]]));
        Livewire::test('pages::admin-settings')->assertForbidden();
    }

    public function test_staff_see_a_name_mismatch(): void
    {
        $check = new IdentityVerification(['provider' => 'didit', 'first_name' => 'ROBERT', 'last_name' => 'RIVERA']);

        $this->assertFalse($check->nameDiffersFrom('Rob', 'Rivera'));
        $this->assertTrue($check->nameDiffersFrom('Sam', 'Rivera'));
        $this->assertTrue($check->nameDiffersFrom('Robert', 'Smith'));
    }

    private function goLive(): void
    {
        PlatformSettings::put(PlatformSettings::IDENTITY_MODE, PlatformSettings::IDENTITY_LIVE, null);
    }

    private function checkout()
    {
        return Livewire::test('pages::checkout', ['vehicle' => $this->car])
            ->set('firstName', 'Sam')->set('lastName', 'Rivera')->set('phone', '6045550100');
    }

    private function atIdStep()
    {
        $checkout = $this->checkout();

        return $checkout->set('stepIndex', $checkout->instance()->indexOfStep('id'));
    }

    /** @return array<string, mixed> */
    private function decision(string $status): array
    {
        return [
            'session_id'       => self::SESSION,
            'status'           => $status,
            'id_verifications' => [[
                'status' => $status, 'document_type' => 'Driver\'s License', 'first_name' => 'Sam', 'last_name' => 'Rivera',
                'date_of_birth' => '1990-04-02', 'expiration_date' => '2030-01-01',
            ]],
        ];
    }

    private function webhook(array $body, ?string $signature = null, ?int $timestamp = null)
    {
        $raw = json_encode($body);
        $signature ??= hash_hmac('sha256', DiditWebhookController::canonical(json_decode($raw)), 'whsec_didit');

        return $this->call('POST', route('didit.webhook'), [], [], [], [
            'HTTP_X_SIGNATURE_V2' => $signature,
            'HTTP_X_TIMESTAMP'    => (string) ($timestamp ?? time()),
            'CONTENT_TYPE'        => 'application/json',
        ], $raw);
    }
}

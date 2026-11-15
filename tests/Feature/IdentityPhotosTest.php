<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class IdentityPhotosTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private const SESSION = '11111111-2222-3333-4444-555555555555';

    private Dealer $dealer;
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.didit.api_key' => 'sk_test_key', 'services.didit.workflow_id' => 'wf-1']);

        $this->dealer = $this->makeDealer();
        $this->buyer = User::factory()->create();

        Http::fake([
            'verification.didit.me/v3/session/' . self::SESSION . '/decision/' => Http::response([
                'status'           => 'Approved',
                'id_verifications' => [['front_image' => 'https://media.didit.test/front.jpg?sig=1', 'back_image' => 'https://media.didit.test/back.jpg?sig=1']],
                'liveness_checks'  => [['reference_image' => 'https://media.didit.test/selfie.jpg?sig=1']],
            ]),
            'verification.didit.me/v3/session/' . self::SESSION . '/generate-pdf/' => Http::response('%PDF-1.7 report', 200, ['Content-Type' => 'application/pdf']),
            'media.didit.test/*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
        ]);
    }

    public function test_the_dealer_can_view_and_download_the_buyers_id_photos(): void
    {
        $deal = $this->deal();
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        $this->get(route('dealer.deal.identity', [$deal, 'front']))
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('JPEGBYTES', $this->get(route('dealer.deal.identity', [$deal, 'back']))->getContent());

        $this->get(route('dealer.deal.identity', [$deal, 'selfie']) . '?download=1')
            ->assertOk()->assertDownload('lee-pat-selfie.jpg');
        $this->get(route('dealer.deal.identity', [$deal, 'report']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertDownload('lee-pat-id-report.pdf');

        // Every view and download is on the record.
        $this->assertSame(2, AuditLog::where('action', 'identity.viewed')->where('target_id', $deal->id)->count());
        $this->assertSame(2, AuditLog::where('action', 'identity.downloaded')->where('target_id', $deal->id)->count());

        Livewire::test('pages::deal-detail', ['deal' => $deal])->assertSee('Show ID photos')->assertSee('Download full ID report (PDF)');
    }

    public function test_another_dealership_cannot_reach_them(): void
    {
        $deal = $this->deal();
        $this->actingAs(User::factory()->staffOf($this->makeDealer(['name' => 'Valley Nissan']))->create());

        $this->get(route('dealer.deal.identity', [$deal->id, 'front']))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_the_buyer_cannot_reach_them_through_the_staff_host(): void
    {
        $deal = $this->deal();
        $this->actingAs($this->buyer);

        $this->assertNotSame(200, $this->get(route('dealer.deal.identity', [$deal->id, 'front']))->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_no_photos_on_a_cancelled_or_demo_deal(): void
    {
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        $cancelled = $this->deal(['stage' => 'cancelled']);
        $this->get(route('dealer.deal.identity', [$cancelled, 'front']))->assertNotFound();

        $demo = IdentityVerification::create(['user_id' => $this->buyer->id, 'provider' => 'demo', 'session_id' => 'demo_x', 'status' => 'Approved']);
        $demoDeal = $this->deal(['identity_verification_id' => $demo->id]);
        $this->get(route('dealer.deal.identity', [$demoDeal, 'front']))->assertNotFound();
        Livewire::test('pages::deal-detail', ['deal' => $demoDeal])->assertSee('Demo ID check — no ID photos.');

        Http::assertNothingSent();
    }

    public function test_command_centre_with_reservations_view_can_see_them(): void
    {
        $deal = $this->deal();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [Permission::RESERVATIONS_VIEW]]));
        $this->get(route('admin.reservations.identity', [$deal->id, 'front']))->assertOk();
        Livewire::test('pages::admin-reservation', ['deal' => $deal->id])->assertSee('Show ID photos');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [Permission::CUSTOMERS_VIEW]]));
        $this->get(route('admin.reservations.identity', [$deal->id, 'front']))->assertForbidden();
    }

    private function deal(array $overrides = []): Deal
    {
        $verification = IdentityVerification::firstOrCreate(
            ['session_id' => self::SESSION],
            ['user_id' => $this->buyer->id, 'provider' => 'didit', 'status' => 'Approved', 'decided_at' => now()],
        );

        $vehicle = $this->dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);

        return Deal::withoutGlobalScopes()->create($overrides + [
            'dealer_id' => $this->dealer->id, 'vehicle_id' => $vehicle->id, 'user_id' => $this->buyer->id,
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'phone' => '6045550199',
            'purchase_type' => 'finance', 'identity_verified_at' => now(), 'stage' => 'financing',
            'identity_verification_id' => $verification->id,
        ]);
    }
}

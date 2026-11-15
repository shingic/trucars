<?php

namespace Tests\Feature;

use App\Mail\BuyerWelcome;
use App\Mail\CheckoutRecoveryMail;
use App\Models\AuditLog;
use App\Models\CheckoutDraft;
use App\Models\Deal;
use App\Models\EmailLog;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Email\Catalogue;
use App\Support\Marketing\CheckoutRecovery;
use App\Support\Permission;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class EmailCentreTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();
        $this->car = $this->makeDealer()->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'Outlander', 'body_type' => 'SUV',
            'kilometres' => 30000, 'price_in_cents' => 3299000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_email_previews_and_leaves_nothing_behind(): void
    {
        $users = User::count();

        foreach (array_keys(Catalogue::ALL) as $slug) {
            $preview = Catalogue::preview($slug);

            $this->assertNotNull($preview, "No preview for {$slug}");
            $this->assertNotSame('', $preview['subject'], $slug);
            $this->assertStringContainsString('</html>', $preview['html'], $slug);
        }

        $this->assertSame($users, User::count());
        $this->assertSame(0, Deal::withoutGlobalScopes()->count());
        $this->assertSame(0, CheckoutDraft::count());
    }

    public function test_sent_emails_are_logged_by_type(): void
    {
        $buyer = User::factory()->create();
        Mail::to($buyer->email)->send(new BuyerWelcome($buyer));

        $draft = CheckoutDraft::create(['user_id' => $buyer->id, 'vehicle_id' => $this->car->id, 'step_key' => 'plan', 'data' => [], 'last_active_at' => now()]);
        Mail::to($buyer)->send(new CheckoutRecoveryMail($draft->load(['user', 'vehicle']), 'recovery_3d'));

        $this->assertSame(['welcome', 'recovery_3d'], EmailLog::orderBy('id')->pluck('type')->all());
        $this->assertSame($buyer->id, EmailLog::first()->user_id);
        $this->assertSame('Welcome to TruCars', EmailLog::first()->subject);
    }

    public function test_pausing_one_checkout_stage_stops_only_that_stage(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-04 11:00', 'America/Toronto'));
        PlatformSettings::put('business_mailing_address', '100 King St W, Toronto', null);
        PlatformSettings::put(PlatformSettings::MARKETING_EMAILS, PlatformSettings::MARKETING_ON, null);
        Catalogue::setPaused('recovery_1h', true, null);

        $buyer = User::factory()->create();
        CheckoutDraft::create(['user_id' => $buyer->id, 'vehicle_id' => $this->car->id, 'step_key' => 'plan', 'data' => [], 'last_active_at' => now()->subHours(2)]);

        app(CheckoutRecovery::class)->run();
        Mail::assertNothingSent();

        Carbon::setTestNow(now()->addDay());
        app(CheckoutRecovery::class)->run();
        Mail::assertSent(CheckoutRecoveryMail::class, fn ($mail) => $mail->kind === 'recovery_1d');
    }

    public function test_the_page_and_its_controls(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-emails')
            ->assertSee('Checkout reminder · 3 days')->assertSee('Always on')
            ->call('togglePaused', 'recovery_3d')->assertSee('Checkout reminder · 3 days paused.')
            ->call('showPreview', 'reservation_confirmed')->assertSee('sample with made-up details');

        $this->assertTrue(Catalogue::paused('recovery_3d'));
        $this->assertTrue(AuditLog::where('action', 'email.paused')->exists());

        // Emails a reservation depends on can't be paused.
        Livewire::test('pages::admin-emails')->call('togglePaused', 'reservation_confirmed')->assertStatus(422);
        $this->assertFalse(Catalogue::paused('reservation_confirmed'));

        // marketing.view can look but not change anything.
        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => Permission::normalize([Permission::MARKETING_VIEW])]));
        Livewire::test('pages::admin-emails')->assertOk()->call('togglePaused', 'recovery_1d')->assertForbidden();
        $this->assertFalse(Catalogue::paused('recovery_1d'));

        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [Permission::CUSTOMERS_VIEW]]));
        Livewire::test('pages::admin-emails')->assertForbidden();
    }
}

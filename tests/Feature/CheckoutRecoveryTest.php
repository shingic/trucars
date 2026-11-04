<?php

namespace Tests\Feature;

use App\Mail\CheckoutRecoveryMail;
use App\Models\CheckoutDraft;
use App\Models\Deal;
use App\Models\MarketingEmail;
use App\Models\Scopes\DealerScope;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Marketing\CheckoutRecovery;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

/**
 * Shoppers who start a checkout and don't pay belong to the platform: the
 * Command Centre sees them and follows up by email; dealers never do.
 */
class CheckoutRecoveryTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Vehicle $car;

    private User $shopper;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-04 11:00', 'America/Toronto'));
        PlatformSettings::put('business_mailing_address', '100 King St W, Toronto, ON M5X 1A9', null);

        $this->car = $this->carFor($this->makeDealer(), 'Outlander', 3299000, 'SUV');
        $this->shopper = User::factory()->create(['name' => 'Sam Shopper', 'email' => 'sam@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_starting_checkout_saves_a_draft_that_survives_a_new_session(): void
    {
        $this->startCheckout(step: 4);

        $draft = CheckoutDraft::sole();
        $this->assertSame('handover', $draft->step_key);
        $this->assertSame('6045550100', $draft->phone());

        session()->flush(); // a different day, a different device
        $this->actingAs($this->shopper);
        Livewire::test('pages::checkout', ['vehicle' => $this->car])->assertSet('stepIndex', 4)->assertSet('phone', '6045550100');
    }

    public function test_paying_the_deposit_turns_the_draft_into_the_reservation(): void
    {
        $this->startCheckout()->call('preparePayment')->call('reserve');

        $deal = Deal::withoutGlobalScope(DealerScope::class)->sole();
        $draft = CheckoutDraft::sole();
        $this->assertSame(CheckoutDraft::STATUS_RESERVED, $draft->status);
        $this->assertSame($deal->id, $draft->deal_id);
    }

    public function test_the_first_email_goes_an_hour_after_they_stop(): void
    {
        $this->startCheckout();

        Carbon::setTestNow(now()->addMinutes(50));
        $this->artisan('checkouts:send-recovery');
        Mail::assertNothingSent();

        Carbon::setTestNow(now()->addMinutes(15));
        $this->artisan('checkouts:send-recovery');
        Mail::assertSent(CheckoutRecoveryMail::class, fn ($mail) => $mail->kind === 'recovery_1h' && $mail->hasTo('sam@example.com'));

        $this->artisan('checkouts:send-recovery');
        Mail::assertSent(CheckoutRecoveryMail::class, 1);
    }

    public function test_the_whole_sequence_then_silence(): void
    {
        $this->startCheckout();

        foreach (['recovery_1h' => 61, 'recovery_1d' => 60 * 24, 'recovery_3d' => 60 * 24 * 3, 'recovery_7d' => 60 * 24 * 7] as $kind => $minutes) {
            Carbon::setTestNow(Carbon::parse('2026-11-04 11:00', 'America/Toronto')->addMinutes($minutes + 5));
            app(CheckoutRecovery::class)->run();
        }
        Carbon::setTestNow(Carbon::parse('2026-11-20 11:00', 'America/Toronto'));
        app(CheckoutRecovery::class)->run();

        $this->assertSame(['recovery_1h', 'recovery_1d', 'recovery_3d', 'recovery_7d'], MarketingEmail::orderBy('id')->pluck('kind')->all());
    }

    public function test_the_one_day_email_suggests_similar_cars_without_naming_any_dealer(): void
    {
        $this->carFor($this->car->dealer, 'Outlander', 3199000, 'SUV');
        $this->startCheckout();
        Carbon::setTestNow(now()->addDay()->addMinutes(5));
        app(CheckoutRecovery::class)->run();

        Mail::assertSent(CheckoutRecoveryMail::class, function (CheckoutRecoveryMail $mail) {
            $html = $mail->render();

            return $mail->kind === 'recovery_1d'
                && $mail->suggestions->count() === 1
                && str_contains($html, 'Similar cars near you')
                && str_contains($html, '100 King St W, Toronto')
                && str_contains($html, 'Unsubscribe')
                && ! str_contains(strtolower($html), 'chilliwack mitsubishi');
        });
    }

    public function test_nothing_sends_without_the_business_address(): void
    {
        PlatformSettings::put('business_mailing_address', '', null);
        $this->startCheckout();
        Carbon::setTestNow(now()->addHours(2));

        $this->artisan('checkouts:send-recovery')->expectsOutputToContain('Paused');
        Mail::assertNothingSent();
    }

    public function test_nothing_sends_overnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 21:30', 'America/Toronto'));
        $this->startCheckout();
        Carbon::setTestNow(now()->addMinutes(65)); // 10:35 pm

        app(CheckoutRecovery::class)->run();
        Mail::assertNothingSent();
    }

    public function test_unsubscribing_stops_everything(): void
    {
        $this->startCheckout();
        $url = URL::signedRoute('marketing.unsubscribe', ['user' => $this->shopper->id]);

        auth()->logout();
        $this->get($url)->assertOk()->assertSee("You're unsubscribed", false);
        $this->assertNotNull($this->shopper->fresh()->marketing_unsubscribed_at);

        Carbon::setTestNow(now()->addHours(2));
        app(CheckoutRecovery::class)->run();
        Mail::assertNothingSent();
    }

    public function test_one_click_unsubscribe_from_mail_apps(): void
    {
        $this->post(URL::signedRoute('marketing.unsubscribe', ['user' => $this->shopper->id]))->assertNoContent();
        $this->assertNotNull($this->shopper->fresh()->marketing_unsubscribed_at);
    }

    public function test_an_unsigned_unsubscribe_link_is_refused(): void
    {
        $this->get('/email/unsubscribe/' . $this->shopper->id)->assertForbidden();
        $this->assertNull($this->shopper->fresh()->marketing_unsubscribed_at);
    }

    public function test_when_someone_else_reserves_the_car_the_sequence_ends(): void
    {
        $this->startCheckout();
        $rival = User::factory()->create();
        Deal::create(['dealer_id' => $this->car->dealer_id, 'vehicle_id' => $this->car->id, 'user_id' => $rival->id, 'first_name' => 'R', 'last_name' => 'V', 'email' => 'r@example.com', 'phone' => '1']);

        Carbon::setTestNow(now()->addHours(2));
        app(CheckoutRecovery::class)->run();

        Mail::assertNothingSent();
        $this->assertSame(CheckoutDraft::STATUS_CAR_GONE, CheckoutDraft::sole()->status);
    }

    public function test_the_command_centre_lists_unfinished_checkouts(): void
    {
        $this->startCheckout(step: 4);
        auth()->logout();

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('admin.checkouts'))
            ->assertOk()
            ->assertSee('Sam Shopper')
            ->assertSee('sam@example.com')
            ->assertSee('Pickup');
    }

    public function test_dealers_never_see_unpaid_shoppers(): void
    {
        $this->startCheckout(step: 4);
        auth()->logout();
        $staff = User::factory()->staffOf($this->car->dealer)->create();

        $this->actingAs($staff)->get(route('admin.checkouts'))->assertForbidden();
        $this->actingAs($staff)->get(route('admin.inquiries'))->assertForbidden();
        $this->actingAs($staff)->get(route('dealer.reservations'))->assertOk()->assertDontSee('Sam Shopper')->assertDontSee('Inquiries');
    }

    private function startCheckout(int $step = 1)
    {
        $this->actingAs($this->shopper);

        return Livewire::test('pages::checkout', ['vehicle' => $this->car])
            ->set('firstName', 'Sam')
            ->set('phone', '6045550100')
            ->set('stepIndex', $step);
    }

    private function carFor($dealer, string $model, int $price, string $body): Vehicle
    {
        return $dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => $model, 'body_type' => $body,
            'kilometres' => 30000, 'price_in_cents' => $price, 'condition' => 'USED', 'is_published' => true,
        ]);
    }
}

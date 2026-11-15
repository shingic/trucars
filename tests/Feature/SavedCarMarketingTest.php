<?php

namespace Tests\Feature;

use App\Mail\SavedCarAlertMail;
use App\Models\AuditLog;
use App\Models\CheckoutDraft;
use App\Models\Deal;
use App\Models\MarketingEmail;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Marketing\Consent;
use App\Support\Marketing\EmailTypes;
use App\Support\Marketing\SavedCarAlerts;
use App\Support\Permission;
use App\Support\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class SavedCarMarketingTest extends TestCase
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
        PlatformSettings::put(PlatformSettings::MARKETING_EMAILS, PlatformSettings::MARKETING_ON, null);

        $dealer = $this->makeDealer();
        $this->car = $this->carFor($dealer, 'Outlander', 3299000);
        $this->carFor($dealer, 'Outlander', 3199000); // a similar car to suggest
        $this->shopper = User::factory()->create(['name' => 'Sam Shopper', 'email' => 'sam@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_everyone_starts_subscribed_to_every_type(): void
    {
        foreach (array_keys(EmailTypes::ALL) as $type) {
            $this->assertTrue($this->shopper->wantsEmail($type), $type);
        }
    }

    public function test_a_price_drop_is_emailed_once(): void
    {
        $this->save();
        $this->run_();                                   // first look: remembers $32,990
        Mail::assertNothingSent();

        $this->car->update(['price_in_cents' => 3099000]); // $2,000 less
        Carbon::setTestNow(now()->addHour());
        $this->run_();

        Mail::assertSent(SavedCarAlertMail::class, function (SavedCarAlertMail $mail) {
            $html = $mail->render();

            return $mail->kind === 'saved_price_drop'
                && str_contains($mail->envelope()->subject, 'Price drop')
                && str_contains($html, '$2,000 less')
                && str_contains($html, 'Manage email preferences or unsubscribe from all')
                && str_contains($html, '100 King St W, Toronto')
                && ! str_contains(strtolower($html), strtolower($this->car->dealer->name));
        });

        Carbon::setTestNow(now()->addDay());
        $this->run_();
        Mail::assertSentCount(1);
    }

    public function test_a_small_change_is_not_a_price_drop(): void
    {
        $this->save();
        $this->run_();
        $this->car->update(['price_in_cents' => 3289000]); // $100 less
        Carbon::setTestNow(now()->addHour());
        $this->run_();

        Mail::assertNothingSent();
    }

    public function test_a_saved_car_reserved_by_someone_else_offers_similar_cars(): void
    {
        $this->save();
        Deal::withoutGlobalScopes()->create([
            'dealer_id' => $this->car->dealer_id, 'vehicle_id' => $this->car->id, 'user_id' => User::factory()->create()->id,
            'first_name' => 'R', 'last_name' => 'V', 'email' => 'r@example.com', 'phone' => '1',
        ]);
        $this->run_();

        Mail::assertSent(SavedCarAlertMail::class, fn (SavedCarAlertMail $mail) => $mail->kind === 'saved_gone' && $mail->suggestions->count() === 1);
    }

    public function test_still_thinking_nudge_after_three_days_unless_they_started_checkout(): void
    {
        $this->save();
        Carbon::setTestNow(now()->addDays(2));
        $this->run_();
        Mail::assertNothingSent();

        Carbon::setTestNow(now()->addDay()->addMinute());
        $this->run_();
        Mail::assertSent(SavedCarAlertMail::class, fn ($mail) => $mail->kind === 'saved_still_available');

        // Another shopper who started reserving it gets the checkout reminders instead.
        $other = User::factory()->create();
        $other->favouriteVehicles()->attach($this->car->id, ['created_at' => now()->subDays(4)]);
        CheckoutDraft::create(['user_id' => $other->id, 'vehicle_id' => $this->car->id, 'step_key' => 'buyer', 'data' => [], 'last_active_at' => now()]);
        Carbon::setTestNow(now()->addDay());
        $this->run_();
        Mail::assertNotSent(SavedCarAlertMail::class, fn ($mail) => $mail->hasTo($other->email));
    }

    public function test_switched_off_types_quiet_hours_and_the_gap_are_respected(): void
    {
        $this->save();
        $this->run_();
        $this->car->update(['price_in_cents' => 3099000]);

        $this->shopper->setEmailType(EmailTypes::PRICE_DROPS, false);
        $this->shopper->save();
        Carbon::setTestNow(now()->addHour());
        $this->run_();
        Mail::assertNothingSent();

        $this->shopper->setEmailType(EmailTypes::PRICE_DROPS, true);
        $this->shopper->save();
        Carbon::setTestNow(Carbon::parse('2026-11-04 23:00', 'America/Toronto'));
        $this->run_();
        Mail::assertNothingSent();

        MarketingEmail::create(['user_id' => $this->shopper->id, 'kind' => 'recovery_1h', 'sent_at' => Carbon::parse('2026-11-05 07:00', 'America/Toronto')]);
        Carbon::setTestNow(Carbon::parse('2026-11-05 09:00', 'America/Toronto'));
        $this->run_();
        Mail::assertNothingSent();
    }

    public function test_no_email_once_implied_consent_has_lapsed(): void
    {
        $this->shopper->forceFill(['created_at' => now()->subMonths(8)])->save();
        $this->shopper->favouriteVehicles()->attach($this->car->id, ['created_at' => now()->subMonths(7), 'alerted_price_in_cents' => 3599000]);

        $this->assertFalse(Consent::implied($this->shopper));
        $this->run_();
        Mail::assertNothingSent();
    }

    public function test_unsubscribe_from_one_type_by_signed_link(): void
    {
        $url = URL::signedRoute('marketing.unsubscribe.type', ['user' => $this->shopper->id, 'type' => EmailTypes::PRICE_DROPS]);
        $this->get($url)->assertOk()->assertSee('price drop alerts')->assertSee('Manage email preferences');

        $this->shopper->refresh();
        $this->assertFalse($this->shopper->wantsEmail(EmailTypes::PRICE_DROPS));
        $this->assertTrue($this->shopper->wantsEmail(EmailTypes::SAVED_CAR_UPDATES));

        $this->get(route('marketing.unsubscribe.type', ['user' => $this->shopper->id, 'type' => EmailTypes::PRICE_DROPS]))->assertForbidden();
    }

    public function test_the_preferences_page_from_a_signed_link(): void
    {
        $this->get(URL::signedRoute('email.preferences', $this->shopper))->assertOk()->assertSee('5 of 5 on');
        $this->get(route('email.preferences', $this->shopper))->assertForbidden();

        Livewire::test('pages::email-preferences', ['user' => $this->shopper])
            ->call('toggleType', EmailTypes::RECOMMENDATIONS)->assertSee('4 of 5 on')
            ->call('toggleAll')->assertSee('0 of 5 on')
            ->call('toggleType', EmailTypes::PRICE_DROPS)->assertSee('1 of 5 on');

        $this->shopper->refresh();
        $this->assertNull($this->shopper->marketing_unsubscribed_at);
        $this->assertTrue($this->shopper->wantsEmail(EmailTypes::PRICE_DROPS));
        $this->assertFalse($this->shopper->wantsEmail(EmailTypes::CHECKOUT_REMINDERS));
        $this->assertTrue(AuditLog::where('action', 'marketing.preferences_updated')->exists());
    }

    public function test_the_shoppers_list_needs_marketing_view(): void
    {
        $this->save();
        User::factory()->create(['name' => 'No Saves']);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => Permission::normalize([Permission::MARKETING_VIEW])]));
        Livewire::test('pages::admin-shoppers')
            ->assertSee('Sam Shopper')->assertSee('No Saves')
            ->call('showFilter', 'saved')->assertSee('Sam Shopper')->assertDontSee('No Saves');
        $this->assertTrue(AuditLog::where('action', 'shoppers.listed')->exists());

        // The customer page shows what they saved.
        Livewire::test('pages::admin-customer', ['customer' => $this->shopper])
            ->assertSee('Saved cars')->assertSee('2022 Mitsubishi Outlander')->assertSee('All on');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [Permission::CUSTOMERS_VIEW]]));
        Livewire::test('pages::admin-shoppers')->assertForbidden();
    }

    public function test_the_marketing_switch_stops_everything_and_needs_the_address_to_go_on(): void
    {
        $this->save();
        Carbon::setTestNow(now()->addDays(4));

        PlatformSettings::put(PlatformSettings::MARKETING_EMAILS, PlatformSettings::MARKETING_OFF, null);
        $this->run_();
        $this->artisan('saved:send-alerts')->expectsOutputToContain('switched off');
        $this->artisan('checkouts:send-recovery')->expectsOutputToContain('switched off');
        Mail::assertNothingSent();

        $this->actingAs(User::factory()->owner()->create());
        PlatformSettings::put('business_mailing_address', '', null);
        Livewire::test('pages::admin-settings')->call('setMarketingEmails', 'on')->assertSee('Add the business mailing address');
        $this->assertSame('off', PlatformSettings::get(PlatformSettings::MARKETING_EMAILS));

        PlatformSettings::put('business_mailing_address', '100 King St W, Toronto, ON M5X 1A9', null);
        Livewire::test('pages::admin-settings')->call('setMarketingEmails', 'on')->assertSee('Marketing emails on');
        $this->assertSame('on', PlatformSettings::get(PlatformSettings::MARKETING_EMAILS));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);

        $this->run_();
        Mail::assertSent(SavedCarAlertMail::class);
    }

    public function test_marketing_emails_are_off_until_switched_on(): void
    {
        \Illuminate\Support\Facades\DB::table('platform_settings')->where('key', PlatformSettings::MARKETING_EMAILS)->delete();
        \Illuminate\Support\Facades\Cache::flush();

        $this->assertSame('off', PlatformSettings::get(PlatformSettings::MARKETING_EMAILS));
    }

    private function save(): void
    {
        $this->shopper->favouriteVehicles()->attach($this->car->id);
    }

    private function run_(): void
    {
        app(SavedCarAlerts::class)->run();
    }

    private function carFor($dealer, string $model, int $price): Vehicle
    {
        return $dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => $model, 'body_type' => 'SUV',
            'kilometres' => 30000, 'price_in_cents' => $price, 'condition' => 'USED', 'is_published' => true,
        ]);
    }
}

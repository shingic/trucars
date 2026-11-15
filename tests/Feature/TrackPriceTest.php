<?php

namespace Tests\Feature;

use App\Mail\SavedCarAlertMail;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Marketing\EmailTypes;
use App\Support\Marketing\SavedCarAlerts;
use App\Support\PlatformSettings;
use App\Support\ShopperLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class TrackPriceTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();
        session([ShopperLocation::SESSION_KEY => ['source' => 'manual', 'label' => 'Newmarket, ON', 'city' => 'Newmarket', 'province' => 'ON']]);
        $this->car = $this->makeDealer()->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Toyota', 'model' => 'Tacoma', 'body_type' => 'Truck',
            'kilometres' => 30000, 'price_in_cents' => 4899000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_tracking_saves_the_car_from_todays_price_and_a_drop_is_emailed(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-04 11:00', 'America/Toronto'));
        PlatformSettings::put('business_mailing_address', '100 King St W, Toronto', null);
        PlatformSettings::put(PlatformSettings::MARKETING_EMAILS, PlatformSettings::MARKETING_ON, null);
        $shopper = User::factory()->create();
        $this->actingAs($shopper);

        Livewire::test('pages::vehicle', ['vehicle' => $this->car])
            ->assertSee('Track price')
            ->call('toggleTrackPrice')
            ->assertSee('Tracking price')
            ->assertDispatched('favourites-updated', count: 1);

        $this->assertSame(4899000, DB::table('favourites')->where(['user_id' => $shopper->id, 'vehicle_id' => $this->car->id])->value('alerted_price_in_cents'));

        $this->car->update(['price_in_cents' => 4699000]);
        Carbon::setTestNow(now()->addHour());
        app(SavedCarAlerts::class)->run();

        Mail::assertSent(SavedCarAlertMail::class, fn ($mail) => $mail->kind === 'saved_price_drop' && $mail->hasTo($shopper->email));
    }

    public function test_a_second_tap_stops_tracking(): void
    {
        $shopper = User::factory()->create();
        $this->actingAs($shopper);

        Livewire::test('pages::vehicle', ['vehicle' => $this->car])
            ->call('toggleTrackPrice')
            ->call('toggleTrackPrice')
            ->assertSee('Track price')
            ->assertDontSee('Tracking price');

        $this->assertFalse($shopper->hasFavourited($this->car));
    }

    public function test_tracking_turns_price_drops_back_on_and_nothing_else(): void
    {
        $shopper = User::factory()->create(['marketing_unsubscribed_at' => now()]);
        $this->actingAs($shopper);

        Livewire::test('pages::vehicle', ['vehicle' => $this->car])->call('toggleTrackPrice')->assertSee('Tracking price');

        $shopper->refresh();
        $this->assertTrue($shopper->wantsEmail(EmailTypes::PRICE_DROPS));
        $this->assertFalse($shopper->wantsEmail(EmailTypes::RECOMMENDATIONS));
        $this->assertFalse($shopper->wantsEmail(EmailTypes::CHECKOUT_REMINDERS));
        $this->assertDatabaseHas('audit_logs', ['action' => 'marketing.preferences_updated']);
    }

    public function test_a_guest_signs_in_and_comes_back_already_tracking(): void
    {
        Livewire::test('pages::vehicle', ['vehicle' => $this->car])
            ->call('toggleTrackPrice')
            ->assertRedirect(route('buyer.login'));

        $shopper = User::factory()->create();
        $this->actingAs($shopper);

        Livewire::test('pages::vehicle', ['vehicle' => $this->car])->assertSee('Tracking price');
        $this->assertTrue($shopper->hasFavourited($this->car));

        // Only once: unsaving later sticks.
        $shopper->favouriteVehicles()->detach($this->car->id);
        Livewire::test('pages::vehicle', ['vehicle' => $this->car])->assertSee('Track price')->assertDontSee('Tracking price');
    }

    public function test_dealer_staff_never_see_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_DEALER_STAFF, 'dealer_id' => $this->car->dealer_id]));

        Livewire::test('pages::vehicle', ['vehicle' => $this->car])->assertDontSee('Track price');
    }
}

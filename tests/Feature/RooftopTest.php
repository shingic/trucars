<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Dealer;
use App\Models\DealerGroup;
use App\Models\Rooftop;
use App\Models\Scopes\DealerScope;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class RooftopTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private const HEADER = ['rooftop_id', 'dealer_name', 'dealer_email', 'dealer_phone', 'dealer_address', 'dealer_city', 'dealer_region_code', 'dealer_postal_code', 'dealer_latitude', 'dealer_longitude', 'vin', 'stockNumber', 'condition', 'year', 'make', 'model', 'trim', 'price', 'mileage', 'body_type', 'exterior_color', 'transmission', 'drivetrain', 'fuel_type', 'photos', 'certified'];

    public function test_every_dealer_has_a_main_store_that_follows_its_address(): void
    {
        $dealer = $this->makeDealer(['feed_rooftop_id' => 'ROOF-A']);
        $main = $dealer->primaryRooftop;

        $this->assertTrue($main->is_primary);
        $this->assertSame('45510 Yale Rd', $main->street_address);
        $this->assertSame('ROOF-A', $main->feed_rooftop_id);
        $this->assertSame('CHILLIWACK MITSUBISHI', $main->display_name);

        $dealer->update(['street_address' => '1 New Rd', 'timezone' => 'America/Edmonton']);

        $this->assertSame('1 New Rd', $main->fresh()->street_address);
        $this->assertSame('America/Edmonton', $main->fresh()->timezone);
        $this->assertSame(1, $dealer->rooftops()->count());
    }

    public function test_cars_sit_at_the_main_store_unless_placed_at_another(): void
    {
        $dealer = $this->makeDealer();
        $kelowna = $this->secondStore($dealer);

        $atMain = $this->car($dealer);
        $atKelowna = $this->car($dealer, ['rooftop_id' => $kelowna->id]);

        $this->assertSame($dealer->primaryRooftop->id, $atMain->rooftop_id);
        $this->assertSame('Chilliwack, BC', $atMain->display_location);
        $this->assertSame('Kelowna, BC', $atKelowna->fresh()->display_location);
    }

    public function test_a_car_cannot_sit_at_another_dealers_store(): void
    {
        $dealer = $this->makeDealer();
        $rival = $this->makeDealer(['name' => 'RIVAL MOTORS']);

        $this->expectException(ValidationException::class);
        $this->car($dealer, ['rooftop_id' => $rival->primaryRooftop->id]);
    }

    public function test_moving_a_car_to_another_dealer_moves_it_to_that_dealers_main_store(): void
    {
        $dealer = $this->makeDealer();
        $rival = $this->makeDealer(['name' => 'RIVAL MOTORS']);
        $car = $this->car($dealer, ['rooftop_id' => $this->secondStore($dealer)->id]);

        $car->update(['dealer_id' => $rival->id]);

        $this->assertSame($rival->primaryRooftop->id, $car->fresh()->rooftop_id);
    }

    public function test_a_closed_store_takes_its_cars_off_the_marketplace(): void
    {
        $dealer = $this->makeDealer();
        $kelowna = $this->secondStore($dealer);
        $this->car($dealer);
        $atKelowna = $this->car($dealer, ['rooftop_id' => $kelowna->id]);

        $this->assertSame(2, Vehicle::listed()->count());

        $kelowna->update(['is_active' => false]);

        $this->assertSame(1, Vehicle::listed()->count());
        $this->assertFalse($atKelowna->fresh()->isListed());
    }

    public function test_a_reservation_freezes_the_store_beside_the_seller(): void
    {
        $dealer = $this->makeDealer();
        $toronto = $this->secondStore($dealer, ['name' => 'Toronto store', 'city' => 'Toronto', 'province' => 'ON', 'postal_code' => 'M5V 2T6', 'timezone' => 'America/Toronto']);
        $car = $this->car($dealer, ['rooftop_id' => $toronto->id]);

        $deal = Deal::create([
            'dealer_id' => $dealer->id, 'vehicle_id' => $car->id, 'user_id' => User::factory()->create()->id,
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'phone' => '6045550199', 'purchase_type' => 'cash',
        ]);

        $toronto->update(['name' => 'Renamed store', 'street_address' => '9 Other St']);
        $deal = $deal->fresh();

        $this->assertSame($toronto->id, $deal->rooftop_id);
        $this->assertSame('Toronto store', $deal->rooftop_snapshot['name']);
        $this->assertSame('1 Store Rd', $deal->rooftop_snapshot['street_address']);
        $this->assertSame('Toronto store · Toronto, ON', $deal->rooftop_label);
        $this->assertSame('America/Toronto', $deal->dealerTimezone());
        $this->assertSame('CHILLIWACK MITSUBISHI', $deal->seller_name);
    }

    public function test_checkout_offers_pickup_at_the_cars_store_in_its_timezone(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-01 12:00:00', 'UTC'));

        $dealer = $this->makeDealer();
        $toronto = $this->secondStore($dealer, ['name' => 'Toronto store', 'city' => 'Toronto', 'province' => 'ON', 'postal_code' => 'M5V 2T6', 'timezone' => 'America/Toronto']);
        $car = $this->car($dealer, ['rooftop_id' => $toronto->id]);
        $this->actingAs(User::factory()->create());

        $checkout = Livewire::test('pages::checkout', ['vehicle' => $car]);
        $this->assertSame([['name' => 'Toronto Store', 'address' => '1 Store Rd, Toronto, ON M5V 2T6']], $checkout->instance()->pickupSpots());

        $checkout->set('handoverTimeSlot', '10:00')
            ->set('firstName', 'Sam')
            ->set('phone', '6045550100')
            ->call('preparePayment')
            ->call('reserve');

        $deal = Deal::withoutGlobalScope(DealerScope::class)->sole();

        $this->assertSame($toronto->id, $deal->rooftop_id);
        $this->assertSame('Toronto Store', $deal->pickup_location);
        // 10am in Toronto (EDT, UTC-4) is 14:00 UTC.
        $this->assertSame('2026-07-02 14:00:00', $deal->getRawOriginal('pickup_at'));

        Carbon::setTestNow();
    }

    public function test_a_second_feed_becomes_another_store_and_never_unpublishes_the_first(): void
    {
        $this->import([$this->row('ROOF-A', 'VIN0000000000000A')])->assertSuccessful();
        $dealer = Dealer::sole();
        $dealer->update(['status' => Dealer::STATUS_ACTIVE]);
        Vehicle::sole()->update(['is_published' => true]);

        $this->import([$this->row('ROOF-B', 'VIN0000000000000B', ['dealer_name' => 'KELOWNA MITSUBISHI', 'dealer_city' => 'Kelowna', 'dealer_postal_code' => 'V1Y 1A1'])], ['--dealer' => $dealer->id])
            ->expectsOutputToContain('--new-rooftop')
            ->assertFailed();

        $this->import([$this->row('ROOF-B', 'VIN0000000000000B', ['dealer_name' => 'KELOWNA MITSUBISHI', 'dealer_city' => 'Kelowna', 'dealer_postal_code' => 'V1Y 1A1'])], ['--dealer' => $dealer->id, '--new-rooftop' => true])
            ->assertSuccessful();

        $kelowna = Rooftop::where('feed_rooftop_id', 'ROOF-B')->sole();
        $this->assertSame($dealer->id, $kelowna->dealer_id);
        $this->assertFalse($kelowna->is_primary);
        $this->assertSame('Kelowna', $kelowna->city);
        $this->assertSame($kelowna->id, Vehicle::where('vin', 'VIN0000000000000B')->value('rooftop_id'));

        // Store A's published car isn't in store B's feed, and stays live.
        $this->assertTrue(Vehicle::where('vin', 'VIN0000000000000A')->value('is_published'));

        // Re-imports find store B by its feed id alone.
        $this->import([$this->row('ROOF-B', 'VIN0000000000000C', ['dealer_name' => 'KELOWNA MITSUBISHI'])])->assertSuccessful();
        $this->assertSame($kelowna->id, Vehicle::where('vin', 'VIN0000000000000C')->value('rooftop_id'));
        $this->assertSame(1, Dealer::count());
    }

    public function test_an_import_never_moves_a_car_between_stores_of_one_dealer(): void
    {
        $dealer = $this->makeDealer(['feed_rooftop_id' => 'ROOF-A']);
        $kelowna = $this->secondStore($dealer, ['feed_rooftop_id' => 'ROOF-B']);
        $this->car($dealer, ['vin' => 'VIN0000000000000K', 'rooftop_id' => $kelowna->id]);

        $this->import([$this->row('ROOF-A', 'VIN0000000000000K')])
            ->expectsOutputToContain('Skipped (VIN belongs to another rooftop): 1')
            ->assertSuccessful();

        $this->assertSame($kelowna->id, Vehicle::where('vin', 'VIN0000000000000K')->value('rooftop_id'));
    }

    public function test_admin_adds_and_closes_a_store(): void
    {
        $dealer = $this->makeDealer();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer', ['dealer' => $dealer])
            ->set('tab', 'location')
            ->call('startRooftop')
            ->set('rooftopForm.name', 'Kelowna store')
            ->set('rooftopForm.street_address', '100 Harvey Ave')
            ->set('rooftopForm.city', 'Kelowna')
            ->set('rooftopForm.province', 'BC')
            ->set('rooftopForm.postal_code', 'v1y1a1')
            ->call('saveRooftop')
            ->assertHasNoErrors()
            ->assertSee('Kelowna store');

        $kelowna = $dealer->rooftops()->where('is_primary', false)->sole();
        $this->assertSame('V1Y 1A1', $kelowna->postal_code);
        $this->assertSame('America/Vancouver', $kelowna->timezone);
        $this->assertDatabaseHas('audit_logs', ['action' => 'rooftop.created']);

        Livewire::test('pages::admin-dealer', ['dealer' => $dealer])
            ->call('toggleRooftop', $kelowna->id);

        $this->assertFalse($kelowna->fresh()->is_active);
    }

    public function test_the_main_store_is_not_edited_or_closed_from_the_rooftop_form(): void
    {
        $dealer = $this->makeDealer();
        $this->actingAs(User::factory()->owner()->create());

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('pages::admin-dealer', ['dealer' => $dealer])->call('toggleRooftop', $dealer->primaryRooftop->id);
    }

    public function test_a_dealer_can_be_put_in_a_group(): void
    {
        $dealer = $this->makeDealer();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer', ['dealer' => $dealer])
            ->set('groupName', 'Fraser Valley Auto Group')
            ->call('save')
            ->assertHasNoErrors();

        $other = $this->makeDealer(['name' => 'ABBOTSFORD MITSUBISHI']);
        Livewire::test('pages::admin-dealer', ['dealer' => $other])
            ->set('groupName', 'fraser valley auto group')
            ->call('save');

        $group = DealerGroup::sole();
        $this->assertSame('Fraser Valley Auto Group', $group->name);
        $this->assertEqualsCanonicalizing([$dealer->id, $other->id], $group->dealers()->pluck('id')->all());
    }

    private function secondStore(Dealer $dealer, array $overrides = []): Rooftop
    {
        return $dealer->rooftops()->create($overrides + [
            'name' => 'Kelowna store', 'street_address' => '1 Store Rd', 'city' => 'Kelowna', 'province' => 'BC',
            'postal_code' => 'V1Y 1A1', 'country' => 'CA', 'timezone' => 'America/Vancouver',
        ]);
    }

    private function car(Dealer $dealer, array $overrides = []): Vehicle
    {
        return $dealer->vehicles()->create($overrides + [
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }

    private function row(string $rooftopId, string $vin, array $overrides = []): array
    {
        return $overrides + [
            'rooftop_id' => $rooftopId, 'dealer_name' => 'CHILLIWACK MITSUBISHI', 'dealer_email' => '',
            'dealer_phone' => '+1 672-784-5040', 'dealer_address' => '45510 Yale Rd', 'dealer_city' => 'Chilliwack',
            'dealer_region_code' => 'BC', 'dealer_postal_code' => 'V2P 2M9', 'dealer_latitude' => '49.151131',
            'dealer_longitude' => '-121.963743', 'vin' => $vin, 'stockNumber' => 'S1', 'condition' => 'USED',
            'year' => '2022', 'make' => 'Mitsubishi', 'model' => 'Outlander', 'trim' => 'ES', 'price' => '29999',
            'mileage' => '30000', 'body_type' => 'SUV', 'exterior_color' => 'Blue', 'transmission' => 'Automatic',
            'drivetrain' => 'AWD', 'fuel_type' => 'Gasoline', 'photos' => '', 'certified' => 'TRUE',
        ];
    }

    private function import(array $rows, array $options = [])
    {
        $path = tempnam(sys_get_temp_dir(), 'feed') . '.csv';
        $handle = fopen($path, 'w');
        fputcsv($handle, self::HEADER);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($column) => $row[$column], self::HEADER));
        }

        fclose($handle);

        return $this->artisan('vehicles:import', ['path' => $path] + $options);
    }
}

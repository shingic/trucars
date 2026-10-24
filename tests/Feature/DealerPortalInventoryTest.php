<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class DealerPortalInventoryTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;
    private Dealer $other;
    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = $this->makeDealer(['feed_rooftop_id' => 'ROOF-A']);
        $this->other = $this->makeDealer(['name' => 'Other Rooftop', 'feed_rooftop_id' => 'ROOF-B']);
        $this->staff = User::factory()->staffOf($this->dealer)->create();
    }

    public function test_dealer_staff_see_only_their_own_cars(): void
    {
        $this->vehicle($this->dealer, ['model' => 'Our Outlander']);
        $this->vehicle($this->other, ['model' => 'Their Corolla']);

        $this->actingAs($this->staff)->get(route('dealer.inventory'))
            ->assertOk()
            ->assertSee('Our Outlander')
            ->assertDontSee('Their Corolla')
            ->assertSee('Import inventory feed');
    }

    public function test_dealer_staff_publish_their_own_cars_and_it_is_audited(): void
    {
        $car = $this->vehicle($this->dealer, ['is_published' => false]);
        $this->actingAs($this->staff);

        Livewire::test('pages::dealer-inventory')->call('publish', $car->id)->assertSee('Published 1 vehicle.');

        $this->assertTrue($car->fresh()->is_published);
        $entry = AuditLog::where('action', 'inventory.published')->sole();
        $this->assertSame($this->staff->id, $entry->actor_user_id);
        $this->assertSame('dealer_staff', $entry->actor_role);
    }

    public function test_another_dealers_car_cannot_be_touched_even_with_its_id(): void
    {
        $theirs = $this->vehicle($this->other, ['is_published' => false]);
        $this->actingAs($this->staff);

        Livewire::test('pages::dealer-inventory')
            ->call('publish', $theirs->id)
            ->set('selected', [(string) $theirs->id])
            ->call('publishSelected');

        $this->assertFalse($theirs->fresh()->is_published);
    }

    public function test_dealer_staff_import_their_own_feed_but_not_another_rooftops(): void
    {
        $this->actingAs($this->staff);

        Livewire::test('pages::dealer-inventory')
            ->set('feed', $this->feedFile('ROOF-A', 'VIN00000000000001'))
            ->call('importFeed')
            ->assertSee('Feed imported');

        $this->assertSame($this->dealer->id, Vehicle::where('vin', 'VIN00000000000001')->sole()->dealer_id);

        Livewire::test('pages::dealer-inventory')
            ->set('feed', $this->feedFile('ROOF-B', 'VIN00000000000002'))
            ->call('importFeed')
            ->assertSee('wasn’t imported');

        $this->assertNull(Vehicle::where('vin', 'VIN00000000000002')->first());
        $this->assertSame(0, $this->other->vehicles()->count());
    }

    public function test_only_dealer_staff_reach_the_page(): void
    {
        $this->actingAs(User::factory()->owner()->create())->get(route('dealer.inventory'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('dealer.inventory'))->assertRedirect(route('garage'));
        auth()->logout();
        $this->get(route('dealer.inventory'))->assertRedirect(route('dealer.login'));
    }

    public function test_suspended_dealers_staff_are_locked_out(): void
    {
        $this->dealer->update(['status' => Dealer::STATUS_SUSPENDED]);

        $this->actingAs($this->staff)->get(route('dealer.inventory'))->assertForbidden();

        $car = $this->vehicle($this->dealer, ['is_published' => false]);
        Livewire::test('pages::dealer-inventory')->call('publish', $car->id)->assertForbidden();
        $this->assertFalse($car->fresh()->is_published);
    }

    private function vehicle(Dealer $dealer, array $overrides = []): Vehicle
    {
        return $dealer->vehicles()->create($overrides + [
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 20000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }

    private function feedFile(string $rooftopId, string $vin): UploadedFile
    {
        $header = ['rooftop_id', 'dealer_name', 'dealer_email', 'dealer_phone', 'dealer_address', 'dealer_city', 'dealer_region_code', 'dealer_postal_code', 'dealer_latitude', 'dealer_longitude', 'vin', 'stockNumber', 'condition', 'year', 'make', 'model', 'trim', 'price', 'mileage', 'body_type', 'exterior_color', 'transmission', 'drivetrain', 'fuel_type', 'photos', 'certified'];
        $row = [$rooftopId, 'CHILLIWACK MITSUBISHI', '', '', '45510 Yale Rd', 'Chilliwack', 'BC', 'V2P 2M9', '49.15', '-121.96', $vin, 'S1', 'USED', '2022', 'Mitsubishi', 'RVR', 'ES', '24999', '30000', 'SUV', 'Red', 'Automatic', 'AWD', 'Gasoline', '', 'TRUE'];

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $header);
        fputcsv($handle, $row);
        rewind($handle);

        return UploadedFile::fake()->createWithContent('feed.csv', stream_get_contents($handle));
    }
}

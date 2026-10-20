<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Permission;
use Database\Seeders\DealerUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class DealerInventoryAdminTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private const HEADER = ['rooftop_id', 'dealer_name', 'dealer_email', 'dealer_phone', 'dealer_address', 'dealer_city', 'dealer_region_code', 'dealer_postal_code', 'dealer_latitude', 'dealer_longitude', 'vin', 'stockNumber', 'condition', 'year', 'make', 'model', 'trim', 'price', 'mileage', 'body_type', 'exterior_color', 'transmission', 'drivetrain', 'fuel_type', 'photos', 'certified'];

    private Dealer $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = $this->makeDealer(['feed_rooftop_id' => 'ROOF-A']);
    }

    public function test_viewers_see_inventory_but_cannot_change_it(): void
    {
        $this->vehicle(['model' => 'Listed Outlander']);
        $draft = $this->vehicle(['model' => 'Draft RVR', 'is_published' => false]);
        $this->actingAs($this->staff([Permission::DEALERS_VIEW]));

        $this->get(route('admin.dealers.inventory', $this->dealer))
            ->assertOk()
            ->assertSee('Listed Outlander')
            ->assertSee('Draft RVR')
            ->assertDontSee('Import inventory feed');

        Livewire::test('pages::admin-dealer-inventory', ['dealer' => $this->dealer])->call('publish', $draft->id)->assertForbidden();
        $this->assertFalse($draft->fresh()->is_published);
    }

    public function test_inventory_is_closed_without_dealers_view(): void
    {
        $this->actingAs($this->staff(Permission::PRESETS['customer_support']['permissions']))
            ->get(route('admin.dealers.inventory', $this->dealer))
            ->assertForbidden();

        $this->actingAs(User::factory()->staffOf($this->dealer)->create())
            ->get(route('admin.dealers.inventory', $this->dealer))
            ->assertForbidden();
    }

    public function test_publishing_puts_a_car_on_the_marketplace_and_is_audited(): void
    {
        $car = $this->vehicle(['model' => 'Fresh Eclipse Cross', 'is_published' => false]);
        $owner = User::factory()->owner()->create();

        $this->get('/')->assertDontSee('Fresh Eclipse Cross');

        $this->actingAs($owner);
        Livewire::test('pages::admin-dealer-inventory', ['dealer' => $this->dealer])
            ->call('publish', $car->id)
            ->assertSee('Published 1 vehicle.');
        auth()->logout();

        $this->get('/')->assertSee('Fresh Eclipse Cross');

        $entry = AuditLog::where('action', 'inventory.published')->sole();
        $this->assertSame($owner->id, $entry->actor_user_id);
        $this->assertSame([$car->id], $entry->after['vehicle_ids']);
    }

    public function test_bulk_publish_and_unpublish(): void
    {
        $cars = collect(range(1, 3))->map(fn () => $this->vehicle(['is_published' => false]));
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer-inventory', ['dealer' => $this->dealer])
            ->set('selected', $cars->take(2)->pluck('id')->map(fn ($id) => (string) $id)->all())
            ->call('publishSelected')
            ->assertSet('selected', []);

        $this->assertSame([true, true, false], $cars->map(fn ($car) => $car->fresh()->is_published)->all());

        Livewire::test('pages::admin-dealer-inventory', ['dealer' => $this->dealer])
            ->set('selected', [(string) $cars[0]->id])
            ->call('unpublishSelected');

        $this->assertFalse($cars[0]->fresh()->is_published);
    }

    public function test_publishing_is_refused_while_the_dealer_address_is_incomplete(): void
    {
        $legacy = Dealer::create(['name' => 'Legacy Nissan', 'city' => 'Toronto', 'status' => Dealer::STATUS_ACTIVE]);
        $car = $legacy->vehicles()->create($this->attributes(['is_published' => false]));
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer-inventory', ['dealer' => $legacy])
            ->call('publish', $car->id)
            ->assertSee('can’t be published until its dealer has a complete address');

        $this->assertFalse($car->fresh()->is_published);
    }

    public function test_another_dealers_car_cannot_be_changed_from_this_page(): void
    {
        $other = $this->makeDealer(['name' => 'Other Rooftop']);
        $theirs = $other->vehicles()->create($this->attributes(['is_published' => false]));
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer-inventory', ['dealer' => $this->dealer])
            ->call('publish', $theirs->id)
            ->set('selected', [(string) $theirs->id])
            ->call('publishSelected');

        $this->assertFalse($theirs->fresh()->is_published);
    }

    public function test_uploading_the_feed_adds_new_cars_unpublished_and_keeps_live_ones_live(): void
    {
        $live = $this->vehicle(['vin' => 'VIN00000000000001', 'price_in_cents' => 1000000]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer-inventory', ['dealer' => $this->dealer])
            ->set('feed', $this->feedFile('ROOF-A', ['VIN00000000000001', 'VIN00000000000002']))
            ->call('importFeed')
            ->assertSee('Feed imported')
            ->assertSee('1 new');

        $this->assertTrue($live->fresh()->is_published);
        $this->assertSame(2999900, $live->fresh()->price_in_cents);
        $this->assertFalse(Vehicle::where('vin', 'VIN00000000000002')->sole()->is_published);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.imported', 'target_id' => $this->dealer->id]);
    }

    public function test_a_feed_from_another_rooftop_is_refused(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer-inventory', ['dealer' => $this->dealer])
            ->set('feed', $this->feedFile('ROOF-OTHER', ['VIN00000000000009']))
            ->call('importFeed')
            ->assertSee('wasn’t imported')
            ->assertSee('already linked to rooftop ROOF-A');

        $this->assertSame(0, Vehicle::count());
    }

    public function test_demo_login_is_never_seeded_outside_local(): void
    {
        $this->app['env'] = 'production';

        // Run the class directly — db:seed in production would stop to ask for confirmation.
        $this->app->make(DealerUserSeeder::class)->run();

        $this->assertNull(User::where('email', 'shingi@trueleads.ca')->first());
        $this->app['env'] = 'testing';
    }

    /* ---- helpers ----------------------------------------------------- */

    private function attributes(array $overrides = []): array
    {
        return $overrides + [
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 20000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true, 'is_certified' => true,
        ];
    }

    private function vehicle(array $overrides = []): Vehicle
    {
        return $this->dealer->vehicles()->create($this->attributes($overrides));
    }

    private function staff(array $permissions): User
    {
        return User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => Permission::normalize($permissions)]);
    }

    private function feedFile(string $rooftopId, array $vins): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADER);

        foreach ($vins as $vin) {
            fputcsv($handle, [
                $rooftopId, 'CHILLIWACK MITSUBISHI', '', '+1 672-784-5040', '45510 Yale Rd', 'Chilliwack', 'BC', 'V2P 2M9',
                '49.151131', '-121.963743', $vin, 'S1', 'USED', '2022', 'Mitsubishi', 'Outlander', 'ES', '29999',
                '30000', 'SUV', 'Blue', 'Automatic', 'AWD', 'Gasoline', '', 'FALSE',
            ]);
        }

        rewind($handle);

        return UploadedFile::fake()->createWithContent('feed.csv', stream_get_contents($handle));
    }
}

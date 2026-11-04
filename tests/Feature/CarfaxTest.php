<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Carfax\CarfaxReport;
use App\Services\Carfax\CarfaxService;
use App\Services\Carfax\Providers\CarfaxCanadaProvider;
use App\Services\Carfax\Providers\CarfaxProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class CarfaxTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private const VIN = '1HGCM82633A004352';

    private Dealer $dealer;

    /** Every lookup the fake provider received, as [accountId, vin]. */
    private array $lookups = [];

    private ?CarfaxReport $report = null;

    private bool $fail = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = $this->makeDealer(['franchise_make' => 'Honda']);
        $this->report = new CarfaxReport(
            reportUrl: 'https://vhr.carfax.ca/?id=abc123',
            accidentCount: 0,
            ownerCount: 1,
            serviceRecordCount: 7,
            registeredIn: ['Ontario'],
            badges: ['No Reported Accidents', 'One Owner'],
        );

        $test = $this;
        $this->app->instance(CarfaxProvider::class, new class($test) implements CarfaxProvider {
            public function __construct(private CarfaxTest $test) {}

            public function lookup(string $accountId, string $vin): ?CarfaxReport
            {
                return $this->test->recordLookup($accountId, $vin);
            }
        });
    }

    public function recordLookup(string $accountId, string $vin): ?CarfaxReport
    {
        $this->lookups[] = [$accountId, $vin];
        if ($this->fail) {
            throw new RuntimeException('CARFAX is down');
        }

        return $this->report;
    }

    public function test_the_vehicle_page_shows_the_dealers_carfax_report(): void
    {
        $this->enableCarfax('DLR-4471');
        $vehicle = $this->vehicle();

        $this->get('/cars/' . $vehicle->id)
            ->assertOk()
            ->assertSee('Vehicle history')
            ->assertSee('alt="CARFAX Canada"', false)
            ->assertSee('No accidents')
            ->assertSee('1 owner')
            ->assertSee('7 service records')
            ->assertSee('https://vhr.carfax.ca/?id=abc123', false);

        $this->assertSame([['DLR-4471', self::VIN]], $this->lookups);
    }

    public function test_reports_are_cached_between_page_views(): void
    {
        $this->enableCarfax('DLR-4471');
        $vehicle = $this->vehicle();

        $this->get('/cars/' . $vehicle->id)->assertOk();
        $this->get('/cars/' . $vehicle->id)->assertOk();

        $this->assertCount(1, $this->lookups);
    }

    public function test_nothing_shows_or_is_looked_up_while_carfax_is_off(): void
    {
        $this->dealer->update(['carfax_enabled' => false, 'carfax_account_id' => 'DLR-4471']);
        $vehicle = $this->vehicle();

        $this->get('/cars/' . $vehicle->id)->assertOk()->assertDontSee('Vehicle history');
        $this->assertSame([], $this->lookups);
    }

    public function test_nothing_is_looked_up_without_an_account_id_or_a_real_vin(): void
    {
        $this->dealer->update(['carfax_enabled' => true, 'carfax_account_id' => null]);
        $this->assertNull(app(CarfaxService::class)->forVehicle($this->vehicle()));

        $this->enableCarfax('DLR-4471');
        $this->assertNull(app(CarfaxService::class)->forVehicle($this->vehicle(['vin' => 'SHORTVIN'])));

        $this->assertSame([], $this->lookups);
    }

    public function test_no_report_for_the_vin_hides_the_section(): void
    {
        $this->report = null;
        $this->enableCarfax('DLR-4471');

        $this->get('/cars/' . $this->vehicle()->id)->assertOk()->assertDontSee('Vehicle history');
    }

    public function test_a_carfax_outage_never_breaks_the_vehicle_page(): void
    {
        $this->fail = true;
        $this->enableCarfax('DLR-4471');
        $vehicle = $this->vehicle();

        $this->get('/cars/' . $vehicle->id)->assertOk()->assertSee('Civic')->assertDontSee('Vehicle history');
        $this->get('/cars/' . $vehicle->id)->assertOk();

        $this->assertCount(1, $this->lookups, 'A failure is cached briefly, not retried on every view.');
    }

    public function test_reported_accidents_and_stolen_flags_are_shown_plainly(): void
    {
        $this->report = new CarfaxReport(accidentCount: 2, stolen: true);
        $this->enableCarfax('DLR-4471');

        $this->get('/cars/' . $this->vehicle()->id)
            ->assertOk()
            ->assertSee('2 accidents')
            ->assertSee('reports this vehicle as stolen')
            ->assertDontSee('View report');
    }

    public function test_admin_turns_carfax_on_with_an_account_id(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->set('carfaxEnabled', true)
            ->set('carfaxAccountId', '  DLR-4471 ')
            ->call('saveCarfax')
            ->assertHasNoErrors();

        $this->dealer->refresh();
        $this->assertTrue($this->dealer->carfax_enabled);
        $this->assertSame('DLR-4471', $this->dealer->carfax_account_id);
        $this->assertTrue(AuditLog::where('action', 'dealer.carfax_updated')->exists());
    }

    public function test_switching_carfax_on_needs_an_account_id(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->set('carfaxEnabled', true)
            ->set('carfaxAccountId', '')
            ->call('saveCarfax')
            ->assertHasErrors(['carfaxAccountId']);

        $this->assertFalse($this->dealer->refresh()->carfax_enabled);
    }

    public function test_view_only_staff_cannot_change_carfax_settings(): void
    {
        $this->actingAs(User::factory()->create(["role" => User::ROLE_INTERNAL_STAFF, "permissions" => \App\Support\Permission::normalize([\App\Support\Permission::DEALERS_VIEW])]));

        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->set('carfaxEnabled', true)
            ->set('carfaxAccountId', 'DLR-4471')
            ->call('saveCarfax')
            ->assertForbidden();

        $this->assertFalse($this->dealer->refresh()->carfax_enabled);
    }

    public function test_changing_the_account_drops_the_old_accounts_cached_reports(): void
    {
        $this->enableCarfax('OLD-1');
        $vehicle = $this->vehicle();
        app(CarfaxService::class)->forVehicle($vehicle);

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test('pages::admin-dealer', ['dealer' => $this->dealer])
            ->set('carfaxAccountId', 'NEW-2')
            ->call('saveCarfax')
            ->assertHasNoErrors();

        app(CarfaxService::class)->forVehicle($vehicle->fresh());
        $this->assertSame([['OLD-1', self::VIN], ['NEW-2', self::VIN]], $this->lookups);
    }

    public function test_the_carfax_canada_provider_keeps_only_https_report_links(): void
    {
        Http::fake(['carfax.test/*' => Http::response(['reportUrl' => 'javascript:alert(1)', 'accidentCount' => 0])]);

        $report = (new CarfaxCanadaProvider('https://carfax.test', 'key'))->lookup('DLR-4471', self::VIN);

        $this->assertNull($report->reportUrl);
        $this->assertSame(0, $report->accidentCount);
    }

    public function test_the_carfax_canada_provider_treats_404_as_no_report(): void
    {
        Http::fake(['carfax.test/*' => Http::response([], 404)]);

        $this->assertNull((new CarfaxCanadaProvider('https://carfax.test', 'key'))->lookup('DLR-4471', self::VIN));
    }

    private function enableCarfax(string $accountId): void
    {
        $this->dealer->update(['carfax_enabled' => true, 'carfax_account_id' => $accountId]);
    }

    private function vehicle(array $overrides = []): Vehicle
    {
        return $this->dealer->vehicles()->create($overrides + [
            'vin'            => self::VIN,
            'model_year'     => (int) date('Y') - 3,
            'make'           => 'Honda',
            'model'          => 'Civic',
            'kilometres'     => 42000,
            'price_in_cents' => 2499000,
            'condition'      => 'USED',
            'is_published'   => true,
        ]);
    }
}

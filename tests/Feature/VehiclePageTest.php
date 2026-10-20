<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\InspectionProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class VehiclePageTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = $this->makeDealer(['franchise_make' => 'Mitsubishi']);
    }

    public function test_unpublished_vehicle_page_is_not_found(): void
    {
        $vehicle = $this->vehicle(['is_published' => false]);

        $this->get('/cars/' . $vehicle->id)->assertNotFound();
    }

    public function test_published_vehicle_page_still_opens(): void
    {
        $vehicle = $this->vehicle(['model' => 'Outlander']);

        $this->get('/cars/' . $vehicle->id)->assertOk()->assertSee('Outlander');
    }

    public function test_no_checklist_means_no_certification_claim(): void
    {
        $vehicle = $this->vehicle(['is_certified' => true, 'is_manufacturer_certified' => true]);

        $this->assertNull($vehicle->certification());
        $this->get('/cars/' . $vehicle->id)
            ->assertOk()
            ->assertDontSee('point inspection')
            ->assertDontSee('172-point')
            ->assertDontSee('86-point');
    }

    public function test_the_dealers_checklist_shows_its_point_count_and_points(): void
    {
        $this->checklist(['name' => 'Chilliwack Certified', 'summary' => 'Checked by our own technicians.'], [
            ['Brakes', 'Front pad thickness'], ['Brakes', 'Rear rotors'], ['Tires', 'Tread depth'],
        ]);
        $vehicle = $this->vehicle();

        $this->assertSame(3, $vehicle->certification()->pointCount);
        $this->get('/cars/' . $vehicle->id)
            ->assertOk()
            ->assertSee('Chilliwack Certified')
            ->assertSee('3-point inspection')
            ->assertSee('Checked by our own technicians.')
            ->assertSee('Front pad thickness')
            ->assertSee('Tread depth');
    }

    public function test_a_feed_certified_checklist_only_shows_on_cars_the_feed_marks_certified(): void
    {
        $this->checklist(['name' => 'Diamond Certified', 'applies_to' => InspectionProgram::APPLIES_TO_FEED_CERTIFIED], [['Engine', 'Oil leaks']]);

        $this->assertSame('Diamond Certified', $this->vehicle(['is_manufacturer_certified' => true])->certification()?->name);
        $this->assertNull($this->vehicle(['is_manufacturer_certified' => false])->certification());
    }

    public function test_hidden_or_empty_checklists_and_new_cars_show_nothing(): void
    {
        $this->checklist(['name' => 'Hidden', 'is_active' => false], [['Engine', 'Oil leaks']]);
        $this->checklist(['name' => 'Empty'], []);

        $this->assertNull($this->vehicle()->certification());

        $this->checklist(['name' => 'Live'], [['Engine', 'Oil leaks']]);
        \Illuminate\Support\Once::flush(); // brand checklists are loaded once per request
        $this->assertNull($this->vehicle(['condition' => 'NEW'])->certification());
        $this->assertSame('Live', $this->vehicle()->certification()?->name);
    }

    private function checklist(array $attributes, array $points): InspectionProgram
    {
        $program = InspectionProgram::create($attributes + ['make' => 'Mitsubishi']);

        foreach ($points as $position => [$section, $label]) {
            $program->points()->create(['position' => $position + 1, 'section' => $section, 'label' => $label]);
        }

        return $program;
    }

    public function test_saved_cars_hides_vehicles_that_were_unpublished(): void
    {
        $live = $this->vehicle(['model' => 'Live Outlander']);
        $pulled = $this->vehicle(['model' => 'Pulled Mirage']);
        $buyer = User::factory()->create();
        $buyer->favouriteVehicles()->attach([$live->id, $pulled->id]);

        $pulled->update(['is_published' => false]);

        $this->actingAs($buyer);

        Livewire::test('pages::saved')
            ->assertSee('Live Outlander')
            ->assertDontSee('Pulled Mirage');
    }

    private function vehicle(array $overrides = []): Vehicle
    {
        return $this->dealer->vehicles()->create($overrides + [
            'vin'                       => Str::upper(Str::random(17)),
            'model_year'                => (int) date('Y') - 2,
            'make'                      => 'Mitsubishi',
            'model'                     => 'Outlander',
            'kilometres'                => 30000,
            'price_in_cents'            => 3299000,
            'condition'                 => 'USED',
            'is_published'              => true,
            'is_certified'              => true,
            'is_manufacturer_certified' => false,
        ]);
    }
}

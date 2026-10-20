<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\InspectionProgram;
use App\Models\User;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class DealerInspectionChecklistTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = $this->makeDealer(['franchise_make' => 'Mitsubishi']);
    }

    public function test_owner_builds_a_checklist_that_shows_on_the_dealers_cars(): void
    {
        $car = $this->dealer->vehicles()->create([
            'vin' => 'VIN00000000000001', 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 20000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer-inspection', ['dealer' => $this->dealer])
            ->set('name', 'Chilliwack Certified')
            ->set('badgeLabel', 'Certified')
            ->call('save')
            ->set('newSection', 'Brakes')
            ->set('newLabel', 'Front pad thickness')
            ->call('addPoint')
            ->assertSet('newSection', 'Brakes')
            ->set('pasted', "Brakes: Rear rotors\nTires: Tread depth\n\nRoad test")
            ->call('addPasted')
            ->assertSee('Added 3 points.');

        $program = InspectionProgram::sole();
        $this->assertSame(['Front pad thickness', 'Rear rotors', 'Tread depth', 'Road test'], $program->points->pluck('label')->all());
        $this->assertSame(['Brakes', 'Brakes', 'Tires', null], $program->points->pluck('section')->all());
        $this->assertDatabaseHas('audit_logs', ['action' => 'inspection_checklist.created', 'target_id' => $this->dealer->id]);

        auth()->logout();
        $this->get('/')->assertSee('Certified');
        $this->get('/cars/' . $car->id)->assertSee('4-point inspection')->assertSee('Road test');
    }

    public function test_points_can_be_reordered_and_removed(): void
    {
        $program = $this->dealer->inspectionPrograms()->create(['name' => 'Checklist']);
        $first = $program->points()->create(['position' => 1, 'label' => 'First']);
        $second = $program->points()->create(['position' => 2, 'label' => 'Second']);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer-inspection', ['dealer' => $this->dealer])
            ->call('movePoint', $second->id, -1)
            ->call('removePoint', $first->id);

        $this->assertSame(['Second'], $program->points()->pluck('label')->all());
    }

    public function test_a_checklist_can_be_copied_from_another_dealer(): void
    {
        $other = $this->makeDealer(['name' => 'Valley Mitsubishi', 'franchise_make' => 'Mitsubishi']);
        $source = $other->inspectionPrograms()->create(['name' => 'Diamond Certified', 'applies_to' => 'feed_certified']);
        $source->points()->create(['position' => 1, 'section' => 'Engine', 'label' => 'Oil leaks']);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-dealer-inspection', ['dealer' => $this->dealer])
            ->set('copyFromId', (string) $source->id)
            ->call('copyFrom')
            ->assertSee('Copied 1 points from Valley Mitsubishi.');

        $copy = $this->dealer->inspectionPrograms()->sole();
        $this->assertSame('feed_certified', $copy->applies_to);
        $this->assertSame(['Oil leaks'], $copy->points->pluck('label')->all());
        $this->assertSame(1, $source->points()->count());
    }

    public function test_viewers_can_look_but_not_change_and_others_are_kept_out(): void
    {
        $program = $this->dealer->inspectionPrograms()->create(['name' => 'Checklist']);
        $point = $program->points()->create(['position' => 1, 'label' => 'Keep me']);
        $url = 'http://admin.trucars.test/dealers/' . $this->dealer->id . '/inspection';

        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [Permission::DEALERS_VIEW]]));
        $this->get($url)->assertOk()->assertSee('Keep me')->assertDontSee('New checklist');
        Livewire::test('pages::admin-dealer-inspection', ['dealer' => $this->dealer])->call('removePoint', $point->id)->assertForbidden();
        $this->assertSame(1, $program->points()->count());

        $this->actingAs(User::factory()->staffOf($this->dealer)->create())->get($url)->assertForbidden();
    }
}

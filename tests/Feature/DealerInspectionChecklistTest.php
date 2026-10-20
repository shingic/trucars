<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\InspectionProgram;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

        $program = $this->dealer->inspectionPrograms()->sole();
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

    /* ---- brand checklists -------------------------------------------- */

    public function test_the_nissan_checklist_ships_with_169_points(): void
    {
        $nissan = InspectionProgram::whereNull('dealer_id')->where('make', 'Nissan')->sole();

        $this->assertSame(169, $nissan->points()->count());
        $this->assertSame('feed_certified', $nissan->applies_to);
    }

    public function test_a_brand_checklist_applies_automatically_to_franchised_dealers_only(): void
    {
        $nissanStore = $this->makeDealer(['name' => 'Willowdale Nissan', 'franchise_make' => 'Nissan']);
        $certifiedRogue = $this->car($nissanStore, ['make' => 'Nissan', 'model' => 'Rogue', 'is_manufacturer_certified' => true]);

        $this->assertSame('Nissan Certified Pre-Owned', $certifiedRogue->certification()?->name);
        $this->assertSame(169, $certifiedRogue->certification()->pointCount);
        $this->get('/cars/' . $certifiedRogue->id)->assertSee('169-point inspection');

        // Not flagged certified by the feed, a used Honda at the Nissan store, or a Nissan at a Mitsubishi store.
        $this->assertNull($this->car($nissanStore, ['make' => 'Nissan', 'is_manufacturer_certified' => false])->certification());
        $this->assertNull($this->car($nissanStore, ['make' => 'Honda', 'is_manufacturer_certified' => true])->certification());
        $this->assertNull($this->car($this->dealer, ['make' => 'Nissan', 'is_manufacturer_certified' => true])->certification());
    }

    public function test_a_dealers_own_checklist_takes_the_place_of_the_brand_one(): void
    {
        $nissanStore = $this->makeDealer(['name' => 'Willowdale Nissan', 'franchise_make' => 'Nissan']);
        $own = $nissanStore->inspectionPrograms()->create(['name' => 'Willowdale Certified', 'applies_to' => 'feed_certified']);
        $own->points()->create(['position' => 1, 'label' => 'Our own check']);

        $car = $this->car($nissanStore, ['make' => 'Nissan', 'is_manufacturer_certified' => true]);

        $this->assertSame('Willowdale Certified', $car->certification()?->name);
    }

    public function test_brand_checklists_are_managed_on_their_own_screen(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->get('http://admin.trucars.test/brand-checklists')->assertOk()->assertSee('Nissan Certified Pre-Owned');

        Livewire::test('pages::admin-dealer-inspection')
            ->call('startNew')
            ->assertSet('appliesTo', 'feed_certified')
            ->set('name', 'Mitsubishi Diamond Certified')
            ->call('save')
            ->assertHasErrors('make')
            ->set('make', 'Mitsubishi')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('inspection_programs', ['make' => 'Mitsubishi', 'dealer_id' => null]);

        // And the Mitsubishi dealer's tab says it applies automatically.
        $this->get('http://admin.trucars.test/dealers/' . $this->dealer->id . '/inspection')
            ->assertSee('Mitsubishi Diamond Certified applies automatically');
    }

    private function car(Dealer $dealer, array $overrides = []): Vehicle
    {
        return $dealer->vehicles()->create($overrides + [
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi',
            'model' => 'Outlander', 'kilometres' => 20000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }
}

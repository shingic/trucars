<?php

namespace Tests\Feature;

use App\Models\Brand;
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

class BrandSettingsTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    /* ---- brands ------------------------------------------------------- */

    public function test_franchise_brands_ship_and_dealers_link_to_them(): void
    {
        $this->assertTrue(Brand::where('name', 'Toyota')->exists());

        $dealer = $this->makeDealer(['franchise_make' => 'nissan']);
        $this->assertSame('Nissan', $dealer->brand->name);
        $this->assertSame('Nissan', $dealer->franchise_make, 'make follows the brand’s spelling');

        $dealer->update(['brand_id' => Brand::where('name', 'Kia')->value('id')]);
        $this->assertSame('Kia', $dealer->fresh()->franchise_make);
    }

    public function test_the_brand_checklists_ship_with_their_manufacturers_point_counts(): void
    {
        $counts = InspectionProgram::whereNull('dealer_id')->withCount('points')->get()->pluck('points_count', 'make');

        $this->assertSame(169, $counts['Nissan']);
        $this->assertSame(169, $counts['Infiniti']);
        $this->assertSame(152, $counts['Subaru']);
        $this->assertSame(112, $counts['Volkswagen']);
        $this->assertSame(336, $counts['Audi']);
    }

    public function test_every_dealer_of_a_brand_gets_its_checklist_the_same_way(): void
    {
        $first = $this->makeDealer(['name' => 'Willowdale Nissan', 'franchise_make' => 'Nissan']);
        $second = $this->makeDealer(['name' => 'Surrey Nissan', 'franchise_make' => 'Nissan']);

        foreach ([$first, $second] as $dealer) {
            $rogue = $this->car($dealer, ['make' => 'Nissan', 'is_manufacturer_certified' => true]);
            $this->assertSame('Nissan Certified Pre-Owned', $rogue->certification()?->name);
            $this->assertSame(169, $rogue->certification()->pointCount);
        }

        // A used Honda at the Nissan store, or a Nissan at a Mitsubishi store, gets nothing.
        $this->assertNull($this->car($first, ['make' => 'Honda', 'is_manufacturer_certified' => true])->certification());
        $this->assertNull($this->car($this->makeDealer(), ['make' => 'Nissan', 'is_manufacturer_certified' => true])->certification());
    }

    public function test_dealer_specific_checklists_no_longer_apply(): void
    {
        $dealer = $this->makeDealer(['name' => 'Willowdale Nissan', 'franchise_make' => 'Nissan']);
        $own = InspectionProgram::create(['dealer_id' => $dealer->id, 'name' => 'Willowdale Special', 'applies_to' => 'all_used']);
        $own->points()->create(['position' => 1, 'label' => 'Our own check']);

        $this->assertSame('Nissan Certified Pre-Owned', $this->car($dealer, ['make' => 'Nissan', 'is_manufacturer_certified' => true])->certification()?->name);
    }

    /* ---- screens ------------------------------------------------------- */

    public function test_brands_live_in_platform_settings(): void
    {
        $this->makeDealer(['name' => 'Willowdale Nissan', 'franchise_make' => 'Nissan']);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [Permission::DEALERS_MANAGE]]))
            ->get('http://admin.trucars.test/settings/brands')->assertForbidden();

        $this->actingAs(User::factory()->owner()->create());
        $this->get('http://admin.trucars.test/settings')->assertOk()->assertSee('Brands');
        $this->get('http://admin.trucars.test/settings/brands')->assertOk()->assertSee('Nissan')->assertSee('169-pt checklist');

        $nissan = Brand::where('name', 'Nissan')->sole();
        $this->get(route('admin.brands.show', $nissan))->assertOk()->assertSee('Willowdale Nissan')->assertSee('169');
    }

    public function test_owner_builds_a_brand_checklist_that_reaches_every_dealer_of_the_brand(): void
    {
        $toyota = Brand::where('name', 'Toyota')->sole();
        $stores = [$this->makeDealer(['name' => 'Bayview Toyota', 'franchise_make' => 'Toyota']), $this->makeDealer(['name' => 'Surrey Toyota', 'franchise_make' => 'Toyota'])];
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-brand-checklist', ['brand' => $toyota])
            ->set('name', 'Toyota Certified Used Vehicles')
            ->set('appliesTo', 'all_used')
            ->call('save')
            ->set('pasted', "Brakes: Pads\nTires: Tread depth")
            ->call('addPasted')
            ->assertSee('Added 2 points.');

        $this->assertSame('Toyota', InspectionProgram::where('name', 'Toyota Certified Used Vehicles')->value('make'));

        foreach ($stores as $store) {
            $this->assertSame(2, $this->car($store, ['make' => 'Toyota'])->certification()?->pointCount);
        }
    }

    public function test_a_brand_checklist_can_start_from_another_brands(): void
    {
        $infiniti = Brand::where('name', 'Infiniti')->sole();
        $infiniti->checklists()->each(fn ($program) => $program->delete());
        $nissan = InspectionProgram::where('make', 'Nissan')->sole();
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-brand-checklist', ['brand' => $infiniti])
            ->set('copyFromId', (string) $nissan->id)
            ->call('copyFrom');

        $copy = $infiniti->checklists()->sole();
        $this->assertSame(169, $copy->points()->count());
        $this->assertSame(169, $nissan->points()->count());
    }

    public function test_brands_can_be_added_and_unused_ones_removed(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-brands')->set('newBrand', 'Polestar')->call('addBrand');
        $polestar = Brand::where('name', 'Polestar')->sole();

        Livewire::test('pages::admin-brand', ['brand' => $polestar])->call('deleteBrand');
        $this->assertFalse(Brand::where('name', 'Polestar')->exists());

        Livewire::test('pages::admin-brand', ['brand' => Brand::where('name', 'Nissan')->sole()])->call('deleteBrand')->assertStatus(422);
    }

    private function car(Dealer $dealer, array $overrides = []): Vehicle
    {
        return $dealer->vehicles()->create($overrides + [
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi',
            'model' => 'Outlander', 'kilometres' => 20000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }
}

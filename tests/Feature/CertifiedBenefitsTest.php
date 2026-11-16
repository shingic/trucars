<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Permission;
use App\Support\ShopperLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class CertifiedBenefitsTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Brand $toyota;

    protected function setUp(): void
    {
        parent::setUp();
        session([ShopperLocation::SESSION_KEY => ['source' => 'manual', 'label' => 'Newmarket, ON', 'city' => 'Newmarket', 'province' => 'ON']]);
        $this->toyota = Brand::where('name', 'Toyota')->firstOrFail();
    }

    public function test_toyota_comes_prefilled_and_live(): void
    {
        $this->assertCount(8, $this->toyota->certifiedBenefitRows());
        $this->assertSame('Exchange privilege*', $this->toyota->certifiedBenefitRows()[2]['title']);
        $this->assertTrue($this->toyota->cpo_benefits_live);

        // No fine print entered yet: the marks still point somewhere.
        $car = $this->makeDealer(['name' => 'NEWMARKET TOYOTA', 'franchise_make' => 'Toyota'])->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Toyota', 'model' => 'RAV4', 'body_type' => 'SUV',
            'kilometres' => 30000, 'price_in_cents' => 3899000, 'condition' => 'USED', 'is_published' => true, 'is_manufacturer_certified' => true,
        ]);
        Livewire::test('pages::vehicle', ['vehicle' => $car])->assertSee('Exchange privilege*')->assertSee('Conditions apply');
    }

    public function test_only_manufacturer_certified_cars_of_the_brand_show_them(): void
    {
        $this->goLive();
        $dealer = $this->makeDealer(['name' => 'NEWMARKET TOYOTA', 'franchise_make' => 'Toyota']);

        $certified = $this->car($dealer, ['is_manufacturer_certified' => true]);
        Livewire::test('pages::vehicle', ['vehicle' => $certified])
            ->assertSee('Toyota Certified Pre-Owned')
            ->assertSee('Exchange privilege*')
            ->assertSee('7 days / 1,500 km')
            ->assertSee('*Exchange within 7 days.')
            ->assertSee('toyota.ca');

        // A plain used Toyota at the same dealer: the program doesn't cover it.
        Livewire::test('pages::vehicle', ['vehicle' => $this->car($dealer, ['is_manufacturer_certified' => false])])
            ->assertDontSee('Exchange privilege');

        // Switched off: nothing anywhere.
        $this->toyota->update(['cpo_benefits_live' => false]);
        Livewire::test('pages::vehicle', ['vehicle' => $certified->fresh()])->assertDontSee('Exchange privilege');
    }

    public function test_a_different_brands_dealer_never_shows_toyotas_benefits(): void
    {
        $this->goLive();
        $nissanDealer = $this->makeDealer(['name' => 'NEWMARKET NISSAN', 'franchise_make' => 'Nissan']);

        // Even if something flagged it, a Toyota at a Nissan store isn't Toyota-certified.
        Livewire::test('pages::vehicle', ['vehicle' => $this->car($nissanDealer, ['is_manufacturer_certified' => true])])
            ->assertDontSee('Exchange privilege');
    }

    public function test_turning_on_needs_the_brand_page_and_a_checked_date(): void
    {
        $this->actingAs(User::factory()->owner()->create());

        $this->toyota->update(['cpo_benefits_live' => false]);

        Livewire::test('pages::admin-brand-benefits', ['brand' => $this->toyota])
            ->assertSee('Exchange privilege*')
            ->call('setLive', true)
            ->assertHasErrors(['sourceUrl', 'checkedOn'])
            ->set('sourceUrl', 'http://www.toyota.ca/cpo')
            ->set('checkedOn', now()->toDateString())
            ->call('setLive', true)
            ->assertHasErrors('sourceUrl') // https only
            ->set('sourceUrl', 'https://www.toyota.ca/cpo')
            ->set('finePrint', '*Exchange within 7 days.')
            ->call('moveRow', 2, -1)
            ->call('removeRow', 7)
            ->call('setLive', true)
            ->assertHasNoErrors()
            ->assertSee('Showing on car pages');

        $this->toyota->refresh();
        $this->assertTrue($this->toyota->cpo_benefits_live);
        $this->assertCount(7, $this->toyota->certifiedBenefitRows());
        $this->assertSame('Exchange privilege*', $this->toyota->certifiedBenefitRows()[1]['title']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'brand.benefits_updated']);
    }

    public function test_the_editor_needs_settings_manage(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [Permission::DEALERS_MANAGE]]))
            ->get(route('admin.brands.benefits', $this->toyota))->assertForbidden();

        $this->actingAs(User::factory()->owner()->create())
            ->get(route('admin.brands.benefits', $this->toyota))->assertOk()->assertSee('Certified benefits');
    }

    private function goLive(): void
    {
        $this->toyota->update([
            'cpo_benefits_live' => true,
            'cpo_fine_print'    => '*Exchange within 7 days.',
            'cpo_source_url'    => 'https://www.toyota.ca/cpo',
            'cpo_checked_on'    => now()->toDateString(),
        ]);
    }

    private function car($dealer, array $overrides): Vehicle
    {
        return $dealer->vehicles()->create($overrides + [
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Toyota', 'model' => 'RAV4', 'body_type' => 'SUV',
            'kilometres' => 30000, 'price_in_cents' => 3899000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }
}

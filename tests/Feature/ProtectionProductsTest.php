<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\ProtectionProduct;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Permission;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class ProtectionProductsTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    private Dealer $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dealer = $this->makeDealer();
        $this->other = $this->makeDealer(['name' => 'Valley Nissan']);
    }

    public function test_every_dealership_starts_with_the_menu_checkout_always_had(): void
    {
        $menu = ProtectionProduct::menuFor($this->dealer);

        $this->assertSame(['Open Road', 'Safe Bet', 'Bumper-to-Bumper'], $menu->where('kind', 'plan')->pluck('name')->values()->all());
        $this->assertSame(1800, $menu->firstWhere('kind', 'gap')->offered_amount_in_cents);
        $this->assertCount(5, $menu->where('kind', 'addon'));
    }

    public function test_a_dealership_hides_a_default_and_sets_its_own_price_without_touching_anyone_else(): void
    {
        $gap = ProtectionProduct::defaults()->where('kind', 'gap')->sole();
        $keys = ProtectionProduct::defaults()->where('name', 'Lost key cover')->sole();
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        Livewire::test('pages::dealer-protection')
            ->call('startPricing', $gap->id)
            ->set('pricingAmount', '24.50')
            ->call('savePricing')
            ->assertHasNoErrors()
            ->call('toggleDefault', $keys->id)
            ->assertSee('Hidden from buyers');

        $mine = ProtectionProduct::menuFor($this->dealer);
        $this->assertSame(2450, $mine->firstWhere('kind', 'gap')->offered_amount_in_cents);
        $this->assertFalse($mine->contains('name', 'Lost key cover'));

        $theirs = ProtectionProduct::menuFor($this->other);
        $this->assertSame(1800, $theirs->firstWhere('kind', 'gap')->offered_amount_in_cents);
        $this->assertTrue($theirs->contains('name', 'Lost key cover'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'protection.default_hidden', 'target_id' => $this->dealer->id]);
    }

    public function test_a_dealerships_own_accessory_shows_in_its_checkout_only(): void
    {
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        Livewire::test('pages::dealer-protection')
            ->call('startAddProduct', 'accessory')
            ->set('productName', 'All-weather floor mats')
            ->set('productAmount', '249')
            ->set('productBasis', 'one_time')
            ->call('saveOwnProduct')
            ->assertHasNoErrors()
            ->assertSee('All-weather floor mats');

        $this->actingAs(User::factory()->create());

        $mine = Livewire::test('pages::checkout', ['vehicle' => $this->car($this->dealer)]);
        $this->assertSame(['All-weather floor mats'], array_column($mine->get('accessoryCatalog'), 'name'));
        $this->assertSame('$249', array_values($mine->get('accessoryCatalog'))[0]['price']);

        $theirs = Livewire::test('pages::checkout', ['vehicle' => $this->car($this->other)]);
        $this->assertSame([], $theirs->get('accessoryCatalog'));
    }

    public function test_a_dealership_cannot_edit_another_dealerships_items_or_the_defaults_themselves(): void
    {
        $theirs = $this->other->protectionProducts()->create(['kind' => 'accessory', 'name' => 'Roof rack']);
        $default = ProtectionProduct::defaults()->where('name', 'Safe Bet')->sole();
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        $page = Livewire::test('pages::dealer-protection');

        $this->assertThrows(fn () => $page->call('removeOwnProduct', $theirs->id), ModelNotFoundException::class);
        $this->assertThrows(fn () => $page->call('startEditOwn', $default->id), ModelNotFoundException::class);
        $this->assertThrows(fn () => $page->call('toggleDefault', $theirs->id), ModelNotFoundException::class);

        $this->assertModelExists($theirs);
    }

    public function test_checkout_keeps_what_was_flagged_at_the_dealerships_prices_and_ignores_made_up_keys(): void
    {
        $tires = ProtectionProduct::defaults()->where('name', 'Tire & rim cover')->sole();
        $this->dealer->protectionSettings()->create(['protection_product_id' => $tires->id, 'amount_in_cents' => 1100]);
        $safeBet = ProtectionProduct::defaults()->where('name', 'Safe Bet')->sole();
        $this->actingAs(User::factory()->create());

        $checkout = Livewire::test('pages::checkout', ['vehicle' => $this->car($this->dealer)])
            ->call('selectWarranty', 'p999999')
            ->assertSet('warrantyPlan', null)
            ->call('toggleExtra', 'p999999')
            ->assertSet('selectedExtras', [])
            ->call('selectWarranty', 'p' . $safeBet->id)
            ->call('toggleGap')
            ->call('toggleExtra', 'p' . $tires->id);

        $this->assertSame([
            ['kind' => 'plan', 'name' => 'Safe Bet', 'amount_in_cents' => null, 'price_basis' => 'biweekly'],
            ['kind' => 'gap', 'name' => 'GAP protection', 'amount_in_cents' => 1800, 'price_basis' => 'biweekly'],
            ['kind' => 'addon', 'name' => 'Tire & rim cover', 'amount_in_cents' => 1100, 'price_basis' => 'biweekly'],
        ], $checkout->instance()->protectionSnapshot());
    }

    public function test_the_command_centre_manages_the_defaults_with_settings_access(): void
    {
        $this->actingAs($this->staff([Permission::SETTINGS_MANAGE]));

        Livewire::test('pages::admin-protection')
            ->call('startAddProduct', 'accessory')
            ->set('productName', 'Cargo tray')
            ->set('productAmount', '129')
            ->set('productBasis', 'one_time')
            ->call('saveDefault')
            ->assertHasNoErrors();

        $this->assertTrue(ProtectionProduct::menuFor($this->dealer)->contains('name', 'Cargo tray'));
        $this->assertTrue(ProtectionProduct::menuFor($this->other)->contains('name', 'Cargo tray'));

        $this->actingAs($this->staff([Permission::DEALERS_VIEW]));
        $this->get(route('admin.settings.protection'))->assertForbidden();
    }

    public function test_the_command_centre_changes_a_dealers_list_only_with_manage_access(): void
    {
        $gap = ProtectionProduct::defaults()->where('kind', 'gap')->sole();

        $this->actingAs($this->staff([Permission::DEALERS_VIEW]));
        Livewire::test('pages::admin-dealer-protection', ['dealer' => $this->dealer])
            ->assertSee('GAP protection')
            ->call('toggleDefault', $gap->id)
            ->assertForbidden();

        $this->actingAs($this->staff([Permission::DEALERS_VIEW, Permission::DEALERS_MANAGE]));
        Livewire::test('pages::admin-dealer-protection', ['dealer' => $this->dealer])->call('toggleDefault', $gap->id);

        $this->assertNull(ProtectionProduct::menuFor($this->dealer)->firstWhere('kind', 'gap'));
    }

    private function car(Dealer $dealer): Vehicle
    {
        return $dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }

    private function staff(array $permissions): User
    {
        return User::factory()->create([
            'role'        => User::ROLE_INTERNAL_STAFF,
            'permissions' => Permission::normalize($permissions),
        ]);
    }
}

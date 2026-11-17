<?php

namespace Tests\Feature;

use App\Models\DealerFee;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\CanadianAddress;
use App\Support\ProvinceRules;
use App\Support\TradeTaxCredit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class ProvinceRulesTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    public function test_every_province_has_a_sales_tax(): void
    {
        foreach (array_keys(CanadianAddress::PROVINCES) as $code) {
            $tax = ProvinceRules::salesTax($code);
            $this->assertNotEmpty($tax['label'], $code);
            $this->assertGreaterThan(0, $tax['rate'], $code);
        }

        $this->assertSame(0.13, ProvinceRules::salesTax('ON')['rate']);
        $this->assertSame(['label' => 'GST + PST', 'excludes' => 'GST, PST', 'rate' => 0.12], ProvinceRules::salesTax('BC', 3000000));
        $this->assertSame(0.05, ProvinceRules::salesTax('AB')['rate']);
        $this->assertSame(0.14975, ProvinceRules::salesTax('Quebec')['rate']);
    }

    public function test_bc_pst_steps_up_with_the_price(): void
    {
        $this->assertEqualsWithDelta(0.12, ProvinceRules::salesTax('BC', 5499999)['rate'], 1e-9);
        $this->assertEqualsWithDelta(0.13, ProvinceRules::salesTax('BC', 5500000)['rate'], 1e-9);
        $this->assertEqualsWithDelta(0.14, ProvinceRules::salesTax('BC', 5650000)['rate'], 1e-9);
        $this->assertEqualsWithDelta(0.15, ProvinceRules::salesTax('BC', 6000000)['rate'], 1e-9);
        $this->assertEqualsWithDelta(0.20, ProvinceRules::salesTax('BC', 13000000)['rate'], 1e-9);
        $this->assertEqualsWithDelta(0.25, ProvinceRules::salesTax('BC', 16000000)['rate'], 1e-9);
    }

    public function test_regulators_are_named_only_where_confirmed(): void
    {
        $this->assertSame('OMVIC', ProvinceRules::regulator('ON')['short']);
        $this->assertSame('VSA', ProvinceRules::regulator('BC')['short']);
        $this->assertSame('AMVIC', CanadianAddress::regulatorFor('AB'));
        $this->assertNull(ProvinceRules::regulator('NS'));
    }

    public function test_trade_in_savings_use_the_province_and_bc_price_tier(): void
    {
        $this->assertSame(130000, TradeTaxCredit::savingsInCents('ON', 1000000));
        $this->assertSame(120000, TradeTaxCredit::savingsInCents('BC', 1000000, 3000000));
        $this->assertSame(150000, TradeTaxCredit::savingsInCents('BC', 1000000, 6000000));
        $this->assertSame('GST + PST', TradeTaxCredit::taxName('BC'));
    }

    public function test_a_bc_car_page_never_mentions_omvic_or_hst(): void
    {
        $car = $this->car($this->makeDealer());

        $this->get('/cars/' . $car->id)->assertOk()
            ->assertSee('freight, prep and dealer fees included')
            ->assertSee('Excludes GST, PST &amp; licensing', false)
            ->assertDontSee('OMVIC')
            ->assertDontSee('HST');
    }

    public function test_an_ontario_car_names_the_dealers_own_included_fees(): void
    {
        $dealer = $this->makeDealer(['name' => 'Toronto Motors', 'province' => 'ON', 'city' => 'Toronto', 'postal_code' => 'M5V 2T6', 'latitude' => 43.64, 'longitude' => -79.39, 'timezone' => 'America/Toronto']);
        DealerFee::create(['dealer_id' => $dealer->id, 'label' => 'Freight', 'kind' => DealerFee::KIND_INCLUDED, 'amount_in_cents' => 180000, 'sort_order' => 1, 'is_active' => true]);
        DealerFee::create(['dealer_id' => $dealer->id, 'label' => 'OMVIC fee', 'kind' => DealerFee::KIND_INCLUDED, 'amount_in_cents' => 1250, 'sort_order' => 2, 'is_active' => true]);
        DealerFee::create(['dealer_id' => $dealer->id, 'label' => 'Licensing', 'kind' => DealerFee::KIND_PASS_THROUGH, 'amount_in_cents' => 5900, 'sort_order' => 3, 'is_active' => true]);

        $this->get('/cars/' . $this->car($dealer)->id)->assertOk()
            ->assertSee('freight &amp; OMVIC fee included', false)
            ->assertSee('Excludes HST &amp; licensing', false);
    }

    public function test_the_payment_estimate_uses_the_dealers_tax(): void
    {
        $bc = $this->car($this->makeDealer());
        $on = $this->car($this->makeDealer(['name' => 'Toronto Motors', 'province' => 'ON', 'city' => 'Toronto', 'postal_code' => 'M5V 2T6', 'latitude' => 43.64, 'longitude' => -79.39, 'timezone' => 'America/Toronto']));

        $this->assertGreaterThan($bc->biweeklyAt(7.5, 72), $on->biweeklyAt(7.5, 72));
    }

    public function test_checkout_shows_the_provinces_tax_line(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::checkout', ['vehicle' => $this->car($this->makeDealer())])
            ->assertSee('GST + PST (12%)')
            ->assertDontSee('HST (13%)');
    }

    private function car($dealer): Vehicle
    {
        return $dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\ProtectionProduct;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PaymentEstimate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class CheckoutCoverageTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();

        $this->car = $this->makeDealer()->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);
        $this->actingAs(User::factory()->create());
    }

    public function test_no_added_coverage_is_a_choice_that_shows_as_selected(): void
    {
        $checkout = Livewire::test('pages::checkout', ['vehicle' => $this->car]);
        $safeBet = 'p' . ProtectionProduct::defaults()->where('name', 'Safe Bet')->value('id');

        $checkout->set('stepIndex', $checkout->instance()->indexOfStep('plan'))
            ->assertSeeHtml('class="choice no-cover is-on"')
            ->call('selectWarranty', $safeBet)
            ->assertSet('warrantyPlan', $safeBet)
            ->assertDontSeeHtml('class="choice no-cover is-on"')
            ->call('selectWarranty', null)
            ->assertSet('warrantyPlan', null)
            ->assertSeeHtml('class="choice no-cover is-on"');
    }

    public function test_a_plan_is_entered_at_full_price_and_checkout_shows_the_biweekly(): void
    {
        $safeBet = ProtectionProduct::defaults()->where('name', 'Safe Bet')->sole();
        $safeBet->update(['amount_in_cents' => 300000, 'price_basis' => 'biweekly']);
        $this->assertSame('one_time', $safeBet->fresh()->price_basis, 'plans are always a full price');

        $checkout = Livewire::test('pages::checkout', ['vehicle' => $this->car]);
        $checkout->set('stepIndex', $checkout->instance()->indexOfStep('plan'));
        $before = $checkout->instance()->estimatedBiweekly();
        $planBiweekly = PaymentEstimate::loanBiweekly(3000, 72);

        $checkout->assertSee('$3,000 added to your loan')
            ->assertSee('+$' . number_format($planBiweekly, 0))
            ->call('selectWarranty', 'p' . $safeBet->id);

        $this->assertEqualsWithDelta($before + $planBiweekly, $checkout->instance()->estimatedBiweekly(), 0.01);
        $breakdown = $checkout->instance()->paymentBreakdown();
        $this->assertEqualsWithDelta($before, $breakdown['vehicleBiweekly'] + $breakdown['taxesBiweekly'], 0.01, 'the car line stays the car');

        // A longer term spreads the plan thinner.
        $checkout->call('adjustTerm', 1);
        $this->assertEqualsWithDelta(PaymentEstimate::loanBiweekly(3000, 84), $checkout->instance()->financedItemBiweekly(300000), 0.01);

        // Paying in full adds the full price.
        $checkout->call('setPurchaseType', 'cash');
        $this->assertEqualsWithDelta(29990 + 3000, $checkout->instance()->cashTotalDollars(), 0.01);

        $checkout->call('selectWarranty', null);
        $this->assertEqualsWithDelta(29990, $checkout->instance()->cashTotalDollars(), 0.01);
    }
}

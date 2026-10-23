<?php

namespace Tests\Feature;

use App\Models\ProtectionProduct;
use App\Models\User;
use App\Models\Vehicle;
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
}

<?php

namespace Tests\Feature;

use App\Models\CheckoutDraft;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class SwitchCheckoutTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Vehicle $mustang;

    private Vehicle $gle;

    private User $shopper;

    protected function setUp(): void
    {
        parent::setUp();
        $dealer = $this->makeDealer();
        $this->mustang = $this->car($dealer, 'Ford', 'Mustang', 2024);
        $this->gle = $this->car($dealer, 'Mercedes-Benz', 'GLE', 2021);
        $this->shopper = User::factory()->create(['name' => 'Sam Shopper']);
        $this->actingAs($this->shopper);
    }

    public function test_starting_a_second_car_asks_first_and_saves_nothing_until_they_choose(): void
    {
        $this->partwayThroughTheMustang();

        Livewire::test('pages::checkout', ['vehicle' => $this->gle])
            ->assertSee('Start a new checkout?')
            ->assertSee('2024 Ford Mustang')
            ->assertSee('you stopped at Plan')
            ->call('goNext'); // blocked while asking

        $this->assertFalse(CheckoutDraft::where('vehicle_id', $this->gle->id)->exists());
    }

    public function test_keep_previous_goes_back_to_the_first_car(): void
    {
        $this->partwayThroughTheMustang();

        Livewire::test('pages::checkout', ['vehicle' => $this->gle])
            ->call('keepPreviousCheckout')
            ->assertRedirect(route('checkout', ['vehicle' => $this->mustang->id]));

        $this->assertSame(CheckoutDraft::STATUS_ACTIVE, CheckoutDraft::where('vehicle_id', $this->mustang->id)->value('status'));
        $this->assertFalse(CheckoutDraft::where('vehicle_id', $this->gle->id)->exists());
    }

    public function test_start_new_ends_the_old_one_and_brings_their_details(): void
    {
        $this->partwayThroughTheMustang();

        Livewire::test('pages::checkout', ['vehicle' => $this->gle])
            ->call('startNewCheckout')
            ->assertDontSee('Start a new checkout?')
            ->assertSet('phone', '(647) 555-0123')
            ->assertSet('city', 'Newmarket')
            ->assertSet('purchaseType', 'cash')
            ->assertSet('tradeMake', 'Honda')
            ->assertSet('tradeSubStep', 'condition')       // re-valued for this car's dealer
            ->assertSet('tradeEstimatePointInCents', null)
            ->assertSet('stepIndex', 0);

        $this->assertSame(CheckoutDraft::STATUS_REPLACED, CheckoutDraft::where('vehicle_id', $this->mustang->id)->value('status'));
        $this->assertSame(CheckoutDraft::STATUS_ACTIVE, CheckoutDraft::where('vehicle_id', $this->gle->id)->value('status'));
    }

    public function test_no_question_when_it_isnt_needed(): void
    {
        // Barely opened the other checkout: nothing to lose.
        CheckoutDraft::create(['user_id' => $this->shopper->id, 'vehicle_id' => $this->mustang->id, 'step_key' => 'trade', 'data' => ['stepIndex' => 0, 'tradeSubStep' => 'choice'], 'last_active_at' => now()]);
        Livewire::test('pages::checkout', ['vehicle' => $this->gle])->assertDontSee('Start a new checkout?');

        // Coming back to a car they already started.
        CheckoutDraft::query()->delete();
        $this->partwayThroughTheMustang();
        Livewire::test('pages::checkout', ['vehicle' => $this->mustang])->assertDontSee('Start a new checkout?');

        // The other car was taken off the market.
        $this->mustang->update(['is_published' => false]);
        Livewire::test('pages::checkout', ['vehicle' => $this->gle])->assertDontSee('Start a new checkout?');
    }

    private function partwayThroughTheMustang(): void
    {
        CheckoutDraft::create([
            'user_id' => $this->shopper->id, 'vehicle_id' => $this->mustang->id, 'step_key' => 'plan', 'last_active_at' => now(),
            'data'    => [
                'stepIndex' => 2, 'wantsTrade' => true, 'tradeSubStep' => 'estimate', 'tradeYear' => 2015, 'tradeMake' => 'Honda', 'tradeModel' => 'Civic',
                'tradeKilometres' => 140000, 'tradeEstimatePointInCents' => 900000,
                'firstName' => 'Sam', 'lastName' => 'Shopper', 'phone' => '(647) 555-0123', 'streetAddress' => '1 Main St', 'city' => 'Newmarket',
                'province' => 'Ontario', 'postalCode' => 'L3Y 4X1', 'purchaseType' => 'cash', 'warrantyPlan' => 'gold', 'handoverDate' => '2026-12-01',
            ],
        ]);
    }

    private function car($dealer, string $make, string $model, int $year): Vehicle
    {
        return $dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => $year, 'make' => $make, 'model' => $model, 'body_type' => 'SUV',
            'kilometres' => 30000, 'price_in_cents' => 4129000, 'condition' => 'USED', 'is_published' => true,
        ]);
    }
}

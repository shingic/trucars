<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

/** A refresh mid-checkout picks up where the buyer left off. */
class CheckoutDraftTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-04 12:00', 'America/Vancouver')); // a Wednesday

        $this->car = $this->makeDealer()->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 3999500, 'condition' => 'USED', 'is_published' => true,
        ]);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_refresh_keeps_the_step_and_everything_entered(): void
    {
        Livewire::test('pages::checkout', ['vehicle' => $this->car])
            ->set('firstName', 'Sam')
            ->set('phone', '6045550100')
            ->set('purchaseType', 'cash')
            ->set('handoverTimeSlot', '14:00')
            ->set('stepIndex', 4);

        // The page is loaded again from scratch.
        Livewire::test('pages::checkout', ['vehicle' => $this->car])
            ->assertSet('stepIndex', 4)
            ->assertSet('firstName', 'Sam')
            ->assertSet('phone', '6045550100')
            ->assertSet('purchaseType', 'cash')
            ->assertSee('When will you pick it up?');
    }

    public function test_a_saved_pickup_day_that_has_passed_gives_way_to_the_first_one_offered(): void
    {
        Livewire::test('pages::checkout', ['vehicle' => $this->car])->assertSet('handoverDate', '2026-11-06');

        Carbon::setTestNow(Carbon::parse('2026-11-09 12:00', 'America/Vancouver')); // the next Monday

        Livewire::test('pages::checkout', ['vehicle' => $this->car])->assertSet('handoverDate', '2026-11-11');
    }

    public function test_each_car_keeps_its_own_draft(): void
    {
        $other = $this->car->dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'RVR',
            'kilometres' => 20000, 'price_in_cents' => 2599500, 'condition' => 'USED', 'is_published' => true,
        ]);

        Livewire::test('pages::checkout', ['vehicle' => $this->car])->set('stepIndex', 3);

        Livewire::test('pages::checkout', ['vehicle' => $other])->assertSet('stepIndex', 0);
    }

    public function test_the_draft_is_cleared_once_the_car_is_reserved(): void
    {
        Livewire::test('pages::checkout', ['vehicle' => $this->car])
            ->set('firstName', 'Sam')
            ->set('phone', '6045550100')
            ->call('preparePayment')
            ->call('reserve')
            ->assertSet('dealReference', fn ($reference) => $reference !== null);

        $this->assertNull(session('checkout_draft.' . auth()->id() . '.' . $this->car->id));
    }
}

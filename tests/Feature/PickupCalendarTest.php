<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PickupCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class PickupCalendarTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 2026-11-02 is a Monday. */
    public function test_two_weekdays_of_prep_before_the_first_pickup(): void
    {
        $earliest = fn (string $ordered) => PickupCalendar::earliest(Carbon::parse($ordered))->format('D Y-m-d');

        $this->assertSame('Wed 2026-11-04', $earliest('2026-11-02 09:00')); // Mon
        $this->assertSame('Thu 2026-11-05', $earliest('2026-11-03 23:30')); // Tue
        $this->assertSame('Fri 2026-11-06', $earliest('2026-11-04 12:00')); // Wed
        $this->assertSame('Mon 2026-11-09', $earliest('2026-11-05 12:00')); // Thu
        $this->assertSame('Tue 2026-11-10', $earliest('2026-11-06 08:00')); // Fri
        $this->assertSame('Tue 2026-11-10', $earliest('2026-11-07 12:00')); // Sat
        $this->assertSame('Tue 2026-11-10', $earliest('2026-11-08 12:00')); // Sun
    }

    public function test_checkout_never_offers_next_day_or_sunday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 18:00', 'America/Vancouver')); // a Thursday at the store
        $car = $this->makeDealer()->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 3999500, 'condition' => 'USED', 'is_published' => true,
        ]);
        $this->actingAs(User::factory()->create());

        $checkout = Livewire::test('pages::checkout', ['vehicle' => $car]);
        $dates = array_column($checkout->instance()->handoverDateOptions(), 'date');

        $this->assertSame('2026-11-09', $dates[0], 'Thursday order: first pickup Monday.');
        $this->assertNotContains('2026-11-06', $dates); // Friday: still prepping
        $this->assertNotContains('2026-11-08', $dates); // Sunday
        foreach ($dates as $date) {
            $this->assertFalse(Carbon::parse($date)->isSunday());
        }
        $checkout->set('stepIndex', 4)->assertSee('the first pickup day is Monday, November 9');

        // A date before the first pickup day is refused, even if sent directly.
        $checkout->call('setHandoverDate', '2026-11-06')->assertSet('handoverDate', '2026-11-09');
    }
}

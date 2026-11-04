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
        $this->assertSame('Thu 2026-11-05', $earliest('2026-11-03 17:59')); // Tue, before the 6 pm cutoff
        $this->assertSame('Fri 2026-11-06', $earliest('2026-11-04 12:00')); // Wed
        $this->assertSame('Mon 2026-11-09', $earliest('2026-11-05 12:00')); // Thu
        $this->assertSame('Tue 2026-11-10', $earliest('2026-11-06 08:00')); // Fri
        $this->assertSame('Tue 2026-11-10', $earliest('2026-11-07 12:00')); // Sat
        $this->assertSame('Tue 2026-11-10', $earliest('2026-11-08 12:00')); // Sun
    }

    public function test_an_order_after_the_cutoff_counts_as_the_next_days(): void
    {
        $earliest = fn (string $ordered, ?string $cutoff = null) => PickupCalendar::earliest(Carbon::parse($ordered), $cutoff)->format('D Y-m-d');

        $this->assertSame('Fri 2026-11-06', $earliest('2026-11-03 18:00'));          // Tue at 6 pm: a Wednesday order
        $this->assertSame('Fri 2026-11-06', $earliest('2026-11-03 22:28'));          // Tue late night
        $this->assertSame('Thu 2026-11-05', $earliest('2026-11-03 19:30', '21:00')); // store open till 9 pm
        $this->assertSame('Tue 2026-11-10', $earliest('2026-11-05 20:00'));          // Thu evening: a Friday order
    }

    public function test_tuesday_night_at_a_pacific_store_first_pickup_is_friday(): void
    {
        // Wed Nov 4, 1:28 am in Ontario is Tue Nov 3, 10:28 pm at a Chilliwack store.
        Carbon::setTestNow(Carbon::parse('2026-11-04 01:28', 'America/Toronto'));
        $car = $this->makeDealer()->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 3999500, 'condition' => 'USED', 'is_published' => true,
        ]);
        $this->actingAs(User::factory()->create());

        $dates = array_column(Livewire::test('pages::checkout', ['vehicle' => $car])->instance()->handoverDateOptions(), 'date');

        $this->assertSame('2026-11-06', $dates[0]);
    }

    public function test_checkout_never_offers_next_day_or_sunday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00', 'America/Vancouver')); // a Thursday afternoon at the store
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

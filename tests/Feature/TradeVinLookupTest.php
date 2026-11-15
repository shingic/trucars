<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Services\Vin\VinDecoder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class TradeVinLookupTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private const VIN = '1HGCM82633A004352'; // a real, valid-check-digit Honda VIN

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();
        $this->car = $this->makeDealer()->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2021, 'make' => 'Jeep', 'model' => 'Grand Cherokee', 'body_type' => 'SUV',
            'kilometres' => 37000, 'price_in_cents' => 4195000, 'condition' => 'USED', 'is_published' => true,
        ]);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_check_digit_catches_typos(): void
    {
        $this->assertTrue(VinDecoder::isValid(self::VIN));
        $this->assertFalse(VinDecoder::isValid('1HGCM82633A004353')); // last digit changed
        $this->assertFalse(VinDecoder::isValid('1HGCM8263IA004352')); // an I
        $this->assertSame(self::VIN, VinDecoder::clean(' 1hgcm8-2633a004352 '));
    }

    public function test_a_vin_fills_in_the_car(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response(['Results' => [[
            'ErrorCode' => '0', 'ModelYear' => '2003', 'Make' => 'HONDA', 'Model' => 'Accord', 'Trim' => 'EX-V6', 'Series' => '',
        ]]])]);

        $checkout = $this->atTradeDetails()
            ->set('tradeVinInput', strtolower(self::VIN))
            ->call('lookUpTradeVin')
            ->assertSet('tradeYear', 2003)
            ->assertSet('tradeMake', 'Honda')
            ->assertSet('tradeModel', 'Accord')
            ->assertSet('tradeTrim', 'EX-V6')
            ->assertSet('tradeVin', self::VIN)
            ->assertSee('Found it: 2003 Honda Accord EX-V6');

        $this->assertSame(self::VIN, $checkout->instance()->tradeVin);
        Http::assertSentCount(1);

        // Cached: the same VIN again doesn't call out.
        $this->atTradeDetails()->set('tradeVinInput', self::VIN)->call('lookUpTradeVin')->assertSet('tradeMake', 'Honda');
        Http::assertSentCount(1);
    }

    public function test_bad_or_unknown_vins_say_so_and_leave_the_form_alone(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response(['Results' => [['ErrorCode' => '8', 'ModelYear' => '', 'Make' => '', 'Model' => '']]])]);

        $this->atTradeDetails()->set('tradeVinInput', 'ABC123')->call('lookUpTradeVin')
            ->assertSee('A VIN is 17 letters and numbers. You typed 6.')->assertSet('tradeMake', '');

        $this->atTradeDetails()->set('tradeVinInput', '1HGCM82633A004353')->call('lookUpTradeVin')
            ->assertSee('doesn’t add up')->assertSet('tradeMake', '');
        Http::assertNothingSent();

        $this->atTradeDetails()->set('tradeVinInput', self::VIN)->call('lookUpTradeVin')
            ->assertSee('We couldn’t find that VIN')->assertSet('tradeMake', '')->assertSet('tradeVin', '');
    }

    private function atTradeDetails()
    {
        return Livewire::test('pages::checkout', ['vehicle' => $this->car])->call('setWantsTrade', true)->call('goNext')->assertSet('tradeSubStep', 'details');
    }
}

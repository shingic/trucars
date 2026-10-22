<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class AddressSuggestionsTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Vehicle $car;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_places.key' => 'AIza-test']);
        $this->session = (string) Str::uuid();

        $this->car = $this->makeDealer()->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 3999500, 'condition' => 'USED', 'is_published' => true,
        ]);
        $this->actingAs(User::factory()->create());
    }

    public function test_typing_suggests_addresses_and_picking_one_fills_the_form(): void
    {
        Http::fake([
            'places.googleapis.com/v1/places:autocomplete' => Http::response(['suggestions' => [
                ['placePrediction' => ['placeId' => 'ChIJyonge', 'text' => ['text' => '1 Yonge St, Toronto, ON, Canada'],
                    'structuredFormat' => ['mainText' => ['text' => '1 Yonge St'], 'secondaryText' => ['text' => 'Toronto, ON, Canada']]]],
            ]]),
            'places.googleapis.com/v1/places/ChIJyonge*' => Http::response(['addressComponents' => [
                ['longText' => '1801', 'shortText' => '1801', 'types' => ['subpremise']],
                ['longText' => '1', 'shortText' => '1', 'types' => ['street_number']],
                ['longText' => 'Yonge Street', 'shortText' => 'Yonge St', 'types' => ['route']],
                ['longText' => 'Toronto', 'shortText' => 'Toronto', 'types' => ['locality', 'political']],
                ['longText' => 'Ontario', 'shortText' => 'ON', 'types' => ['administrative_area_level_1', 'political']],
                ['longText' => 'M5E 1W7', 'shortText' => 'M5E 1W7', 'types' => ['postal_code']],
            ]]),
        ]);

        $checkout = Livewire::test('pages::checkout', ['vehicle' => $this->car]);
        $checkout->set('stepIndex', 1)->assertSee('Start typing your address');

        $suggestions = $checkout->instance()->findAddress('1 Yon', $this->session);
        $this->assertSame([['id' => 'ChIJyonge', 'text' => '1 Yonge St', 'description' => 'Toronto, ON, Canada']], $suggestions);

        $checkout->call('pickAddress', 'ChIJyonge', $this->session)
            ->assertSet('streetAddress', '1 Yonge St, Unit 1801')
            ->assertSet('city', 'Toronto')
            ->assertSet('province', 'Ontario')
            ->assertSet('postalCode', 'M5E 1W7');

        // The key travels in a header (never the URL), Canada only, one session for both calls.
        Http::assertSent(fn ($request) => $request->hasHeader('X-Goog-Api-Key', 'AIza-test')
            && ! str_contains($request->url(), 'AIza-test')
            && ($request->method() === 'GET' ? str_contains($request->url(), 'sessionToken=' . $this->session) : $request['sessionToken'] === $this->session && $request['includedRegionCodes'] === ['ca']));
    }

    public function test_a_province_outside_the_short_list_is_added(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['addressComponents' => [
            ['longText' => '1', 'shortText' => '1', 'types' => ['street_number']],
            ['longText' => 'Spring Garden Road', 'shortText' => 'Spring Garden Rd', 'types' => ['route']],
            ['longText' => 'Halifax', 'shortText' => 'Halifax', 'types' => ['locality']],
            ['longText' => 'Nova Scotia', 'shortText' => 'NS', 'types' => ['administrative_area_level_1']],
            ['longText' => 'B3J 1A1', 'shortText' => 'B3J 1A1', 'types' => ['postal_code']],
        ]])]);

        $checkout = Livewire::test('pages::checkout', ['vehicle' => $this->car])->call('pickAddress', 'ChIJhalifax', $this->session);

        $checkout->assertSet('province', 'Nova Scotia')->assertSet('streetAddress', '1 Spring Garden Rd');
        $this->assertContains('Nova Scotia', $checkout->get('provinceOptions'));
    }

    public function test_problems_fall_back_to_typing_it_in(): void
    {
        Http::fake(['places.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'message' => 'API key not valid']], 403)]);

        $checkout = Livewire::test('pages::checkout', ['vehicle' => $this->car])->set('streetAddress', '12 Main St');

        $this->assertSame([], $checkout->instance()->findAddress('12 Main', $this->session));
        $checkout->call('pickAddress', 'ChIJx', $this->session)->assertSet('streetAddress', '12 Main St');

        // A bad session token never reaches Google.
        $this->assertSame([], $checkout->instance()->findAddress('12 Main', 'not-a-uuid'));
        Http::assertSentCount(2);
    }

    public function test_no_key_means_a_plain_field_and_no_calls(): void
    {
        config(['services.google_places.key' => null]);
        Http::fake();

        $checkout = Livewire::test('pages::checkout', ['vehicle' => $this->car])->set('stepIndex', 1);

        $checkout->assertDontSee('Start typing your address');
        $this->assertSame([], $checkout->instance()->findAddress('1 Yonge', $this->session));
        Http::assertNothingSent();
    }
}

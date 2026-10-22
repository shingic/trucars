<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\Lead;
use App\Models\Scopes\DealerScope;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class DealerCampaignTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;
    private Dealer $rival;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->dealer = $this->makeDealer(['franchise_make' => 'Mitsubishi']);
        $this->rival = $this->makeDealer(['name' => 'RIVAL MITSUBISHI', 'city' => 'Abbotsford', 'postal_code' => 'V2S 1A1', 'franchise_make' => 'Mitsubishi']);
    }

    /* ------------------------------------------------------------------ */

    public function test_campaign_link_shows_only_that_dealers_eligible_inventory(): void
    {
        $ours = $this->cpoVehicle($this->dealer, ['model' => 'Outlander']);
        $theirs = $this->cpoVehicle($this->rival, ['model' => 'Eclipse Cross']);
        $campaign = $this->campaign($this->dealer);

        $this->get($campaign->trackableUrl())
            ->assertOk()
            ->assertSee('Outlander')
            ->assertSee(route('campaign.vehicle', ['token' => $campaign->token, 'vehicle' => $ours->id]), false)
            ->assertDontSee('Eclipse Cross')
            ->assertDontSee('/cars/' . $theirs->id, false);
    }

    public function test_campaign_search_never_leaves_the_dealer(): void
    {
        $this->cpoVehicle($this->dealer, ['model' => 'Outlander']);
        $this->cpoVehicle($this->rival, ['model' => 'Outlander Sport']);
        $campaign = $this->campaign($this->dealer);

        $this->get($campaign->shopUrl());

        Livewire::test('pages::campaign', ['token' => $campaign->token])
            ->set('search', 'Outlander')
            ->assertSee('Outlander')
            ->assertDontSee('Outlander Sport');
    }

    public function test_campaign_cannot_expose_another_dealers_vehicle_by_altering_the_id(): void
    {
        $this->cpoVehicle($this->dealer);
        $theirs = $this->cpoVehicle($this->rival);
        $campaign = $this->campaign($this->dealer);

        $this->get(route('campaign.vehicle', ['token' => $campaign->token, 'vehicle' => $theirs->id]))
            ->assertNotFound();
    }

    public function test_dealer_id_in_the_query_string_is_ignored(): void
    {
        $this->cpoVehicle($this->dealer, ['model' => 'Outlander']);
        $this->cpoVehicle($this->rival, ['model' => 'Eclipse Cross']);
        $campaign = $this->campaign($this->dealer);

        $this->get($campaign->shopUrl() . '?dealer_id=' . $this->rival->id . '&dealer=' . $this->rival->id)
            ->assertOk()
            ->assertSee('Outlander')
            ->assertDontSee('Eclipse Cross');
    }

    public function test_inactive_or_unknown_campaign_links_do_not_resolve(): void
    {
        $this->cpoVehicle($this->dealer);
        $draft = $this->campaign($this->dealer, ['status' => Campaign::STATUS_DRAFT]);
        $paused = $this->campaign($this->dealer, ['status' => Campaign::STATUS_PAUSED]);

        $this->get($draft->shopUrl())->assertNotFound();
        $this->get($paused->shopUrl())->assertNotFound();
        $this->get('/shop/zzzzzzzzzzzz')->assertNotFound();
    }

    public function test_unpublished_unavailable_and_ineligible_vehicles_are_excluded(): void
    {
        $this->cpoVehicle($this->dealer, ['model' => 'Eligible Outlander']);
        $this->cpoVehicle($this->dealer, ['model' => 'Unpublished RVR', 'is_published' => false]);
        $this->cpoVehicle($this->dealer, ['model' => 'Plain Used Mirage', 'is_manufacturer_certified' => false, 'is_certified' => true]);
        $this->cpoVehicle($this->dealer, ['model' => 'Brand New Outlander PHEV', 'condition' => 'NEW']);
        $this->cpoVehicle($this->dealer, ['make' => 'Volkswagen', 'model' => 'Off Brand Tiguan']);
        $held = $this->cpoVehicle($this->dealer, ['model' => 'Reserved Eclipse']);
        $this->deal($held);

        $campaign = $this->campaign($this->dealer);

        $this->get($campaign->shopUrl())
            ->assertOk()
            ->assertSee('Eligible Outlander')
            ->assertDontSee('Unpublished RVR')
            ->assertDontSee('Plain Used Mirage')
            ->assertDontSee('Brand New Outlander PHEV')
            ->assertDontSee('Off Brand Tiguan')
            ->assertDontSee('Reserved Eclipse');
    }

    public function test_dealer_without_confirmed_franchise_has_no_eligible_inventory(): void
    {
        $this->dealer->update(['franchise_make' => null]);
        $this->cpoVehicle($this->dealer, ['model' => 'Outlander']);
        $campaign = $this->campaign($this->dealer);

        $this->get($campaign->shopUrl())
            ->assertOk()
            ->assertDontSee('Outlander')
            ->assertSee('No certified vehicles right now');
    }

    public function test_normal_marketplace_browsing_remains_cross_dealer(): void
    {
        $this->cpoVehicle($this->dealer, ['model' => 'Outlander']);
        $this->cpoVehicle($this->rival, ['model' => 'Eclipse Cross']);
        $campaign = $this->campaign($this->dealer);

        $this->get('/shop')->assertOk()->assertSee('Outlander')->assertSee('Eclipse Cross');

        // Even after a campaign visit in the same session, the open floor isn't locked.
        $this->get($campaign->trackableUrl())->assertOk();
        $this->get('/shop')->assertOk()->assertSee('Outlander')->assertSee('Eclipse Cross');
    }

    public function test_a_google_referrer_alone_does_not_scope_the_marketplace(): void
    {
        $this->cpoVehicle($this->dealer, ['model' => 'Outlander', 'photos' => ['https://example.test/outlander.jpg']]);
        $this->cpoVehicle($this->rival, ['model' => 'Eclipse Cross', 'photos' => ['https://example.test/eclipse.jpg']]);
        $this->campaign($this->dealer, ['source' => 'google_ads']);

        $this->withHeader('Referer', 'https://www.google.com/')
            ->get('/?utm_source=google&utm_medium=cpc')
            ->assertOk()
            ->assertSee('Outlander')
            ->assertSee('Eclipse Cross');

        $this->assertNull(session('campaign_attribution'));
    }

    public function test_sold_campaign_vehicle_falls_back_only_to_the_same_dealers_inventory(): void
    {
        $target = $this->cpoVehicle($this->dealer, ['model' => 'Featured Outlander']);
        $this->cpoVehicle($this->dealer, ['model' => 'Sibling RVR']);
        $this->cpoVehicle($this->rival, ['model' => 'Rival Eclipse']);
        $campaign = $this->campaign($this->dealer, ['destination_type' => Campaign::DESTINATION_VEHICLE, 'vehicle_id' => $target->id]);

        // While available, the link opens straight on the featured car.
        $this->get($campaign->shopUrl())
            ->assertRedirect(route('campaign.vehicle', ['token' => $campaign->token, 'vehicle' => $target->id]));

        $this->deal($target, ['stage' => 'delivered']);

        $this->get($campaign->shopUrl())
            ->assertOk()
            ->assertSee('is no longer available')
            ->assertSee('Sibling RVR')
            ->assertDontSee('Rival Eclipse');

        // A saved deep link to the sold car lands in the same place.
        $this->get(route('campaign.vehicle', ['token' => $campaign->token, 'vehicle' => $target->id]))
            ->assertRedirect(route('campaign.shop', ['token' => $campaign->token]));
    }

    public function test_attribution_is_saved_on_resulting_deals_through_sign_in(): void
    {
        $vehicle = $this->cpoVehicle($this->dealer);
        $campaign = $this->campaign($this->dealer, ['source' => 'facebook_instagram']);
        $buyer = User::factory()->create(['email' => 'buyer@example.com']);

        // Land as a guest from a Facebook post, then hit reserve — which asks for sign-in.
        $this->get($campaign->shopUrl() . '?utm_source=facebook&utm_medium=social&utm_campaign=spring&utm_content=carousel-1')->assertOk();
        $this->get(route('campaign.vehicle', ['token' => $campaign->token, 'vehicle' => $vehicle->id]))->assertOk();

        Livewire::test('pages::buyer-login')
            ->set('email', 'buyer@example.com')
            ->set('password', 'password')
            ->call('signIn');

        $this->assertAuthenticatedAs($buyer);

        Livewire::test('pages::checkout', ['vehicle' => $vehicle])
            ->set('firstName', 'Sam')
            ->set('lastName', 'Rivera')
            ->set('phone', '6045550100')
            ->call('preparePayment')
            ->call('reserve');

        $deal = Deal::withoutGlobalScope(DealerScope::class)->sole();

        $this->assertSame($campaign->id, $deal->campaign_id);
        $this->assertSame('facebook_instagram', $deal->attribution['source']);
        $this->assertSame('facebook', $deal->attribution['utm_source']);
        $this->assertSame('social', $deal->attribution['utm_medium']);
        $this->assertSame('spring', $deal->attribution['utm_campaign']);
        $this->assertSame('carousel-1', $deal->attribution['utm_content']);
        $this->assertTrue($deal->activities()->where('body', 'like', '%campaign link%')->exists());
        $this->assertSame(1, $campaign->fresh()->visits_count);
    }

    public function test_attribution_is_saved_on_resulting_leads(): void
    {
        $vehicle = $this->cpoVehicle($this->dealer);
        $campaign = $this->campaign($this->dealer, ['source' => 'google_ads']);

        $this->get($campaign->shopUrl() . '?utm_source=google&utm_medium=cpc')->assertOk();

        $lead = Lead::create(['vehicle_id' => $vehicle->id, 'dealer_id' => $this->dealer->id, 'name' => 'Sam', 'email' => 'sam@example.com']);

        $this->assertSame($campaign->id, $lead->campaign_id);
        $this->assertSame('google_ads', $lead->attribution['source']);
        $this->assertSame('cpc', $lead->attribution['utm_medium']);
        $this->assertArrayNotHasKey('utm_content', $lead->attribution);
    }

    public function test_attribution_is_not_credited_to_a_different_dealers_purchase(): void
    {
        $this->cpoVehicle($this->dealer);
        $rivalCar = $this->cpoVehicle($this->rival);
        $campaign = $this->campaign($this->dealer);

        $this->get($campaign->shopUrl())->assertOk();

        $deal = $this->deal($rivalCar);

        $this->assertNull($deal->campaign_id);
        $this->assertNull($deal->attribution);
    }

    public function test_organic_reservations_carry_no_attribution(): void
    {
        $vehicle = $this->cpoVehicle($this->dealer);

        $this->get('/shop')->assertOk();

        $this->assertNull($this->deal($vehicle)->campaign_id);
    }

    /* ---- dealer console ---------------------------------------------- */

    public function test_dealer_can_create_and_activate_a_campaign_for_their_own_cpo_vehicle(): void
    {
        $vehicle = $this->cpoVehicle($this->dealer);
        $this->actingAs($this->staff($this->dealer));

        Livewire::test('pages::campaigns')
            ->call('startCreate')
            ->set('campaignName', 'Spring CPO event')
            ->set('campaignSource', 'google_business_profile')
            ->set('destinationType', Campaign::DESTINATION_VEHICLE)
            ->set('targetVehicleId', (string) $vehicle->id)
            ->call('saveCampaign', 'activate')
            ->assertHasNoErrors();

        $campaign = Campaign::sole();
        $this->assertSame($this->dealer->id, $campaign->dealer_id);
        $this->assertSame(Campaign::STATUS_ACTIVE, $campaign->status);
        $this->assertSame($vehicle->id, $campaign->vehicle_id);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{12}$/', $campaign->token);
        $this->assertStringContainsString('utm_source=google&utm_medium=business_profile&utm_campaign=spring-cpo-event', $campaign->trackableUrl());
    }

    public function test_dealer_cannot_target_another_dealers_or_ineligible_vehicle(): void
    {
        $theirs = $this->cpoVehicle($this->rival);
        $unpublished = $this->cpoVehicle($this->dealer, ['is_published' => false]);
        $this->actingAs($this->staff($this->dealer));

        foreach ([$theirs, $unpublished] as $vehicle) {
            Livewire::test('pages::campaigns')
                ->call('startCreate')
                ->set('campaignName', 'Sneaky')
                ->set('destinationType', Campaign::DESTINATION_VEHICLE)
                ->set('targetVehicleId', (string) $vehicle->id)
                ->call('saveCampaign', 'draft')
                ->assertHasErrors('targetVehicleId');
        }

        $this->assertSame(0, Campaign::withoutGlobalScope(DealerScope::class)->count());
    }

    public function test_dealer_cannot_manage_another_dealers_campaign(): void
    {
        $rivalCampaign = $this->campaign($this->rival, ['status' => Campaign::STATUS_PAUSED]);
        $this->actingAs($this->staff($this->dealer));

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test('pages::campaigns')->call('activateCampaign', $rivalCampaign->id);
    }

    public function test_campaign_source_must_be_supported(): void
    {
        $this->cpoVehicle($this->dealer);
        $this->actingAs($this->staff($this->dealer));

        Livewire::test('pages::campaigns')
            ->call('startCreate')
            ->set('campaignName', 'Bad source')
            ->set('campaignSource', 'tiktok_api')
            ->call('saveCampaign', 'draft')
            ->assertHasErrors('campaignSource');
    }

    public function test_activation_is_blocked_without_confirmed_franchise_or_eligible_stock(): void
    {
        $this->dealer->update(['franchise_make' => null]);
        $this->cpoVehicle($this->dealer);
        $this->actingAs($this->staff($this->dealer));

        Livewire::test('pages::campaigns')
            ->call('startCreate')
            ->set('campaignName', 'Too early')
            ->call('saveCampaign', 'activate')
            ->assertHasErrors('activation')
            ->call('saveCampaign', 'draft')
            ->assertHasNoErrors(['campaignName', 'campaignSource', 'destinationType', 'targetVehicleId']);

        $this->assertSame(Campaign::STATUS_DRAFT, Campaign::sole()->status);
    }

    public function test_dealer_can_pause_a_live_campaign(): void
    {
        $this->cpoVehicle($this->dealer);
        $campaign = $this->campaign($this->dealer);
        $this->actingAs($this->staff($this->dealer));

        Livewire::test('pages::campaigns')->call('pauseCampaign', $campaign->id);

        $this->assertSame(Campaign::STATUS_PAUSED, $campaign->fresh()->status);
    }

    /* ---- helpers ----------------------------------------------------- */

    private function cpoVehicle(Dealer $dealer, array $overrides = []): Vehicle
    {
        return $dealer->vehicles()->create($overrides + [
            'vin'                       => Str::upper(Str::random(17)),
            'model_year'                => (int) date('Y') - 2,
            'make'                      => 'Mitsubishi',
            'model'                     => 'Outlander',
            'kilometres'                => 30000,
            'price_in_cents'            => 3299000,
            'condition'                 => 'USED',
            'is_published'              => true,
            'is_certified'              => true,
            'is_manufacturer_certified' => true,
        ]);
    }

    private function campaign(Dealer $dealer, array $overrides = []): Campaign
    {
        return Campaign::create($overrides + [
            'dealer_id'        => $dealer->id,
            'name'             => 'Spring CPO event',
            'source'           => 'facebook_instagram',
            'destination_type' => Campaign::DESTINATION_CPO_INVENTORY,
            'status'           => Campaign::STATUS_ACTIVE,
        ]);
    }

    private function deal(Vehicle $vehicle, array $overrides = []): Deal
    {
        return Deal::create($overrides + [
            'dealer_id'  => $vehicle->dealer_id,
            'vehicle_id' => $vehicle->id,
            'first_name' => 'Pat',
            'last_name'  => 'Lee',
            'email'      => 'pat@example.com',
            'phone'      => '6045550199',
        ]);
    }

    private function staff(Dealer $dealer): User
    {
        return User::factory()->staffOf($dealer)->create();
    }
}

<?php

namespace Tests\Feature;

use App\Mail\FinancingApproved;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\LenderOffer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class LenderOffersTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->dealer = $this->makeDealer();
        $this->buyer = User::factory()->create(['email' => 'pat@example.com']);
    }

    public function test_a_revised_decision_from_dealertrack_updates_the_same_offer(): void
    {
        $deal = $this->deal();

        LenderOffer::recordDecision($deal, ['external_id' => 'DT-1', 'lender' => 'RBC', 'decision' => 'pending']);
        LenderOffer::recordDecision($deal, $this->approval('RBC', 'DT-1', 22938));

        $this->assertSame(1, $deal->lenderOffers()->count());
        $this->assertSame('approved', $deal->lenderOffers()->first()->decision);
        $this->assertSame(2, $deal->activities()->where('kind', 'lender')->count());
        $this->assertFalse($deal->fresh()->hasFinancingApproval(), 'Nothing reaches the buyer until it is published.');
        Mail::assertNotSent(FinancingApproved::class);
    }

    public function test_the_dealer_publishes_the_offer_they_pick_and_the_buyer_sees_only_that_one(): void
    {
        $deal = $this->deal();
        LenderOffer::recordDecision($deal, $this->approval('Scotiabank', 'DT-1', 22321));
        $td = LenderOffer::recordDecision($deal, $this->approval('TD Auto Finance', 'DT-2', 22628) + ['conditions' => 'Proof of income']);
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->set('tab', 'financing')
            ->assertSee('Scotiabank')
            ->assertSee('TD Auto Finance')
            ->set('selectedOfferId', $td->id)
            ->call('publishOffer')
            ->assertSee('The buyer sees these terms in My Garage.');

        $deal->refresh();
        $this->assertSame('TD Auto Finance', $deal->financing_lender);
        $this->assertSame(22628, $deal->financing_payment_in_cents);
        $this->assertSame('Proof of income', $deal->financing_conditions);
        $this->assertSame([$td->id], $deal->lenderOffers()->whereNotNull('published_at')->pluck('id')->all());
        Mail::assertSent(FinancingApproved::class, 1);

        $this->actingAs($this->buyer)->get(route('garage'))
            ->assertSee('Approved by TD Auto Finance')
            ->assertSee('Proof of income')
            ->assertDontSee('Scotiabank');
    }

    public function test_a_pending_or_declined_offer_cannot_be_published(): void
    {
        $deal = $this->deal();
        $pending = LenderOffer::recordDecision($deal, ['external_id' => 'DT-9', 'lender' => 'BMO', 'decision' => 'pending']);
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->set('selectedOfferId', $pending->id)
            ->call('publishOffer');

        $this->assertFalse($deal->fresh()->hasFinancingApproval());
        Mail::assertNotSent(FinancingApproved::class);
    }

    public function test_another_deals_offer_cannot_be_published_here(): void
    {
        $deal = $this->deal();
        $elsewhere = LenderOffer::recordDecision($this->deal(), $this->approval('Scotiabank', 'DT-1', 22321));
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->set('selectedOfferId', $elsewhere->id)
            ->call('publishOffer');

        $this->assertNull($deal->fresh()->financing_lender);
    }

    public function test_the_dealer_is_told_when_the_lender_changes_a_published_offer(): void
    {
        $deal = $this->deal();
        $offer = LenderOffer::recordDecision($deal, $this->approval('Scotiabank', 'DT-1', 22321));
        $deal->publishLenderOffer($offer);
        LenderOffer::recordDecision($deal, $this->approval('Scotiabank', 'DT-1', 23010));
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        Livewire::test('pages::deal-detail', ['deal' => $deal->fresh()])
            ->set('tab', 'financing')
            ->assertSee('Lender changed the terms since you published')
            ->call('publishOffer');

        $this->assertSame(23010, $deal->fresh()->financing_payment_in_cents);
        Mail::assertSent(FinancingApproved::class, fn ($mail) => $mail->updated);
    }

    public function test_the_overview_points_the_dealer_at_offers_waiting_to_be_published(): void
    {
        $deal = $this->deal();
        LenderOffer::recordDecision($deal, $this->approval('Scotiabank', 'DT-1', 22321));
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->assertSee('Choose a lender offer')
            ->assertSee('1 offer to review');
    }

    private function approval(string $lender, string $externalId, int $paymentInCents): array
    {
        return [
            'external_id' => $externalId, 'lender' => $lender, 'decision' => 'approved', 'apr' => '7.49',
            'term_months' => 72, 'payment_in_cents' => $paymentInCents, 'frequency' => 'biweekly', 'amount_in_cents' => 2800000,
        ];
    }

    private function deal(): Deal
    {
        $vehicle = $this->dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'RVR',
            'kilometres' => 30000, 'price_in_cents' => 2360000, 'condition' => 'USED', 'is_published' => true,
        ]);

        return Deal::create([
            'dealer_id' => $this->dealer->id, 'vehicle_id' => $vehicle->id, 'user_id' => $this->buyer->id,
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'phone' => '6045550199',
            'purchase_type' => 'finance', 'term_months' => 72, 'identity_verified_at' => now(), 'stage' => 'financing',
        ]);
    }
}

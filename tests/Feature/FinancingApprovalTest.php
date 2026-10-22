<?php

namespace Tests\Feature;

use App\Mail\FinancingApproved;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class FinancingApprovalTest extends TestCase
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

    public function test_the_dealer_enters_the_bank_approval_and_the_buyer_sees_it_in_their_garage(): void
    {
        $deal = $this->deal();

        $this->actingAs($this->buyer)->get(route('garage'))
            ->assertSee('Estimated payment')
            ->assertDontSee('Approved by');

        $this->approve($deal);

        $deal->refresh();
        $this->assertSame('Scotiabank', $deal->financing_lender);
        $this->assertSame(41250, $deal->financing_payment_in_cents);
        $this->assertSame(156, $deal->financing_payment_count);
        Mail::assertSent(FinancingApproved::class, fn ($mail) => $mail->hasTo('pat@example.com') && ! $mail->updated
            && str_contains($mail->render(), '$412.50 biweekly'));

        $this->actingAs($this->buyer)->get(route('garage'))
            ->assertSee('Approved by Scotiabank')
            ->assertSee('$412.50')
            ->assertSee('7.49% APR')
            ->assertSee('72 months · 156 payments')
            ->assertSee('$28,500.00')
            ->assertSee('Dec 5, 2026')
            ->assertDontSee('Estimated payment');
    }

    public function test_changing_the_terms_emails_the_buyer_the_update(): void
    {
        $deal = $this->deal();
        $this->approve($deal);

        Livewire::test('pages::deal-detail', ['deal' => $deal->fresh()])
            ->call('editFinancing')
            ->assertSet('financingPayment', '412.5')
            ->set('financingApr', '6.99')
            ->call('saveFinancing')
            ->assertHasNoErrors();

        $this->assertSame('6.99', $deal->fresh()->financing_apr);
        Mail::assertSent(FinancingApproved::class, 2);
        Mail::assertSent(FinancingApproved::class, fn ($mail) => $mail->updated);
    }

    public function test_the_approval_needs_the_core_terms(): void
    {
        $deal = $this->deal();
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->set('financingLender', '')
            ->set('financingPayment', '')
            ->call('saveFinancing')
            ->assertHasErrors(['financingLender', 'financingPayment', 'financingApr', 'financingAmount']);

        $this->assertFalse($deal->fresh()->hasFinancingApproval());
        Mail::assertNotSent(FinancingApproved::class);
    }

    public function test_cash_deals_have_no_approval_to_enter(): void
    {
        $deal = $this->deal(['purchase_type' => 'cash']);
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->assertSee("no bank approval to enter")
            ->set('financingLender', 'Scotiabank')
            ->call('saveFinancing');

        $this->assertNull($deal->fresh()->financing_lender);
    }

    private function approve(Deal $deal): void
    {
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());

        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->assertSee('Save approval and email the buyer')
            ->set('financingLender', 'Scotiabank')
            ->set('financingPayment', '412.50')
            ->set('financingFrequency', 'biweekly')
            ->set('financingApr', '7.49')
            ->set('financingTermMonths', '72')
            ->set('financingAmount', '28500')
            ->set('financingFirstPaymentOn', '2026-12-05')
            ->call('saveFinancing')
            ->assertHasNoErrors()
            ->assertSee('The buyer sees these terms in My Garage.');
    }

    private function deal(array $overrides = []): Deal
    {
        $vehicle = $this->dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);

        return Deal::create($overrides + [
            'dealer_id' => $this->dealer->id, 'vehicle_id' => $vehicle->id, 'user_id' => $this->buyer->id,
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'phone' => '6045550199',
            'purchase_type' => 'finance', 'term_months' => 72, 'identity_verified_at' => now(), 'stage' => 'financing',
        ]);
    }
}

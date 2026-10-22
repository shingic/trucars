<?php

namespace Tests\Feature;

use App\Mail\DocumentsReminder;
use App\Mail\MoreInfoRequested;
use App\Mail\ReservationCancelled;
use App\Models\Deal;
use App\Models\Dealer;
use App\Models\DepositPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class DealerDealActionsTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Dealer $dealer;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('deal_documents');
        $this->dealer = $this->makeDealer();
        $this->buyer = User::factory()->create(['email' => 'pat@example.com']);
        $this->actingAs(User::factory()->staffOf($this->dealer)->create());
    }

    public function test_the_dealer_can_ask_for_an_extra_document_and_the_buyer_can_upload_it(): void
    {
        $deal = $this->deal();

        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->call('startAsking', 'bank_statement')
            ->assertSet('askName', 'Bank statement')
            ->set('askNote', 'From the account your pay goes into.')
            ->call('requestMore')
            ->assertHasNoErrors()
            ->assertSee('Bank statement');

        $extra = $deal->documents()->where('name', 'Bank statement')->sole();
        $this->assertNotNull($extra->requested_at);
        $this->assertSame('From the account your pay goes into.', $extra->status);
        Mail::assertSent(MoreInfoRequested::class, fn ($mail) => $mail->hasTo('pat@example.com')
            && str_contains($mail->render(), 'From the account your pay goes into.'));

        Livewire::actingAs($this->buyer)->test('pages::garage')
            ->assertSee('Bank statement')
            ->set("pendingFiles.{$extra->id}", UploadedFile::fake()->create('statement.pdf', 100, 'application/pdf'));
        $this->assertNotNull($extra->fresh()->file_path);
    }

    public function test_a_custom_request_needs_a_name_and_still_gets_reminded(): void
    {
        $deal = $this->deal();
        $deal->documents()->update(['is_done' => true]);

        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->call('startAsking', 'other')
            ->call('requestMore')
            ->assertHasErrors('askName')
            ->set('askName', 'Co-signer’s licence')
            ->call('requestMore')
            ->assertHasNoErrors();

        $this->travel(3)->days();
        $this->artisan('deals:remind-documents');
        Mail::assertSent(DocumentsReminder::class, fn ($mail) => $mail->documents->pluck('name')->all() === ['Co-signer’s licence']);
    }

    public function test_an_unanswered_extra_request_can_be_withdrawn_but_standard_ones_cannot(): void
    {
        $deal = $this->deal();
        $component = Livewire::test('pages::deal-detail', ['deal' => $deal])->call('startAsking', 'proof_of_address')->call('requestMore');
        $extra = $deal->documents()->where('name', 'Proof of address')->sole();
        $stubs = $deal->documents()->where('slug', 'proof_of_income')->sole();

        $component->call('withdrawRequest', $stubs->id)->call('withdrawRequest', $extra->id);

        $this->assertModelExists($stubs);
        $this->assertModelMissing($extra);
    }

    public function test_declining_refunds_the_deposit_and_tells_the_buyer(): void
    {
        $deal = $this->deal();
        $this->paidDeposit($deal);

        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->call('startDecline')
            ->call('declineDeal')
            ->assertHasErrors('declineReason')
            ->set('declineReason', 'No lender approved it')
            ->set('declineNote', 'Feel free to try again with a co-signer.')
            ->call('declineDeal')
            ->assertHasNoErrors()
            ->assertSee('This reservation is off.');

        $deal->refresh();
        $this->assertSame('cancelled', $deal->stage);
        $this->assertSame('refunded', $deal->deposit_status);
        $this->assertSame(['pi_test_1'], $this->stripe->refunds);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.declined', 'target_id' => $deal->id]);
        Mail::assertSent(ReservationCancelled::class, function ($mail) {
            $html = $mail->render();

            return $mail->hasTo('pat@example.com') && $mail->byDealer && $mail->refunded
                && str_contains($html, 'Feel free to try again with a co-signer.')
                && ! str_contains($html, 'No lender approved it');
        });
    }

    public function test_a_failed_refund_leaves_the_reservation_alone(): void
    {
        $deal = $this->deal();
        $this->paidDeposit($deal);
        $this->stripe->refundFails = true;

        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->set('declining', true)
            ->set('declineReason', 'No lender approved it')
            ->call('declineDeal')
            ->assertHasErrors('declineReason');

        $this->assertSame('financing', $deal->fresh()->stage);
        $this->assertSame('held', $deal->fresh()->deposit_status);
        Mail::assertNotSent(ReservationCancelled::class);
    }

    public function test_a_dealer_cannot_decline_another_dealers_reservation(): void
    {
        $other = $this->makeDealer(['name' => 'Valley Nissan']);
        $theirs = $this->deal([], $other);

        $this->get(route('dealer.deal', $theirs->id))->assertNotFound();
    }

    private function paidDeposit(Deal $deal): void
    {
        $payment = DepositPayment::create(['user_id' => $this->buyer->id, 'vehicle_id' => $deal->vehicle_id, 'stripe_payment_intent_id' => 'pi_test_1', 'amount_in_cents' => 15000, 'currency' => 'cad']);
        $payment->forceFill(['status' => DepositPayment::STATUS_PAID, 'paid_at' => now(), 'deal_id' => $deal->id])->save();
    }

    private function deal(array $overrides = [], ?Dealer $dealer = null): Deal
    {
        $dealer ??= $this->dealer;
        $vehicle = $dealer->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2022, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 2999000, 'condition' => 'USED', 'is_published' => true,
        ]);

        return Deal::withoutGlobalScopes()->create($overrides + [
            'dealer_id' => $dealer->id, 'vehicle_id' => $vehicle->id, 'user_id' => $this->buyer->id,
            'first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'phone' => '6045550199',
            'purchase_type' => 'finance', 'identity_verified_at' => now(), 'stage' => 'financing',
        ]);
    }
}

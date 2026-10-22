<?php

namespace Tests\Feature;

use App\Mail\DocumentReuploadRequested;
use App\Mail\DocumentsReminder;
use App\Mail\InsuranceRequested;
use App\Mail\ReadyForHandover;
use App\Models\Deal;
use App\Models\DealDocument;
use App\Models\Dealer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class DealDocumentStagesTest extends TestCase
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
    }

    public function test_a_financed_deal_asks_for_pay_stubs_and_a_void_cheque_now_and_insurance_later(): void
    {
        $deal = $this->deal();

        $this->assertEqualsCanonicalizing(['driver_licence', 'proof_of_income', 'void_cheque'], $deal->documents()->whereNotNull('requested_at')->pluck('slug')->all());
        $this->assertNull($this->insurance($deal)->requested_at);
        $this->assertSame('Recent pay stubs', $deal->documents()->where('slug', 'proof_of_income')->value('name'));
    }

    public function test_a_cash_deal_skips_the_lender_documents(): void
    {
        $deal = $this->deal(['purchase_type' => 'cash']);

        $this->assertEqualsCanonicalizing(['driver_licence', DealDocument::INSURANCE], $deal->documents()->pluck('slug')->all());
    }

    public function test_insurance_cannot_be_uploaded_before_the_deal_is_finalized(): void
    {
        $deal = $this->deal();
        $insurance = $this->insurance($deal);

        $this->actingAs($this->buyer)->get(route('garage'))->assertOk()->assertSee('Later');

        Livewire::actingAs($this->buyer)->test('pages::garage')
            ->set("pendingFiles.{$insurance->id}", UploadedFile::fake()->create('pink-slip.pdf', 100, 'application/pdf'));

        $this->assertNull($insurance->fresh()->file_path);
    }

    public function test_finalizing_the_deal_asks_the_buyer_for_insurance(): void
    {
        $deal = $this->deal(['stage' => 'financing']);

        $this->actingAs(User::factory()->staffOf($this->dealer)->create());
        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->assertSee('Emails the buyer for proof of insurance')
            ->call('advanceStage');

        $this->assertNotNull($this->insurance($deal)->requested_at);
        Mail::assertSent(InsuranceRequested::class, fn ($mail) => $mail->hasTo('pat@example.com'));
        $this->assertDatabaseHas('deal_activities', ['deal_id' => $deal->id, 'kind' => 'email']);

        // Once asked, the buyer can upload it.
        Livewire::actingAs($this->buyer)->test('pages::garage')
            ->set("pendingFiles.{$this->insurance($deal)->id}", UploadedFile::fake()->create('pink-slip.pdf', 100, 'application/pdf'));
        $this->assertNotNull($this->insurance($deal)->file_path);
    }

    public function test_marking_the_car_ready_tells_the_buyer_what_is_still_missing(): void
    {
        $deal = $this->deal(['stage' => 'documents']);
        $deal->requestInsurance();

        $deal->update(['stage' => 'ready_for_delivery']);

        Mail::assertSent(ReadyForHandover::class, fn ($mail) => $mail->hasTo('pat@example.com')
            && $mail->documents->pluck('slug')->contains(DealDocument::INSURANCE));
        $this->assertStringContainsString('Proof of insurance', (new ReadyForHandover($deal))->render());
    }

    public function test_sending_a_document_back_emails_the_buyer_the_reason(): void
    {
        $deal = $this->deal();
        $stubs = $deal->documents()->where('slug', 'proof_of_income')->first();
        $stubs->update(['file_path' => 'deal-1/stubs.pdf', 'is_done' => true, 'status' => 'Uploaded']);

        $this->actingAs(User::factory()->staffOf($this->dealer)->create());
        Livewire::test('pages::deal-detail', ['deal' => $deal])
            ->call('startRejection', $stubs->id)
            ->set('rejectionReason', 'The second page is cut off.')
            ->call('requestReupload', $stubs->id);

        Mail::assertSent(DocumentReuploadRequested::class, fn ($mail) => $mail->hasTo('pat@example.com')
            && str_contains($mail->render(), 'The second page is cut off.'));
    }

    public function test_buyers_get_one_reminder_per_request(): void
    {
        $deal = $this->deal();
        $deal->documents()->whereNotNull('requested_at')->update(['requested_at' => now()->subDays(3)]);
        $deal->documents()->where('slug', 'void_cheque')->update(['is_done' => true]);

        $this->artisan('deals:remind-documents')->assertSuccessful();
        $this->artisan('deals:remind-documents')->assertSuccessful();

        Mail::assertSent(DocumentsReminder::class, 1);
        Mail::assertSent(DocumentsReminder::class, fn ($mail) => $mail->documents->pluck('slug')->all() === ['proof_of_income']);

        // A fresh request (insurance) earns its own reminder two days later.
        $this->travel(1)->days();
        $deal->update(['stage' => 'documents']);
        $this->artisan('deals:remind-documents');
        Mail::assertSent(DocumentsReminder::class, 1);

        $this->travel(3)->days();
        $this->artisan('deals:remind-documents');
        Mail::assertSent(DocumentsReminder::class, 2);
    }

    public function test_cancelled_and_fresh_deals_are_not_reminded(): void
    {
        $this->deal(['stage' => 'cancelled'])->documents()->update(['requested_at' => now()->subDays(3)]);
        $this->deal();

        $this->artisan('deals:remind-documents');

        Mail::assertNotSent(DocumentsReminder::class);
    }

    public function test_every_email_renders(): void
    {
        $deal = $this->deal(['stage' => 'documents']);
        $insurance = $this->insurance($deal);

        foreach ([new DocumentsReminder($deal), new InsuranceRequested($deal), new ReadyForHandover($deal), new DocumentReuploadRequested($deal, $insurance)] as $mail) {
            $this->assertStringContainsString($deal->reference, $mail->render());
        }
        $this->assertStringContainsString($deal->vehicle->vin, (new InsuranceRequested($deal))->render());
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
            'purchase_type' => 'finance', 'identity_verified_at' => now(),
        ]);
    }

    private function insurance(Deal $deal): DealDocument
    {
        return $deal->documents()->where('slug', DealDocument::INSURANCE)->first();
    }
}

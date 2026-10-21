<?php

namespace Tests\Feature;

use App\Mail\ReservationConfirmed;
use App\Models\Deal;
use App\Models\DepositPayment;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\CreatesDealers;
use Tests\TestCase;

class DepositPaymentTest extends TestCase
{
    use CreatesDealers;
    use RefreshDatabase;

    private Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->car = $this->makeDealer()->vehicles()->create([
            'vin' => Str::upper(Str::random(17)), 'model_year' => 2023, 'make' => 'Mitsubishi', 'model' => 'Outlander',
            'kilometres' => 30000, 'price_in_cents' => 3999500, 'condition' => 'USED', 'is_published' => true,
        ]);
    }

    public function test_reaching_reserve_sets_up_a_150_dollar_stripe_payment(): void
    {
        $this->actingAs(User::factory()->create());

        $checkout = $this->checkout()->call('preparePayment')->assertDispatched('deposit-ready');

        $payment = DepositPayment::sole();
        $intent = $this->stripe->intents[$payment->stripe_payment_intent_id];
        $this->assertSame(15000, $intent['amount']);
        $this->assertSame('cad', $intent['currency']);
        $this->assertSame((string) $payment->id, $intent['metadata']['deposit_payment_id']);
        $this->assertSame($intent['client_secret'], $checkout->get('paymentClientSecret'));

        // Going back and returning reuses the same payment, not a second charge.
        $this->stripe->nextStatus = 'requires_payment_method';
        $checkout->call('preparePayment');
        $this->assertSame(1, DepositPayment::count());
    }

    public function test_a_paid_deposit_becomes_the_reservation(): void
    {
        $this->actingAs(User::factory()->create());

        $this->checkout()->call('preparePayment')->call('reserve')->assertSet('paymentError', '');

        $deal = Deal::withoutGlobalScopes()->sole();
        $payment = DepositPayment::sole();
        $this->assertSame('held', $deal->deposit_status);
        $this->assertSame(15000, $deal->deposit_in_cents);
        $this->assertSame($deal->id, $payment->deal_id);
        $this->assertSame(DepositPayment::STATUS_PAID, $payment->status);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_no_reservation_without_a_successful_payment(): void
    {
        $this->actingAs(User::factory()->create());
        $this->stripe->nextStatus = 'requires_payment_method';

        $this->checkout()->call('preparePayment')->call('reserve')
            ->assertSet('paymentError', 'Your payment hasn’t gone through. Please check your card details and try again.');

        $this->assertSame(0, Deal::withoutGlobalScopes()->count());

        // Skipping the payment entirely gets nowhere either.
        $this->checkout()->call('reserve');
        $this->assertSame(0, Deal::withoutGlobalScopes()->count());
    }

    public function test_reserving_twice_with_one_payment_makes_one_reservation(): void
    {
        $this->actingAs(User::factory()->create());

        $checkout = $this->checkout()->call('preparePayment')->call('reserve');
        Mail::assertSent(ReservationConfirmed::class, 1);
        $emails = Mail::sent(ReservationConfirmed::class)->count();

        $checkout->call('reserve')->assertSet('stepIndex', count($checkout->get('steps')) - 1);

        $this->assertSame(1, Deal::withoutGlobalScopes()->count());
        $this->assertSame($emails, Mail::sent(ReservationConfirmed::class)->count(), 'no second round of emails');
    }

    public function test_a_car_already_reserved_cant_be_paid_for(): void
    {
        $this->reserveAs(User::factory()->create());
        $this->actingAs(User::factory()->create());

        $this->checkout()->call('preparePayment')
            ->assertSet('paymentClientSecret', null)
            ->assertSet('paymentError', 'Sorry — this car was just reserved by someone else. You haven’t been charged.');

        $this->assertSame(1, DepositPayment::count());
    }

    public function test_paying_as_someone_else_reserves_first_refunds_straight_away(): void
    {
        $this->actingAs($second = User::factory()->create());
        $checkout = $this->checkout()->call('preparePayment');

        $this->reserveAs(User::factory()->create());

        $this->actingAs($second);
        $checkout->call('reserve');
        $this->assertStringContainsString('Your $150 has been refunded to your card', $checkout->get('paymentError'));

        $this->assertSame(1, Deal::withoutGlobalScopes()->count());
        $late = DepositPayment::where('user_id', $second->id)->sole();
        $this->assertSame(DepositPayment::STATUS_REFUNDED, $late->status);
        $this->assertSame([$late->stripe_payment_intent_id], $this->stripe->refunds);
    }

    public function test_no_stripe_keys_means_no_payment_and_no_reservation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->stripe->configured = false;

        $this->checkout()->call('preparePayment')->assertSet('paymentClientSecret', null)->call('reserve');

        $this->assertSame(0, DepositPayment::count());
        $this->assertSame(0, Deal::withoutGlobalScopes()->count());
    }

    public function test_reconcile_refunds_payments_that_never_became_reservations(): void
    {
        $this->actingAs(User::factory()->create());
        $this->checkout()->call('preparePayment'); // paid in the browser, tab closed before reserve()

        $this->actingAs(User::factory()->create());
        $this->stripe->nextStatus = 'requires_payment_method';
        $this->checkout()->call('preparePayment'); // never paid

        $this->travel(31)->minutes();
        $this->stripe->nextStatus = 'succeeded';
        $orphan = DepositPayment::oldest('id')->first();
        $this->stripe->intents[DepositPayment::latest('id')->first()->stripe_payment_intent_id]['status'] = 'requires_payment_method';
        $this->stripe->nextStatus = 'requires_payment_method';
        $this->stripe->intents[$orphan->stripe_payment_intent_id]['status'] = 'succeeded';

        $this->artisan('deposits:reconcile')->assertSuccessful();

        $this->assertSame(DepositPayment::STATUS_REFUNDED, $orphan->fresh()->status);
        $this->assertSame(DepositPayment::STATUS_CANCELED, DepositPayment::latest('id')->first()->status);
        $this->assertSame([$orphan->stripe_payment_intent_id], $this->stripe->refunds);
    }

    public function test_command_centre_cancels_and_refunds(): void
    {
        $deal = $this->reserveAs(User::factory()->create());

        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [Permission::RESERVATIONS_VIEW]]));
        Livewire::test('pages::admin-reservation', ['deal' => $deal->id])
            ->assertDontSee('Cancel &amp; refund', false)
            ->set('cancelReason', 'Customer changed their mind')
            ->call('cancelAndRefund')
            ->assertForbidden();

        $this->actingAs(User::factory()->owner()->create());
        Livewire::test('pages::admin-reservation', ['deal' => $deal->id])
            ->call('cancelAndRefund')->assertHasErrors('cancelReason')
            ->set('cancelReason', 'Customer changed their mind')
            ->call('cancelAndRefund')
            ->assertHasNoErrors();

        $deal->refresh();
        $this->assertSame('cancelled', $deal->stage);
        $this->assertSame('refunded', $deal->deposit_status);
        $this->assertSame(DepositPayment::STATUS_REFUNDED, $deal->depositPayment->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'reservation.cancelled', 'target_id' => $deal->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'deposit.refunded']);

        // The car is free again.
        $this->actingAs(User::factory()->create());
        $this->checkout()->call('preparePayment')->assertSet('paymentError', '');
    }

    public function test_the_webhook_only_accepts_signed_events_and_records_dashboard_refunds(): void
    {
        config(['services.stripe.webhook_secret' => 'whsec_test']);
        $deal = $this->reserveAs(User::factory()->create());
        $intent = $deal->depositPayment->stripe_payment_intent_id;

        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'charge.refunded', 'data' => ['object' => [
            'id' => 'ch_1', 'object' => 'charge', 'payment_intent' => $intent, 'refunded' => true,
            'refunds' => ['object' => 'list', 'data' => [['id' => 're_dash', 'object' => 'refund']]],
        ]]]);

        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=bad', 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(400);
        $this->assertSame('held', $deal->fresh()->deposit_status);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

        $this->assertSame('refunded', $deal->fresh()->deposit_status);
        $this->assertSame('re_dash', $deal->depositPayment->fresh()->stripe_refund_id);
        $this->assertNotSame('', \App\Support\PlatformSettings::get('stripe_webhook_last_seen'), 'shown as proof the webhook works');
    }

    public function test_demo_mode_reserves_without_touching_stripe(): void
    {
        \App\Support\PlatformSettings::put('payments_mode', 'demo', null);
        $this->stripe->configured = false;
        $this->actingAs(User::factory()->create());

        $this->checkout()->call('preparePayment')->assertSet('paymentDemo', true)->assertNotDispatched('deposit-ready')->call('reserve');

        $deal = Deal::withoutGlobalScopes()->sole();
        $this->assertTrue($deal->depositPayment->isDemo());
        $this->assertSame([], $this->stripe->intents);
        $this->assertDatabaseHas('deal_activities', ['deal_id' => $deal->id, 'body' => 'Demo mode — $150 deposit simulated. No card was charged.']);

        // Cancelling a demo reservation "refunds" without calling Stripe.
        $this->actingAs(User::factory()->owner()->create());
        Livewire::test('pages::admin-reservation', ['deal' => $deal->id])->set('cancelReason', 'Demo run finished')->call('cancelAndRefund');
        $this->assertSame('refunded', $deal->fresh()->deposit_status);
        $this->assertSame([], $this->stripe->refunds);
    }

    public function test_switching_payments_mode(): void
    {
        $this->actingAs(User::factory()->owner()->create());
        $this->stripe->configured = false;
        \App\Support\PlatformSettings::put('payments_mode', 'demo', null);

        Livewire::test('pages::admin-settings')->call('setPaymentsMode', 'live')->assertSee('Live payments need the Stripe keys');
        $this->assertSame('demo', \App\Support\PlatformSettings::get('payments_mode'));

        $this->stripe->configured = true;
        Livewire::test('pages::admin-settings')->call('setPaymentsMode', 'live');
        $this->assertSame('live', \App\Support\PlatformSettings::get('payments_mode'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_INTERNAL_STAFF, 'permissions' => [Permission::RESERVATIONS_VIEW]]));
        Livewire::test('pages::admin-settings')->assertForbidden();
    }

    public function test_the_connection_check_reports_each_part(): void
    {
        config(['services.stripe.key' => 'pk_test_abc', 'services.stripe.secret' => 'rk_live_abc', 'services.stripe.webhook_secret' => '']);
        $this->actingAs(User::factory()->owner()->create());

        Livewire::test('pages::admin-settings')->call('checkConnection')
            ->assertSee('Connected to Stripe (live mode).')
            ->assertSee('One key is test and the other is live')
            ->assertSee('Missing — add STRIPE_WEBHOOK_SECRET');

        config(['services.stripe.secret' => 'rk_test_abc', 'services.stripe.webhook_secret' => 'whsec_abc']);
        $this->stripe->connectionError = 'Stripe rejected the secret key — check STRIPE_SECRET was copied in full.';
        Livewire::test('pages::admin-settings')->call('checkConnection')
            ->assertSee('Stripe rejected the secret key')
            ->assertSee('No events received yet');
    }

    private function checkout()
    {
        return Livewire::test('pages::checkout', ['vehicle' => $this->car])
            ->set('firstName', 'Sam')->set('lastName', 'Rivera')->set('phone', '6045550100');
    }

    private function reserveAs(User $buyer): Deal
    {
        $this->actingAs($buyer);
        $this->checkout()->call('preparePayment')->call('reserve');

        return Deal::withoutGlobalScopes()->where('user_id', $buyer->id)->sole();
    }
}

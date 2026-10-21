<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\DepositPayment;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Payments\DepositGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The $150 reservation deposit, end to end:
 *
 *  1. prepare()  — the buyer reaches Reserve: a Stripe PaymentIntent is made
 *                  (or the open one reused) and its client secret goes to the
 *                  card form.
 *  2. The buyer pays in the browser (Stripe.js — card details never touch us).
 *  3. confirm()  — before any reservation is made, Stripe itself is asked
 *                  whether the payment succeeded, for the right amount.
 *  4. refund()   — cancelled reservations, a car taken by someone else first,
 *                  or a payment that never became a reservation (reconcile()).
 */
class Deposits
{
    public const AMOUNT_IN_CENTS = 15000;
    public const CURRENCY = 'cad';

    /** A paid deposit with no reservation after this long is refunded. */
    public const ORPHAN_AFTER_MINUTES = 30;

    public function __construct(private DepositGateway $gateway) {}

    /** Demo mode (Platform settings → Payments): the whole flow works, no money moves. */
    public function demo(): bool
    {
        return PlatformSettings::get(PlatformSettings::PAYMENTS_MODE) !== PlatformSettings::PAYMENTS_LIVE;
    }

    public function available(): bool
    {
        return $this->demo() || $this->gateway->isConfigured();
    }

    /** Live payments can be switched on — the Stripe keys are set. */
    public function liveReady(): bool
    {
        return $this->gateway->isConfigured();
    }

    public function publishableKey(): ?string
    {
        return $this->gateway->publishableKey();
    }

    /**
     * The open deposit for this buyer and car, or a new one.
     *
     * @return array{payment: DepositPayment, client_secret: string}
     */
    public function prepare(User $buyer, Vehicle $vehicle): array
    {
        if ($this->demo()) {
            $payment = DepositPayment::create([
                'user_id'                  => $buyer->id,
                'vehicle_id'               => $vehicle->id,
                'stripe_payment_intent_id' => 'demo_' . bin2hex(random_bytes(10)),
                'amount_in_cents'          => self::AMOUNT_IN_CENTS,
                'currency'                 => self::CURRENCY,
            ]);

            return ['payment' => $payment, 'client_secret' => 'demo'];
        }

        $payment = DepositPayment::query()
            ->where('user_id', $buyer->id)
            ->where('vehicle_id', $vehicle->id)
            ->where('status', DepositPayment::STATUS_PENDING)
            ->whereNull('deal_id')
            ->where('created_at', '>=', now()->subMinutes(self::ORPHAN_AFTER_MINUTES))
            ->latest('id')
            ->first();

        // Coming back to Reserve reuses the open intent — one buyer, one car,
        // one charge. (The client secret isn't stored; Stripe returns it.)
        if ($payment !== null) {
            $intent = $this->gateway->retrieveIntent($payment->stripe_payment_intent_id);
            if (in_array($intent['status'], ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                return ['payment' => $payment, 'client_secret' => $intent['client_secret']];
            }
        }

        $payment = DepositPayment::create([
            'user_id'                  => $buyer->id,
            'vehicle_id'               => $vehicle->id,
            'stripe_payment_intent_id' => 'pending-' . bin2hex(random_bytes(8)),
            'amount_in_cents'          => self::AMOUNT_IN_CENTS,
            'currency'                 => self::CURRENCY,
        ]);

        try {
            $intent = $this->gateway->createIntent(
                self::AMOUNT_IN_CENTS,
                self::CURRENCY,
                "TruCars refundable reservation deposit — {$vehicle->model_year} {$vehicle->make} {$vehicle->model} (VIN {$vehicle->vin})",
                $buyer->email,
                ['deposit_payment_id' => (string) $payment->id, 'user_id' => (string) $buyer->id, 'vehicle_id' => (string) $vehicle->id],
                'deposit-' . $payment->id,
            );
        } catch (Throwable $e) {
            $payment->delete();
            throw $e;
        }

        $payment->update(['stripe_payment_intent_id' => $intent['id']]);

        return ['payment' => $payment, 'client_secret' => $intent['client_secret']];
    }

    /**
     * Ask Stripe — never the browser — whether this deposit was paid, in full,
     * for this buyer and car. Marks it paid when it was.
     */
    public function confirm(DepositPayment $payment): bool
    {
        if ($payment->status === DepositPayment::STATUS_PAID) {
            return true;
        }

        if ($payment->status !== DepositPayment::STATUS_PENDING) {
            return false;
        }

        if ($payment->isDemo()) {
            $this->markPaid($payment);

            return true;
        }

        $intent = $this->gateway->retrieveIntent($payment->stripe_payment_intent_id);

        $paid = $intent['status'] === 'succeeded'
            && $intent['amount'] === $payment->amount_in_cents
            && strtolower($intent['currency']) === $payment->currency
            && ($intent['metadata']['deposit_payment_id'] ?? null) === (string) $payment->id;

        if ($paid) {
            $this->markPaid($payment);
        }

        return $paid;
    }

    /** Stripe says it's paid (confirm() or the webhook). */
    public function markPaid(DepositPayment $payment): void
    {
        if ($payment->status === DepositPayment::STATUS_PENDING) {
            $payment->forceFill(['status' => DepositPayment::STATUS_PAID, 'paid_at' => now()])->save();
        }
    }

    /**
     * Give the $150 back. Safe to call twice — Stripe sees the same refund
     * request and we see an already-refunded row.
     */
    public function refund(DepositPayment $payment, string $reason): void
    {
        if ($payment->status === DepositPayment::STATUS_REFUNDED) {
            return;
        }

        $refundId = $payment->isDemo() ? null : $this->gateway->refund($payment->stripe_payment_intent_id, 'refund-' . $payment->id);
        $this->markRefunded($payment, $refundId, $reason);
    }

    /** Stripe says it's refunded (refund() or the webhook — including refunds made in the Stripe dashboard). */
    public function markRefunded(DepositPayment $payment, ?string $refundId, string $reason): void
    {
        DB::transaction(function () use ($payment, $refundId, $reason) {
            $payment = DepositPayment::lockForUpdate()->find($payment->id);
            if ($payment->status === DepositPayment::STATUS_REFUNDED) {
                return;
            }

            $payment->forceFill(['status' => DepositPayment::STATUS_REFUNDED, 'stripe_refund_id' => $refundId, 'refunded_at' => now()])->save();

            if ($deal = $payment->deal) {
                $deal->forceFill(['deposit_status' => 'refunded'])->save();
                $deal->recordActivity('system', '$' . number_format($payment->amount_in_cents / 100) . ' deposit refunded to the customer’s card — ' . $reason);
            }

            AuditLog::record('deposit.refunded', $payment->deal ?? $payment, null, ['deposit_payment_id' => $payment->id, 'amount_in_cents' => $payment->amount_in_cents, 'reason' => $reason]);
        });
    }

    /**
     * Payments that never became a reservation — the buyer paid and closed the
     * tab, or the reservation failed — are refunded; unpaid ones are closed.
     * Run on a schedule; also catches anything a missed webhook would have.
     */
    public function reconcile(): int
    {
        $handled = 0;

        DepositPayment::query()
            ->whereNull('deal_id')
            ->whereIn('status', [DepositPayment::STATUS_PENDING, DepositPayment::STATUS_PAID])
            ->where('created_at', '<', now()->subMinutes(self::ORPHAN_AFTER_MINUTES))
            ->where('stripe_payment_intent_id', 'like', 'pi_%')
            ->each(function (DepositPayment $payment) use (&$handled) {
                try {
                    $intent = $this->gateway->retrieveIntent($payment->stripe_payment_intent_id);

                    if ($intent['status'] === 'succeeded') {
                        $this->markPaid($payment);
                        $this->refund($payment, 'paid, but the reservation was never completed.');
                    } elseif ($intent['status'] === 'canceled') {
                        $payment->forceFill(['status' => DepositPayment::STATUS_CANCELED])->save();
                    } elseif ($intent['status'] !== 'processing') {
                        $this->gateway->cancelIntent($payment->stripe_payment_intent_id);
                        $payment->forceFill(['status' => DepositPayment::STATUS_CANCELED])->save();
                    }

                    $handled++;
                } catch (Throwable $e) {
                    Log::error('Deposit reconcile failed', ['deposit_payment_id' => $payment->id, 'error' => $e->getMessage()]);
                }
            });

        return $handled;
    }

    /**
     * Each piece of the Stripe setup, checked: key => [ok, message]. Calls
     * Stripe once to prove the secret key works.
     *
     * @return array<string, array{0: bool, 1: string}>
     */
    public function connectionReport(): array
    {
        $publishable = (string) config('services.stripe.key');
        $secret = (string) config('services.stripe.secret');
        $webhookSecret = (string) config('services.stripe.webhook_secret');
        $modeOf = fn (string $key) => str_contains($key, '_live_') ? 'live' : 'test';

        $report = [];

        $report['Publishable key'] = match (true) {
            $publishable === ''                  => [false, 'Missing — add STRIPE_KEY (starts with pk_).'],
            ! str_starts_with($publishable, 'pk_') => [false, 'This isn’t a publishable key — it should start with pk_.'],
            default                              => [true, 'Set (' . $modeOf($publishable) . ' mode).'],
        };

        if ($secret === '') {
            $report['Secret key'] = [false, 'Missing — add STRIPE_SECRET (your rk_ restricted key).'];
        } elseif (! str_starts_with($secret, 'rk_') && ! str_starts_with($secret, 'sk_')) {
            $report['Secret key'] = [false, 'This isn’t a secret key — it should start with rk_.'];
        } else {
            $error = $this->gateway->connectionError();
            $report['Secret key'] = $error === null ? [true, 'Connected to Stripe (' . $modeOf($secret) . ' mode).'] : [false, $error];
        }

        if ($publishable !== '' && $secret !== '' && $modeOf($publishable) !== $modeOf($secret)) {
            $report['Keys match'] = [false, 'One key is test and the other is live — both must be from the same mode.'];
        }

        $lastSeen = PlatformSettings::get(PlatformSettings::STRIPE_WEBHOOK_LAST_SEEN);
        $report['Webhook'] = match (true) {
            $webhookSecret === ''                      => [false, 'Missing — add STRIPE_WEBHOOK_SECRET (starts with whsec_).'],
            ! str_starts_with($webhookSecret, 'whsec_') => [false, 'This isn’t a signing secret — it should start with whsec_.'],
            $lastSeen === ''                           => [true, 'Signing secret set. No events received yet — they arrive after the first test payment.'],
            default                                    => [true, 'Working — last event received ' . \Illuminate\Support\Carbon::parse($lastSeen)->diffForHumans() . '.'],
        };

        return $report;
    }

    /** Someone else's live reservation already holds this car. */
    public static function vehicleIsTaken(Vehicle $vehicle): bool
    {
        return $vehicle->isReserved();
    }
}

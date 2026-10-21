<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\DepositPayment;
use App\Support\Deposits;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Stripe → TruCars. Keeps deposit rows in step with Stripe even when the
 * buyer's browser never reports back, and records refunds made straight in
 * the Stripe dashboard. Only signed events are accepted.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, Deposits $deposits): Response
    {
        $secret = (string) config('services.stripe.webhook_secret');
        abort_if($secret === '', 404);

        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response('Invalid signature', 400);
        }

        $object = $event->data->object;
        $intentId = match ($event->type) {
            'payment_intent.succeeded', 'payment_intent.canceled' => $object->id,
            'charge.refunded'                                     => $object->payment_intent,
            default                                               => null,
        };

        $payment = $intentId ? DepositPayment::firstWhere('stripe_payment_intent_id', $intentId) : null;

        if ($payment !== null) {
            match ($event->type) {
                'payment_intent.succeeded' => $deposits->markPaid($payment),
                'payment_intent.canceled'  => $payment->status === DepositPayment::STATUS_PENDING
                    ? $payment->forceFill(['status' => DepositPayment::STATUS_CANCELED])->save()
                    : null,
                // Only a full refund counts as the deposit being returned.
                'charge.refunded'          => $object->refunded
                    ? $deposits->markRefunded($payment, $object->refunds->data[0]->id ?? null, 'refunded in Stripe.')
                    : null,
            };
        }

        return response('ok');
    }
}

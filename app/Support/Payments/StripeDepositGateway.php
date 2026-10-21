<?php

declare(strict_types=1);

namespace App\Support\Payments;

use Stripe\StripeClient;

class StripeDepositGateway implements DepositGateway
{
    private ?StripeClient $client = null;

    public function isConfigured(): bool
    {
        return filled(config('services.stripe.key')) && filled(config('services.stripe.secret'));
    }

    public function publishableKey(): ?string
    {
        return config('services.stripe.key');
    }

    public function createIntent(int $amountInCents, string $currency, string $description, ?string $receiptEmail, array $metadata, string $idempotencyKey): array
    {
        $intent = $this->client()->paymentIntents->create(array_filter([
            'amount'               => $amountInCents,
            'currency'             => $currency,
            'description'          => $description,
            'receipt_email'        => $receiptEmail,
            'metadata'             => $metadata,
            // Cards, Apple Pay and Google Pay — all confirm on the page, so the
            // buyer never leaves checkout (and never loses what they entered).
            'payment_method_types' => ['card'],
        ]), ['idempotency_key' => $idempotencyKey]);

        return ['id' => $intent->id, 'client_secret' => $intent->client_secret];
    }

    public function retrieveIntent(string $intentId): array
    {
        $intent = $this->client()->paymentIntents->retrieve($intentId);

        return [
            'id'       => $intent->id,
            'status'   => $intent->status,
            'amount'   => (int) $intent->amount,
            'currency' => $intent->currency,
            'metadata' => $intent->metadata?->toArray() ?? [],
            'client_secret' => $intent->client_secret,
        ];
    }

    public function cancelIntent(string $intentId): void
    {
        $this->client()->paymentIntents->cancel($intentId);
    }

    public function connectionError(): ?string
    {
        try {
            // The cheapest call the restricted key is allowed to make.
            $this->client()->paymentIntents->all(['limit' => 1]);

            return null;
        } catch (\Stripe\Exception\AuthenticationException) {
            return 'Stripe rejected the secret key — check STRIPE_SECRET was copied in full.';
        } catch (\Stripe\Exception\PermissionException) {
            return 'The restricted key is missing a permission — set PaymentIntents and Refunds to Write.';
        } catch (\Throwable $e) {
            return 'Couldn’t reach Stripe: ' . $e->getMessage();
        }
    }

    public function refund(string $intentId, string $idempotencyKey): string
    {
        return $this->client()->refunds->create(['payment_intent' => $intentId], ['idempotency_key' => $idempotencyKey])->id;
    }

    private function client(): StripeClient
    {
        return $this->client ??= new StripeClient((string) config('services.stripe.secret'));
    }
}

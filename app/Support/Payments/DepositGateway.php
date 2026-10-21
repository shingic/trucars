<?php

declare(strict_types=1);

namespace App\Support\Payments;

/**
 * The card processor behind the reservation deposit. Stripe in production
 * (StripeDepositGateway); tests swap in a fake.
 */
interface DepositGateway
{
    /** Keys are set, so deposits can be taken. */
    public function isConfigured(): bool;

    /** The browser-side key for Stripe.js. */
    public function publishableKey(): ?string;

    /**
     * @param  array<string, string>  $metadata
     * @return array{id: string, client_secret: string}
     */
    public function createIntent(int $amountInCents, string $currency, string $description, ?string $receiptEmail, array $metadata, string $idempotencyKey): array;

    /** @return array{id: string, status: string, amount: int, currency: string, metadata: array<string, string>, client_secret: string} */
    public function retrieveIntent(string $intentId): array;

    public function cancelIntent(string $intentId): void;

    /** Try the secret key against the processor: null when it works, else what went wrong. */
    public function connectionError(): ?string;

    /** Refund the whole intent. Returns the refund id. */
    public function refund(string $intentId, string $idempotencyKey): string;
}

<?php

namespace Tests\Fakes;

use App\Support\Payments\DepositGateway;

/**
 * Stands in for Stripe in tests. Intents succeed by default — as if the
 * buyer's card went through in the browser; set $nextStatus to change that.
 */
class FakeDepositGateway implements DepositGateway
{
    public bool $configured = true;

    public string $nextStatus = 'succeeded';

    /** @var array<string, array{id: string, status: string, amount: int, currency: string, metadata: array<string, string>, client_secret: string}> */
    public array $intents = [];

    /** @var array<int, string> intent ids refunded */
    public array $refunds = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function publishableKey(): ?string
    {
        return 'pk_test_fake';
    }

    public function createIntent(int $amountInCents, string $currency, string $description, ?string $receiptEmail, array $metadata, string $idempotencyKey): array
    {
        $id = 'pi_fake_' . (count($this->intents) + 1);
        $this->intents[$id] = ['id' => $id, 'status' => 'requires_payment_method', 'amount' => $amountInCents, 'currency' => $currency, 'metadata' => $metadata, 'client_secret' => $id . '_secret'];

        return ['id' => $id, 'client_secret' => $id . '_secret'];
    }

    public function retrieveIntent(string $intentId): array
    {
        $intent = $this->intents[$intentId];

        return ['status' => $intent['status'] === 'requires_payment_method' ? $this->nextStatus : $intent['status']] + $intent;
    }

    public function cancelIntent(string $intentId): void
    {
        $this->intents[$intentId]['status'] = 'canceled';
    }

    public ?string $connectionError = null;

    public function connectionError(): ?string
    {
        return $this->connectionError;
    }

    /** Set to make the next refund fail the way a Stripe outage would. */
    public bool $refundFails = false;

    public function refund(string $intentId, string $idempotencyKey): string
    {
        if ($this->refundFails) {
            throw new \RuntimeException('Stripe is unreachable.');
        }

        $this->refunds[] = $intentId;

        return 're_fake_' . count($this->refunds);
    }
}

<?php

namespace App\Services\Valuation\Data;

/**
 * One row in the breakdown the customer sees: a label, a signed amount in cents,
 * and whether it's the anchoring base value (rendered differently in the UI).
 */
class ValuationLine
{
    public function __construct(
        public readonly string $label,
        public readonly int $amountInCents,
        public readonly bool $isBase = false,
    ) {
    }

    public function isPositive(): bool
    {
        return $this->amountInCents > 0;
    }

    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'amount_in_cents' => $this->amountInCents,
            'is_base' => $this->isBase,
        ];
    }
}

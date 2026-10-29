<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lender's decision on a reservation's credit application.
 *
 * Dealertrack sends decisions back on its own (source 'dealertrack', keyed by
 * its id so a revised decision updates the same row); the finance office can
 * type one in for a lender outside it (source 'manual'). Nothing here reaches
 * the buyer until the dealership publishes an offer (Deal::publishLenderOffer).
 *
 * Not dealer-scoped itself: always reached through a deal, which is.
 */
class LenderOffer extends Model
{
    public const SOURCE_DEALERTRACK = 'dealertrack';
    public const SOURCE_MANUAL = 'manual';

    public const APPROVED = 'approved';
    public const CONDITIONAL = 'conditional';
    public const PENDING = 'pending';
    public const DECLINED = 'declined';

    /** In the order the offers list shows them. */
    public const DECISIONS = [
        self::APPROVED    => 'Approved',
        self::CONDITIONAL => 'Conditional',
        self::PENDING     => 'Pending',
        self::DECLINED    => 'Declined',
    ];

    protected $fillable = [
        'source', 'external_id', 'lender', 'decision', 'apr', 'term_months', 'payment_in_cents', 'frequency',
        'amount_in_cents', 'down_payment_in_cents', 'conditions', 'decided_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'apr'          => 'decimal:2',
            'decided_at'   => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function getDecisionLabelAttribute(): string
    {
        return self::DECISIONS[$this->decision] ?? ucfirst($this->decision);
    }

    /** An approval (outright or with conditions) with the terms the buyer would be shown. */
    public function isPublishable(): bool
    {
        return in_array($this->decision, [self::APPROVED, self::CONDITIONAL], true)
            && $this->apr !== null && $this->term_months && $this->payment_in_cents && $this->amount_in_cents;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** Published, but the lender has since changed the terms: the buyer is looking at old numbers. */
    public function changedSincePublished(Deal $deal): bool
    {
        return $this->isPublished() && (
            $deal->financing_lender !== $this->lender
            || (string) $deal->financing_apr !== (string) $this->apr
            || (int) $deal->financing_term_months !== (int) $this->term_months
            || (int) $deal->financing_payment_in_cents !== (int) $this->payment_in_cents
            || (int) $deal->financing_amount_in_cents !== (int) $this->amount_in_cents
            || (string) $deal->financing_conditions !== (string) $this->conditions
        );
    }

    /** "$223.21 biweekly", or null before the lender sets a payment. */
    public function getPaymentLabelAttribute(): ?string
    {
        if (! $this->payment_in_cents) {
            return null;
        }

        return '$' . number_format($this->payment_in_cents / 100, 2) . ' ' . strtolower(Deal::PAYMENT_FREQUENCIES[$this->frequency ?? 'biweekly'] ?? '');
    }

    public function getAprLabelAttribute(): ?string
    {
        return $this->apr === null ? null : rtrim(rtrim(number_format((float) $this->apr, 2), '0'), '.') . '%';
    }

    /**
     * Record a lender's decision on a deal. A decision carrying the lender's own
     * id updates the row it sent before, so a pending decision that later comes
     * back approved stays one offer. Logged on the deal's activity.
     *
     * $decision keys: lender, decision, and optionally external_id, apr,
     * term_months, payment_in_cents, frequency, amount_in_cents,
     * down_payment_in_cents, conditions, decided_at.
     */
    public static function recordDecision(Deal $deal, array $decision, string $source = self::SOURCE_DEALERTRACK): self
    {
        $attributes = array_intersect_key($decision, array_flip([
            'lender', 'decision', 'apr', 'term_months', 'payment_in_cents', 'frequency',
            'amount_in_cents', 'down_payment_in_cents', 'conditions', 'decided_at',
        ])) + ['frequency' => 'biweekly', 'decided_at' => now()];

        if (! isset(self::DECISIONS[$attributes['decision'] ?? ''])) {
            throw new \InvalidArgumentException('Unknown lender decision: ' . ($attributes['decision'] ?? 'none'));
        }

        $externalId = $decision['external_id'] ?? null;
        $offer = $externalId !== null
            ? $deal->lenderOffers()->firstOrNew(['source' => $source, 'external_id' => $externalId])
            : $deal->lenderOffers()->make(['source' => $source]);

        $offer->fill($attributes)->save();

        $deal->recordActivity(
            'lender',
            ($source === self::SOURCE_DEALERTRACK ? 'Dealertrack: ' : '') . $offer->lender . ' — ' . strtolower($offer->decision_label)
                . ($offer->payment_label ? ', ' . $offer->payment_label . ' at ' . $offer->apr_label . ' over ' . $offer->term_months . ' months' : '') . '.',
            $source === self::SOURCE_DEALERTRACK ? 'Dealertrack' : null,
            'inbound',
        );

        return $offer;
    }
}

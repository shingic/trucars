<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A buyer's ID check — a Didit session, or a demo approval. See App\Support\IdentityChecks. */
class IdentityVerification extends Model
{
    public const PROVIDER_DIDIT = 'didit';
    public const PROVIDER_DEMO = 'demo';

    // Didit's own status strings (case-sensitive).
    public const NOT_STARTED = 'Not Started';
    public const IN_PROGRESS = 'In Progress';
    public const APPROVED = 'Approved';
    public const DECLINED = 'Declined';
    public const IN_REVIEW = 'In Review';
    public const RESUBMITTED = 'Resubmitted';
    public const EXPIRED = 'Expired';
    public const ABANDONED = 'Abandoned';
    public const KYC_EXPIRED = 'Kyc Expired';

    protected $fillable = [
        'user_id', 'provider', 'session_id', 'session_url', 'status',
        'first_name', 'last_name', 'date_of_birth', 'document_type', 'document_expires_on', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth'       => 'date',
            'document_expires_on' => 'date',
            'decided_at'          => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isDemo(): bool
    {
        return $this->provider === self::PROVIDER_DEMO;
    }

    /** Approved, on a document that hasn't expired since. */
    public function isApproved(): bool
    {
        return $this->status === self::APPROVED
            && ($this->document_expires_on === null || ! $this->document_expires_on->isPast());
    }

    /** A person at Didit is checking it; the buyer may reserve meanwhile. */
    public function isInReview(): bool
    {
        return $this->status === self::IN_REVIEW;
    }

    /** Good enough to reserve with. */
    public function passes(): bool
    {
        return $this->isApproved() || $this->isInReview();
    }

    /** Still with the buyer: not started, part way, or asked to redo a step. */
    public function isWaiting(): bool
    {
        return in_array($this->status, [self::NOT_STARTED, self::IN_PROGRESS, self::RESUBMITTED], true);
    }

    /** For staff: how this buyer was verified. */
    public function label(): string
    {
        return match (true) {
            $this->isDemo() && $this->isApproved() => 'Verified (demo)',
            $this->isApproved()                    => 'Verified',
            $this->isInReview()                    => 'Under review at Didit',
            default                                => $this->status,
        };
    }

    /** The name on the ID differs from the name given at checkout. */
    public function nameDiffersFrom(?string $firstName, ?string $lastName): bool
    {
        if ($this->isDemo() || $this->first_name === null || $this->last_name === null) {
            return false;
        }

        $normal = fn (?string $name) => preg_replace('/[^a-z]/', '', strtolower(\Illuminate\Support\Str::ascii((string) $name)));

        $idFirst = $normal($this->first_name);
        $givenFirst = $normal($firstName);

        // First names may be shortened either way ("Rob" / "Robert"); last names must match.
        return $normal($this->last_name) !== $normal($lastName)
            || ($givenFirst === '' || ! (str_starts_with($idFirst, $givenFirst) || str_starts_with($givenFirst, $idFirst)));
    }
}

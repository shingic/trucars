<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Invitation links: a 64-character random token whose SHA-256 is all the
 * table keeps. Usable once, until it expires or is revoked.
 */
trait HasOneTimeToken
{
    public const LIFETIME_DAYS = 7;

    /** @return array{0: string, 1: string} [plain token for the email, hash to store] */
    protected static function freshToken(): array
    {
        $plainToken = Str::random(64);

        return [$plainToken, hash('sha256', $plainToken)];
    }

    /** The open invitation a link points at, or null for unknown/used/revoked/expired. */
    public static function findUsable(string $plainToken): ?static
    {
        return static::query()->open()->where('token_hash', hash('sha256', $plainToken))->first();
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}

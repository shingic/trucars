<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOneTimeToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An owner's invitation to join one dealer's portal. The plain token is only
 * ever returned from issue() for the email; the table keeps its SHA-256.
 */
class DealerInvitation extends Model
{
    use HasOneTimeToken;

    protected $fillable = ['dealer_id', 'invited_by_user_id', 'name', 'email', 'token_hash', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at'  => 'datetime',
        ];
    }

    /**
     * Create an invitation and return it with its one-time plain token.
     * Any still-open invitation for the same email and dealer is revoked, so
     * only the newest link works.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Dealer $dealer, string $name, string $email, ?User $invitedBy): array
    {
        $email = Str::lower(trim($email));

        self::query()->where('dealer_id', $dealer->id)->where('email', $email)->open()->update(['revoked_at' => now()]);

        [$plainToken, $tokenHash] = self::freshToken();

        $invitation = self::create([
            'dealer_id'          => $dealer->id,
            'invited_by_user_id' => $invitedBy?->id,
            'name'               => trim($name),
            'email'              => $email,
            'token_hash'         => $tokenHash,
            'expires_at'         => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return [$invitation, $plainToken];
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }
}

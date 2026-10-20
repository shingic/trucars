<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasOneTimeToken;
use App\Support\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An invitation for someone to join the Command Centre as internal staff,
 * carrying the permissions they'll be granted. The account is created on
 * acceptance with the invitee's own password.
 */
class StaffInvitation extends Model
{
    use HasOneTimeToken;

    protected $fillable = ['invited_by_user_id', 'name', 'email', 'permissions', 'token_hash', 'expires_at'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'expires_at'  => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at'  => 'datetime',
        ];
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array{0: self, 1: string} the invitation and its one-time plain token
     */
    public static function issue(string $name, string $email, array $permissions, ?User $invitedBy): array
    {
        $email = Str::lower(trim($email));

        self::query()->where('email', $email)->open()->update(['revoked_at' => now()]);

        [$plainToken, $tokenHash] = self::freshToken();

        $invitation = self::create([
            'invited_by_user_id' => $invitedBy?->id,
            'name'               => trim($name),
            'email'              => $email,
            'permissions'        => Permission::normalize($permissions),
            'token_hash'         => $tokenHash,
            'expires_at'         => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return [$invitation, $plainToken];
    }
}

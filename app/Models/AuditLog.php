<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only record of sensitive actions: staff access changes, dealer status
 * and dealer-user management, and (when support screens exist) staff viewing or
 * acting on customer data. Rows are written with record() and never changed.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'before'     => 'array',
            'after'      => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit log entries are append-only.'));
    }

    /**
     * Record an action by the signed-in user (or the console/system when no one
     * is signed in) against $target. Pass only the fields that matter to the
     * action — never passwords, tokens or other secrets.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(string $action, ?Model $target = null, ?array $before = null, ?array $after = null, ?User $actor = null): self
    {
        $actor ??= auth()->user();
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        return self::create([
            'actor_user_id' => $actor?->id,
            'actor_email'   => $actor?->email,
            'actor_role'    => $actor?->role ?? (app()->runningInConsole() ? 'console' : null),
            'action'        => $action,
            'target_type'   => $target ? class_basename($target) : null,
            'target_id'     => $target?->getKey(),
            'target_label'  => $target ? self::labelFor($target) : null,
            'before'        => $before,
            'after'         => $after,
            'ip_address'    => $request?->ip(),
            'user_agent'    => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    private static function labelFor(Model $target): ?string
    {
        return match (true) {
            $target instanceof User   => $target->email,
            $target instanceof Dealer => $target->name,
            isset($target->email)     => $target->email,
            default                   => null,
        };
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}

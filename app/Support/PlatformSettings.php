<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-wide settings the Command Centre can change (settings.manage).
 *
 * The keys are fixed here with their defaults — a setting only exists once
 * the app actually reads it. Values live in platform_settings and are cached;
 * a missing row (or a database that hasn't been migrated yet) falls back to
 * the default, so the marketplace never breaks over a setting.
 */
final class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings';

    /** key => [label, help, default, max length] */
    public const DEFINITIONS = [
        'marketplace_headline' => [
            'Marketplace headline',
            'The main heading on the marketplace home page.',
            'Certified Pre-Owned cars',
            80,
        ],
        'marketplace_subheadline' => [
            'Marketplace sub-heading',
            'The line under the headline.',
            'Every car is inspected, certified, and priced all-in — no as-is cars, ever. HST & licensing extra.',
            200,
        ],
        'marketplace_notice' => [
            'Site notice',
            'Optional banner across the marketplace, e.g. holiday hours. Leave empty for none.',
            '',
            200,
        ],
    ];

    /**
     * Payments: demo or live. Has its own control on the settings page, not
     * the text form above; see Deposits::demo().
     */
    public const PAYMENTS_MODE = 'payments_mode';
    public const PAYMENTS_DEMO = 'demo';
    public const PAYMENTS_LIVE = 'live';

    /** When the last signed Stripe webhook arrived (set by StripeWebhookController). */
    public const STRIPE_WEBHOOK_LAST_SEEN = 'stripe_webhook_last_seen';

    public static function get(string $key): string
    {
        $default = match ($key) {
            // Demo until someone deliberately switches to live payments.
            self::PAYMENTS_MODE => (string) config('services.stripe.default_mode', self::PAYMENTS_DEMO),
            default             => self::DEFINITIONS[$key][2] ?? '',
        };

        return self::stored()[$key] ?? $default;
    }

    /** @return array<string, string> every setting, stored value or default */
    public static function all(): array
    {
        $values = [];

        foreach (array_keys(self::DEFINITIONS) as $key) {
            $values[$key] = self::get($key);
        }

        return $values;
    }

    public static function put(string $key, string $value, ?int $updatedByUserId): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_by_user_id' => $updatedByUserId, 'updated_at' => now(), 'created_at' => now()],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, string> */
    private static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            if (! Schema::hasTable('platform_settings')) {
                return [];
            }

            return DB::table('platform_settings')->pluck('value', 'key')->map(fn ($value) => (string) $value)->all();
        });
    }
}

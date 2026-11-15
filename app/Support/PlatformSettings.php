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
        'business_mailing_address' => [
            'Business mailing address',
            'Printed in the footer of every marketing email, as Canada\'s anti-spam law (CASL) requires. Follow-up emails to unfinished checkouts don\'t send until this is set.',
            '',
            200,
        ],
        'contact_email' => [
            'Contact email',
            'Shown in the footer of every email, e.g. hello@trucars.ca. Leave empty to hide it.',
            '',
            120,
        ],
        'contact_phone' => [
            'Contact phone',
            'Shown in the footer of every email. Leave empty to hide it.',
            '',
            40,
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

    /**
     * ID checks at checkout: demo (approved on the spot, no ID) or live
     * (Didit). Its own control on the settings page; see IdentityChecks::demo().
     */
    public const IDENTITY_MODE = 'identity_mode';
    public const IDENTITY_DEMO = 'demo';
    public const IDENTITY_LIVE = 'live';

    /**
     * Marketing emails (checkout reminders, saved-car emails): on or off.
     * Off until someone deliberately switches them on. Its own control on the
     * settings page; see CheckoutRecovery::blockedReason().
     */
    public const MARKETING_EMAILS = 'marketing_emails';
    public const MARKETING_ON = 'on';
    public const MARKETING_OFF = 'off';

    /** Email types staff paused in the Email Centre (JSON list of App\Support\Email\Catalogue slugs). */
    public const EMAIL_TYPES_PAUSED = 'email_types_paused';

    /** When the last signed Didit webhook arrived (set by DiditWebhookController). */
    public const DIDIT_WEBHOOK_LAST_SEEN = 'didit_webhook_last_seen';

    public static function get(string $key): string
    {
        $default = match ($key) {
            // Demo until someone deliberately switches to live payments.
            self::PAYMENTS_MODE => (string) config('services.stripe.default_mode', self::PAYMENTS_DEMO),
            self::IDENTITY_MODE => (string) config('services.didit.default_mode', self::IDENTITY_DEMO),
            self::MARKETING_EMAILS => self::MARKETING_OFF,
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

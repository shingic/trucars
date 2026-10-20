<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Campaign;
use App\Models\Scopes\DealerScope;
use Illuminate\Http\Request;

/**
 * Keeps a referred shopper's campaign touch in their session, so it can be
 * stamped onto whatever they go on to create — a lead or a reservation.
 *
 * Only ever written from a campaign row the server resolved from its token, so
 * the dealer_id held here is trustworthy. Last touch wins: following a second
 * campaign link replaces the first. Session regeneration on sign-in and
 * registration carries the data across, so attribution survives the
 * account-first checkout.
 */
class CampaignAttribution
{
    public const SESSION_KEY = 'campaign_attribution';

    /** Campaign ids whose landing has already been counted this session. */
    private const VISITED_KEY = 'campaign_visits';

    private const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];

    public static function capture(Campaign $campaign, Request $request): void
    {
        $attribution = [
            'campaign_id' => $campaign->id,
            'dealer_id'   => $campaign->dealer_id,
            'source'      => $campaign->source,
        ];

        // UTM tags are recorded as they arrived, when present. They describe the
        // touch; they never widen or change the dealer scope.
        foreach (self::UTM_KEYS as $key) {
            $value = $request->query($key);

            if (is_string($value) && trim($value) !== '') {
                $attribution[$key] = mb_substr(trim($value), 0, 150);
            }
        }

        // A deep link inside the experience (e.g. the car page) arrives without
        // tags — keep the ones from the landing rather than wiping them.
        $previous = self::current();

        if ($previous !== null && $previous['campaign_id'] === $campaign->id) {
            $attribution += $previous;
        }

        $attribution['landed_at'] ??= now()->toIso8601String();

        session()->put(self::SESSION_KEY, $attribution);

        $visited = session()->get(self::VISITED_KEY, []);

        if (! in_array($campaign->id, $visited, true)) {
            $campaign->increment('visits_count');
            session()->put(self::VISITED_KEY, [...$visited, $campaign->id]);
        }
    }

    /** @return array<string, mixed>|null */
    public static function current(): ?array
    {
        if (! app()->bound('session')) {
            return null;
        }

        $attribution = session()->get(self::SESSION_KEY);

        return is_array($attribution) && isset($attribution['campaign_id'], $attribution['dealer_id'])
            ? $attribution
            : null;
    }

    /**
     * The touch to credit for a record created against this dealer, or null.
     * A shopper referred by dealer A who later reserves dealer B's car through
     * the open marketplace is not credited to A's campaign.
     *
     * @return array<string, mixed>|null
     */
    public static function forDealer(int $dealerId): ?array
    {
        $attribution = self::current();

        return $attribution !== null && (int) $attribution['dealer_id'] === $dealerId
            ? $attribution
            : null;
    }

    /** The active campaign the shopper is browsing under for this dealer, if any. */
    public static function campaignForDealer(int $dealerId): ?Campaign
    {
        $attribution = self::forDealer($dealerId);

        if ($attribution === null) {
            return null;
        }

        $campaign = Campaign::withoutGlobalScope(DealerScope::class)->find($attribution['campaign_id']);

        return $campaign?->isActive() ? $campaign : null;
    }
}

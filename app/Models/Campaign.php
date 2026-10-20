<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\DealerScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A dealer's trackable TruCars link. The dealer places it in their own ad or
 * post; TruCars never publishes it anywhere. Whoever follows the link lands in
 * a TruCars experience scoped to this dealer's eligible manufacturer-CPO stock.
 *
 * The public URL carries only the token. Dealer, destination and status are
 * always read back from this row — never from the query string or referrer.
 */
#[ScopedBy(DealerScope::class)]
class Campaign extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT  => 'Draft',
        self::STATUS_ACTIVE => 'Active',
        self::STATUS_PAUSED => 'Paused',
    ];

    public const DESTINATION_VEHICLE = 'vehicle';
    public const DESTINATION_CPO_INVENTORY = 'cpo_inventory';

    public const DESTINATION_LABELS = [
        self::DESTINATION_VEHICLE       => 'Specific vehicle',
        self::DESTINATION_CPO_INVENTORY => 'CPO inventory',
    ];

    /**
     * Where a dealer can place the link, with the UTM tags baked into the
     * generated URL. The tags are for the dealer's own analytics and our
     * attribution record — they never decide the dealer or inventory scope.
     */
    public const SOURCES = [
        'facebook_instagram'      => ['label' => 'Facebook / Instagram',    'utm_source' => 'facebook',       'utm_medium' => 'social'],
        'google_ads'              => ['label' => 'Google Ads',              'utm_source' => 'google',         'utm_medium' => 'cpc'],
        'google_business_profile' => ['label' => 'Google Business Profile', 'utm_source' => 'google',         'utm_medium' => 'business_profile'],
        'dealer_website'          => ['label' => 'Dealer website',          'utm_source' => 'dealer_website', 'utm_medium' => 'referral'],
        'email_sms'               => ['label' => 'Email / SMS',             'utm_source' => 'email_sms',      'utm_medium' => 'messaging'],
        'other_referral'          => ['label' => 'Other / referral',        'utm_source' => 'referral',       'utm_medium' => 'referral'],
    ];

    protected $fillable = [
        'dealer_id', 'name', 'source', 'destination_type', 'vehicle_id', 'status', 'activated_at',
    ];

    protected $attributes = [
        'status'       => self::STATUS_DRAFT,
        'visits_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'visits_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Campaign $campaign) {
            $campaign->token ??= self::freshToken();
        });
    }

    /**
     * Unguessable, URL-safe and unique across every dealer (the scope is lifted
     * for the uniqueness check, since a clash with another rooftop still clashes).
     */
    public static function freshToken(): string
    {
        do {
            $token = Str::lower(Str::random(12));
        } while (self::withoutGlobalScope(DealerScope::class)->where('token', $token)->exists());

        return $token;
    }

    /**
     * Resolve a public link to its campaign. Only active campaigns resolve; the
     * tenant scope is lifted because the visitor is a shopper, not staff.
     */
    public static function findActiveByToken(string $token): ?self
    {
        return self::withoutGlobalScope(DealerScope::class)
            ->with('dealer')
            ->where('token', $token)
            ->where('status', self::STATUS_ACTIVE)
            ->first();
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /** The target car, for 'vehicle' campaigns. */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function targetsVehicle(): bool
    {
        return $this->destination_type === self::DESTINATION_VEHICLE;
    }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCES[$this->source]['label'] ?? ucfirst((string) $this->source);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    /** The dealer-scoped storefront on the public host, without tracking tags. */
    public function shopUrl(): string
    {
        return route('campaign.shop', ['token' => $this->token]);
    }

    /** The link the dealer pastes into their ad or post, UTM-tagged for its source. */
    public function trackableUrl(): string
    {
        $source = self::SOURCES[$this->source] ?? self::SOURCES['other_referral'];

        return $this->shopUrl() . '?' . http_build_query([
            'utm_source'   => $source['utm_source'],
            'utm_medium'   => $source['utm_medium'],
            'utm_campaign' => Str::slug($this->name) ?: $this->token,
        ]);
    }
}

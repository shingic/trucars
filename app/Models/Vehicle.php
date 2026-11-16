<?php


namespace App\Models;

use App\Models\Concerns\CleansText;
use App\Models\Scopes\DealerScope;
use App\Support\VehicleCertification;
use App\Support\VehicleOffers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class Vehicle extends Model
{
    /** Fewer photos than this is a placeholder or a promo banner, not a listing. */
    public const MIN_PHOTOS = 4;

    use CleansText;

    /** Text columns kept as clean UTF-8 (see CleansText). */
    public const CLEAN_TEXT = ['stock_number', 'make', 'model', 'trim', 'body_type', 'colour', 'transmission', 'drivetrain', 'fuel_type'];

    protected $fillable = [
        'dealer_id',
        'rooftop_id',
        'vin',
        'model_year',
        'make',
        'model',
        'trim',
        'body_type',
        'colour',
        'kilometres',
        'price_in_cents',
        'is_published',
        'stock_number',
        'condition',
        'is_certified',
        'is_manufacturer_certified',
        'transmission',
        'drivetrain',
        'fuel_type',
        'photos',
    ];

    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'kilometres' => 'integer',
            'price_in_cents' => 'integer',
            'is_published' => 'boolean',
            'is_certified' => 'boolean',
            'is_manufacturer_certified' => 'boolean',
            'photos' => 'array',
            'first_listed_at' => 'datetime',
            'price_dropped_at' => 'datetime',
            'price_before_drop_in_cents' => 'integer',
        ];
    }

    /**
     * Every car sits at one of its dealer's rooftops — the main store unless
     * told otherwise — and never at another dealer's. A car can't go live
     * until that rooftop has an address: without one there's no honest answer
     * to "where is it?". Checked on publish and on a move to another dealer or
     * rooftop. (Bulk query-builder updates skip model events; publish through
     * the model.)
     */
    protected static function booted(): void
    {
        static::saving(function (Vehicle $vehicle) {
            $vehicle->placeAtRooftop();
            $vehicle->photo_count = count(array_filter((array) $vehicle->photos));
            $vehicle->notePriceChange();

            if ($vehicle->is_published && $vehicle->first_listed_at === null) {
                $vehicle->first_listed_at = now();
            }

            if (! $vehicle->is_published || ! $vehicle->isDirty(['is_published', 'dealer_id', 'rooftop_id'])) {
                return;
            }

            $rooftop = $vehicle->rooftop()->first();

            if ($rooftop === null || ! $rooftop->hasCompleteAddress()) {
                throw ValidationException::withMessages([
                    'is_published' => 'This vehicle can’t be published until its dealer has a complete address (missing: '
                        . implode(', ', $rooftop?->missingAddressFields() ?? ['dealer']) . ').',
                ]);
            }
        });
    }

    /**
     * A price cut starts (or deepens) a "Price dropped" run, remembering the
     * price before it. A rise back to that price or above ends it.
     */
    private function notePriceChange(): void
    {
        if (! $this->exists || ! $this->isDirty('price_in_cents')) {
            return;
        }

        $was = (int) $this->getOriginal('price_in_cents');
        $now = (int) $this->price_in_cents;

        if ($now < $was) {
            $runIsCurrent = $this->price_dropped_at !== null
                && $this->price_dropped_at->gt(now()->subDays(config('site.price_drop_days')));
            if (! $runIsCurrent || $this->price_before_drop_in_cents === null) {
                $this->price_before_drop_in_cents = $was;
            }
            $this->price_dropped_at = now();
        } elseif ($this->price_before_drop_in_cents !== null && $now >= $this->price_before_drop_in_cents) {
            $this->price_before_drop_in_cents = null;
            $this->price_dropped_at = null;
        }
    }

    /**
     * The one tag a car wears on its card and photo, or null: a recent price
     * cut first, then "Popular" (only once a threshold is set), then "Just listed".
     *
     * @return array{kind: string, label: string}|null
     */
    public function momentumTag(): ?array
    {
        $cut = (int) $this->price_before_drop_in_cents - (int) $this->price_in_cents;
        if ($this->price_dropped_at !== null
            && $this->price_dropped_at->gt(now()->subDays(config('site.price_drop_days')))
            && $cut >= config('site.price_drop_min_cents')) {
            return ['kind' => 'drop', 'label' => 'Price dropped $' . number_format(intdiv($cut, 100))];
        }

        $threshold = config('site.hot_views_threshold');
        if ($threshold && $this->id && \Illuminate\Support\Facades\DB::table('vehicle_views')
            ->where('vehicle_id', $this->id)->where('viewed_on', today()->toDateString())->count() >= $threshold) {
            return ['kind' => 'hot', 'label' => 'Popular today'];
        }

        if ($this->first_listed_at !== null && $this->first_listed_at->gt(now()->subDays(config('site.just_listed_days')))) {
            return ['kind' => 'new', 'label' => 'Just listed'];
        }

        return null;
    }

    /**
     * Count a shopper's visit: one per visitor per car per day. Staff, dealer
     * users and bots aren't counted.
     */
    public function recordView(\Illuminate\Http\Request $request): void
    {
        $user = $request->user();
        if ($user !== null && ($user->isDealerStaff() || $user->isInternalStaff())) {
            return;
        }

        if (preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|curl|wget|python|httpclient|facebookexternalhit/i', (string) $request->userAgent()) || ! $request->hasSession()) {
            return;
        }

        \Illuminate\Support\Facades\DB::table('vehicle_views')->insertOrIgnore([
            'vehicle_id' => $this->id,
            'visitor' => sha1($request->session()->getId()),
            'viewed_on' => today()->toDateString(),
            'created_at' => now(),
        ]);
    }

    private function placeAtRooftop(): void
    {
        if ($this->dealer_id === null || ($this->rooftop_id !== null && ! $this->isDirty(['dealer_id', 'rooftop_id']))) {
            return;
        }

        $rooftop = $this->rooftop_id ? Rooftop::find($this->rooftop_id) : null;

        if ($rooftop !== null && (int) $rooftop->dealer_id !== (int) $this->dealer_id) {
            // Moved to another dealer: the old store goes with the old dealer.
            if ($this->isDirty('dealer_id') && ! $this->isDirty('rooftop_id')) {
                $rooftop = null;
            } else {
                throw ValidationException::withMessages(['rooftop_id' => 'That rooftop belongs to another dealer.']);
            }
        }

        $this->rooftop_id = ($rooftop ?? Dealer::find($this->dealer_id)?->primaryRooftop)?->id;
        $this->unsetRelation('rooftop');
    }

    public function dealer(): BelongsTo
    {
        return $this->belongsTo(Dealer::class);
    }

    /** The store the car physically sits at. The dealer is still the seller. */
    public function rooftop(): BelongsTo
    {
        return $this->belongsTo(Rooftop::class);
    }

    /**
     * Where the car is: its rooftop, or the dealer's own address for a car not
     * yet placed at one. Address, coordinates and timezone all come from here.
     */
    public function locatedAt(): Rooftop|Dealer|null
    {
        return $this->rooftop ?? $this->dealer;
    }

    /**
     * What shoppers may see on the open marketplace: published cars from active
     * dealers, at open rooftops. A dealer switched back to draft, or a store
     * marked closed, drops off without anyone having to unpublish each car.
     * Reserved cars stay listed — shown as reserved, with a bell to hear if
     * the deal falls through. A car with fewer than MIN_PHOTOS photos (none,
     * a placeholder, a promo banner) isn't shown at all; it comes back by
     * itself once a feed brings real photos.
     */
    public function scopeListed(Builder $query): void
    {
        $query->where('vehicles.is_published', true)
            ->where('vehicles.photo_count', '>=', self::MIN_PHOTOS)
            ->whereHas('dealer', fn (Builder $dealer) => $dealer->where('status', Dealer::STATUS_ACTIVE))
            ->whereDoesntHave('rooftop', fn (Builder $rooftop) => $rooftop->where('is_active', false));
    }

    /** Adds is_reserved to each car, for the "Reserved" tag on cards. */
    public function scopeWithReservedFlag(Builder $query): void
    {
        $query->withExists(['deals as is_reserved' => fn (Builder $deals) => $deals
            ->withoutGlobalScope(DealerScope::class)
            ->where('stage', '!=', 'cancelled')]);
    }

    /** Shoppers waiting to hear if this car comes free again. */
    public function availabilityAlerts(): HasMany
    {
        return $this->hasMany(VehicleAlert::class);
    }

    /** A live reservation (anything but cancelled) holds this car. */
    public function isReserved(): bool
    {
        return Deal::withoutGlobalScopes()->where('vehicle_id', $this->id)->where('stage', '!=', 'cancelled')->exists();
    }

    public function isReservedBy(?User $user): bool
    {
        return $user !== null && Deal::withoutGlobalScopes()->where('vehicle_id', $this->id)->where('stage', '!=', 'cancelled')->where('user_id', $user->id)->exists();
    }

    public function isListed(): bool
    {
        return $this->is_published && $this->hasPhotos() && $this->dealer?->isActive() && $this->rooftop?->is_active !== false;
    }

    /** Enough real photos to be shown to shoppers. */
    public function hasPhotos(): bool
    {
        return count(array_filter((array) $this->photos)) >= self::MIN_PHOTOS;
    }

    /** Where the car is — its rooftop's city, e.g. "Chilliwack, BC". */
    protected function displayLocation(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->locatedAt()?->display_location);
    }

    /**
     * The first photo from the feed, used as the card image on the floor.
     */
    protected function primaryPhotoUrl(): Attribute
    {
        return Attribute::get(fn(): ?string => $this->photos[0] ?? null);
    }

    /**
     * Price formatted for display, e.g. "$39,490".
     */
    protected function displayPrice(): Attribute
    {
        return Attribute::get(fn(): string => '$' . number_format($this->price_in_cents / 100));
    }

    /**
     * Estimated biweekly payment, matching the mockup's illustrative terms:
     * roughly tax-in, 7.5% APR, 72-month term.
     */
    protected function estimatedBiweekly(): Attribute
    {
        return Attribute::get(fn (): int => $this->biweeklyAt(7.5, 72));
    }

    /** Biweekly payment on the tax-in price at an annual rate over a term, $0 down. */
    public function biweeklyAt(float $apr, int $termMonths): int
    {
        $taxedPrice = ($this->price_in_cents / 100) * 1.13;
        $numberOfPayments = $termMonths / 12 * 26;

        if ($apr <= 0) {
            return (int) round($taxedPrice / $numberOfPayments);
        }

        $biweeklyRate = $apr / 100 / 26;

        return (int) round($taxedPrice * $biweeklyRate / (1 - (1 + $biweeklyRate) ** -$numberOfPayments));
    }

    /**
     * Mileage shown the mockup's way: "42k km", but spelled out for near-new cars.
     */
    protected function displayKilometres(): Attribute
    {
        return Attribute::get(fn(): string => $this->kilometres < 1000
            ? number_format($this->kilometres) . ' km'
            : round($this->kilometres / 1000) . 'k km');
    }

    public function leads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /** Offers this car qualifies for today — see VehicleOffers. */
    public function offers(): Collection
    {
        return VehicleOffers::for($this);
    }

    /** The dealer's inspection checklist this car is shown with, if any. */
    public function certification(): ?VehicleCertification
    {
        return VehicleCertification::for($this);
    }

    /**
     * The one rule for what a dealer campaign may show: this dealer's own cars
     * that are published, used, flagged manufacturer-certified, of the dealer's
     * confirmed franchise brand, and not already held or sold through a deal.
     *
     * A dealer with no confirmed franchise brand has no eligible stock at all —
     * we'd rather show nothing than present generic used cars as factory CPO.
     * is_certified is deliberately not consulted: it's an age/kilometre guess,
     * not evidence of a manufacturer program.
     *
     * A campaign for one store passes $rooftopId to show only that store's cars.
     */
    public function scopeEligibleForDealerCpo(Builder $query, Dealer $dealer, ?int $rooftopId = null): void
    {
        if (blank($dealer->franchise_make)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('vehicles.dealer_id', $dealer->id)
            ->when($rooftopId !== null, fn (Builder $cars) => $cars->where('vehicles.rooftop_id', $rooftopId))
            ->where('is_published', true)
            ->where('condition', 'USED')
            ->where('is_manufacturer_certified', true)
            ->whereRaw('lower(make) = ?', [mb_strtolower(trim($dealer->franchise_make))])
            // Any live deal (reserved through delivered) takes the car off the
            // shelf. The scope is lifted so a staff preview sees the same truth.
            ->whereDoesntHave('deals', fn (Builder $deals) => $deals
                ->withoutGlobalScope(DealerScope::class)
                ->where('stage', '!=', 'cancelled'));
    }

    public function isEligibleForDealerCpo(Dealer $dealer, ?int $rooftopId = null): bool
    {
        return self::query()->whereKey($this->id)->eligibleForDealerCpo($dealer, $rooftopId)->exists();
    }
}

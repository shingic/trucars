<?php

namespace App\Models;

use App\Models\Concerns\CleansText;
use App\Models\Concerns\HasStreetAddress;
use App\Models\Scopes\DealerScope;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The selling dealer: legal seller and franchise identity, registration,
 * brand, and default fee and offer settings. Its cars sit at its rooftops —
 * one or more physical stores (see Rooftop). The address columns here are
 * the dealer's main store and are mirrored onto its primary rooftop.
 */
class Dealer extends Model
{
    use CleansText;
    use HasStreetAddress;

    /** Text columns kept as clean UTF-8 (see CleansText). */
    public const CLEAN_TEXT = ['name', 'street_address', 'unit', 'city', 'franchise_make', 'phone', 'email', 'website'];

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT     => 'Draft',
        self::STATUS_ACTIVE    => 'Active',
        self::STATUS_SUSPENDED => 'Suspended',
    ];

    protected $fillable = [
        'dealer_group_id',
        'feed_rooftop_id',
        'name',
        'street_address',
        'unit',
        'city',
        'province',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'timezone',
        'registration_number',
        'franchise_make',
        'brand_id',
        'phone',
        'email',
        'website',
        'cover_photo_path',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    /**
     * brand_id is the source of truth; franchise_make always carries the brand's
     * name so everything matching on make keeps working. Setting just the make
     * (the importer, older code) links the dealer to that brand.
     */
    protected static function booted(): void
    {
        static::saving(function (Dealer $dealer) {
            if ($dealer->isDirty('brand_id') && $dealer->brand_id) {
                $dealer->franchise_make = Brand::whereKey($dealer->brand_id)->value('name');
            } elseif ($dealer->isDirty('franchise_make')) {
                $make = trim((string) $dealer->franchise_make);
                $brand = $make === '' ? null : (Brand::whereRaw('lower(name) = ?', [mb_strtolower($make)])->first() ?? Brand::create(['name' => $make]));
                $dealer->brand_id = $brand?->id;
                $dealer->franchise_make = $brand?->name;
            }
        });

        static::saved(function (Dealer $dealer) {
            if ($dealer->wasRecentlyCreated || $dealer->wasChanged([...Rooftop::LOCATION_FIELDS, 'feed_rooftop_id'])) {
                $dealer->syncPrimaryRooftop();
            }
        });
    }

    /**
     * Copy the main-store address and feed id onto the primary rooftop,
     * creating it the first time. One way only: dealer → primary rooftop.
     */
    public function syncPrimaryRooftop(): Rooftop
    {
        $rooftop = $this->primaryRooftop()->firstOrNew([], ['is_primary' => true]);
        $rooftop->fill($this->only([...Rooftop::LOCATION_FIELDS, 'feed_rooftop_id']))->save();

        $this->setRelation('primaryRooftop', $rooftop);

        return $rooftop;
    }

    protected function casts(): array
    {
        return [
            'latitude'  => 'float',
            'longitude' => 'float',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Suspended by TruCars: cars unlisted and staff locked out of the portal. */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class)->where('role', User::ROLE_DEALER_STAFF);
    }

    /** The organization this dealer belongs to, if any. Grants no access by itself. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(DealerGroup::class, 'dealer_group_id');
    }

    /** Every physical store of this dealer, main store first. */
    public function rooftops(): HasMany
    {
        return $this->hasMany(Rooftop::class)->orderByDesc('is_primary')->orderBy('id');
    }

    public function primaryRooftop(): HasOne
    {
        return $this->hasOne(Rooftop::class)->where('is_primary', true);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(DealerInvitation::class);
    }

    /**
     * The dealer as it stands right now, frozen onto a reservation so later
     * profile edits never rewrite the buyer's record.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'id'                  => $this->id,
            'name'                => $this->name,
            'registration_number' => $this->registration_number,
            'street_address'      => $this->street_address,
            'unit'                => $this->unit,
            'city'                => $this->city,
            'province'            => $this->province,
            'postal_code'         => $this->postal_code,
            'country'             => $this->country,
            'phone'               => $this->phone,
            'email'               => $this->email,
            'website'             => $this->website,
            'timezone'            => $this->timezone,
            'captured_at'         => now()->toIso8601String(),
        ];
    }

    /**
     * Every car this dealership keeps in its back room.
     */
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    /** All of this dealer's deals — DealerScope is lifted, the relation already fences them. */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class)->withoutGlobalScope(DealerScope::class);
    }

    /** The storefront photo, served by the dealer.cover route; null when none is set. */
    public function coverPhotoUrl(): ?string
    {
        return $this->cover_photo_path ? route('dealer.cover', ['dealer' => $this->id, 'v' => substr(md5($this->cover_photo_path), 0, 8)]) : null;
    }

    /** The franchise brand whose settings apply to this dealer. */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function inspectionPrograms(): HasMany
    {
        return $this->hasMany(InspectionProgram::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * This dealership's fee schedule, in display order — both the 'included'
     * fees (inside the all-in price) and the 'pass_through' charges (added at
     * delivery), active or not.
     *
     * DealerScope is stripped here on purpose. The relation is already bound to
     * this dealer by its foreign key, so the scope would be redundant for the
     * console — and harmful for the consumer checkout, where the signed-in buyer
     * has no dealer_id and the scope would otherwise filter every fee out. The
     * scope still guards top-level DealerFee queries (e.g. editing a fee by id
     * in settings), which is where cross-dealer protection actually matters.
     */
    public function fees(): HasMany
    {
        return $this->hasMany(DealerFee::class)
            ->withoutGlobalScope(DealerScope::class)
            ->orderBy('sort_order');
    }

    /**
     * Only the switched-on fees, in display order — exactly what the checkout
     * breakdown shows and what gets frozen onto a deal at reserve.
     */
    protected function activeFees(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->fees->where('is_active', true)->values(),
        );
    }
}

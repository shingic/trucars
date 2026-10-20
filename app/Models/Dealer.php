<?php

namespace App\Models;

use App\Models\Scopes\DealerScope;
use App\Support\CanadianAddress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One selling dealership at one physical address. Every vehicle on the dealer
 * is at that address — vehicles carry no location of their own.
 */
class Dealer extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_LABELS = [
        self::STATUS_DRAFT     => 'Draft',
        self::STATUS_ACTIVE    => 'Active',
        self::STATUS_SUSPENDED => 'Suspended',
    ];

    /** The address parts that pin a rooftop to a place. Unit is optional. */
    public const REQUIRED_ADDRESS_FIELDS = ['street_address', 'city', 'province', 'postal_code', 'country'];

    protected $fillable = [
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
        'phone',
        'email',
        'website',
        'status',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'latitude'  => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Province and postal code are always stored in one shape ("BC", "V2P 2M9"),
     * whichever path writes them — admin form, importer or tinker. Input that
     * isn't a recognisable province or postal code is kept as typed so it shows
     * up as invalid rather than silently disappearing.
     */
    protected function province(): Attribute
    {
        return Attribute::set(fn (?string $value) => CanadianAddress::normalizeProvince($value) ?? (blank($value) ? null : $value));
    }

    protected function postalCode(): Attribute
    {
        return Attribute::set(fn (?string $value) => CanadianAddress::normalizePostalCode($value) ?? (blank($value) ? null : $value));
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

    public function invitations(): HasMany
    {
        return $this->hasMany(DealerInvitation::class);
    }

    /** @return array<int, string> the required address fields still empty */
    public function missingAddressFields(): array
    {
        return array_values(array_filter(
            self::REQUIRED_ADDRESS_FIELDS,
            fn (string $field) => blank($this->{$field}),
        ));
    }

    /** Enough address to say where this dealer's cars physically are. */
    public function hasCompleteAddress(): bool
    {
        return $this->missingAddressFields() === [];
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Dealers that can't yet place their cars on a map — the backfill list. */
    public function scopeMissingAddress(Builder $query): void
    {
        $query->where(function (Builder $missing) {
            foreach (self::REQUIRED_ADDRESS_FIELDS as $field) {
                $missing->orWhereNull($field)->orWhere($field, '');
            }
        });
    }

    /**
     * The zone for dealer-local times. Falls back to the app zone (UTC) only so
     * nothing crashes for a dealer still awaiting backfill — never a guess.
     */
    public function localTimezone(): string
    {
        return $this->timezone ?: config('app.timezone');
    }

    /** "Chilliwack, BC" — the short location shown on cards and car pages. */
    protected function displayLocation(): Attribute
    {
        return Attribute::get(fn (): ?string => collect([$this->city, $this->province])->filter()->implode(', ') ?: null);
    }

    /** Street line(s) then "City, PR A1A 1A1", for the full address. */
    protected function addressLines(): Attribute
    {
        return Attribute::get(function (): array {
            $street = collect([$this->unit ? "Unit {$this->unit}" : null, $this->street_address])->filter()->implode(', ');
            $locality = trim(collect([$this->city, $this->province])->filter()->implode(', ') . ' ' . $this->postal_code);

            return array_values(array_filter([$street, $locality]));
        });
    }

    /** "OMVIC", "VSA", … for the registration line; null where we don't know it. */
    protected function regulator(): Attribute
    {
        return Attribute::get(fn (): ?string => CanadianAddress::regulatorFor($this->province));
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

    /** Trackable links this dealership has created for its own ads and posts. */
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

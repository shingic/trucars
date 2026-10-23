<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\CanadianAddress;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * A physical address with coordinates and a timezone — shared by the dealer
 * (its registered address, mirrored onto its primary rooftop) and by every
 * rooftop. Both tables use the same column names.
 */
trait HasStreetAddress
{
    /** The address parts that pin a rooftop to a place. Unit is optional. */
    public const REQUIRED_ADDRESS_FIELDS = ['street_address', 'city', 'province', 'postal_code', 'country'];

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

    /** @return array<int, string> the required address fields still empty */
    public function missingAddressFields(): array
    {
        return array_values(array_filter(
            self::REQUIRED_ADDRESS_FIELDS,
            fn (string $field) => blank($this->{$field}),
        ));
    }

    /** Enough address to say where the cars physically are. */
    public function hasCompleteAddress(): bool
    {
        return $this->missingAddressFields() === [];
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Rows that can't yet place their cars on a map — the backfill list. */
    public function scopeMissingAddress(Builder $query): void
    {
        $query->where(function (Builder $missing) {
            foreach (self::REQUIRED_ADDRESS_FIELDS as $field) {
                $missing->orWhereNull($field)->orWhere($field, '');
            }
        });
    }

    /**
     * The zone for local times. Falls back to the app zone (UTC) only so
     * nothing crashes for a row still awaiting backfill — never a guess.
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
}

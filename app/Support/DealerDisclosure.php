<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Vehicle;

/**
 * The dealer details every listing must carry. Under OMVIC's advertising
 * rules (and VSA's and AMVIC's), a car advertised for a dealer shows the
 * dealer's registered name and a way of contacting it that the regulator
 * publishes; BC listings also show the dealer number. Trumo shows the
 * registered name, the regulator and number, and the business address of
 * the store the car is at. Phone and email stay for after the deposit.
 */
final class DealerDisclosure
{
    private function __construct(
        public readonly string $name,
        public readonly ?string $registration,   // "VSA #11018", "OMVIC #4567"
        public readonly ?string $address,        // "2575 Bowen Rd, Nanaimo, BC V9T 3L4"
        public readonly ?string $street,
        public readonly ?string $city,
        public readonly ?string $province,
        public readonly ?string $postalCode,
    ) {}

    public static function for(Vehicle $vehicle): ?self
    {
        $dealer = $vehicle->dealer;

        if ($dealer === null) {
            return null;
        }

        $store = $vehicle->locatedAt() ?? $dealer;
        $street = collect([$store->unit ? "Unit {$store->unit}" : null, $store->street_address])->filter()->implode(', ') ?: null;
        $locality = trim(collect([$store->city, $store->province])->filter()->implode(', ') . ' ' . $store->postal_code) ?: null;
        $regulator = ProvinceRules::regulator($dealer->province)['short'] ?? null;

        return new self(
            name: self::registeredName($dealer->registered_name, $dealer->name),
            registration: filled($dealer->registration_number) ? trim(($regulator ? "{$regulator} " : 'Dealer ') . '#' . ltrim((string) $dealer->registration_number, '#')) : null,
            address: collect([$street, $locality])->filter()->implode(', ') ?: null,
            street: $street,
            city: $store->city,
            province: $store->province,
            postalCode: $store->postal_code,
        );
    }

    /** The registered name; else the trading name, title-cased when a feed sent it in capitals. */
    public static function registeredName(?string $registered, ?string $name): string
    {
        if (filled($registered)) {
            return trim($registered);
        }

        $name = trim((string) $name);

        return $name !== '' && $name === mb_strtoupper($name) ? mb_convert_case(mb_strtolower($name), MB_CASE_TITLE) : $name;
    }

    /** "Sold by Harris Kia · VSA #11018 · 2575 Bowen Rd, Nanaimo, BC V9T 3L4". */
    public function line(): string
    {
        return collect(["Sold by {$this->name}", $this->registration, $this->address])->filter()->implode(' · ');
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Vehicle;

class CertificationProgram
{
    /**
     * @param  array<int, array{title: string, detail: string, iconPath: string}>  $benefits
     */
    public function __construct(
        public string $name,
        public string $shortName,
        public string $tagline,
        public int $inspectionPoints,
        public int $warrantyMonths,
        public int $warrantyKilometres,
        public array $benefits,
    ) {}

    /**
     * Pick the most thorough program the vehicle qualifies for, or null if none.
     */
    public static function resolveFor(Vehicle $vehicle): ?self
    {
        $ageInYears = (int) date('Y') - $vehicle->model_year;

        foreach (self::definitions() as $definition) {
            $withinAge = $ageInYears <= $definition['maxAgeYears'];
            $withinKilometres = $vehicle->kilometres < $definition['maxKilometres'];

            if ($withinAge && $withinKilometres) {
                return new self(
                    name: $definition['name'],
                    shortName: $definition['shortName'],
                    tagline: $definition['tagline'],
                    inspectionPoints: $definition['inspectionPoints'],
                    warrantyMonths: $definition['warrantyMonths'],
                    warrantyKilometres: $definition['warrantyKilometres'],
                    benefits: $definition['benefits'],
                );
            }
        }

        return null;
    }

    /**
     * Most thorough program first — the vehicle is matched to the first it fits.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function definitions(): array
    {
        $clipboard = 'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1h-4a1 1 0 0 1-1-1Z';
        $shield = 'M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6ZM9 12l2 2 4-4';
        $refresh = 'M3 12a9 9 0 0 1 15-6.7L21 8M21 3v5h-5M21 12a9 9 0 0 1-15 6.7L3 16M3 21v-5h5';
        $phone = 'M5 4h4l2 5-3 2a11 11 0 0 0 5 5l2-3 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z';
        $droplet = 'M12 3s6 7 6 11a6 6 0 0 1-12 0c0-4 6-11 6-11Z';
        $history = 'M3 12a9 9 0 1 0 9-9 9 9 0 0 0-6.4 2.6L3 8M3 3v5h5M12 7v5l3 2';
        $exchange = 'M21 8H3M6 5 3 8l3 3M3 16h18M18 13l3 3-3 3';

        return [
            [
                'name' => 'Manufacturer Certified Pre-Owned',
                'shortName' => 'Manufacturer CPO',
                'tagline' => "The manufacturer's own certified program — the most rigorous inspection and the longest factory-backed warranty.",
                'maxAgeYears' => 6,
                'maxKilometres' => 120000,
                'inspectionPoints' => 172,
                'warrantyMonths' => 72,
                'warrantyKilometres' => 120000,
                'benefits' => [
                    ['title' => '172-point inspection', 'detail' => "Every system checked and signed off by the dealer's factory-trained technicians.", 'iconPath' => $clipboard],
                    ['title' => '72-mo / 120,000 km powertrain warranty', 'detail' => 'Manufacturer-backed coverage on 600+ components from the in-service date.', 'iconPath' => $shield],
                    ['title' => '10-day / 1,500 km exchange promise', 'detail' => "Not the right fit? Exchange it for another vehicle within 10 days or 1,500 km.", 'iconPath' => $exchange],
                    ['title' => 'Fully refundable deposit', 'detail' => 'Your $150 hold is refundable any time before you take delivery.', 'iconPath' => $refresh],
                    ['title' => '24/7 roadside assistance', 'detail' => 'Towing, boosts, flat tires, lockouts — anywhere in Canada.', 'iconPath' => $phone],
                    ['title' => '2-year no-charge oil changes', 'detail' => 'Up to 32,000 km of included maintenance.', 'iconPath' => $droplet],
                    ['title' => 'Full CARFAX history', 'detail' => 'Accidents, liens and service records, in the open.', 'iconPath' => $history],
                ],
            ],
            [
                'name' => 'Certified Pre-Owned',
                'shortName' => 'Certified Pre-Owned',
                'tagline' => 'Dealer-certified and fully inspected, with a powertrain warranty and complete history — ready to drive with confidence.',
                'maxAgeYears' => 9,
                'maxKilometres' => 170000,
                'inspectionPoints' => 86,
                'warrantyMonths' => 6,
                'warrantyKilometres' => 10000,
                'benefits' => [
                    ['title' => '86-point inspection', 'detail' => "A thorough review signed off by the dealer's licensed technicians.", 'iconPath' => $clipboard],
                    ['title' => '6-mo / 10,000 km powertrain warranty', 'detail' => 'Dealer-backed powertrain coverage from the contract date.', 'iconPath' => $shield],
                    ['title' => '10-day / 1,500 km exchange promise', 'detail' => "Not the right fit? Exchange it for another vehicle within 10 days or 1,500 km.", 'iconPath' => $exchange],
                    ['title' => 'Fully refundable deposit', 'detail' => 'Hold the car with $150 — refundable until you take delivery.', 'iconPath' => $refresh],
                    ['title' => '24/7 roadside assistance', 'detail' => 'Help is one call away, coast to coast.', 'iconPath' => $phone],
                    ['title' => 'Full CARFAX history', 'detail' => 'Complete accident and ownership record.', 'iconPath' => $history],
                ],
            ],
        ];
    }
}

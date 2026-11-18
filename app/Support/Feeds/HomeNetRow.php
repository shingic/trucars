<?php

declare(strict_types=1);

namespace App\Support\Feeds;

/**
 * A HomeNet (Cox) inventory export row, turned into the columns the importer
 * reads (the eDealer layout), with HomeNet's wording mapped to the shop's
 * filter vocabulary ("Sport Utility" → SUV, "Gasoline Fuel" → Gasoline).
 *
 * HomeNet has no store id in the file, so the store is named after the file
 * ("Trumo_HarrisKIA.csv" → homenet-harriskia): one file per store, every run.
 * The description isn't carried over: it names the dealership and its fees.
 */
final class HomeNetRow
{
    /** Columns that only a HomeNet export has together. */
    private const SIGNATURE = ['ImageList', 'SellingPrice', 'Type', 'Dealer Name'];

    /** @param  array<int, string>  $headers */
    public static function matches(array $headers): bool
    {
        return array_diff(self::SIGNATURE, $headers) === [];
    }

    /** "Trumo_HarrisKIA.csv" → "homenet-harriskia". */
    public static function storeIdFor(string $path): string
    {
        $name = pathinfo($path, PATHINFO_FILENAME);
        $name = preg_replace('/^trumo[_-]/i', '', $name) ?? $name;

        return 'homenet-' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $name) ?? $name);
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string>|null  null for rows that aren't cars for sale ("Other": RVs, trailers)
     */
    public static function toImportRow(array $row, string $storeId): ?array
    {
        $type = strtoupper(trim($row['Type'] ?? ''));

        if (! in_array($type, ['NEW', 'USED'], true)) {
            return null;
        }

        return [
            'rooftop_id'         => $storeId,
            'dealer_name'        => trim($row['Dealer Name'] ?? ''),
            'dealer_address'     => trim($row['Dealer Address'] ?? ''),
            'dealer_city'        => self::town($row['Dealer City'] ?? ''),
            'dealer_region_code' => trim($row['Dealer State'] ?? ''),
            'dealer_postal_code' => trim($row['Dealer Zip'] ?? ''),
            'dealer_phone'       => self::phone($row['Dealer Phone'] ?? ''),
            'dealer_email'       => trim($row['Dealer Email'] ?? ''),
            'condition'          => $type === 'NEW' ? 'New' : 'Used',
            'certified'          => strcasecmp(trim($row['Certified'] ?? ''), 'true') === 0 ? 'true' : 'false',
            'vin'                => strtoupper(trim($row['VIN'] ?? '')),
            'stockNumber'        => trim($row['Stock'] ?? ''),
            'year'               => trim($row['Year'] ?? ''),
            'make'               => trim($row['Make'] ?? ''),
            'model'              => trim($row['Model'] ?? ''),
            'trim'               => trim($row['Trim'] ?? ''),
            'body_type'          => self::body($row['Body'] ?? '', $row['MarketClass'] ?? ''),
            'exterior_color'     => trim($row['Ext_Color_Generic'] ?? '') ?: trim($row['ExteriorColor'] ?? ''),
            'mileage'            => trim($row['Miles'] ?? ''), // kilometres, despite the name
            'price'              => trim($row['SellingPrice'] ?? ''),
            'transmission'       => self::transmission($row['Transmission'] ?? ''),
            'drivetrain'         => self::drivetrain($row['Drivetrain'] ?? ''),
            'fuel_type'          => self::fuel($row['Fuel_Type'] ?? ''),
            'photos'             => implode('|', array_filter(array_map('trim', explode(',', $row['ImageList'] ?? '')))),
            'photo_source'       => '',
        ];
    }

    /** A real number, or blank for a placeholder like 7777777777. */
    private static function phone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';

        return strlen($digits) < 10 || preg_match('/^(\d)\1+$/', $digits) ? '' : trim($phone);
    }

    /** "KELOWNA" → "Kelowna", "Grand Prairie" stays as written. */
    private static function town(string $city): string
    {
        $city = trim($city);

        return $city === mb_strtoupper($city) ? mb_convert_case(mb_strtolower($city), MB_CASE_TITLE) : $city;
    }

    private static function body(string $body, string $marketClass): string
    {
        $b = mb_strtolower(trim($body));

        return match (true) {
            $b === '' => str_contains(mb_strtolower($marketClass), 'sport utility') ? 'SUV' : '',
            str_contains($b, 'sport utility'), $b === 'suv' => 'SUV',
            str_contains($b, 'pickup') => 'Truck',
            str_contains($b, 'mini-van'), str_contains($b, 'minivan') => 'Minivan',
            str_contains($b, 'van') => 'Van',
            $b === '4dr car' => 'Sedan',
            $b === '2dr car' => 'Coupe',
            str_contains($b, 'wagon') => 'Wagon',
            str_contains($b, 'hatchback') => 'Hatchback',
            str_contains($b, 'convertible') => 'Convertible',
            default => str_contains(mb_strtolower($marketClass), 'sport utility') ? 'SUV' : '',
        };
    }

    private static function drivetrain(string $drive): string
    {
        return match (strtoupper(trim($drive))) {
            'AWD', 'ALL WHEEL DRIVE' => 'All Wheel Drive',
            '4WD', 'FOUR WHEEL DRIVE', '4X4' => 'Four Wheel Drive',
            'FWD', 'FRONT WHEEL DRIVE' => 'Front Wheel Drive',
            'RWD', 'REAR WHEEL DRIVE' => 'Rear Wheel Drive',
            default => '',
        };
    }

    private static function fuel(string $fuel): string
    {
        $f = mb_strtolower(trim($fuel));

        return match (true) {
            str_contains($f, 'hybrid') => 'Hybrid',
            str_contains($f, 'electric') => 'Electric',
            str_contains($f, 'diesel') => 'Diesel',
            str_contains($f, 'flex') => 'Flex Fuel',
            str_contains($f, 'gasoline') => 'Gasoline',
            default => '',
        };
    }

    private static function transmission(string $transmission): string
    {
        return match (mb_strtolower(trim($transmission))) {
            'automatic', 'variable', 'single reduction gear' => 'Automatic',
            'manual' => 'Manual',
            default => '',
        };
    }
}

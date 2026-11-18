<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Brand;
use App\Models\Vehicle;

/**
 * The brand's certified pre-owned program benefits a car comes with. Only for
 * cars the feed flags manufacturer-certified (which already means the dealer's
 * own franchise brand), whose brand has benefits entered and switched on.
 * A Toyota sold as a plain used car gets none — the program doesn't cover it.
 */
final class CertifiedBenefits
{
    /** Icon choices for a benefit row: key → [label, SVG inner markup]. */
    public const ICONS = [
        'coverage'    => ['Coverage', '<path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/>'],
        'roadside'    => ['Roadside', '<path d="M3 17h2l1-5h12l1 5h2"/><circle cx="7.5" cy="17.5" r="1.5"/><circle cx="16.5" cy="17.5" r="1.5"/><path d="M7 12l2-5h6l2 5"/>'],
        'deductible'  => ['No cost', '<circle cx="12" cy="12" r="9"/><path d="M15 9.5c-.5-1-1.6-1.5-3-1.5-1.7 0-3 .8-3 2s1.3 1.7 3 2 3 .8 3 2-1.3 2-3 2c-1.4 0-2.5-.5-3-1.5M12 6.5v11"/>'],
        'exchange'    => ['Exchange', '<path d="M17 3l4 4-4 4"/><path d="M21 7H8"/><path d="M7 21l-4-4 4-4"/><path d="M3 17h13"/>'],
        'service'     => ['Service', '<path d="M12 3s6 6.5 6 11a6 6 0 0 1-12 0c0-4.5 6-11 6-11Z"/>'],
        'dealers'     => ['Dealers', '<path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21Z"/><circle cx="12" cy="9.5" r="2.5"/>'],
        'recondition' => ['Reconditioning', '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.5-.5-.5-2.5Z"/>'],
        'history'     => ['History report', '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>'],
        'connected'   => ['Connected', '<path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0"/><circle cx="12" cy="19.5" r="1"/><path d="M1.5 9a15 15 0 0 1 21 0"/>'],
        'check'       => ['Other', '<path d="M20 6 9 17l-5-5"/>'],
    ];

    /** @param  array<int, array{icon: string, title: string, detail: string}>  $benefits */
    private function __construct(
        public readonly string $brandName,
        public readonly string $programName,
        public readonly array $benefits,
        public readonly ?string $finePrint,
        public readonly ?string $sourceUrl,
    ) {}

    public static function for(Vehicle $vehicle): ?self
    {
        if (! $vehicle->is_manufacturer_certified || strtoupper((string) $vehicle->condition) !== 'USED') {
            return null;
        }

        $brand = $vehicle->dealer?->brand;

        if ($brand === null || ! CpoRules::sameFranchise($vehicle->make, $brand->name)) {
            return null;
        }

        return self::forBrand($brand);
    }

    /** The brand's benefits as shoppers see them, or null while hidden or empty. */
    public static function forBrand(Brand $brand, bool $evenIfHidden = false): ?self
    {
        $benefits = $brand->certifiedBenefitRows();

        if ($benefits === [] || (! $brand->cpo_benefits_live && ! $evenIfHidden)) {
            return null;
        }

        return new self(
            brandName: $brand->name,
            programName: $brand->cpo_program_name ?: "{$brand->name} Certified Pre-Owned",
            benefits: $benefits,
            finePrint: $brand->cpo_fine_print ?: null,
            sourceUrl: $brand->cpo_source_url ?: null,
        );
    }

    public static function icon(string $key): string
    {
        return (self::ICONS[$key] ?? self::ICONS['check'])[1];
    }

    /** Whether any benefit carries a footnote mark (*, †, ‡) that needs explaining. */
    public function hasFootnoteMarks(): bool
    {
        return collect($this->benefits)->contains(fn ($row) => preg_match('/[*†‡]/u', $row['title'] . $row['detail']) === 1);
    }

    /** "toyota.ca" from the source link, for a short credit line. */
    public function sourceHost(): ?string
    {
        $host = $this->sourceUrl ? parse_url($this->sourceUrl, PHP_URL_HOST) : null;

        return $host ? preg_replace('/^www\./', '', $host) : null;
    }
}

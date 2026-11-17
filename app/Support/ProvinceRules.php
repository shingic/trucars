<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Province by province: who licenses car dealers, and the sales tax on a used
 * car bought from a dealer. The car is picked up at the dealership, so it's
 * always the dealership's province that counts, never the shopper's.
 *
 * Checked against official sources (Nov 2026). A regulator we couldn't confirm
 * is left null rather than guessed; the copy then just doesn't name one.
 */
final class ProvinceRules
{
    /** Dealer licensing bodies, where confirmed. */
    private const REGULATORS = [
        'ON' => ['short' => 'OMVIC', 'name' => 'Ontario Motor Vehicle Industry Council', 'url' => 'https://www.omvic.ca'],
        'BC' => ['short' => 'VSA', 'name' => 'Vehicle Sales Authority of BC', 'url' => 'https://vsabc.ca'],
        'AB' => ['short' => 'AMVIC', 'name' => 'Alberta Motor Vehicle Industry Council', 'url' => 'https://www.amvic.org'],
        'QC' => ['short' => 'OPC', 'name' => 'Office de la protection du consommateur', 'url' => 'https://www.opc.gouv.qc.ca'],
        'SK' => ['short' => 'FCAA', 'name' => 'Financial and Consumer Affairs Authority of Saskatchewan', 'url' => 'https://fcaa.gov.sk.ca'],
    ];

    /**
     * Sales tax on a used car from a dealer: [label, the parts named in
     * "Excludes … & licensing", combined rate].
     */
    private const SALES_TAX = [
        'ON' => ['HST', 'HST', 0.13],
        'NB' => ['HST', 'HST', 0.15],
        'NL' => ['HST', 'HST', 0.15],
        'PE' => ['HST', 'HST', 0.15],
        'NS' => ['HST', 'HST', 0.14],
        'QC' => ['GST + QST', 'GST, QST', 0.14975],
        'BC' => ['GST + PST', 'GST, PST', 0.12],
        'MB' => ['GST + RST', 'GST, RST', 0.12],
        'SK' => ['GST + PST', 'GST, PST', 0.11],
        'AB' => ['GST', 'GST', 0.05],
        'NT' => ['GST', 'GST', 0.05],
        'NU' => ['GST', 'GST', 0.05],
        'YT' => ['GST', 'GST', 0.05],
    ];

    /**
     * BC's PST on a passenger vehicle from a dealer steps up with the price
     * (before any trade-in): [from this price in dollars, PST rate].
     */
    private const BC_PST_TIERS = [
        [150000, 0.20],
        [125000, 0.15],
        [57000, 0.10],
        [56000, 0.09],
        [55000, 0.08],
        [0, 0.07],
    ];

    private const GST = 0.05;

    /** @return array{short: string, name: string, url: string}|null */
    public static function regulator(?string $province): ?array
    {
        return self::REGULATORS[CanadianAddress::normalizeProvince($province) ?? ''] ?? null;
    }

    /**
     * The tax on this price in this province. Unknown province: Ontario's HST,
     * the most common case, so an estimate is never left at zero.
     *
     * @return array{label: string, excludes: string, rate: float}
     */
    public static function salesTax(?string $province, ?int $priceInCents = null): array
    {
        $code = CanadianAddress::normalizeProvince($province);
        [$label, $excludes, $rate] = self::SALES_TAX[$code ?? ''] ?? self::SALES_TAX['ON'];

        if ($code === 'BC' && $priceInCents !== null) {
            $dollars = $priceInCents / 100;
            foreach (self::BC_PST_TIERS as [$from, $pst]) {
                if ($dollars >= $from) {
                    $rate = round(self::GST + $pst, 5);
                    break;
                }
            }
        }

        return ['label' => $label, 'excludes' => $excludes, 'rate' => $rate];
    }

    /** "Excludes HST & licensing" / "Excludes GST, PST & licensing". */
    public static function allInExcludes(?string $province): string
    {
        return 'Excludes ' . self::salesTax($province)['excludes'] . ' & licensing';
    }

    /** "+HST & lic." for tight spaces. */
    public static function shortExtras(?string $province): string
    {
        return '+' . self::salesTax($province)['excludes'] . ' & lic.';
    }

    /** "13%", "12%", "14.975%". */
    public static function ratePercent(float $rate): string
    {
        return rtrim(rtrim(number_format($rate * 100, 3), '0'), '.') . '%';
    }

    /**
     * "freight, PDI, admin and OMVIC fee": the dealer's own included fee
     * labels, or a neutral description when they haven't set any.
     *
     * @param  iterable<string>  $labels
     */
    public static function includedFeesPhrase(iterable $labels): string
    {
        $labels = collect($labels)->map(fn ($l) => trim((string) $l))->filter()->unique()->values();

        if ($labels->isEmpty()) {
            return 'freight, prep and dealer fees';
        }

        $labels = $labels->map(fn ($l) => preg_match('/^[A-Z]{2,}/', $l) ? $l : lcfirst($l));

        return $labels->count() === 1 ? $labels[0] : $labels->slice(0, -1)->implode(', ') . ' & ' . $labels->last();
    }
}

<?php

namespace App\Services\Valuation\Providers;

use App\Services\Valuation\Data\ProviderQuote;
use App\Services\Valuation\Data\TradeInput;
use App\Services\Valuation\Data\ValuationLine;

/**
 * The in-house base-value provider used until a real vendor (Canadian Black
 * Book / Carfax Canada / vAuto) is contracted.
 *
 * It ports the journey mockup's computeTradeOffer() adjustment schedule
 * verbatim — the SAME deductions and additions the prototype showed — but
 * starts from a deliberately conservative depreciation curve rather than a
 * live market value. We never claim to know the market price (OMVIC
 * misrepresentation risk); we produce a defensible floor the dealer can beat.
 *
 * All amounts here are in CENTS.
 */
class ConservativeStubProvider implements TradeValuationProvider
{
    /** Adjustment values, mirrored from the mockup but expressed in cents. */
    private const EXTERIOR_TIER = ['excellent' => 90000, 'good' => 0, 'fair' => -90000, 'poor' => -180000];
    private const INTERIOR_TIER = ['excellent' => 70000, 'good' => 0, 'fair' => -70000, 'poor' => -140000];
    private const TIRE_TIER = ['new' => 40000, 'good' => 0, 'worn' => -50000];
    private const MECHANICAL_TIER = ['perfect' => 60000, 'good' => 0, 'minor' => -120000, 'warning' => -250000];
    private const ACCIDENT_TIER = ['none' => 0, 'minor' => -120000, 'major' => -350000];
    private const OWNER_TIER = ['1' => 40000, '2' => 0, '3+' => -70000];

    /** Resale-relevant options, slug => cents. */
    private const FEATURE_VALUES = [
        'sunroof' => 50000,
        'leather' => 70000,
        'heated' => 20000,
        'nav' => 30000,
        'carplay' => 20000,
        'tow' => 40000,
        'winter' => 60000,
    ];

    private const FEATURE_LABELS = [
        'sunroof' => 'Sunroof',
        'leather' => 'Leather seats',
        'heated' => 'Heated seats',
        'nav' => 'Navigation',
        'carplay' => 'Apple CarPlay',
        'tow' => 'Tow package',
        'winter' => 'Winter tire set',
    ];

    public function key(): string
    {
        return 'conservative';
    }

    public function quote(TradeInput $trade): ProviderQuote
    {
        $lines = [];

        $baseInCents = $this->conservativeBaseInCents($trade);
        $lines[] = new ValuationLine(
            label: "Base value · {$trade->year} {$trade->make} {$trade->model}",
            amountInCents: $baseInCents,
            isBase: true,
        );

        $runningTotal = $baseInCents;

        $addLine = function (string $label, int $amountInCents) use (&$lines, &$runningTotal): void {
            if ($amountInCents !== 0) {
                $lines[] = new ValuationLine($label, $amountInCents);
                $runningTotal += $amountInCents;
            }
        };

        // Mileage relative to a 60,000 km baseline, valued at $0.10/km, rounded to $50.
        $kilometresDelta = $this->roundToFiftyDollars((60000 - $trade->kilometres) * 10);
        $formattedKilometres = number_format($trade->kilometres);
        $addLine("Mileage · {$formattedKilometres} km", $kilometresDelta);

        $addLine('Exterior condition', self::EXTERIOR_TIER[$trade->exteriorCondition] ?? 0);
        $addLine('Interior condition', self::INTERIOR_TIER[$trade->interiorCondition] ?? 0);
        $addLine('Tires', self::TIRE_TIER[$trade->tireCondition] ?? 0);
        $addLine('Mechanical', self::MECHANICAL_TIER[$trade->mechanicalCondition] ?? 0);

        // Customer-disclosed history — their statement, never a fabricated report.
        $addLine('Accident history', self::ACCIDENT_TIER[$trade->accidentHistory] ?? 0);
        $addLine('Ownership', self::OWNER_TIER[$trade->ownerCount] ?? 0);

        if ($trade->titleStatus === 'rebuilt') {
            $addLine('Rebuilt title', -400000);
        }
        if ($trade->keyCount <= 1) {
            $addLine('Only one key', -25000);
        }
        if ($trade->wasSmokedIn) {
            $addLine('Smoked in', -80000);
        }
        if ($trade->carriedPets) {
            $addLine('Pet transport', -30000);
        }
        if ($trade->hasAftermarketMods) {
            $addLine('Aftermarket modifications', -50000);
        }

        // Features roll up into one tidy line, matching the mockup's breakdown.
        $featureTotal = 0;
        $featureNames = [];
        foreach (self::FEATURE_VALUES as $slug => $valueInCents) {
            if ($trade->hasFeature($slug)) {
                $featureTotal += $valueInCents;
                $featureNames[] = self::FEATURE_LABELS[$slug];
            }
        }
        if ($featureTotal > 0) {
            $addLine('Features · ' . implode(', ', $featureNames), $featureTotal);
        }

        // Provider floor: never quote below $2,000 raw, before the pipeline's margin.
        $rawValueInCents = max(200000, $this->roundToFiftyDollars($runningTotal));

        return new ProviderQuote(
            providerKey: $this->key(),
            rawValueInCents: $rawValueInCents,
            lines: $lines,
        );
    }

    /**
     * A conservative base before condition/history adjustments. Without a live
     * market feed we anchor on age-based depreciation: newer cars hold more,
     * each year past the model year shaves value, and we floor it so very old
     * trades still clear the provider minimum. Intentionally pessimistic —
     * the dealer should always be able to meet or beat this.
     */
    private function conservativeBaseInCents(TradeInput $trade): int
    {
        $currentYear = (int) date('Y');
        $ageInYears = max(0, $currentYear - $trade->year);

        // Anchor a recent-model trade at a cautious $18,000, then taper ~22%/yr.
        $anchorInCents = 1800000;
        $retained = (0.78 ** $ageInYears);
        $baseInCents = (int) round($anchorInCents * $retained);

        return max(150000, $this->roundToFiftyDollars($baseInCents));
    }

    /** Round a cents amount to the nearest $50, matching the mockup's round50. */
    private function roundToFiftyDollars(int|float $amountInCents): int
    {
        return (int) (round($amountInCents / 5000) * 5000);
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\InspectionProgram;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * The inspection checklist a car is shown with: an active checklist that
 * applies to it and has points entered — the dealer's own, or a brand
 * checklist for the car's make when the dealer is franchised for it.
 *
 * When several apply: a feed-certified-only checklist (e.g. CPO) beats an
 * all-used-cars one, and the dealer's own beats the brand's. No checklist,
 * no badge — there's no default point count.
 */
final class VehicleCertification
{
    /** Relations to eager-load before calling for() on a list of cars. */
    public const EAGER = ['dealer.inspectionPrograms.points'];

    /** @param  array<string, array<int, string>>  $sections  section → point labels */
    private function __construct(
        public readonly string $name,
        public readonly string $badge,
        public readonly ?string $summary,
        public readonly int $pointCount,
        public readonly array $sections,
    ) {}

    public static function for(Vehicle $vehicle): ?self
    {
        $program = ($vehicle->dealer?->inspectionPrograms ?? collect())
            ->concat(self::brandPrograms())
            ->filter(fn (InspectionProgram $program) => $program->appliesTo($vehicle) && $program->points->isNotEmpty())
            ->sortBy(fn (InspectionProgram $program) => [
                $program->applies_to === InspectionProgram::APPLIES_TO_FEED_CERTIFIED ? 0 : 1,
                $program->isBrand() ? 1 : 0,
                $program->id,
            ])
            ->first();

        if ($program === null) {
            return null;
        }

        return new self(
            name: $program->name,
            badge: $program->badge(),
            summary: $program->summary,
            pointCount: $program->points->count(),
            sections: $program->points
                ->groupBy(fn ($point) => $point->section ?: 'General')
                ->map(fn ($points) => $points->pluck('label')->all())
                ->all(),
        );
    }

    /** Active brand checklists, loaded once per request. */
    private static function brandPrograms(): Collection
    {
        return once(fn () => InspectionProgram::whereNull('dealer_id')->where('is_active', true)->with('points')->get());
    }
}

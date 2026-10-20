<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\InspectionProgram;
use App\Models\Vehicle;

/**
 * The inspection checklist a car is shown with: its dealer's active program
 * that applies to it and has points entered. A feed-certified-only program
 * (e.g. manufacturer CPO) wins over an all-used-cars one. No program, no
 * badge — there's no default point count.
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
            ->filter(fn (InspectionProgram $program) => $program->appliesTo($vehicle) && $program->points->isNotEmpty())
            ->sortBy(fn (InspectionProgram $program) => [$program->applies_to === InspectionProgram::APPLIES_TO_FEED_CERTIFIED ? 0 : 1, $program->id])
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
}

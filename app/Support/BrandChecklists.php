<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Loads a manufacturer's checklist from database/data/inspection-checklists
 * (one "Section: Point" per line) as a brand checklist. Used by the migrations
 * that ship each brand; a brand that already has a checklist is left alone,
 * so edits made in the Command Centre are never overwritten.
 */
final class BrandChecklists
{
    /** @param  array{make: string, name: string, badge_label: string, summary: string, file: string}  $brand */
    public static function install(array $brand, string $appliesTo = 'feed_certified'): void
    {
        $exists = DB::table('inspection_programs')->whereNull('dealer_id')
            ->whereRaw('lower(make) = ?', [mb_strtolower($brand['make'])])->exists();

        if ($exists) {
            return;
        }

        $now = now();
        $programId = DB::table('inspection_programs')->insertGetId([
            'dealer_id'   => null,
            'make'        => $brand['make'],
            'name'        => $brand['name'],
            'badge_label' => $brand['badge_label'],
            'summary'     => $brand['summary'],
            'applies_to'  => $appliesTo,
            'is_active'   => true,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $lines = file(database_path('data/inspection-checklists/' . $brand['file']), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        DB::table('inspection_points')->insert(array_map(function (string $line, int $index) use ($programId, $now) {
            [$section, $label] = array_map('trim', explode(':', $line, 2));

            return ['inspection_program_id' => $programId, 'position' => $index + 1, 'section' => $section, 'label' => $label, 'created_at' => $now, 'updated_at' => $now];
        }, $lines, array_keys($lines)));
    }

    /** @param  array<int, string>  $makes */
    public static function remove(array $makes): void
    {
        $ids = DB::table('inspection_programs')->whereNull('dealer_id')->whereIn('make', $makes)->pluck('id');
        DB::table('inspection_points')->whereIn('inspection_program_id', $ids)->delete();
        DB::table('inspection_programs')->whereIn('id', $ids)->delete();
    }
}

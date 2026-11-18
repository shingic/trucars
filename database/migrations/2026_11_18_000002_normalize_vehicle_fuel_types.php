<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| One name per fuel across every feed (Vehicle::normalizeFuel): the GTA, BC
| and HomeNet feeds each spelled hybrids and gasoline differently, which split
| the same cars across two filter options and two fuel pages.
*/
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vehicles')->whereNotNull('fuel_type')->select(['id', 'fuel_type'])->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $fuel = \App\Models\Vehicle::normalizeFuel($row->fuel_type);
                    if ($fuel !== $row->fuel_type) {
                        DB::table('vehicles')->where('id', $row->id)->update(['fuel_type' => $fuel]);
                    }
                }
            });
    }

    public function down(): void
    {
        // The old spellings aren't kept; nothing to undo.
    }
};

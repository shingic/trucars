<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brand checklists: an inspection checklist with no dealer and a make,
     * applied automatically to that make's cars at every dealer franchised for
     * it. Ships with Nissan Canada's Certified Pre-Owned checklist (169 points),
     * shown on cars the feed marks certified.
     */
    public function up(): void
    {
        Schema::table('inspection_programs', function (Blueprint $table) {
            $table->foreignId('dealer_id')->nullable()->change();
            $table->string('make', 40)->nullable()->after('dealer_id');
        });

        $now = now();
        $programId = DB::table('inspection_programs')->insertGetId([
            'dealer_id'   => null,
            'make'        => 'Nissan',
            'name'        => 'Nissan Certified Pre-Owned',
            'badge_label' => 'Nissan CPO',
            'summary'     => 'Inspected to Nissan Canada’s Certified Pre-Owned checklist.',
            'applies_to'  => 'feed_certified',
            'is_active'   => true,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        $lines = file(database_path('data/inspection-checklists/nissan-cpo.txt'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        DB::table('inspection_points')->insert(array_map(function (string $line, int $index) use ($programId, $now) {
            [$section, $label] = array_map('trim', explode(':', $line, 2));

            return ['inspection_program_id' => $programId, 'position' => $index + 1, 'section' => $section, 'label' => $label, 'created_at' => $now, 'updated_at' => $now];
        }, $lines, array_keys($lines)));
    }

    public function down(): void
    {
        $brandIds = DB::table('inspection_programs')->whereNull('dealer_id')->pluck('id');
        DB::table('inspection_points')->whereIn('inspection_program_id', $brandIds)->delete();
        DB::table('inspection_programs')->whereIn('id', $brandIds)->delete();

        Schema::table('inspection_programs', function (Blueprint $table) {
            $table->dropColumn('make');
            $table->foreignId('dealer_id')->nullable(false)->change();
        });
    }
};

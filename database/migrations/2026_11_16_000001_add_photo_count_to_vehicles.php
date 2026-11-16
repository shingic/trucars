<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How many photos a car has, kept by the Vehicle model, so the marketplace
     * can leave out cars with too few (a placeholder or a promo banner) in SQL.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedSmallInteger('photo_count')->default(0)->after('photos');
        });

        DB::table('vehicles')->select(['id', 'photos'])->orderBy('id')->chunk(500, function ($vehicles) {
            foreach ($vehicles as $vehicle) {
                $count = count(array_filter((array) json_decode((string) $vehicle->photos, true)));
                DB::table('vehicles')->where('id', $vehicle->id)->update(['photo_count' => $count]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('photo_count');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The selling dealer as it stood at the moment of reserve — name,
     * registration number, address, contact and timezone — like fees_snapshot
     * does for fees. A later edit to the dealer profile never rewrites a
     * buyer's historical record. Null on deals made before this existed.
     */
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->json('dealer_snapshot')->nullable()->after('fees_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('dealer_snapshot');
        });
    }
};

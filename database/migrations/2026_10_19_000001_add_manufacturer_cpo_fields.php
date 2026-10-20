<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Evidence for manufacturer CPO eligibility, kept apart from the existing
     * is_certified flag. is_certified is a dev stand-in set from age/kilometres
     * in the importer — it says nothing about a manufacturer program, so dealer
     * campaigns can't rely on it. The dealer's franchise brand lives on the
     * dealer profile (add_location_profile_to_dealers_table).
     */
    public function up(): void
    {
        // True only when the inventory source flags the unit as certified AND it
        // is the dealer's own franchise brand. See ImportDealerInventory.
        Schema::table('vehicles', function (Blueprint $table) {
            $table->boolean('is_manufacturer_certified')->default(false)->after('is_certified');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('is_manufacturer_certified');
        });
    }
};

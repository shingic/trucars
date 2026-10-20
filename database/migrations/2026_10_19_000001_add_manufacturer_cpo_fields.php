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
     * campaigns can't rely on it.
     */
    public function up(): void
    {
        // The brand this rooftop is franchised for, e.g. "Mitsubishi". Set by
        // TruCars when the dealer is onboarded — never by the dealer from the
        // console — and it bounds which make can appear as manufacturer CPO.
        // Null means TruCars hasn't confirmed a franchise, so no inventory from
        // this dealer qualifies for a CPO campaign.
        Schema::table('dealers', function (Blueprint $table) {
            $table->string('franchise_make')->nullable()->after('omvic_number');
        });

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

        Schema::table('dealers', function (Blueprint $table) {
            $table->dropColumn('franchise_make');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A brand's certified pre-owned program benefits (coverage, exchange
     * privilege, etc.), typed in from the brand's own Canadian CPO page. They
     * show only on cars the feed flags manufacturer-certified at a dealer of
     * that brand, and only once switched on.
     */
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->string('cpo_program_name', 120)->nullable()->after('name');
            $table->json('cpo_benefits')->nullable()->after('cpo_program_name');
            $table->text('cpo_fine_print')->nullable()->after('cpo_benefits');
            $table->string('cpo_source_url', 500)->nullable()->after('cpo_fine_print');
            $table->date('cpo_checked_on')->nullable()->after('cpo_source_url');
            $table->boolean('cpo_benefits_live')->default(false)->after('cpo_checked_on');
        });

        // Toyota, from Toyota's certified program list. Live from the start;
        // the fine print and source link get added in the Command Centre.
        DB::table('brands')->whereRaw('lower(name) = ?', ['toyota'])->update(['cpo_benefits_live' => true, 'cpo_benefits' => json_encode([
            ['icon' => 'coverage', 'title' => 'Powertrain & roadside assistance coverage', 'detail' => 'Minimum 6 months / 10,000 km'],
            ['icon' => 'deductible', 'title' => 'Zero deductible', 'detail' => ''],
            ['icon' => 'exchange', 'title' => 'Exchange privilege*', 'detail' => '7 days / 1,500 km'],
            ['icon' => 'service', 'title' => 'Complimentary first oil & filter change**', 'detail' => ''],
            ['icon' => 'dealers', 'title' => 'Warranty honoured', 'detail' => 'At over 1,500 Toyota dealers in Canada and the U.S.'],
            ['icon' => 'recondition', 'title' => 'Extensive mechanical & appearance reconditioning', 'detail' => 'By Toyota factory-trained technicians'],
            ['icon' => 'history', 'title' => 'CARFAX Canada vehicle history report', 'detail' => 'Chronicling the entire history of the vehicle'],
            ['icon' => 'connected', 'title' => 'Toyota Connected Services trial†', 'detail' => 'Minimum 12-month trial'],
        ])]);
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn(['cpo_program_name', 'cpo_benefits', 'cpo_fine_print', 'cpo_source_url', 'cpo_checked_on', 'cpo_benefits_live']);
        });
    }
};

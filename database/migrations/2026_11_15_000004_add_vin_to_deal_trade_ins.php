<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The trade-in's VIN, when the shopper looked it up, so the dealer has it for appraisal and paperwork. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('deal_trade_ins', function (Blueprint $table) {
            $table->string('vin', 17)->nullable()->after('trim');
        });
    }

    public function down(): void
    {
        Schema::table('deal_trade_ins', function (Blueprint $table) {
            $table->dropColumn('vin');
        });
    }
};

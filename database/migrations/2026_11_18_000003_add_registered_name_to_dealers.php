<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Dealer advertising rules (OMVIC, VSA, AMVIC): every listing names the
| dealer as registered with its regulator, which can differ from the name it
| trades under ("… Ltd."). Empty means the trading name is the registered one.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            $table->string('registered_name')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('dealers', fn (Blueprint $table) => $table->dropColumn('registered_name'));
    }
};

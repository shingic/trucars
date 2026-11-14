<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Coverage plans ("Pick your safety net") are entered at their full price,
 * e.g. $3,000. Checkout rolls that into the loan and shows the biweekly
 * amount, so nobody has to work it out and type it in. Any price already
 * entered (default or dealer override) is kept and now read as the full price.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('protection_products')
            ->where('kind', 'plan')
            ->update(['price_basis' => 'one_time', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('protection_products')
            ->where('kind', 'plan')
            ->update(['price_basis' => 'biweekly', 'updated_at' => now()]);
    }
};

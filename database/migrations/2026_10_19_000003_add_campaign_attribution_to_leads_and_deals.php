<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campaign attribution on the records a referred shopper produces. campaign_id
     * drives the console results; attribution keeps the touch as it landed
     * (source + whichever UTM parameters were present) so it survives later
     * edits to the campaign. Both null for organic marketplace shoppers.
     */
    public function up(): void
    {
        foreach (['leads', 'deals'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('campaign_id')->nullable()->after('dealer_id')->constrained()->nullOnDelete();
                $table->json('attribution')->nullable()->after('campaign_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['leads', 'deals'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('campaign_id');
                $table->dropColumn('attribution');
            });
        }
    }
};

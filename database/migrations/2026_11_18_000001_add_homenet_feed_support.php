<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| HomeNet feeds: fees the dealer leaves out of its feed price (added on top so
| every price is all-in), brand certified-program age/km rules, cars the
| dealer certifies at pickup, and where each store's feed file lives.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealer_fees', function (Blueprint $table) {
            $table->boolean('added_to_feed_price')->default(false)->after('kind');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('feed_price_in_cents')->nullable()->after('price_in_cents');
            $table->boolean('certify_at_pickup')->default(false)->after('is_manufacturer_certified');
        });

        Schema::table('dealers', function (Blueprint $table) {
            $table->boolean('certifies_at_pickup')->default(false)->after('brand_id');
        });

        Schema::table('brands', function (Blueprint $table) {
            $table->unsignedTinyInteger('cpo_max_model_years')->nullable()->after('cpo_program_name');
            $table->unsignedInteger('cpo_max_km')->nullable()->after('cpo_max_model_years');
        });

        Schema::table('rooftops', function (Blueprint $table) {
            $table->string('feed_file')->nullable()->after('feed_rooftop_id');
            $table->timestamp('feed_file_modified_at')->nullable()->after('feed_file');
            $table->timestamp('feed_synced_at')->nullable()->after('feed_file_modified_at');
            $table->string('feed_error', 500)->nullable()->after('feed_synced_at');
            $table->timestamp('feed_stale_alerted_at')->nullable()->after('feed_error');
        });

        // Canadian certified-program limits, from each brand's published terms
        // (dealer and guide pages, Nov 2026). Editable under Brands; a brand
        // without them only counts cars the feed flags certified.
        $rules = [
            'Mazda' => [6, 120000], 'Kia' => [6, 120000], 'Hyundai' => [6, 120000],
            'Honda' => [5, 150000],
            'Chevrolet' => [6, 120000], 'Buick' => [6, 120000], 'GMC' => [6, 120000],
            'Chrysler' => [6, 180000], 'Dodge' => [6, 180000], 'Jeep' => [6, 180000], 'Ram' => [6, 180000],
        ];
        foreach ($rules as $name => [$years, $km]) {
            DB::table('brands')->where('name', $name)->update(['cpo_max_model_years' => $years, 'cpo_max_km' => $km]);
        }
    }

    public function down(): void
    {
        Schema::table('rooftops', fn (Blueprint $table) => $table->dropColumn(['feed_file', 'feed_file_modified_at', 'feed_synced_at', 'feed_error', 'feed_stale_alerted_at']));
        Schema::table('brands', fn (Blueprint $table) => $table->dropColumn(['cpo_max_model_years', 'cpo_max_km']));
        Schema::table('dealers', fn (Blueprint $table) => $table->dropColumn('certifies_at_pickup'));
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropColumn(['feed_price_in_cents', 'certify_at_pickup']));
        Schema::table('dealer_fees', fn (Blueprint $table) => $table->dropColumn('added_to_feed_price'));
    }
};

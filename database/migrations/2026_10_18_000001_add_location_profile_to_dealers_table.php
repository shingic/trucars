<?php

use App\Support\CanadianAddress;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One dealer = one selling rooftop at one physical address. Every vehicle
     * on the dealer is at that address, so location lives here and nowhere else.
     *
     * Existing rows are preserved. Nothing is invented for them: the only data
     * change is splitting a legacy "City, PR" value the importer wrote into its
     * city and province parts. Whatever is still missing is reported by
     * `php artisan dealers:needs-backfill`.
     */
    public function up(): void
    {
        // OMVIC is Ontario's regulator; BC dealers register with the VSA, Alberta
        // with AMVIC. The number is the same idea everywhere, so the column is
        // renamed to say so. Values carry over untouched.
        Schema::table('dealers', function (Blueprint $table) {
            $table->renameColumn('omvic_number', 'registration_number');
        });

        Schema::table('dealers', function (Blueprint $table) {
            // The inventory feed's stable rooftop id. Imports match on this, never
            // on the display name, so two rooftops called "Downtown Honda" stay apart.
            $table->string('feed_rooftop_id')->nullable()->unique()->after('id');

            // The brand this rooftop is franchised for, e.g. "Mitsubishi". Set by
            // a TruCars admin, never by the dealer.
            $table->string('franchise_make')->nullable()->after('registration_number');

            // Structured address. Province is the two-letter code, postal code is
            // "A1A 1A1", country is ISO 3166-1 alpha-2 (Canada only for now).
            $table->string('street_address')->nullable()->after('name');
            $table->string('unit', 30)->nullable()->after('street_address');
            $table->string('province', 2)->nullable()->after('city');
            $table->string('postal_code', 7)->nullable()->after('province');
            $table->string('country', 2)->nullable()->after('postal_code');

            // Optional until a geocoder is chosen; taken from the feed when it has them.
            $table->decimal('latitude', 10, 7)->nullable()->after('country');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            // IANA zone (e.g. America/Vancouver) for dealer-local hours and
            // handover windows. Never a fixed offset. Timestamps stay UTC.
            $table->string('timezone', 64)->nullable()->after('longitude');

            // Public contact details shown to shoppers.
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // 'draft' dealers are being set up and their cars aren't listed;
            // 'active' dealers are live on the marketplace.
            $table->string('status')->default('draft');
        });

        // Every dealer that exists today is already live — keep it that way.
        DB::table('dealers')->update(['status' => 'active']);

        DB::table('dealers')->orderBy('id')->each(function (object $dealer) {
            if (! preg_match('/^(.+),\s*([A-Za-z.]{2,4})$/', (string) $dealer->city, $parts)) {
                return;
            }

            $province = CanadianAddress::normalizeProvince($parts[2]);

            if ($province !== null) {
                DB::table('dealers')->where('id', $dealer->id)->update([
                    'city'     => trim($parts[1]),
                    'province' => $province,
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('dealers')->whereNotNull('province')->orderBy('id')->each(function (object $dealer) {
            DB::table('dealers')->where('id', $dealer->id)->update(['city' => "{$dealer->city}, {$dealer->province}"]);
        });

        Schema::table('dealers', function (Blueprint $table) {
            $table->dropUnique(['feed_rooftop_id']);
            $table->dropColumn([
                'feed_rooftop_id', 'franchise_make', 'street_address', 'unit', 'province', 'postal_code',
                'country', 'latitude', 'longitude', 'timezone', 'phone', 'email', 'website', 'status',
            ]);
        });

        Schema::table('dealers', function (Blueprint $table) {
            $table->renameColumn('registration_number', 'omvic_number');
        });
    }
};

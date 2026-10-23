<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits where a car physically is (the rooftop) from who sells it (the
 * dealer), and lets dealers be gathered into a group.
 *
 * Additive only. Every existing dealer gets one primary rooftop copied from
 * its current address and feed rooftop id, and every vehicle and reservation
 * points at that rooftop. dealer_id, the dealer's own columns and all URLs stay
 * exactly as they were.
 */
return new class extends Migration
{
    private const LOCATION = [
        'street_address', 'unit', 'city', 'province', 'postal_code', 'country',
        'latitude', 'longitude', 'timezone', 'phone', 'email',
    ];

    public function up(): void
    {
        Schema::create('dealer_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('dealers', function (Blueprint $table) {
            $table->foreignId('dealer_group_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::create('rooftops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();

            // Null on the primary rooftop: it goes by the dealer's name.
            $table->string('name')->nullable();

            // The primary rooftop mirrors the dealer's own address columns;
            // any others are edited on their own.
            $table->boolean('is_primary')->default(false);

            // The inventory feed's id for this store. Imports match on it.
            $table->string('feed_rooftop_id')->nullable()->unique();

            $table->string('street_address')->nullable();
            $table->string('unit', 30)->nullable();
            $table->string('city')->nullable();
            $table->string('province', 2)->nullable();
            $table->string('postal_code', 7)->nullable();
            $table->string('country', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();

            // A closed store's cars come off the marketplace.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['dealer_id', 'is_primary']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('rooftop_id')->nullable()->after('dealer_id')->constrained()->nullOnDelete();
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('rooftop_id')->nullable()->after('dealer_id')->constrained()->nullOnDelete();

            // The store as it stood at reserve, beside dealer_snapshot.
            $table->json('rooftop_snapshot')->nullable()->after('dealer_snapshot');
        });

        DB::table('dealers')->orderBy('id')->each(function (object $dealer) {
            $rooftopId = DB::table('rooftops')->insertGetId(
                collect(self::LOCATION)->mapWithKeys(fn (string $column) => [$column => $dealer->{$column}])->all() + [
                    'dealer_id'       => $dealer->id,
                    'is_primary'      => true,
                    'feed_rooftop_id' => $dealer->feed_rooftop_id,
                    'is_active'       => true,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
            );

            DB::table('vehicles')->where('dealer_id', $dealer->id)->update(['rooftop_id' => $rooftopId]);
            DB::table('deals')->where('dealer_id', $dealer->id)->update(['rooftop_id' => $rooftopId]);
        });

        // Older reservations already froze the address in dealer_snapshot, so
        // the rooftop snapshot is copied from there, not from today's profile.
        DB::table('deals')->whereNotNull('dealer_snapshot')->orderBy('id')->each(function (object $deal) {
            $seller = json_decode($deal->dealer_snapshot, true) ?: [];

            DB::table('deals')->where('id', $deal->id)->update(['rooftop_snapshot' => json_encode(
                ['id' => $deal->rooftop_id, 'name' => $seller['name'] ?? null]
                + array_intersect_key($seller, array_flip([...self::LOCATION, 'captured_at'])),
            )]);
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rooftop_id');
            $table->dropColumn('rooftop_snapshot');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rooftop_id');
        });

        Schema::dropIfExists('rooftops');

        Schema::table('dealers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dealer_group_id');
        });

        Schema::dropIfExists('dealer_groups');
    }
};

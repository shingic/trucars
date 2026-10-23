<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store-level access, opening hours, extra pickup points and single-store
 * campaigns. Additive: nothing existing changes meaning.
 *
 * - rooftop_user: the stores a staff member is limited to. No rows means
 *   every store of their own dealer, exactly as before. Rows at another
 *   dealer only count when both dealers are in the same group.
 * - rooftops.hours: per weekday [open, close] in the store's timezone, or
 *   null for closed. A null column means hours aren't known, and nothing is
 *   filtered by them.
 * - rooftops.pickup_points: extra places a buyer can collect a car.
 * - campaigns.rooftop_id: null is every store of the dealer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooftop_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rooftop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['rooftop_id', 'user_id']);
        });

        Schema::table('rooftops', function (Blueprint $table) {
            $table->json('hours')->nullable()->after('email');
            $table->json('pickup_points')->nullable()->after('hours');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('rooftop_id')->nullable()->after('dealer_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rooftop_id');
        });

        Schema::table('rooftops', function (Blueprint $table) {
            $table->dropColumn(['hours', 'pickup_points']);
        });

        Schema::dropIfExists('rooftop_user');
    }
};

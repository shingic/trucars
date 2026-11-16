<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Just listed" and "Price dropped" tags, and a daily count of who looked at
 * each car (one row per visitor per car per day, for a future "popular" tag).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->timestamp('first_listed_at')->nullable();
            $table->timestamp('price_dropped_at')->nullable();
            $table->unsignedBigInteger('price_before_drop_in_cents')->nullable();
        });

        Schema::create('vehicle_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->char('visitor', 40);
            $table->date('viewed_on');
            $table->timestamp('created_at')->nullable();
            $table->unique(['vehicle_id', 'visitor', 'viewed_on']);
            $table->index(['viewed_on', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_views');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['first_listed_at', 'price_dropped_at', 'price_before_drop_in_cents']);
        });
    }
};

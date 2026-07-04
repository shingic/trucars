<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buyer favourites — the cars a signed-in buyer has saved.
     *
     * This is the join between a buyer and the vehicles they've hearted. It is
     * intentionally a plain pivot with no dealer_id: favourites are buyer-owned
     * and cross-dealership, the same stance taken on deals. Dealer staff never
     * favourite, so nothing here is dealer-scoped.
     *
     * The unique pair keeps a buyer from saving the same car twice and lets the
     * toggle stay idempotent. Both foreign keys cascade on delete so favourites
     * disappear cleanly when either the buyer or the vehicle is removed.
     */
    public function up(): void
    {
        Schema::create('favourites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favourites');
    }
};

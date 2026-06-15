<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city');
            $table->string('omvic_number')->nullable();

            // Which valuation provider supplies the BASE value for this dealer's trade estimates.
            // 'conservative' = the in-house stub; vendor keys (carfax, vauto, cbb) drop in later.
            $table->string('valuation_provider')->default('conservative');

            // Vendor credentials / account identifiers, set via superadmin later. Never consumer-facing.
            $table->json('valuation_provider_config')->nullable();

            $table->timestamps();
        });

        // Per-dealer fee schedule that feeds the checkout breakdown.
        //
        // Under OMVIC all-in pricing the advertised price already contains the
        // dealer's own costs (freight, PDI, admin), so those are tagged
        // 'included' — disclosed as already inside the price, never added on top.
        // Only at-cost government charges (licensing, registration) are tagged
        // 'pass_through' and added at delivery. The kind decides which side of
        // the all-in line a fee sits on; nothing here ever moves the headline
        // price or the biweekly estimate for an 'included' fee.
        //
        // Lives in this migration (not a separate file) because it's dealer-owned
        // config and the dealers table it references is created just above, so the
        // foreign key resolves in order.
        Schema::create('dealer_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();

            // What the buyer sees on the breakdown line, e.g. "Freight & PDI".
            $table->string('label');

            // 'included'    — already inside the all-in price (adds nothing).
            // 'pass_through' — at-cost charge added at delivery.
            $table->string('kind')->default('included');

            // Stored in cents to match the rest of the schema (deposit_in_cents,
            // price_in_cents, …). The old hardcoded baseFees were whole dollars;
            // cents is the house style going forward.
            $table->unsignedInteger('amount_in_cents')->default(0);

            // Display order within its group on the breakdown and settings panel.
            $table->unsignedSmallInteger('sort_order')->default(0);

            // A dealer can switch a fee off without deleting it; inactive fees
            // drop out of the checkout breakdown and the reserve snapshot.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['dealer_id', 'sort_order']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('dealer_id')
                ->references('id')
                ->on('dealers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['dealer_id']);
        });

        Schema::dropIfExists('dealer_fees');
        Schema::dropIfExists('dealers');
    }
};

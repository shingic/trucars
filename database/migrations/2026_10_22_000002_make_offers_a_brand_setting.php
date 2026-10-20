<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offers become a brand setting: each offer belongs to a brand and applies
     * to that brand's eligible cars at every one of its dealers. Dealer opt-ins
     * and dealer-funded promotions are gone, so the first version's tables are
     * replaced. (deals.offers_snapshot is kept.)
     */
    public function up(): void
    {
        Schema::dropIfExists('dealer_offer');
        Schema::dropIfExists('offers');

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);   // apr | cash | warranty
            $table->string('title', 120);
            $table->json('models')->nullable();
            $table->unsignedSmallInteger('year_min')->nullable();
            $table->unsignedSmallInteger('year_max')->nullable();
            $table->json('provinces')->nullable();
            $table->boolean('certified_only')->default(true);
            $table->decimal('apr', 5, 2)->nullable();
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->string('lender', 120)->nullable();
            $table->unsignedInteger('cash_amount_in_cents')->nullable();
            $table->string('benefit', 200)->nullable();
            $table->text('conditions');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('combinable')->default(false);
            $table->string('status', 20)->default('draft'); // draft | published | archived
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['brand_id', 'status', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        // The first version's offers can't be recreated from brand offers.
        Schema::dropIfExists('offers');
    }
};

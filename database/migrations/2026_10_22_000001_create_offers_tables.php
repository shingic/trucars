<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offers & Incentives.
     *
     *   offers          manufacturer programs (managed by TruCars) and
     *                   dealer-funded promotions (approved by TruCars), each
     *                   with its eligibility, dates and conditions
     *   dealer_offer    dealers who opted in to a manufacturer offer
     *   deals.offers_snapshot   the offers, exactly as shown, when a customer reserved
     */
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20); // manufacturer | dealer
            $table->foreignId('dealer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20);   // apr | cash | warranty
            $table->string('title', 120);
            $table->string('make', 40);
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
            $table->boolean('requires_opt_in')->default(true);
            $table->boolean('combinable')->default(false);
            $table->string('status', 20)->default('draft'); // draft | pending | published | rejected | archived
            $table->string('review_note', 255)->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'starts_on', 'ends_on']);
        });

        Schema::create('dealer_offer', function (Blueprint $table) {
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opted_in_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->primary(['offer_id', 'dealer_id']);
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->json('offers_snapshot')->nullable()->after('fees_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('offers_snapshot');
        });
        Schema::dropIfExists('dealer_offer');
        Schema::dropIfExists('offers');
    }
};

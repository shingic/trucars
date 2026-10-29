<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Each lender's decision on a reservation's credit application: from
| Dealertrack, which sends decisions back on its own, or typed in by the
| finance office for a lender outside it. The dealership publishes one offer,
| which copies its terms onto the deal (the financing_* columns My Garage
| reads) and emails the buyer.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lender_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('external_id', 120)->nullable();
            $table->string('lender', 120);
            $table->string('decision', 20);
            $table->decimal('apr', 5, 2)->nullable();
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->unsignedInteger('payment_in_cents')->nullable();
            $table->string('frequency', 20)->nullable();
            $table->unsignedInteger('amount_in_cents')->nullable();
            $table->unsignedInteger('down_payment_in_cents')->nullable();
            $table->string('conditions', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['deal_id', 'source', 'external_id'], 'lender_offers_source_unique');
        });

        if (! Schema::hasColumn('deals', 'financing_conditions')) {
            Schema::table('deals', function (Blueprint $table) {
                $table->string('financing_conditions', 500)->nullable()->after('financing_first_payment_on');
            });
        }
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('financing_conditions');
        });
        Schema::dropIfExists('lender_offers');
    }
};

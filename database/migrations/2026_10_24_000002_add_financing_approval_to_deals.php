<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lender's approval as it came back to the dealership's finance office,
 * entered by the dealer and shown to the buyer in My Garage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->string('financing_lender', 120)->nullable();
            $table->decimal('financing_apr', 5, 2)->nullable();
            $table->unsignedSmallInteger('financing_term_months')->nullable();
            $table->unsignedInteger('financing_payment_in_cents')->nullable();
            $table->string('financing_frequency', 20)->nullable();
            $table->unsignedInteger('financing_amount_in_cents')->nullable();
            $table->unsignedInteger('financing_down_payment_in_cents')->nullable();
            $table->date('financing_first_payment_on')->nullable();
            $table->timestamp('financing_approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn([
                'financing_lender', 'financing_apr', 'financing_term_months', 'financing_payment_in_cents', 'financing_frequency',
                'financing_amount_in_cents', 'financing_down_payment_in_cents', 'financing_first_payment_on', 'financing_approved_at',
            ]);
        });
    }
};

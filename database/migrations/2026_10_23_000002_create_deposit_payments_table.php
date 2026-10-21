<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per $150 deposit attempt — one Stripe PaymentIntent. It's
        // created when the buyer reaches the Reserve step and gets its deal_id
        // once the payment is confirmed and the reservation is made. A paid
        // row that never gets a deal is refunded (deposits:reconcile).
        Schema::create('deposit_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deal_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('stripe_payment_intent_id')->unique();
            $table->unsignedInteger('amount_in_cents');
            $table->string('currency', 3);
            // pending → paid → (refunded); pending → canceled.
            $table->string('status')->default('pending');
            $table->string('stripe_refund_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'deal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_payments');
    }
};

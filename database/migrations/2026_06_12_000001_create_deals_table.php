<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();

            // Ownership. dealer_id is the tenant scope (DealerScope); user_id is
            // the buyer account that placed the reservation. user_id is nullable
            // so dealer-created walk-in deals and seed data don't require an
            // account — the consumer checkout always sets it.
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();

            // Phone-friendly public reference (TL-XXXXX), set on creating().
            $table->string('reference')->unique();

            // Pipeline stage — see Deal::STAGE_LABELS.
            $table->string('stage')->default('reserved');

            // Plan & payment (estimate-only; the dealer's F&I office confirms).
            $table->string('purchase_type')->default('finance');
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->unsignedInteger('down_payment_in_cents')->nullable();
            $table->string('warranty_plan')->nullable();

            // Stackable coverage the buyer flagged interest in (GAP + add-on
            // keys). Non-binding interest only — the dealer's F&I office prices
            // and confirms it; nothing here is tied to the sale.
            $table->json('extras_interest')->nullable();

            // The dealer's fee schedule frozen at the moment of reserve, so later
            // changes in the console never rewrite a buyer's agreed numbers. Each
            // entry mirrors a dealer_fees row: { label, kind, amount_in_cents }.
            // 'included' fees are disclosed as inside the all-in price; 'pass_through'
            // fees are the at-cost charges added at delivery. Nullable so older
            // rows and seed data without a snapshot still load cleanly.
            $table->json('fees_snapshot')->nullable();

            // Refundable $150 hold, credited to the purchase price.
            $table->unsignedInteger('deposit_in_cents')->default(15000);
            $table->string('deposit_status')->default('held');

            // Buyer contact + address.
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone');
            $table->string('street_address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('postal_code')->nullable();

            // Handover — how and when the car changes hands. handover_mode is
            // 'pickup' (collect from a named hub) or 'delivery' (brought to the
            // buyer); pickup_location holds the hub name or the delivery summary;
            // pickup_at is the requested day + time window as one timestamp. The
            // dealership confirms the exact time with the buyer.
            $table->string('handover_mode')->default('pickup');
            $table->string('pickup_location')->nullable();
            $table->timestamp('pickup_at')->nullable();

            // Identity verification (Persona / Paays) — stamped at reserve.
            $table->timestamp('identity_verified_at')->nullable();

            $table->timestamps();

            $table->index(['dealer_id', 'stage']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};

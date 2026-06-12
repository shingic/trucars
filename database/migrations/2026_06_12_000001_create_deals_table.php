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
            $table->foreignId('dealer_id')->constrained();
            $table->foreignId('vehicle_id')->constrained();
            $table->string('reference')->unique();

            $table->string('stage')->default('reserved');

            $table->string('purchase_type'); // finance | cash
            $table->unsignedSmallInteger('term_months')->nullable();
            $table->unsignedInteger('down_payment_in_cents')->nullable();
            $table->string('warranty_plan')->nullable();

            $table->unsignedInteger('deposit_in_cents')->default(15000);
            $table->string('deposit_status')->default('held'); // held | refunded | credited

            $table->string('first_name');
            $table->string('last_name');
            $table->string('email');
            $table->string('phone');
            $table->string('street_address');
            $table->string('city');
            $table->string('province');
            $table->string('postal_code');

            $table->timestamp('identity_verified_at')->nullable();

            $table->timestamps();

            $table->index(['dealer_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};

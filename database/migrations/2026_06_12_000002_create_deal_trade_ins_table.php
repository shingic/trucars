<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_trade_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();

            // --- Vehicle basics ---
            $table->integer('model_year');
            $table->string('make');
            $table->string('model');
            $table->string('trim')->nullable();
            $table->integer('kilometres');
            $table->string('condition');
            $table->bigInteger('lien_owing_in_cents')->default(0);
            $table->text('customer_notes')->nullable();

            // --- Questionnaire: details sub-step (self-reported by the customer) ---
            $table->string('exterior_colour')->nullable();
            $table->unsignedTinyInteger('key_count')->nullable();
            $table->json('features')->nullable();

            // --- Questionnaire: condition sub-step ---
            $table->string('exterior_condition')->nullable();
            $table->string('interior_condition')->nullable();
            $table->string('tire_condition')->nullable();
            $table->string('mechanical_condition')->nullable();
            $table->string('accident_history')->nullable();
            $table->string('owner_count')->nullable();
            $table->string('title_status')->nullable();
            $table->boolean('was_smoked_in')->nullable();
            $table->boolean('carried_pets')->nullable();
            $table->boolean('has_aftermarket_mods')->nullable();

            // --- Saved estimate (preliminary, non-binding; dealer confirms on inspection) ---
            $table->bigInteger('estimated_value_in_cents')->nullable();
            $table->bigInteger('estimated_value_low_in_cents')->nullable();
            $table->bigInteger('estimated_value_high_in_cents')->nullable();
            $table->json('valuation_breakdown')->nullable();
            $table->string('valuation_provider')->nullable();
            $table->timestamp('valuated_at')->nullable();
            $table->boolean('estimate_is_binding')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_trade_ins');
    }
};

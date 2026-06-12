<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();

            $table->string('vin', 17)->unique();
            $table->unsignedSmallInteger('model_year');
            $table->string('make');
            $table->string('model');
            $table->string('trim')->nullable();
            $table->string('body_type')->nullable();
            $table->string('colour')->nullable();
            $table->unsignedInteger('kilometres');
            $table->unsignedInteger('price_in_cents');
            $table->boolean('is_published')->default(false);

            // Extra fields the real dealer feed gives us.
            $table->string('stock_number')->nullable();
            $table->string('condition')->default('USED'); // NEW or USED
            $table->boolean('is_certified')->default(false);
            $table->string('transmission')->nullable();
            $table->string('drivetrain')->nullable();
            $table->string('fuel_type')->nullable();
            $table->json('photos')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};

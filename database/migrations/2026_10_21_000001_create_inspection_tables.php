<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inspection checklists TruCars sets up per dealer: a named program (e.g.
     * "Mitsubishi Diamond Certified") and its points, grouped by section. The
     * point count shown to shoppers is simply how many points are entered —
     * there's no universal "150-point" number.
     */
    public function up(): void
    {
        Schema::create('inspection_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('badge_label', 60)->nullable();
            $table->text('summary')->nullable();
            $table->string('applies_to', 20)->default('all_used'); // all_used | feed_certified
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('inspection_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inspection_program_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('section', 80)->nullable();
            $table->string('label', 200);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_points');
        Schema::dropIfExists('inspection_programs');
    }
};

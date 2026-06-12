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

            $table->unsignedSmallInteger('model_year');
            $table->string('make');
            $table->string('model');
            $table->string('trim')->nullable();
            $table->unsignedInteger('kilometres');
            $table->string('condition'); // excellent | good | fair | poor
            $table->unsignedInteger('lien_owing_in_cents')->default(0);
            $table->text('customer_notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_trade_ins');
    }
};

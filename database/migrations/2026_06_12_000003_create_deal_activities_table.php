<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();

            $table->string('kind'); // system | status | sms | email | note
            $table->string('direction')->nullable(); // inbound | outbound
            $table->text('body');
            $table->string('author_name')->nullable(); // null = Trueleads system

            $table->timestamps();

            $table->index(['deal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_activities');
    }
};

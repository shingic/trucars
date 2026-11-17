<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The contact form lands in the Command Centre's Inquiries, next to questions
 * about cars. A contact message isn't about one car or dealer, so both become
 * optional, and the shopper's chosen topic is kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('vehicle_id')->nullable()->change();
            $table->foreignId('dealer_id')->nullable()->change();
            $table->string('topic')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('topic');
        });
    }
};

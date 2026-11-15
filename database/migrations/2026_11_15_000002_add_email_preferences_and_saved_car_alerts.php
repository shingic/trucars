<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which marketing email types a shopper switched off ({"off": [...]}).
        // Empty = everything on. marketing_unsubscribed_at stays the "all off" switch.
        Schema::table('users', function (Blueprint $table) {
            $table->json('email_preferences')->nullable()->after('marketing_unsubscribed_at');
        });

        // What each saved car has already been emailed about, so nothing goes twice.
        Schema::table('favourites', function (Blueprint $table) {
            $table->unsignedInteger('alerted_price_in_cents')->nullable(); // the price last told about (or seen at save)
            $table->timestamp('gone_alerted_at')->nullable();              // "reserved by someone else / sold" sent
            $table->timestamp('nudged_at')->nullable();                    // "still thinking?" sent
        });
    }

    public function down(): void
    {
        Schema::table('favourites', function (Blueprint $table) {
            $table->dropColumn(['alerted_price_in_cents', 'gone_alerted_at', 'nudged_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_preferences');
        });
    }
};

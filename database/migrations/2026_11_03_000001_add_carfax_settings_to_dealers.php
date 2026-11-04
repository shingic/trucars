<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            // The dealership's own CARFAX Canada account. Its reports are the
            // ones shown on its vehicle pages. Off until an admin switches it on.
            $table->boolean('carfax_enabled')->default(false)->after('cover_photo_path');
            $table->string('carfax_account_id', 64)->nullable()->after('carfax_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            $table->dropColumn(['carfax_enabled', 'carfax_account_id']);
        });
    }
};

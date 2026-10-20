<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A storefront photo for the dealer's profile header. Stored on the local disk, served by a route. */
    public function up(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            $table->string('cover_photo_path')->nullable()->after('website');
        });
    }

    public function down(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            $table->dropColumn('cover_photo_path');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who an account is, stated rather than inferred from dealer_id:
     *   customer     — shops on the marketplace; sees only their own account
     *   dealer_staff — works one dealer's portal; sees only that dealer's data
     *   owner        — TruCars admin; manages dealers and dealer users
     *
     * Public registration only ever makes customers (role isn't mass-assignable);
     * dealer staff join through an owner's invitation; owners are made from the
     * console. suspended_at blocks sign-in on every portal without deleting.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('customer')->after('dealer_id');
            $table->timestamp('suspended_at')->nullable()->after('role');
            $table->index('role');
        });

        // Today a staff account is exactly "has a dealer_id".
        DB::table('users')->whereNotNull('dealer_id')->update(['role' => 'dealer_staff']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'suspended_at']);
        });
    }
};

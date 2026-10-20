<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Command Centre (admin host) access for TruCars' own people.
     *
     *  - users.permissions: the explicit permission keys an internal_staff
     *    account holds (see App\Support\Permission). Owners hold every
     *    permission implicitly; customers and dealer staff hold none.
     *  - staff_invitations: same one-time hashed-token pattern as
     *    dealer_invitations, carrying the permissions the invitee will get.
     *  - audit_logs: append-only record of sensitive actions — who, what, on
     *    which record, before/after, from where. Never updated or deleted by
     *    the app.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permissions')->nullable()->after('role');
        });

        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->json('permissions');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('email');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Who. Kept as a snapshot too, so the entry still reads right after
            // the account is renamed or removed. Null actor = console/system.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_email')->nullable();
            $table->string('actor_role')->nullable();

            // What, e.g. "dealer.status_changed", "staff.permissions_changed".
            $table->string('action');

            // On which record.
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('target_label')->nullable();

            $table->json('before')->nullable();
            $table->json('after')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_type', 'target_id']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('staff_invitations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An owner's invitation for someone to join a dealer's portal. Only a
     * SHA-256 of the token is stored; the plain token exists once, in the email.
     * The account itself is created when the invite is accepted, with the
     * password the invitee chooses — no owner ever sets or sees it.
     */
    public function up(): void
    {
        Schema::create('dealer_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['dealer_id', 'accepted_at']);
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_invitations');
    }
};

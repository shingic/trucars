<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per ID check: a Didit session, or a demo approval. Tied to
        // the buyer, not a car, so an approved buyer isn't asked again. Only
        // the few fields staff need are kept — the scans stay with Didit.
        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');                 // didit | demo
            $table->string('session_id')->unique();
            $table->string('session_url', 500)->nullable();
            $table->string('status');                   // Didit's status, e.g. "Approved"
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('document_type')->nullable();
            $table->date('document_expires_on')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider']);
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('identity_verification_id')->nullable()->after('identity_verified_at')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('identity_verification_id');
        });

        Schema::dropIfExists('identity_verifications');
    }
};

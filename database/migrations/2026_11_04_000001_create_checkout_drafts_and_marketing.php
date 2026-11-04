<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A checkout a shopper started but hasn't paid the deposit on. It belongs
        // to the platform, never a dealer: only the Command Centre sees it.
        Schema::create('checkout_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('step_key', 20);
            $table->json('data');                          // everything entered so far
            $table->json('shopper_location')->nullable();  // where they were shopping from
            $table->string('status', 20)->default('active'); // active | reserved | car_gone
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->text('staff_note')->nullable();
            $table->timestamp('last_active_at');
            $table->timestamps();

            $table->unique(['user_id', 'vehicle_id']);
            $table->index(['status', 'last_active_at']);
        });

        // Every marketing email sent, so nothing goes twice and staff see the history.
        Schema::create('marketing_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checkout_draft_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 40); // recovery_1h | recovery_1d | recovery_3d | recovery_7d
            $table->timestamp('sent_at');
            $table->index(['user_id', 'sent_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('marketing_unsubscribed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('marketing_unsubscribed_at'));
        Schema::dropIfExists('marketing_emails');
        Schema::dropIfExists('checkout_drafts');
    }
};

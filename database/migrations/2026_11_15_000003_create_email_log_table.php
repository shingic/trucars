<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every email the platform sends, by type (App\Support\Email\Catalogue),
        // for the Command Centre's Email Centre. Kept 12 months (email-log:prune).
        Schema::create('email_log', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_email');
            $table->string('subject', 300)->nullable();
            $table->timestamp('sent_at');

            $table->index(['type', 'sent_at']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_log');
    }
};

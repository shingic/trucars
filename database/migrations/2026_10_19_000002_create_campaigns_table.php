<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A dealer's trackable TruCars link, placed by the dealer in their own
        // ads or posts. The token is the only thing a visitor's URL carries —
        // the dealer and destination are always read back from this row.
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            // Tenant scope (DealerScope).
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // Where the dealer places the link — see Campaign::SOURCES.
            $table->string('source');

            // 'vehicle' (one car) or 'cpo_inventory' (the dealer's eligible CPO stock).
            $table->string('destination_type');

            // Only for 'vehicle' campaigns. Null-on-delete so a removed car
            // falls back to the dealer's CPO inventory instead of breaking the link.
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();

            // draft → active ⇄ paused. Only active links resolve publicly.
            $table->string('status')->default('draft');

            // Unguessable public token used in /shop/{token}.
            $table->string('token', 32)->unique();

            // Landings, counted once per shopping session.
            $table->unsignedInteger('visits_count')->default(0);

            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->index(['dealer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};

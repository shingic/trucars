<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_documents', function (Blueprint $table) {
            $table->id();

            // Owning deal. Documents live and die with the deal, so cascade on
            // delete. Tenant scoping is inherited through the deal (DealerScope
            // lives on Deal); deal_documents has no dealer_id of its own.
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();

            // Stable machine key for the canonical four — see
            // DealDocument::blueprintFor(). Used by the auto-verify wiring
            // (driver's licence flips done when identity is verified).
            $table->string('slug');

            // Buyer-facing label and the status copy shown under it in the
            // My Garage documents card.
            $table->string('name');
            $table->string('status');

            // Done = check + "Done"; pending = dashed circle + Upload button.
            $table->boolean('is_done')->default(false);

            // Display order in the documents card (licence first, void cheque
            // last) so the list never re-shuffles between reads.
            $table->unsignedTinyInteger('sort_order')->default(0);

            // Where an uploaded file lands once the buyer hits Upload, and when.
            // Null until they upload — the canonical four are created up front
            // as placeholders so the buyer always sees the full checklist.
            $table->string('file_path')->nullable();
            $table->timestamp('uploaded_at')->nullable();

            $table->timestamps();

            // One row per document type per deal — the seeder and any future
            // re-sync rely on this to stay idempotent.
            $table->unique(['deal_id', 'slug']);
            $table->index(['deal_id', 'is_done']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_documents');
    }
};

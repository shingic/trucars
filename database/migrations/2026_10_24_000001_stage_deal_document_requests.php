<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents are asked for in two rounds: pay stubs and a void cheque right
 * after the reservation, proof of insurance once the deal is finalized.
 * requested_at marks when the buyer was asked (null = not yet), and
 * documents_reminded_at stops the reminder email going out twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_documents', function (Blueprint $table) {
            $table->timestamp('requested_at')->nullable()->after('sort_order');
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->timestamp('documents_reminded_at')->nullable();
        });

        DB::table('deal_documents')->where('slug', '!=', 'proof_of_insurance')->update(['requested_at' => DB::raw('created_at')]);

        $finalized = DB::table('deals')->whereIn('stage', ['documents', 'ready_for_delivery', 'delivered'])->pluck('id');
        DB::table('deal_documents')->where('slug', 'proof_of_insurance')->whereIn('deal_id', $finalized)->update(['requested_at' => DB::raw('updated_at')]);
        DB::table('deal_documents')->where('slug', 'proof_of_insurance')->whereNotIn('deal_id', $finalized)->where('is_done', false)
            ->update(['status' => "We'll ask once your deal is finalized"]);

        DB::table('deal_documents')->where('slug', 'proof_of_income')->update(['name' => 'Recent pay stubs']);
        DB::table('deal_documents')->where('slug', 'proof_of_income')->where('status', 'Needed to finalize approval')
            ->update(['status' => 'Your last two. One PDF or photo with both is fine']);
    }

    public function down(): void
    {
        DB::table('deal_documents')->where('slug', 'proof_of_income')->update(['name' => 'Proof of income']);

        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('documents_reminded_at');
        });

        Schema::table('deal_documents', function (Blueprint $table) {
            $table->dropColumn('requested_at');
        });
    }
};

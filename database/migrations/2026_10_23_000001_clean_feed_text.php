<?php

use App\Models\Dealer;
use App\Models\Vehicle;
use App\Support\FeedText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| Cars imported before feed text was cleaned can show "4Ã—4" instead of
| "4×4", "&amp;" and the like. Imports and saves are clean from now on
| (FeedText, CleansText); this cleans what's already stored.
*/
return new class extends Migration
{
    public function up(): void
    {
        $this->clean('vehicles', Vehicle::CLEAN_TEXT);
        $this->clean('dealers', Dealer::CLEAN_TEXT);
    }

    public function down(): void
    {
        // Nothing to undo — the cleaned text is the correct text.
    }

    private function clean(string $table, array $columns): void
    {
        DB::table($table)->select(['id', ...$columns])->orderBy('id')->each(function ($row) use ($table, $columns) {
            $changes = [];
            foreach ($columns as $column) {
                if (is_string($row->{$column}) && ($cleaned = FeedText::clean($row->{$column})) !== $row->{$column}) {
                    $changes[$column] = $cleaned;
                }
            }
            if ($changes) {
                DB::table($table)->where('id', $row->id)->update($changes);
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GAP is now entered at its full price too, like every other protection item;
 * checkout rolls it into the loan and shows the biweekly (finance only).
 *
 * Any GAP still priced as a biweekly figure — and any dealer price override
 * on it — is converted to the full price that gives the same biweekly at the
 * default 72-month term (7.5% APR, 13% HST), rounded to the nearest $5:
 * $18/biweekly becomes $2,000. Same method as the add-ons migration.
 */
return new class extends Migration
{
    // PaymentEstimate::loanBiweekly(1, 72): the biweekly payment on $1 financed.
    private const BIWEEKLY_PER_DOLLAR = 0.0090054907182787;

    public function up(): void
    {
        DB::transaction(function () {
            $biweeklyIds = DB::table('protection_products')->where('kind', 'gap')->where('price_basis', 'biweekly')->pluck('id');

            foreach (DB::table('protection_products')->whereIn('id', $biweeklyIds)->whereNotNull('amount_in_cents')->get(['id', 'amount_in_cents']) as $row) {
                DB::table('protection_products')->where('id', $row->id)->update(['amount_in_cents' => $this->toFullPrice($row->amount_in_cents)]);
            }

            foreach (DB::table('dealer_protection_settings')->whereIn('protection_product_id', $biweeklyIds)->whereNotNull('amount_in_cents')->get(['id', 'amount_in_cents']) as $row) {
                DB::table('dealer_protection_settings')->where('id', $row->id)->update(['amount_in_cents' => $this->toFullPrice($row->amount_in_cents)]);
            }

            DB::table('protection_products')->where('kind', 'gap')->update(['price_basis' => 'one_time', 'updated_at' => now()]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            $ids = DB::table('protection_products')->where('kind', 'gap')->pluck('id');

            foreach (DB::table('protection_products')->whereIn('id', $ids)->whereNotNull('amount_in_cents')->get(['id', 'amount_in_cents']) as $row) {
                DB::table('protection_products')->where('id', $row->id)->update(['amount_in_cents' => $this->toBiweekly($row->amount_in_cents)]);
            }

            foreach (DB::table('dealer_protection_settings')->whereIn('protection_product_id', $ids)->whereNotNull('amount_in_cents')->get(['id', 'amount_in_cents']) as $row) {
                DB::table('dealer_protection_settings')->where('id', $row->id)->update(['amount_in_cents' => $this->toBiweekly($row->amount_in_cents)]);
            }

            DB::table('protection_products')->whereIn('id', $ids)->update(['price_basis' => 'biweekly', 'updated_at' => now()]);
        });
    }

    private function toFullPrice(int $biweeklyCents): int
    {
        return (int) (round($biweeklyCents / 100 / self::BIWEEKLY_PER_DOLLAR / 5) * 5 * 100);
    }

    private function toBiweekly(int $fullCents): int
    {
        return (int) round($fullCents * self::BIWEEKLY_PER_DOLLAR);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Brands. TruCars works only with franchise dealers, and every dealer
     * belongs to one brand; the brand's settings (CPO inspection checklist,
     * offers & incentives) apply to all of its dealers. dealers.franchise_make
     * stays as the brand's name, kept in step by the Dealer model.
     */
    private const BRANDS = [
        'Acura', 'Alfa Romeo', 'Audi', 'BMW', 'Buick', 'Cadillac', 'Chevrolet', 'Chrysler', 'Dodge', 'Fiat',
        'Ford', 'Genesis', 'GMC', 'Honda', 'Hyundai', 'Infiniti', 'Jaguar', 'Jeep', 'Kia', 'Land Rover',
        'Lexus', 'Lincoln', 'Mazda', 'Mercedes-Benz', 'MINI', 'Mitsubishi', 'Nissan', 'Porsche', 'Ram',
        'Subaru', 'Toyota', 'Volkswagen', 'Volvo',
    ];

    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->timestamps();
        });

        Schema::table('dealers', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('franchise_make')->constrained()->restrictOnDelete();
        });

        $now = now();
        $known = collect(self::BRANDS)
            ->merge(DB::table('dealers')->whereNotNull('franchise_make')->pluck('franchise_make'))
            ->merge(DB::table('inspection_programs')->whereNull('dealer_id')->whereNotNull('make')->pluck('make'))
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => mb_strtolower($name))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        DB::table('brands')->insert($known->map(fn ($name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now])->all());

        // Link existing dealers to their brand by name.
        foreach (DB::table('brands')->get(['id', 'name']) as $brand) {
            DB::table('dealers')->whereRaw('lower(franchise_make) = ?', [mb_strtolower($brand->name)])
                ->update(['brand_id' => $brand->id, 'franchise_make' => $brand->name]);
        }
    }

    public function down(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
        Schema::dropIfExists('brands');
    }
};

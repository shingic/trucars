<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| The coverage plans, GAP, add-ons and accessories a buyer can flag at checkout.
|
| A row with no dealer_id is a TruCars default, offered at every dealership.
| A dealership can hide a default or set its own price for it
| (dealer_protection_settings), and can add items of its own (dealer_id set).
|
| The defaults are seeded with the menu checkout carried until now, so buyers
| see exactly the same choices the day this ships.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('protection_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('name', 80);
            $table->string('description', 200)->nullable();
            $table->json('features')->nullable();
            $table->unsignedInteger('amount_in_cents')->nullable();
            $table->string('price_basis', 20)->default('biweekly');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['dealer_id', 'kind', 'position']);
        });

        Schema::create('dealer_protection_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('protection_product_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('amount_in_cents')->nullable();
            $table->timestamps();

            $table->unique(['dealer_id', 'protection_product_id']);
        });

        Schema::table('deals', function (Blueprint $table) {
            $table->json('protection_snapshot')->nullable()->after('extras_interest');
        });

        $now = now();
        $row = fn (string $kind, int $position, string $name, ?string $description, ?int $cents, array $extra = []) => $extra + [
            'dealer_id' => null, 'kind' => $kind, 'name' => $name, 'description' => $description,
            'features' => null, 'amount_in_cents' => $cents, 'price_basis' => 'biweekly',
            'is_featured' => false, 'position' => $position, 'is_active' => true,
            'created_at' => $now, 'updated_at' => $now,
        ];

        DB::table('protection_products')->insert([
            $row('plan', 1, 'Open Road', 'Get rolling with the must-haves covered.', null, [
                'features' => json_encode(['Refundable deposit', '3-month / 5,000 km warranty', 'Roadside assistance']),
            ]),
            $row('plan', 2, 'Safe Bet', 'Our pick — longer cover, no surprise bills.', null, [
                'features' => json_encode(['Refundable deposit', '3-year / 60,000 km warranty', 'Roadside assistance', 'Free delivery or pickup']),
                'is_featured' => true,
            ]),
            $row('plan', 3, 'Bumper-to-Bumper', 'Everything in. Drive without a second thought.', null, [
                'features' => json_encode(['Refundable deposit', '3-year / 60,000 km warranty', 'Roadside + tire & rim', 'Rust & paint shield', 'Free delivery or pickup']),
            ]),
            $row('gap', 1, 'GAP protection', 'Covers the shortfall between your loan and what insurance pays if the car is written off.', 1800),
            $row('addon', 1, 'Theft recovery & tracking', "Hidden device + recovery service if it's ever taken", 700),
            $row('addon', 2, 'Tire & rim cover', 'Pothole and curb damage, repaired or replaced', 900),
            $row('addon', 3, 'Paint & interior shield', 'Stains, scuffs, and fade protection inside and out', 600),
            $row('addon', 4, 'Prepaid maintenance', 'Oil, filters, and scheduled service at a locked rate', 1200),
            $row('addon', 5, 'Lost key cover', 'Replacement smart keys without the dealer markup', 400),
        ]);
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('protection_snapshot');
        });
        Schema::dropIfExists('dealer_protection_settings');
        Schema::dropIfExists('protection_products');
    }
};

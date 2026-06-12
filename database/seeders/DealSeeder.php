<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Deal;
use App\Models\Dealer;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

class DealSeeder extends Seeder
{
    public function run(): void
    {
        $dealer = Dealer::firstOrFail();
        $vehicles = Vehicle::where('dealer_id', $dealer->id)->take(2)->get();

        if ($vehicles->count() < 2) {
            $this->command->warn('DealSeeder skipped — need at least 2 vehicles for the dealer.');

            return;
        }

        $financedDeal = Deal::create([
            'dealer_id'             => $dealer->id,
            'vehicle_id'            => $vehicles[0]->id,
            'purchase_type'         => 'finance',
            'term_months'           => 72,
            'down_payment_in_cents' => 250000,
            'warranty_plan'         => 'safebet',
            'first_name'            => 'Amara',
            'last_name'             => 'Okafor',
            'email'                 => 'amara.okafor@example.com',
            'phone'                 => '(416) 555-0184',
            'street_address'        => '88 Harbour Street',
            'city'                  => 'Toronto',
            'province'              => 'Ontario',
            'postal_code'           => 'M5J 2T7',
            'identity_verified_at'  => now()->subMinutes(45),
        ]);

        $financedDeal->recordActivity('system', 'Reservation created through TruCars checkout.');
        $financedDeal->recordActivity('system', '$150 refundable deposit held.');
        $financedDeal->recordActivity('system', 'Identity verified.');
        $financedDeal->recordActivity('sms', 'Hi Amara — your ' . $vehicles[0]->model_year . ' ' . $vehicles[0]->make . ' ' . $vehicles[0]->model . ' is reserved! Reference ' . $financedDeal->reference . '. The dealership will reach out shortly.', null, 'outbound');

        $cashDeal = Deal::create([
            'dealer_id'      => $dealer->id,
            'vehicle_id'     => $vehicles[1]->id,
            'stage'          => 'contacted',
            'purchase_type'  => 'cash',
            'first_name'     => 'Devon',
            'last_name'      => 'Tremblay',
            'email'          => 'devon.tremblay@example.com',
            'phone'          => '(905) 555-0142',
            'street_address' => '12 Lakeshore Road East',
            'city'           => 'Mississauga',
            'province'       => 'Ontario',
            'postal_code'    => 'L5G 1C9',
        ]);

        $cashDeal->recordActivity('system', 'Reservation created through TruCars checkout.');
        $cashDeal->recordActivity('system', '$150 refundable deposit held.');
        $cashDeal->recordActivity('status', 'Stage moved to Contacted.', 'Dealer');
    }
}

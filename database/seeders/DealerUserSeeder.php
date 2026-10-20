<?php

namespace Database\Seeders;

use App\Models\Dealer;
use App\Models\Lead;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DealerUserSeeder extends Seeder
{
    public function run(): void
    {
        // Demo data only: a dealer-staff login with a known password and fake
        // reservations. Never on a real deployment — seeding production must not
        // create an account anyone can sign in to with "password".
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Skipping DealerUserSeeder: demo login and sample reservations are local-only.');

            return;
        }

        $dealerWithInventory = Dealer::query()
            ->withCount('vehicles')
            ->orderByDesc('vehicles_count')
            ->first()
            ?? Dealer::create([
                'name' => 'Willowdale Nissan',
                'city' => 'Toronto',
                'registration_number' => '1234567',
            ]);

        // Local dev only. role and dealer_id aren't mass-assignable, so they're
        // set explicitly — the same way an accepted invitation sets them.
        User::firstOrNew(['email' => 'shingi@trueleads.ca'])
            ->forceFill([
                'name'      => 'Dealer Desk',
                'password'  => Hash::make('password'),
                'dealer_id' => $dealerWithInventory->id,
                'role'      => User::ROLE_DEALER_STAFF,
            ])
            ->save();

        $this->seedSampleReservations($dealerWithInventory);
    }

    private function seedSampleReservations(Dealer $dealer): void
    {
        $alreadyHasLeads = Lead::withoutGlobalScopes()
            ->where('dealer_id', $dealer->id)
            ->exists();

        if ($alreadyHasLeads) {
            return;
        }

        $vehiclesToReserve = Vehicle::where('dealer_id', $dealer->id)
            ->limit(4)
            ->get();

        $sampleCustomers = [
            ['name' => 'Priya Sharma',   'email' => 'priya.sharma@example.com',   'phone' => '416-555-0142', 'status' => 'reservation'],
            ['name' => 'Marcus Bennett', 'email' => 'marcus.bennett@example.com', 'phone' => '647-555-0188', 'status' => 'contacted'],
            ['name' => 'Aisha Khan',     'email' => 'aisha.khan@example.com',     'phone' => '905-555-0119', 'status' => 'new'],
            ['name' => 'David Okoye',    'email' => 'david.okoye@example.com',    'phone' => '416-555-0173', 'status' => 'confirmed'],
        ];

        foreach ($vehiclesToReserve as $index => $vehicle) {
            $customer = $sampleCustomers[$index] ?? $sampleCustomers[0];

            Lead::create([
                'vehicle_id' => $vehicle->id,
                'dealer_id'  => $dealer->id,
                'name'       => $customer['name'],
                'email'      => $customer['email'],
                'phone'      => $customer['phone'],
                'message'    => 'Interested in this one — is it still available this week?',
                'status'     => $customer['status'],
            ]);
        }
    }
}

<?php

namespace Tests\Concerns;

use App\Models\Dealer;

trait CreatesDealers
{
    /** A live franchise dealer with a complete rooftop address, ready to publish cars. */
    protected function makeDealer(array $overrides = []): Dealer
    {
        return Dealer::create($overrides + [
            'name'           => 'CHILLIWACK MITSUBISHI',
            'franchise_make' => 'Mitsubishi',
            'street_address' => '45510 Yale Rd',
            'city'           => 'Chilliwack',
            'province'       => 'BC',
            'postal_code'    => 'V2P 2M9',
            'country'        => 'CA',
            'latitude'       => 49.151131,
            'longitude'      => -121.963743,
            'timezone'       => 'America/Vancouver',
            'status'         => Dealer::STATUS_ACTIVE,
        ]);
    }

    /** A brand's id, as the dealer form's brand picker posts it. */
    protected function brandId(string $name = 'Mitsubishi'): string
    {
        return (string) \App\Models\Brand::where('name', $name)->value('id');
    }
}

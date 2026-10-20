<?php

namespace Tests\Concerns;

use App\Models\Dealer;

trait CreatesDealers
{
    /** A live dealer with a complete rooftop address, ready to publish cars. */
    protected function makeDealer(array $overrides = []): Dealer
    {
        return Dealer::create($overrides + [
            'name'           => 'CHILLIWACK MITSUBISHI',
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
}

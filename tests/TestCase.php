<?php

namespace Tests;

use App\Support\Payments\DepositGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\FakeDepositGateway;

abstract class TestCase extends BaseTestCase
{
    protected FakeDepositGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        // Never reach Stripe from a test.
        $this->stripe = new FakeDepositGateway;
        $this->app->instance(DepositGateway::class, $this->stripe);

        // Cars without photos aren't listed, so test cars get one unless a
        // test sets photos itself (e.g. 'photos' => [] to test exactly that).
        \App\Models\Vehicle::creating(function (\App\Models\Vehicle $vehicle) {
            if (! array_key_exists('photos', $vehicle->getAttributes())) {
                $vehicle->photos = ['https://photos.test/car.jpg'];
            }
        });
    }
}

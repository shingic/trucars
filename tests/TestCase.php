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

        // Cars with too few photos aren't listed, so test cars get enough
        // unless a test sets photos itself (e.g. 'photos' => [] to test that).
        \App\Models\Vehicle::creating(function (\App\Models\Vehicle $vehicle) {
            if (! array_key_exists('photos', $vehicle->getAttributes())) {
                $vehicle->photos = self::photosFor((string) $vehicle->vin);
                $vehicle->photo_count = count($vehicle->photos);
            }
        });
    }

    /** Enough photos for a car to be listed, all under one name. */
    public static function photosFor(string $name): array
    {
        return array_map(fn ($n) => "https://example.test/{$name}/{$n}.jpg", range(1, \App\Models\Vehicle::MIN_PHOTOS));
    }
}

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
    }
}

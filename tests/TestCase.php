<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\Tests;

use Fagner\LaravelShippingGateway\Providers\ShippingServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ShippingServiceProvider::class];
    }
}


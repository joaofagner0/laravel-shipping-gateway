<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\Providers;

use Fagner\LaravelShippingGateway\Contracts\ShippingGatewayInterface;
use Fagner\LaravelShippingGateway\Manager\ShippingManager;
use Illuminate\Support\ServiceProvider;

final class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/shipping.php', 'shipping');

        $this->app->singleton(ShippingManager::class, static function ($app): ShippingManager {
            return new ShippingManager($app);
        });

        $this->app->bind(ShippingGatewayInterface::class, static function ($app): ShippingGatewayInterface {
            return $app->make(ShippingManager::class)->driver();
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/shipping.php' => $this->app->configPath('shipping.php'),
        ], ['shipping-config', 'config']);
    }
}


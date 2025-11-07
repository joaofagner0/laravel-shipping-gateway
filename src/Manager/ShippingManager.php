<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\Manager;

use Fagner\LaravelShippingGateway\Adapters\CorreiosAdapter;
use Fagner\LaravelShippingGateway\Adapters\MelhorEnvioAdapter;
use Fagner\LaravelShippingGateway\Contracts\ShippingGatewayInterface;
use Fagner\LaravelShippingGateway\DTOs\ShipmentRequest;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Psr\Log\LoggerInterface;

final class ShippingManager
{
    /**
     * @var array<string, ShippingGatewayInterface>
     */
    private array $drivers = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function driver(?string $name = null): ShippingGatewayInterface
    {
        $name ??= $this->getDefaultDriver();

        if (!isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->createDriver($name);
        }

        return $this->drivers[$name];
    }

    /**
     * Retorna as cotações agrupadas por provedor configurado.
     *
     * @return array<string, array<int, mixed>>
     */
    public function getRatesFromAllProviders(ShipmentRequest $shipmentRequest): array
    {
        $results = [];

        foreach ($this->getDriverNames() as $driver) {
            $results[$driver] = $this->driver($driver)->consultarPrecos($shipmentRequest);
        }

        return $results;
    }

    private function createDriver(string $name): ShippingGatewayInterface
    {
        $config = $this->getConfigRepository()->get("shipping.providers.{$name}", []);
        $logger = $this->container->bound(LoggerInterface::class)
            ? $this->container->make(LoggerInterface::class)
            : null;

        return match ($name) {
            'melhor_envio' => new MelhorEnvioAdapter($config, logger: $logger),
            'correios' => new CorreiosAdapter($config, logger: $logger),
            default => throw new \InvalidArgumentException("Driver de frete não suportado [{$name}]."),
        };
    }

    private function getDefaultDriver(): string
    {
        $default = $this->getConfigRepository()->get('shipping.default', 'melhor_envio');

        return is_string($default) ? $default : 'melhor_envio';
    }

    /**
     * @return array<int, string>
     */
    private function getDriverNames(): array
    {
        $providers = $this->getConfigRepository()->get('shipping.providers', []);

        return array_keys(is_array($providers) ? $providers : []);
    }

    private function getConfigRepository(): ConfigRepository
    {
        /** @var ConfigRepository $config */
        $config = $this->container->make(ConfigRepository::class);

        return $config;
    }
}


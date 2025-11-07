<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\Adapters;

use Fagner\LaravelShippingGateway\Contracts\ShippingGatewayInterface;
use Fagner\LaravelShippingGateway\DTOs\LabelResult;
use Fagner\LaravelShippingGateway\DTOs\ShipmentRequest;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

abstract class AbstractAdapter implements ShippingGatewayInterface
{
    protected Client $client;
    protected ?LoggerInterface $logger;

    /**
     * @param array<string, mixed> $config Configurações do provedor (token, base_uri, timeout, etc.).
     */
    public function __construct(
        protected array $config,
        ?Client $client = null,
        ?LoggerInterface $logger = null
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => $config['base_uri'] ?? null,
            'timeout' => $config['timeout'] ?? 10,
        ]);

        $this->logger = $logger;
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, $message, $context);
    }

    public function gerarEtiqueta(ShipmentRequest $solicitacaoRemessa): LabelResult
    {
        throw new \RuntimeException('not implemented');
    }

    public function imprimirEtiqueta(ShipmentRequest $solicitacaoRemessa): LabelResult
    {
        throw new \RuntimeException('not implemented');
    }

    public function track(string $codigoRastreio): array
    {
        throw new \RuntimeException('not implemented');
    }
}


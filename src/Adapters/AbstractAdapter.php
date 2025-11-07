<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\Adapters;

use Fagner\LaravelShippingGateway\Contracts\ShippingGatewayInterface;
use Fagner\LaravelShippingGateway\DTOs\LabelResult;
use Fagner\LaravelShippingGateway\DTOs\ShipmentRequest;
use GuzzleHttp\Client;

abstract class AbstractAdapter implements ShippingGatewayInterface
{
    protected Client $client;

    /**
     * @param array<string, mixed> $config Configurações do provedor (token, base_uri, timeout, etc.).
     */
    public function __construct(
        protected array $config,
        ?Client $client = null
    ) {
        $this->client = $client ?? new Client([
            'base_uri' => $config['base_uri'] ?? null,
            'timeout' => $config['timeout'] ?? 10,
        ]);
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


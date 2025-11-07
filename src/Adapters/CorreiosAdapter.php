<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\Adapters;

use Fagner\LaravelShippingGateway\DTOs\ShipmentRequest;
use GuzzleHttp\Client;

final class CorreiosAdapter extends AbstractAdapter
{
    /**
     * @param array<string, mixed> $config Configurações específicas dos Correios.
     */
    public function __construct(array $config, ?Client $client = null)
    {
        parent::__construct($config, $client);
    }

    public function consultarPrecos(ShipmentRequest $solicitacaoRemessa): array
    {
        return [];
    }
}


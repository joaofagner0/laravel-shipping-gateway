<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\Contracts;

use Fagner\LaravelShippingGateway\DTOs\LabelResult;
use Fagner\LaravelShippingGateway\DTOs\ShipmentRequest;

interface ShippingGatewayInterface
{
    /**
     * Retorna a lista de resultados de cotação do provedor.
     *
     * @return array<int, mixed>
     */
    public function consultarPrecos(ShipmentRequest $solicitacaoRemessa): array;

    public function gerarEtiqueta(ShipmentRequest $solicitacaoRemessa): LabelResult;

    public function imprimirEtiqueta(ShipmentRequest $solicitacaoRemessa): LabelResult;

    /**
     * Retorna dados de rastreamento para o código informado.
     *
     * @return array<string, mixed>
     */
    public function track(string $codigoRastreio): array;
}


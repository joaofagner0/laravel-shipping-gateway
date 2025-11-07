<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\DTOs;

final class ShipmentRequest
{
    public function __construct(
        public readonly string $cepOrigem,
        public readonly string $cepDestino,
        public readonly float $pesoKg,
        public readonly float $comprimentoCm,
        public readonly float $larguraCm,
        public readonly float $alturaCm,
        public readonly float $valor,
        public readonly array $opcoes = []
    ) {
    }
}


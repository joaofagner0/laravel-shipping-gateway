<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\DTOs;

final class RateResult
{
    public function __construct(
        public readonly string $provedor,
        public readonly string $servico,
        public readonly float $preco,
        public readonly ?int $diasEstimados,
        public readonly array $bruto
    ) {
    }
}


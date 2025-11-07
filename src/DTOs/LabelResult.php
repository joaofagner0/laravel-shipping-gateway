<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\DTOs;

final class LabelResult
{
    public function __construct(
        public readonly string $provedor,
        public readonly string $codigoRastreio,
        public readonly ?string $etiquetaBase64,
        public readonly array $bruto
    ) {
    }
}


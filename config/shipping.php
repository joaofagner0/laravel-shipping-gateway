<?php

declare(strict_types=1);

return [
    'default' => 'melhor_envio',

    'providers' => [
        'melhor_envio' => [
            'token' => env('MELHOR_ENVIO_TOKEN'),
            'base_uri' => env('MELHOR_ENVIO_BASE_URI', 'https://www.melhorenvio.com.br/api/v2/'),
            'sandbox_base_uri' => env('MELHOR_ENVIO_SANDBOX_BASE_URI', 'https://sandbox.melhorenvio.com.br/api/v2/'),
            'use_sandbox' => filter_var(env('MELHOR_ENVIO_USE_SANDBOX', false), FILTER_VALIDATE_BOOL),
            'timeout' => env('MELHOR_ENVIO_TIMEOUT', 10),
        ],
        'correios' => [
            'token' => env('CORREIOS_TOKEN'),
            'base_uri' => env('CORREIOS_BASE_URI', 'https://api.correios.com.br/'),
            'timeout' => env('CORREIOS_TIMEOUT', 10),
        ],
    ],
];


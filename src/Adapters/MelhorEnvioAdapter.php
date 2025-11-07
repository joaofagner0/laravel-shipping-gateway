<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\Adapters;

use Fagner\LaravelShippingGateway\DTOs\LabelResult;
use Fagner\LaravelShippingGateway\DTOs\RateResult;
use Fagner\LaravelShippingGateway\DTOs\ShipmentRequest;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class MelhorEnvioAdapter extends AbstractAdapter
{
    /**
     * @param array<string, mixed> $config Configurações específicas do Melhor Envio.
     */
    public function __construct(array $config, ?Client $client = null, ?LoggerInterface $logger = null)
    {
        if (($config['use_sandbox'] ?? false) === true) {
            $config['base_uri'] = $config['sandbox_base_uri'] ?? 'https://sandbox.melhorenvio.com.br/api/v2/';
        }

        parent::__construct($config, $client, $logger);
    }

    public function consultarPrecos(ShipmentRequest $solicitacaoRemessa): array
    {
        $pesoKg = max(0.001, round($solicitacaoRemessa->pesoKg, 3));

        $payload = [
            'from' => ['postal_code' => $solicitacaoRemessa->cepOrigem],
            'to' => ['postal_code' => $solicitacaoRemessa->cepDestino],
            'package' => [
                'height' => (int) round($solicitacaoRemessa->alturaCm),
                'width' => (int) round($solicitacaoRemessa->larguraCm),
                'length' => (int) round($solicitacaoRemessa->comprimentoCm),
                'weight' => $pesoKg,
            ],
            'options' => [
                'insurance_value' => $solicitacaoRemessa->valor,
            ],
        ];

        if (!empty($solicitacaoRemessa->opcoes['options']) && is_array($solicitacaoRemessa->opcoes['options'])) {
            $payload['options'] = array_merge($payload['options'], $solicitacaoRemessa->opcoes['options']);
        }

        if (!empty($solicitacaoRemessa->opcoes['services'])) {
            $payload['services'] = $solicitacaoRemessa->opcoes['services'];
        }

        if (!empty($solicitacaoRemessa->opcoes['products']) && is_array($solicitacaoRemessa->opcoes['products'])) {
            $payload['products'] = $solicitacaoRemessa->opcoes['products'];
            unset($payload['package']);
        }

        $options = [
            'json' => $payload,
        ];

        if (!empty($this->config['token'])) {
            $options['headers']['Authorization'] = 'Bearer ' . $this->config['token'];
        }

        try {
            $response = $this->client->post('me/shipment/calculate', $options);
        } catch (GuzzleException $exception) {
            $this->log('error', 'Falha ao consultar cotações no Melhor Envio.', [
                'exception' => $exception,
                'payload' => $payload,
            ]);
            return [];
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['data']) || !is_array($decoded['data'])) {
            $this->log('warning', 'Resposta inesperada ao consultar cotações no Melhor Envio.', [
                'body' => $body,
            ]);
            return [];
        }

        $results = [];

        foreach ($decoded['data'] as $rate) {
            if (!is_array($rate)) {
                continue;
            }

            $serviceName = $rate['service_name'] ?? ($rate['service']['name'] ?? '');

            if ($serviceName === '') {
                continue;
            }

            $price = isset($rate['price']) ? (float) $rate['price'] : null;

            if ($price === null) {
                continue;
            }

            $estimatedDays = isset($rate['delivery_time']) ? (int) $rate['delivery_time'] : null;

            $results[] = new RateResult(
                provedor: 'melhor_envio',
                servico: $serviceName,
                preco: $price,
                diasEstimados: $estimatedDays,
                bruto: $rate
            );
        }

        return $results;
    }

    public function gerarEtiqueta(ShipmentRequest $solicitacaoRemessa): LabelResult
    {
        return $this->processarEtiqueta($solicitacaoRemessa);
    }

    public function imprimirEtiqueta(ShipmentRequest $solicitacaoRemessa): LabelResult
    {
        return $this->processarEtiqueta($solicitacaoRemessa);
    }

    private function processarEtiqueta(ShipmentRequest $solicitacaoRemessa): LabelResult
    {
        $opcoes = $solicitacaoRemessa->opcoes;

        $serviceId = $opcoes['service_id'] ?? null;

        if ($serviceId === null) {
            $this->log('error', 'service_id não informado para criação de remessa no Melhor Envio.', [
                'opcoes' => $opcoes,
            ]);
            throw new RuntimeException('service_id é obrigatório para criar uma remessa no Melhor Envio.');
        }

        $from = $opcoes['from'] ?? null;
        $to = $opcoes['to'] ?? null;

        if (!is_array($from) || !is_array($to)) {
            $this->log('error', 'Dados de origem/destino inválidos ao criar remessa no Melhor Envio.', [
                'from' => $from,
                'to' => $to,
            ]);
            throw new RuntimeException('As chaves "from" e "to" devem ser informadas nas opções.');
        }

        $pesoGramas = max(1, (int) round($solicitacaoRemessa->pesoKg * 1000));

        $volumes = $opcoes['volumes'] ?? [[
            'weight' => $pesoGramas,
            'height' => (int) round($solicitacaoRemessa->alturaCm),
            'width' => (int) round($solicitacaoRemessa->larguraCm),
            'length' => (int) round($solicitacaoRemessa->comprimentoCm),
        ]];

        $orderOptions = $opcoes['options'] ?? [];

        if (!array_key_exists('insurance_value', $orderOptions)) {
            $orderOptions['insurance_value'] = $solicitacaoRemessa->valor;
        }

        $order = [
            'service' => $serviceId,
            'from' => $from,
            'to' => $to,
            'volumes' => $volumes,
            'options' => $orderOptions,
        ];

        if (isset($opcoes['products']) && is_array($opcoes['products'])) {
            $order['products'] = $opcoes['products'];
        }

        if (isset($opcoes['tags']) && is_array($opcoes['tags'])) {
            $order['tags'] = $opcoes['tags'];
        }

        try {
            $orderResponse = $this->client->post('shipping/orders', [
                'json' => ['orders' => [$order]],
            ]);
        } catch (GuzzleException $exception) {
            $this->log('error', 'Falha na requisição de criação de remessa no Melhor Envio.', [
                'exception' => $exception,
                'order' => $order,
            ]);
            throw new RuntimeException('Falha ao criar remessa no Melhor Envio.', 0, $exception);
        }

        $orderBody = (string) $orderResponse->getBody();
        $orderDecoded = json_decode($orderBody, true);

        if (!is_array($orderDecoded) || !isset($orderDecoded['data'][0]) || !is_array($orderDecoded['data'][0])) {
            $this->log('error', 'Resposta inesperada ao criar remessa no Melhor Envio.', [
                'body' => $orderBody,
            ]);
            throw new RuntimeException('Resposta inesperada ao criar remessa no Melhor Envio.');
        }

        $orderData = $orderDecoded['data'][0];

        $orderId = $orderData['id'] ?? $orderData['order_id'] ?? null;

        if ($orderId === null) {
            $this->log('error', 'Resposta do Melhor Envio não contém ID da remessa.', [
                'order' => $orderData,
            ]);
            throw new RuntimeException('Não foi possível identificar o ID da remessa criada.');
        }

        $trackingCode = $orderData['tracking_code'] ?? null;

        if ($trackingCode === null && isset($orderData['tracking'])) {
            if (is_string($orderData['tracking'])) {
                $trackingCode = $orderData['tracking'];
            } elseif (is_array($orderData['tracking']) && isset($orderData['tracking']['code'])) {
                $trackingCode = $orderData['tracking']['code'];
            }
        }

        $labelType = $opcoes['label_type'] ?? 'base64';

        try {
            $labelResponse = $this->client->post('shipping/labels', [
                'json' => [
                    'orders' => [$orderId],
                    'type' => $labelType,
                ],
            ]);
        } catch (GuzzleException $exception) {
            $this->log('error', 'Falha na requisição de impressão de etiqueta no Melhor Envio.', [
                'exception' => $exception,
                'order_id' => $orderId,
                'label_type' => $labelType,
            ]);
            throw new RuntimeException('Falha ao solicitar impressão da etiqueta no Melhor Envio.', 0, $exception);
        }

        $labelBody = (string) $labelResponse->getBody();
        $labelDecoded = json_decode($labelBody, true);

        $labelBase64 = null;

        if (is_array($labelDecoded)) {
            if (isset($labelDecoded['data']['base64']) && is_string($labelDecoded['data']['base64'])) {
                $labelBase64 = $labelDecoded['data']['base64'];
            } elseif (isset($labelDecoded['data'][0]['base64']) && is_string($labelDecoded['data'][0]['base64'])) {
                $labelBase64 = $labelDecoded['data'][0]['base64'];
            }
        }

        if ($labelBase64 === null) {
            $this->log('warning', 'Etiqueta gerada sem conteúdo base64 no Melhor Envio.', [
                'order_id' => $orderId,
                'response' => $labelDecoded,
            ]);
        }

        $this->log('info', 'Etiqueta gerada com sucesso no Melhor Envio.', [
            'order_id' => $orderId,
            'tracking_code' => $trackingCode,
            'label_type' => $labelType,
        ]);

        return new LabelResult(
            provedor: 'melhor_envio',
            codigoRastreio: $trackingCode ?? '',
            etiquetaBase64: $labelBase64,
            bruto: [
                'order' => $orderData,
                'label' => $labelDecoded,
            ]
        );
    }
}


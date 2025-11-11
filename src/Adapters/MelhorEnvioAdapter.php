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

        $options = $this->withDefaultHeaders([
            'json' => $payload,
        ]);

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

        if (!is_array($decoded)) {
            $this->log('warning', 'Resposta inesperada ao consultar cotações no Melhor Envio.', [
                'body' => $body,
            ]);
            return [];
        }

        $ratesData = array_is_list($decoded) ? $decoded : ($decoded['data'] ?? null);

        if (!is_array($ratesData)) {
            $this->log('warning', 'Resposta inesperada ao consultar cotações no Melhor Envio.', [
                'body' => $body,
            ]);
            return [];
        }

        $results = [];

        foreach ($ratesData as $rate) {
            if (!is_array($rate)) {
                continue;
            }

            $serviceName = $rate['service_name']
                ?? ($rate['service']['name'] ?? ($rate['name'] ?? ''));

            if ($serviceName === '') {
                continue;
            }

            $price = $rate['custom_price'] ?? $rate['price'] ?? null;
            $price = $price !== null ? (float) $price : null;

            if ($price === null) {
                continue;
            }

            $estimatedDays = $rate['custom_delivery_time'] ?? $rate['delivery_time'] ?? null;
            $estimatedDays = $estimatedDays !== null ? (int) $estimatedDays : null;

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

    /**
     * Gera a etiqueta seguindo o fluxo completo do Melhor Envio:
     * 1. Adiciona a remessa ao carrinho
     * 2. Finaliza a compra (checkout)
     * 3. Gera a etiqueta
     * 4. Imprime a etiqueta
     */
    public function gerarEtiqueta(ShipmentRequest $solicitacaoRemessa): LabelResult
    {
        return $this->processarFluxoCompleto($solicitacaoRemessa);
    }

    /**
     * Imprime a etiqueta seguindo o fluxo completo do Melhor Envio.
     * Alias para gerarEtiqueta() pois o processo é o mesmo.
     */
    public function imprimirEtiqueta(ShipmentRequest $solicitacaoRemessa): LabelResult
    {
        return $this->processarFluxoCompleto($solicitacaoRemessa);
    }

    /**
     * Executa o fluxo completo de criação de remessa no Melhor Envio.
     */
    private function processarFluxoCompleto(ShipmentRequest $solicitacaoRemessa): LabelResult
    {
        // Etapa 1: Adicionar ao carrinho
        $cartItemId = $this->adicionarAoCarrinho($solicitacaoRemessa);

        // Etapa 2: Finalizar compra (checkout)
        $purchaseData = $this->finalizarCompra($cartItemId);

        // Etapa 3: Gerar etiqueta
        $orderData = $this->gerarEtiquetaRemessa($purchaseData);

        // Etapa 4: Imprimir etiqueta
        $labelData = $this->imprimirEtiquetaRemessa($orderData['id'], $solicitacaoRemessa->opcoes);

        // Buscar dados atualizados da ordem para obter o código de rastreamento
        // (que pode não estar disponível imediatamente após o checkout)
        $orderDataAtualizada = $this->buscarOrdem($orderData['id']);
        
        // Extrair código de rastreamento dos dados atualizados
        $trackingCode = $this->extrairCodigoRastreamento($orderDataAtualizada);

        $this->log('info', 'Fluxo completo de etiqueta concluído com sucesso no Melhor Envio.', [
            'order_id' => $orderData['id'],
            'tracking_code' => $trackingCode,
        ]);

        return new LabelResult(
            provedor: 'melhor_envio',
            codigoRastreio: $trackingCode ?? '',
            etiquetaBase64: null, // URL disponível em bruto['label']['url']
            bruto: [
                'cart_item' => $cartItemId,
                'purchase' => $purchaseData,
                'order' => $orderDataAtualizada,
                'label' => $labelData,
                'label_url' => $labelData['url'] ?? null, // URL direta para facilitar acesso
            ]
        );
    }

    /**
     * Etapa 1: Adiciona uma remessa ao carrinho do Melhor Envio.
     *
     * @return string ID do item no carrinho
     */
    private function adicionarAoCarrinho(ShipmentRequest $solicitacaoRemessa): string
    {
        $opcoes = $solicitacaoRemessa->opcoes;

        $serviceId = $opcoes['service_id'] ?? null;
        if ($serviceId === null) {
            $this->log('error', 'service_id não informado para adicionar ao carrinho no Melhor Envio.', [
                'opcoes' => $opcoes,
            ]);
            throw new RuntimeException('service_id é obrigatório para criar uma remessa no Melhor Envio.');
        }

        $from = $opcoes['from'] ?? null;
        $to = $opcoes['to'] ?? null;

        if (!is_array($from) || !is_array($to)) {
            $this->log('error', 'Dados de origem/destino inválidos ao adicionar ao carrinho no Melhor Envio.', [
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

        $cartItem = [
            'service' => $serviceId,
            'from' => $from,
            'to' => $to,
            'volumes' => $volumes,
            'options' => $orderOptions,
        ];

        if (isset($opcoes['products']) && is_array($opcoes['products'])) {
            $cartItem['products'] = $opcoes['products'];
        }

        if (isset($opcoes['agency']) && is_int($opcoes['agency'])) {
            $cartItem['agency'] = $opcoes['agency'];
        }

        $requestOptions = $this->withDefaultHeaders([
            'json' => $cartItem,
        ]);

        try {
            $response = $this->client->post('me/cart', $requestOptions);
        } catch (GuzzleException $exception) {
            $this->log('error', 'Falha ao adicionar remessa ao carrinho no Melhor Envio.', [
                'exception' => $exception,
                'cart_item' => $cartItem,
            ]);
            throw new RuntimeException('Falha ao adicionar remessa ao carrinho no Melhor Envio.', 0, $exception);
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['id'])) {
            $this->log('error', 'Resposta inesperada ao adicionar ao carrinho no Melhor Envio.', [
                'body' => $body,
            ]);
            throw new RuntimeException('Resposta inesperada ao adicionar ao carrinho no Melhor Envio.');
        }

        $this->log('info', 'Remessa adicionada ao carrinho com sucesso no Melhor Envio.', [
            'cart_item_id' => $decoded['id'],
        ]);

        return (string) $decoded['id'];
    }

    /**
     * Etapa 2: Finaliza a compra (checkout) dos itens no carrinho.
     *
     * @param string $cartItemId ID do item no carrinho
     * @return array Dados da compra realizada
     */
    private function finalizarCompra(string $cartItemId): array
    {
        $requestOptions = $this->withDefaultHeaders([
            'json' => [
                'orders' => [$cartItemId],
            ],
        ]);

        try {
            $response = $this->client->post('me/shipment/checkout', $requestOptions);
        } catch (GuzzleException $exception) {
            $this->log('error', 'Falha ao finalizar compra (checkout) no Melhor Envio.', [
                'exception' => $exception,
                'cart_item_id' => $cartItemId,
            ]);
            throw new RuntimeException('Falha ao finalizar compra no Melhor Envio.', 0, $exception);
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['purchase'])) {
            $this->log('error', 'Resposta inesperada ao finalizar compra no Melhor Envio.', [
                'body' => $body,
            ]);
            throw new RuntimeException('Resposta inesperada ao finalizar compra no Melhor Envio.');
        }

        $this->log('info', 'Compra finalizada com sucesso no Melhor Envio.', [
            'purchase' => $decoded['purchase'],
        ]);

        return $decoded;
    }

    /**
     * Etapa 3: Gera a etiqueta para a remessa comprada.
     *
     * @param array $purchaseData Dados da compra
     * @return array Dados da ordem gerada
     */
    private function gerarEtiquetaRemessa(array $purchaseData): array
    {
        $orderIds = $purchaseData['purchase']['orders'] ?? [];

        if (empty($orderIds) || !is_array($orderIds)) {
            $this->log('error', 'Nenhum order_id encontrado na resposta do checkout.', [
                'purchase_data' => $purchaseData,
            ]);
            throw new RuntimeException('Nenhum order_id encontrado após o checkout.');
        }

        $orderData = $orderIds[0] ?? null;

        if (!is_array($orderData) || !isset($orderData['id'])) {
            $this->log('error', 'Dados da ordem inválidos na resposta do checkout.', [
                'orders' => $orderIds,
            ]);
            throw new RuntimeException('Dados da ordem inválidos após o checkout.');
        }

        $status = $orderData['status'] ?? null;
        $statusesInvalidos = ['canceled', 'expired', 'suspended'];
        
        if (in_array($status, $statusesInvalidos)) {
            $this->log('error', 'Ordem em status inválido para geração de etiqueta.', [
                'order_id' => $orderData['id'],
                'status' => $status,
            ]);
            throw new RuntimeException("Ordem está em status '{$status}' e não pode ser processada.");
        }

        $orderId = $orderData['id'];

        $requestOptions = $this->withDefaultHeaders([
            'json' => [
                'orders' => [$orderId],
            ],
        ]);

        try {
            $response = $this->client->post('me/shipment/generate', $requestOptions);
        } catch (GuzzleException $exception) {
            $this->log('error', 'Falha ao solicitar geração de etiqueta no Melhor Envio.', [
                'exception' => $exception,
                'order_id' => $orderId,
            ]);
            throw new RuntimeException('Falha ao solicitar geração de etiqueta no Melhor Envio.', 0, $exception);
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            $this->log('error', 'Resposta inesperada ao solicitar geração de etiqueta no Melhor Envio.', [
                'body' => $body,
            ]);
            throw new RuntimeException('Resposta inesperada ao solicitar geração de etiqueta no Melhor Envio.');
        }

        // A API retorna confirmação assíncrona: {"generate_key": "...", "order-id": {"message": "...", "status": true}}
        $orderConfirmation = $decoded[$orderId] ?? null;
        $generateKey = $decoded['generate_key'] ?? null;
        
        if (!is_array($orderConfirmation) || ($orderConfirmation['status'] ?? false) !== true) {
            $this->log('error', 'Geração de etiqueta não foi confirmada pelo Melhor Envio.', [
                'body' => $body,
                'order_id' => $orderId,
            ]);
            throw new RuntimeException('Geração de etiqueta não foi confirmada pelo Melhor Envio.');
        }

        $this->log('info', 'Etiqueta encaminhada para geração no Melhor Envio.', [
            'order_id' => $orderId,
            'generate_key' => $generateKey,
            'message' => $orderConfirmation['message'] ?? null,
        ]);

        // Após o checkout, a ordem já está com status 'released' e pode ser impressa
        // Os dados completos da ordem já estão disponíveis na resposta do checkout
        // Não precisamos fazer polling, pois o print aceita ordens com status 'released'
        return $orderData;
    }

    /**
     * Etapa 4: Solicita a impressão da etiqueta com retry.
     *
     * @param string|int $orderId ID da ordem
     * @param array<string, mixed> $opcoes Opções adicionais
     * @return array Dados da etiqueta para impressão (contém URL)
     */
    private function imprimirEtiquetaRemessa($orderId, array $opcoes): array
    {
        $mode = $opcoes['print_mode'] ?? 'public';

        $requestOptions = $this->withDefaultHeaders([
            'json' => [
                'mode' => $mode,
                'orders' => [$orderId],
            ],
        ]);

        $maxTentativas = 3;
        $intervalo = 2; // segundos entre tentativas

        for ($tentativa = 1; $tentativa <= $maxTentativas; $tentativa++) {
            try {
                $response = $this->client->post('me/shipment/print', $requestOptions);
                $body = (string) $response->getBody();
                $decoded = json_decode($body, true);

                // Verificar se a resposta contém URL ou dados válidos
                if (!is_array($decoded) || (empty($decoded['url']) && empty($decoded['data']))) {
                    $this->log('warning', "Tentativa {$tentativa}/{$maxTentativas}: Resposta de impressão sem URL válida.", [
                        'order_id' => $orderId,
                        'response' => $decoded,
                    ]);

                    if ($tentativa < $maxTentativas) {
                        sleep($intervalo);
                        continue;
                    }

                    throw new RuntimeException('Resposta de impressão não contém URL válida.');
                }

                $this->log('info', 'URL de impressão obtida com sucesso no Melhor Envio.', [
                    'order_id' => $orderId,
                    'mode' => $mode,
                    'url' => $decoded['url'] ?? null,
                    'tentativa' => $tentativa,
                ]);

                return $decoded;

            } catch (GuzzleException $exception) {
                $this->log('warning', "Tentativa {$tentativa}/{$maxTentativas}: Falha ao solicitar impressão de etiqueta.", [
                    'exception' => $exception,
                    'order_id' => $orderId,
                    'mode' => $mode,
                ]);

                if ($tentativa < $maxTentativas) {
                    sleep($intervalo);
                    continue;
                }

                $this->log('error', 'Falha ao solicitar impressão de etiqueta após todas as tentativas.', [
                    'exception' => $exception,
                    'order_id' => $orderId,
                    'tentativas' => $maxTentativas,
                ]);

                throw new RuntimeException('Falha ao solicitar impressão de etiqueta no Melhor Envio.', 0, $exception);
            }
        }

        return [];
    }


    /**
     * Busca os dados atualizados de uma ordem com retry.
     *
     * @param string $orderId ID da ordem
     * @return array Dados completos e atualizados da ordem
     */
    private function buscarOrdem(string $orderId): array
    {
        // Aguardar um pouco para o sistema processar a geração
        // A geração de etiqueta é assíncrona e pode levar alguns segundos
        $delaySegundos = $this->config['buscar_ordem_delay'] ?? 3;
        
        if ($delaySegundos > 0) {
            $this->log('info', 'Aguardando processamento assíncrono da geração de etiqueta...', [
                'order_id' => $orderId,
                'delay_segundos' => $delaySegundos,
            ]);
            sleep($delaySegundos);
        }

        $maxTentativas = 3;
        $intervalo = 2; // segundos entre tentativas

        for ($tentativa = 1; $tentativa <= $maxTentativas; $tentativa++) {
            try {
                $response = $this->client->get("me/orders/{$orderId}", $this->withDefaultHeaders([]));
                
                $body = (string) $response->getBody();
                $orderData = json_decode($body, true);

                if (!is_array($orderData)) {
                    $this->log('warning', "Tentativa {$tentativa}/{$maxTentativas}: Resposta inesperada ao buscar ordem.", [
                        'body' => $body,
                        'order_id' => $orderId,
                    ]);
                    
                    if ($tentativa < $maxTentativas) {
                        sleep($intervalo);
                        continue;
                    }
                    
                    return ['id' => $orderId];
                }

                $this->log('info', 'Dados da ordem atualizados obtidos com sucesso.', [
                    'order_id' => $orderId,
                    'status' => $orderData['status'] ?? null,
                    'tracking' => $orderData['tracking'] ?? null,
                    'tentativa' => $tentativa,
                ]);

                return $orderData;

            } catch (GuzzleException $exception) {
                $this->log('warning', "Tentativa {$tentativa}/{$maxTentativas}: Falha ao buscar dados da ordem.", [
                    'exception' => $exception,
                    'order_id' => $orderId,
                ]);
                
                if ($tentativa < $maxTentativas) {
                    sleep($intervalo);
                    continue;
                }
                
                // Retornar array básico ao invés de falhar completamente
                return ['id' => $orderId];
            }
        }

        // Fallback: retornar dados básicos
        return ['id' => $orderId];
    }

    /**
     * Extrai o código de rastreamento dos dados da ordem.
     */
    private function extrairCodigoRastreamento(array $orderData): ?string
    {
        $trackingCode = $orderData['tracking'] ?? null;

        if ($trackingCode === null) {
            return null;
        }

        if (is_string($trackingCode)) {
            return $trackingCode;
        }

        if (is_array($trackingCode) && isset($trackingCode['code'])) {
            return $trackingCode['code'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function withDefaultHeaders(array $options): array
    {
        $headers = $options['headers'] ?? [];
        $headers['Accept'] = $headers['Accept'] ?? 'application/json';

        if (!empty($this->config['token']) && empty($headers['Authorization'])) {
            $headers['Authorization'] = 'Bearer ' . $this->config['token'];
        }

        $options['headers'] = $headers;

        return $options;
    }
}

<?php

declare(strict_types=1);

namespace Fagner\LaravelShippingGateway\Tests\Unit;

use Fagner\LaravelShippingGateway\Adapters\MelhorEnvioAdapter;
use Fagner\LaravelShippingGateway\DTOs\ShipmentRequest;
use Fagner\LaravelShippingGateway\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

final class MelhorEnvioAdapterTest extends TestCase
{
    /**
     * @covers \Fagner\LaravelShippingGateway\Adapters\MelhorEnvioAdapter::consultarPrecos
     */
    public function testConsultarPrecosMapeiaRespostaParaRateResult(): void
    {
        $config = [
            'base_uri' => 'https://www.melhorenvio.test/api/v2/',
            'timeout' => 10,
        ];

        $this->app['config']->set('shipping.providers.melhor_envio', $config);

        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'data' => [
                    [
                        'service' => ['name' => 'PAC'],
                        'service_name' => 'PAC',
                        'price' => 15.5,
                        'delivery_time' => 5,
                    ],
                    [
                        'service' => ['name' => 'SEDEX'],
                        'service_name' => 'SEDEX',
                        'price' => 28.3,
                        'delivery_time' => 2,
                    ],
                ],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);

        $client = new Client([
            'handler' => $handlerStack,
            'base_uri' => $config['base_uri'],
            'timeout' => $config['timeout'],
        ]);

        $adapter = new MelhorEnvioAdapter($config, $client);

        $solicitacao = new ShipmentRequest(
            cepOrigem: '01001-000',
            cepDestino: '20040-010',
            pesoKg: 1.2,
            comprimentoCm: 20.0,
            larguraCm: 15.0,
            alturaCm: 10.0,
            valor: 100.0
        );

        $rates = $adapter->consultarPrecos($solicitacao);

        $this->assertCount(2, $rates);
        $this->assertSame('melhor_envio', $rates[0]->provedor);
        $this->assertSame('PAC', $rates[0]->servico);
        $this->assertSame(15.5, $rates[0]->preco);
        $this->assertSame(5, $rates[0]->diasEstimados);
    }

    /**
     * @covers \Fagner\LaravelShippingGateway\Adapters\MelhorEnvioAdapter::consultarPrecos
     */
    public function testConsultarPrecosRetornaResultadoParaChamadaDireta(): void
    {
        $config = [
            'base_uri' => 'https://www.melhorenvio.test/api/v2/',
            'timeout' => 10,
        ];

        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'data' => [[
                    'service_name' => 'PAC',
                    'price' => 20,
                    'delivery_time' => 6,
                ]],
            ])),
        ]);

        $handlerStack = HandlerStack::create($mock);

        $client = new Client([
            'handler' => $handlerStack,
            'base_uri' => $config['base_uri'],
            'timeout' => $config['timeout'],
        ]);

        $adapter = new MelhorEnvioAdapter($config, $client);

        $solicitacao = new ShipmentRequest(
            cepOrigem: '01001-000',
            cepDestino: '20040-010',
            pesoKg: 1.0,
            comprimentoCm: 20.0,
            larguraCm: 15.0,
            alturaCm: 10.0,
            valor: 100.0
        );

        $rates = $adapter->consultarPrecos($solicitacao);

        $this->assertCount(1, $rates);
        $this->assertSame('PAC', $rates[0]->servico);
        $this->assertSame(20.0, $rates[0]->preco);
    }

    /**
     * @covers \Fagner\LaravelShippingGateway\Adapters\MelhorEnvioAdapter::gerarEtiqueta
     * @covers \Fagner\LaravelShippingGateway\Adapters\MelhorEnvioAdapter::imprimirEtiqueta
     */
    public function testGerarEImprimirEtiquetaProcessamPedidos(): void
    {
        $config = [
            'base_uri' => 'https://www.melhorenvio.test/api/v2/',
            'timeout' => 10,
            'buscar_ordem_delay' => 0, // Desabilitar delay nos testes para velocidade
        ];

        $this->app['config']->set('shipping.providers.melhor_envio', $config);

        // O fluxo otimizado do Melhor Envio tem 4 etapas + busca atualizada:
        // 1. Adicionar ao carrinho (POST me/cart)
        // 2. Finalizar compra (POST me/shipment/checkout)
        // 3. Gerar etiqueta (POST me/shipment/generate) - retorna confirmação assíncrona
        // 4. Imprimir etiqueta (POST me/shipment/print) - retorna URL
        // 5. Buscar ordem atualizada (GET me/orders/{id}) - para obter tracking code
        $mock = new MockHandler([
            // 1. Resposta do carrinho
            new Response(200, [], (string) json_encode([
                'id' => 'cart-item-123',
            ])),
            // 2. Resposta do checkout
            new Response(200, [], (string) json_encode([
                'purchase' => [
                    'id' => 'purchase-456',
                    'orders' => [
                        [
                            'id' => 'order-789',
                            'tracking' => null, // Ainda não tem tracking
                            'status' => 'released', // Status válido para prosseguir
                        ],
                    ],
                ],
            ])),
            // 3. Resposta do generate (confirmação assíncrona)
            new Response(200, [], (string) json_encode([
                'generate_key' => 'gen-key-123',
                'order-789' => [
                    'message' => 'Envio encaminhado para geração',
                    'status' => true,
                ],
            ])),
            // 4. Resposta do print (retorna URL)
            new Response(200, [], (string) json_encode([
                'url' => 'https://melhorenvio.test/imprimir/ABC123',
            ])),
            // 5. Resposta da busca da ordem atualizada (pode fazer até 3 tentativas)
            new Response(200, [], (string) json_encode([
                'id' => 'order-789',
                'status' => 'released',
                'tracking' => 'XX123456BR', // Agora tem o tracking code
            ])),
            new Response(200, [], (string) json_encode([
                'id' => 'order-789',
                'status' => 'released',
                'tracking' => 'XX123456BR',
            ])),
            new Response(200, [], (string) json_encode([
                'id' => 'order-789',
                'status' => 'released',
                'tracking' => 'XX123456BR',
            ])),
        ]);

        $history = [];
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push(Middleware::history($history));

        $client = new Client([
            'handler' => $handlerStack,
            'base_uri' => $config['base_uri'],
            'timeout' => $config['timeout'],
        ]);

        $adapter = new MelhorEnvioAdapter($config, $client);

        $solicitacao = new ShipmentRequest(
            cepOrigem: '01001-000',
            cepDestino: '20040-010',
            pesoKg: 1.2,
            comprimentoCm: 20.0,
            larguraCm: 15.0,
            alturaCm: 10.0,
            valor: 100.0,
            opcoes: [
                'service_id' => 123,
                'from' => [
                    'name' => 'Remetente',
                    'postal_code' => '01001000',
                ],
                'to' => [
                    'name' => 'Destinatário',
                    'postal_code' => '20040010',
                ],
            ]
        );

        $label = $adapter->gerarEtiqueta($solicitacao);

        $this->assertSame('melhor_envio', $label->provedor);
        $this->assertSame('XX123456BR', $label->codigoRastreio);
        $this->assertNull($label->etiquetaBase64); // Base64 removido para otimização
        
        // Verificar que a URL está disponível nos dados brutos
        $this->assertArrayHasKey('label_url', $label->bruto);
        $this->assertSame('https://melhorenvio.test/imprimir/ABC123', $label->bruto['label_url']);

        // Verificar que pelo menos 5 requisições foram executadas (pode haver retries)
        $this->assertGreaterThanOrEqual(5, count($history), 'Devem ser executadas no mínimo 5 requisições (cart, checkout, generate, print, get order).');
        $this->assertLessThanOrEqual(9, count($history), 'Não devem ser executadas mais de 9 requisições (5 principais + possíveis retries).');

        // Verificar requisição ao carrinho
        $cartRequest = $history[0]['request'];
        $cartPayload = json_decode((string) $cartRequest->getBody(), true);
        $this->assertSame('POST', $cartRequest->getMethod());
        $this->assertStringContainsString('me/cart', (string) $cartRequest->getUri());
        $this->assertSame(123, $cartPayload['service']);
        $this->assertSame(1200, $cartPayload['volumes'][0]['weight']);
        $this->assertSame(100.0, (float) $cartPayload['options']['insurance_value']);

        // Verificar requisição de checkout
        $checkoutRequest = $history[1]['request'];
        $checkoutPayload = json_decode((string) $checkoutRequest->getBody(), true);
        $this->assertSame('POST', $checkoutRequest->getMethod());
        $this->assertStringContainsString('me/shipment/checkout', (string) $checkoutRequest->getUri());
        $this->assertSame(['cart-item-123'], $checkoutPayload['orders']);

        // Verificar requisição de generate
        $generateRequest = $history[2]['request'];
        $generatePayload = json_decode((string) $generateRequest->getBody(), true);
        $this->assertSame('POST', $generateRequest->getMethod());
        $this->assertStringContainsString('me/shipment/generate', (string) $generateRequest->getUri());
        $this->assertSame(['order-789'], $generatePayload['orders']);

        // Verificar requisição de print
        $printRequest = $history[3]['request'];
        $printPayload = json_decode((string) $printRequest->getBody(), true);
        $this->assertSame('POST', $printRequest->getMethod());
        $this->assertStringContainsString('me/shipment/print', (string) $printRequest->getUri());
        $this->assertSame(['order-789'], $printPayload['orders']);
        $this->assertSame('private', $printPayload['mode']);

        // Verificar dados brutos completos
        $this->assertArrayHasKey('cart_item', $label->bruto);
        $this->assertArrayHasKey('purchase', $label->bruto);
        $this->assertArrayHasKey('order', $label->bruto);
        $this->assertArrayHasKey('label', $label->bruto);

        // Teste do alias imprimirEtiqueta (deve seguir o mesmo fluxo)
        $history = [];
        $mockAlias = new MockHandler([
            new Response(200, [], (string) json_encode(['id' => 'cart-item-999'])),
            new Response(200, [], (string) json_encode([
                'purchase' => ['id' => 'purchase-888', 'orders' => [['id' => 'order-777', 'tracking' => null, 'status' => 'released']]],
            ])),
            new Response(200, [], (string) json_encode([
                'generate_key' => 'gen-key-xyz',
                'order-777' => ['message' => 'Envio encaminhado para geração', 'status' => true],
            ])),
            new Response(200, [], (string) json_encode(['url' => 'https://melhorenvio.test/imprimir/XYZ789'])),
            // Mocks extras para retries da busca de ordem
            new Response(200, [], (string) json_encode([
                'id' => 'order-777',
                'tracking' => 'YY654321BR',
                'status' => 'released',
            ])),
            new Response(200, [], (string) json_encode([
                'id' => 'order-777',
                'tracking' => 'YY654321BR',
                'status' => 'released',
            ])),
            new Response(200, [], (string) json_encode([
                'id' => 'order-777',
                'tracking' => 'YY654321BR',
                'status' => 'released',
            ])),
        ]);

        $handlerStackAlias = HandlerStack::create($mockAlias);
        $handlerStackAlias->push(Middleware::history($history));

        $configAlias = [
            'base_uri' => $config['base_uri'],
            'timeout' => $config['timeout'],
            'buscar_ordem_delay' => 0, // Desabilitar delay nos testes
        ];

        $clientAlias = new Client([
            'handler' => $handlerStackAlias,
            'base_uri' => $configAlias['base_uri'],
            'timeout' => $configAlias['timeout'],
        ]);

        $adapterAlias = new MelhorEnvioAdapter($configAlias, $clientAlias);

        $labelImpressa = $adapterAlias->imprimirEtiqueta($solicitacao);
        $this->assertSame('YY654321BR', $labelImpressa->codigoRastreio);
        $this->assertNull($labelImpressa->etiquetaBase64); // Base64 removido
        $this->assertSame('https://melhorenvio.test/imprimir/XYZ789', $labelImpressa->bruto['label_url']);
        $this->assertGreaterThanOrEqual(5, count($history), 'imprimirEtiqueta() deve executar no mínimo 5 requisições.');
    }
}


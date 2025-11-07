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
        ];

        $this->app['config']->set('shipping.providers.melhor_envio', $config);

        $mock = new MockHandler([
            new Response(200, [], (string) json_encode([
                'data' => [[
                    'id' => 987654,
                    'tracking_code' => 'XX123456BR',
                ]],
            ])),
            new Response(200, [], (string) json_encode([
                'data' => [[
                    'base64' => base64_encode('PDF-DATA'),
                ]],
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
                'from' => ['zip_code' => '01001-000'],
                'to' => ['zip_code' => '20040-010'],
            ]
        );

        $label = $adapter->gerarEtiqueta($solicitacao);

        $this->assertSame('melhor_envio', $label->provedor);
        $this->assertSame('XX123456BR', $label->codigoRastreio);
        $this->assertSame(base64_encode('PDF-DATA'), $label->etiquetaBase64);

        $this->assertCount(2, $history, 'Era esperado que duas requisições fossem disparadas (criação e impressão).');

        $orderRequest = $history[0]['request'];
        $orderPayload = json_decode((string) $orderRequest->getBody(), true);

        $this->assertSame('POST', $orderRequest->getMethod());
        $this->assertNotNull($orderPayload);
        $this->assertSame(123, $orderPayload['orders'][0]['service']);
        $this->assertSame(1200, $orderPayload['orders'][0]['volumes'][0]['weight']);
        $this->assertSame(100.0, (float) $orderPayload['orders'][0]['options']['insurance_value']);

        $labelRequest = $history[1]['request'];
        $labelPayload = json_decode((string) $labelRequest->getBody(), true);

        $this->assertSame('POST', $labelRequest->getMethod());
        $this->assertSame([987654], $labelPayload['orders']);
        $this->assertSame('base64', $labelPayload['type']);

        // Aliases em português utilizam o mesmo fluxo.
        $history = [];
        $mockAlias = new MockHandler([
            new Response(200, [], (string) json_encode([
                'data' => [[
                    'id' => 123456,
                    'tracking_code' => 'YY654321BR',
                ]],
            ])),
            new Response(200, [], (string) json_encode([
                'data' => [[
                    'base64' => base64_encode('PDF-ALIAS'),
                ]],
            ])),
            new Response(200, [], (string) json_encode([
                'data' => [[
                    'id' => 654321,
                    'tracking_code' => 'ZZ112233BR',
                ]],
            ])),
            new Response(200, [], (string) json_encode([
                'data' => [[
                    'base64' => base64_encode('PDF-PRINT'),
                ]],
            ])),
        ]);

        $handlerStackAlias = HandlerStack::create($mockAlias);
        $handlerStackAlias->push(Middleware::history($history));

        $clientAlias = new Client([
            'handler' => $handlerStackAlias,
            'base_uri' => $config['base_uri'],
            'timeout' => $config['timeout'],
        ]);

        $adapterAlias = new MelhorEnvioAdapter($config, $clientAlias);

        $labelGerada = $adapterAlias->gerarEtiqueta($solicitacao);
        $this->assertSame('YY654321BR', $labelGerada->codigoRastreio);
        $this->assertSame(base64_encode('PDF-ALIAS'), $labelGerada->etiquetaBase64);

        $labelImpressa = $adapterAlias->imprimirEtiqueta($solicitacao);
        $this->assertSame('ZZ112233BR', $labelImpressa->codigoRastreio);
        $this->assertSame(base64_encode('PDF-PRINT'), $labelImpressa->etiquetaBase64);
    }
}


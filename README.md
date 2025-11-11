# Laravel Shipping Gateway

Gateway unificado para Melhor Envio e Correios com foco em integrações Laravel. A biblioteca expõe uma API simples em português para consultar preços, gerar e imprimir etiquetas, além de oferecer suporte nativo ao ambiente sandbox do Melhor Envio.

## Índice

- [Instalação](#instalação)
- [Configuração](#configuração)
- [Uso básico](#uso-básico)
- [Melhor Envio - Fluxo Completo](#melhor-envio---fluxo-completo)
- [Sandbox do Melhor Envio](#sandbox-do-melhor-envio)
- [Logs e observabilidade](#logs-e-observabilidade)
- [Testes](#testes)
- [Contribuindo](#contribuindo)

## Instalação

Adicione o pacote ao seu projeto Laravel via Composer:

```bash
composer require fagner/laravel-shipping-gateway
```

## Configuração

1. Registre o `ShippingServiceProvider` na sua aplicação Laravel:

   - **Laravel <= 10**: adicione `Fagner\LaravelShippingGateway\Providers\ShippingServiceProvider::class` ao array `providers` em `config/app.php`.
   - **Laravel 11+**: edite `bootstrap/app.php` e inclua o provider dentro de `withProviders`, por exemplo:

     ```php
     use Fagner\LaravelShippingGateway\Providers\ShippingServiceProvider;

     return Application::configure(basePath: dirname(__DIR__))
         // ...
         ->withProviders([
             ShippingServiceProvider::class,
         ])
         ->create();
     ```

2. Publique o arquivo de configuração (opcional):

   ```bash
   php artisan vendor:publish --tag=shipping-config
   ```

3. (Opcional) Publique e personalize o arquivo de configuração:

   ```bash
   php artisan vendor:publish --tag=shipping-config
   # ou php artisan vendor:publish --tag=laravel-shipping-gateway-config
   ```

   Caso não publique, a lib usa os valores padrão do pacote.

4. Defina as variáveis de ambiente necessárias no `.env` do projeto que consome a lib:

   ```dotenv
   SHIPPING_DEFAULT=melhor_envio

   MELHOR_ENVIO_TOKEN="seu-token"
   MELHOR_ENVIO_USE_SANDBOX=false
   MELHOR_ENVIO_BASE_URI=https://www.melhorenvio.com.br/api/v2/
   MELHOR_ENVIO_SANDBOX_BASE_URI=https://sandbox.melhorenvio.com.br/api/v2/

   CORREIOS_TOKEN=null
   CORREIOS_BASE_URI=https://api.correios.com.br/
   CORREIOS_TIMEOUT=10
   ```

5. Limpe ou recrie o cache de configuração se necessário:

   ```bash
   php artisan config:clear
   # ou
   php artisan config:cache
   ```

## Uso básico

Injete o `ShippingManager` onde precisar e monte um `ShipmentRequest` com os dados do frete.

```php
use Fagner\LaravelShippingGateway\DTOs\ShipmentRequest;
use Fagner\LaravelShippingGateway\Manager\ShippingManager;

class CheckoutController
{
    public function cotar(ShippingManager $shippingManager)
    {
        $solicitacao = new ShipmentRequest(
            cepOrigem: '01001-000',
            cepDestino: '20040-010',
            pesoKg: 1.2,
            comprimentoCm: 20,
            larguraCm: 15,
            alturaCm: 10,
            valor: 100,
            opcoes: [
                'service_id' => 123, // ID do serviço retornado pela API 
                'from' => ['zip_code' => '01001-000'],
                'to' => ['zip_code' => '20040-010'],
                // 'products' => [...], // opcional
                // 'volumes' => [...], // opcional: será gerado automaticamente se omitido
            ],
        );

        // Cotação individual do provedor configurado como padrão
        $cotacoes = $shippingManager->driver()->consultarPrecos($solicitacao);

        // Gerar etiqueta e recuperar PDF em base64
        $etiqueta = $shippingManager->driver()->gerarEtiqueta($solicitacao);

        // Quando precisar reaproveitar o mesmo payload para impressão
        $etiquetaImpressa = $shippingManager->driver()->imprimirEtiqueta($solicitacao);

        return response()->json([
            'cotacoes' => $cotacoes,
            'etiqueta' => [
                'codigo_rastreio' => $etiqueta->codigoRastreio,
                'pdf_base64' => $etiqueta->etiquetaBase64,
            ],
        ]);
    }
}
```

### Consultar preços de todos os provedores

```php
$todas = $shippingManager->getRatesFromAllProviders($solicitacao);
```

## Melhor Envio - Fluxo Completo

O `MelhorEnvioAdapter` implementa **corretamente** o fluxo oficial da API do Melhor Envio, que consiste em 4 etapas automáticas:

1. **Adicionar ao Carrinho** → `POST /api/v2/me/cart`
2. **Finalizar Compra (Checkout)** → `POST /api/v2/me/shipment/checkout`
3. **Gerar Etiqueta** → `POST /api/v2/me/shipment/generate`
4. **Imprimir Etiqueta** → `POST /api/v2/me/shipment/print`

Quando você chama `gerarEtiqueta()` ou `imprimirEtiqueta()`, todas essas etapas são executadas automaticamente. 

**📚 Para exemplos completos e detalhados de uso do Melhor Envio, consulte:**  
👉 **[EXEMPLO_MELHOR_ENVIO.md](EXEMPLO_MELHOR_ENVIO.md)**

O documento inclui exemplos de:
- Consulta de preços e obtenção do `service_id`
- Geração de etiquetas com todos os campos obrigatórios
- Múltiplos volumes e produtos
- Opções de seguro, AR, mão própria
- Tratamento de erros
- E muito mais!

## Sandbox do Melhor Envio

Ative o sandbox definindo `MELHOR_ENVIO_USE_SANDBOX=true` e forneça o endpoint/token apropriado. Consulte a [documentação oficial do Sandbox do Melhor Envio](https://docs.melhorenvio.com.br/docs/sandbox) para gerar credenciais, entender as limitações e simular fluxos com segurança.

## Logs e observabilidade

Os adapters emitem logs através de PSR-3 (`psr/log`). Em um projeto Laravel, o logger padrão do framework é detectado automaticamente. Eventos como falhas de requisição, respostas inesperadas ou etiquetas geradas sem conteúdo são registrados com níveis `error`/`warning`, enquanto operações bem-sucedidas de geração de etiqueta são registradas em `info`.

Se quiser inspecionar ou customizar os registros, ajuste o canal de log da aplicação ou injete um logger próprio ao resolver o `ShippingManager`.

## Testes

```bash
composer install
./vendor/bin/phpunit
```

Os testes utilizam `orchestra/testbench` com mocks do Guzzle, garantindo que as integrações com o Melhor Envio não dependem de chamadas reais.

## Contribuindo

1. Faça um fork e crie sua branch (`git checkout -b feature/minha-feature`).
2. Garanta que os testes continuam verdes (`./vendor/bin/phpunit`).
3. Envie um pull request descrevendo suas mudanças.

Bug reports e sugestões são bem-vindos!



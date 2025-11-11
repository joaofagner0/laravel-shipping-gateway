# Exemplo de Uso - Melhor Envio

Este documento explica como usar o `MelhorEnvioAdapter` seguindo o fluxo correto da API do Melhor Envio.

## 🔄 Fluxo Completo Implementado

O adapter agora implementa corretamente o fluxo oficial do Melhor Envio:

1. **Adicionar ao Carrinho** → `POST /api/v2/me/cart`
2. **Finalizar Compra (Checkout)** → `POST /api/v2/me/shipment/checkout`
3. **Gerar Etiqueta** → `POST /api/v2/me/shipment/generate`
4. **Imprimir Etiqueta** → `POST /api/v2/me/shipment/print`

## 📦 Exemplo Básico de Uso

```php
use Fagner\LaravelShippingGateway\Manager\ShippingManager;
use Fagner\LaravelShippingGateway\DTOs\ShipmentRequest;

// 1. Configurar o manager
$manager = app(ShippingManager::class);

// 2. Criar a solicitação de remessa
$solicitacao = new ShipmentRequest(
    cepOrigem: '01310-100',
    cepDestino: '04001-000',
    pesoKg: 1.5,
    alturaCm: 20,
    larguraCm: 30,
    comprimentoCm: 40,
    valor: 150.00,
    opcoes: [
        'service_id' => 1, // ID do serviço (obtido via consultarPrecos)
        'from' => [
            'name' => 'Sua Empresa',
            'phone' => '11999999999',
            'email' => 'contato@empresa.com.br',
            'document' => '12345678901',
            'address' => 'Rua Exemplo',
            'number' => '123',
            'complement' => 'Sala 1',
            'district' => 'Centro',
            'city' => 'São Paulo',
            'state_abbr' => 'SP',
            'postal_code' => '01310100',
        ],
        'to' => [
            'name' => 'Cliente Nome',
            'phone' => '11988888888',
            'email' => 'cliente@email.com',
            'document' => '98765432100',
            'address' => 'Av. Paulista',
            'number' => '1000',
            'complement' => 'Apto 101',
            'district' => 'Bela Vista',
            'city' => 'São Paulo',
            'state_abbr' => 'SP',
            'postal_code' => '04001000',
        ],
        'print_mode' => 'private', // 'private': URL temporária/autenticada; 'public': URL permanente compartilhável (padrão: public)
    ]
);

// 3. Gerar etiqueta (executa o fluxo completo automaticamente)
$resultado = $manager->driver('melhor_envio')->gerarEtiqueta($solicitacao);

// 4. Usar o resultado
echo "Código de Rastreio: " . $resultado->codigoRastreio . "\n";
echo "URL da Etiqueta: " . $resultado->bruto['label_url'] . "\n";

// Acessar dados brutos de cada etapa
$dadosCarrinho = $resultado->bruto['cart_item'];
$dadosCompra = $resultado->bruto['purchase'];
$dadosOrdem = $resultado->bruto['order'];
$dadosEtiqueta = $resultado->bruto['label'];
$urlEtiqueta = $resultado->bruto['label_url']; // URL para imprimir/baixar
```

## 🔍 Consultar Preços Primeiro

Antes de gerar etiquetas, consulte os preços disponíveis:

```php
$solicitacao = new ShipmentRequest(
    cepOrigem: '01310-100',
    cepDestino: '04001-000',
    pesoKg: 1.5,
    alturaCm: 20,
    larguraCm: 30,
    comprimentoCm: 40,
    valor: 150.00
);

$cotacoes = $manager->driver('melhor_envio')->consultarPrecos($solicitacao);

foreach ($cotacoes as $cotacao) {
    echo "Serviço: {$cotacao->servico}\n";
    echo "Preço: R$ {$cotacao->preco}\n";
    echo "Prazo: {$cotacao->diasEstimados} dias\n";
    echo "Service ID: {$cotacao->bruto['id']}\n"; // Use este ID no 'service_id'
    echo "---\n";
}
```

## 🖨️ Usando a URL da Etiqueta

A URL retornada pode ser usada de várias formas:

### No Controller (Laravel)

```php
// Redirecionar para impressão
return redirect($resultado->bruto['label_url']);

// Ou retornar para o frontend
return response()->json([
    'tracking_code' => $resultado->codigoRastreio,
    'label_url' => $resultado->bruto['label_url'],
    'order_id' => $resultado->bruto['order']['id'],
]);
```

### No Frontend (JavaScript/Vue/React)

```javascript
// Abrir em nova aba para impressão
window.open(labelUrl, '_blank');

// Ou usar em um iframe
<iframe src="{{ labelUrl }}" width="100%" height="600px"></iframe>

// Ou criar um link de download
<a href="{{ labelUrl }}" target="_blank" class="btn btn-primary">
    Imprimir Etiqueta
</a>
```

### Salvar no Banco de Dados

```php
// Exemplo de salvamento no banco
Shipment::create([
    'tracking_code' => $resultado->codigoRastreio,
    'label_url' => $resultado->bruto['label_url'],
    'order_id' => $resultado->bruto['order']['id'],
    'service' => $resultado->bruto['order']['service']['name'],
    'price' => $resultado->bruto['order']['price'],
    'status' => $resultado->bruto['order']['status'],
]);
```

## ⚙️ Opções Avançadas

### Múltiplos Volumes

```php
$solicitacao = new ShipmentRequest(
    cepOrigem: '01310-100',
    cepDestino: '04001-000',
    pesoKg: 1.5,
    alturaCm: 20,
    larguraCm: 30,
    comprimentoCm: 40,
    valor: 150.00,
    opcoes: [
        'service_id' => 1,
        'from' => [...],
        'to' => [...],
        'volumes' => [
            [
                'weight' => 1,
                'height' => 20,
                'width' => 30,
                'length' => 40,
            ],
            [
                'weight' => 5,
                'height' => 45,
                'width' => 20,
                'length' => 30,
            ],
        ],
    ]
);
```

### Produtos na Remessa

```php
$solicitacao = new ShipmentRequest(
    cepOrigem: '01310-100',
    cepDestino: '04001-000',
    pesoKg: 1.5,
    alturaCm: 20,
    larguraCm: 30,
    comprimentoCm: 40,
    valor: 150.00,
    opcoes: [
        'service_id' => 1,
        'from' => [...],
        'to' => [...],
        'products' => [
            [
                'name' => 'Produto 1',
                'quantity' => 2,
                'unitary_value' => 50.00,
            ],
            [
                'name' => 'Produto 2',
                'quantity' => 1,
                'unitary_value' => 50.00,
            ],
        ],
    ]
);
```

### Agência para Retirada

```php
$solicitacao = new ShipmentRequest(
    cepOrigem: '01310-100',
    cepDestino: '04001-000',
    pesoKg: 1.5,
    alturaCm: 20,
    larguraCm: 30,
    comprimentoCm: 40,
    valor: 150.00,
    opcoes: [
        'service_id' => 1,
        'from' => [...],
        'to' => [...],
        'agency' => 123, // ID da agência dos Correios
    ]
);
```

### Opções de Seguro

```php
$solicitacao = new ShipmentRequest(
    cepOrigem: '01310-100',
    cepDestino: '04001-000',
    pesoKg: 1.5,
    alturaCm: 20,
    larguraCm: 30,
    comprimentoCm: 40,
    valor: 150.00,
    opcoes: [
        'service_id' => 1,
        'from' => [...],
        'to' => [...],
        'options' => [
            'insurance_value' => 150.00, // Valor declarado para seguro
            'receipt' => false, // Aviso de recebimento (AR)
            'own_hand' => false, // Mão própria
            'reverse' => false, // Logística reversa
            'non_commercial' => false, // Envio não comercial
            'invoice' => [
                'key' => '12345678901234567890123456789012345678901234', // Chave da NF-e
            ],
        ],
    ]
);
```

## 🧪 Modo Sandbox

Para testar em ambiente de sandbox:

```php
// No arquivo config/shipping.php
'melhor_envio' => [
    'token' => env('MELHOR_ENVIO_TOKEN'),
    'use_sandbox' => true, // Ativar sandbox
    'sandbox_base_uri' => 'https://sandbox.melhorenvio.com.br/api/v2/',
],
```

## 📋 Estrutura do Resultado

O `LabelResult` retornado contém:

```php
LabelResult {
    provedor: 'melhor_envio',
    codigoRastreio: 'BR123456789BR', // Código de rastreamento
    etiquetaBase64: null, // Não retornamos base64 (otimização)
    bruto: [
        'cart_item' => '...', // ID do item no carrinho
        'purchase' => [...], // Dados da compra/checkout
        'order' => [...], // Dados da ordem gerada com tracking code
        'label' => [...], // Dados da etiqueta (contém URL)
        'label_url' => 'https://...', // URL direta da etiqueta (atalho)
    ]
}
```

### Dados Importantes Disponíveis

```php
// Código de rastreamento
$tracking = $resultado->codigoRastreio;

// URL da etiqueta para impressão
$url = $resultado->bruto['label_url'];

// ID da ordem no Melhor Envio
$orderId = $resultado->bruto['order']['id'];

// Dados do serviço
$servico = $resultado->bruto['order']['service']['name']; // Ex: "PAC"
$empresa = $resultado->bruto['order']['service']['company']['name']; // Ex: "Correios"

// Preços e prazos
$preco = $resultado->bruto['order']['price'];
$prazoMin = $resultado->bruto['order']['delivery_min'];
$prazoMax = $resultado->bruto['order']['delivery_max'];

// Status da ordem
$status = $resultado->bruto['order']['status']; // Ex: "released"

// Protocolo
$protocolo = $resultado->bruto['order']['protocol'];
```

## ⚠️ Observações Importantes

1. **Token de Autenticação**: Certifique-se de que o token do Melhor Envio está configurado corretamente no `.env`
2. **Saldo**: O checkout **consome saldo** da sua conta Melhor Envio. Certifique-se de ter saldo suficiente.
3. **Dados Obrigatórios**: Os campos `from` e `to` devem conter **todos** os dados necessários para o envio.
4. **Service ID**: Obtenha o `service_id` através do método `consultarPrecos()` antes de gerar a etiqueta.
5. **URL da Etiqueta**: A API retorna uma **URL** para visualização/impressão da etiqueta, não o PDF diretamente. Use essa URL para abrir em nova aba, iframe ou redirecionar o usuário.
6. **Modo de Impressão**: 
   - `private`: Gera URL privada temporária
   - `public`: Gera URL pública permanente
7. **Otimização**: Não tentamos baixar o PDF automaticamente (economia de 6-9 segundos). A URL fornecida é suficiente para impressão/download.

## 🔧 Tratamento de Erros

O adapter lança `RuntimeException` em caso de erros. Recomenda-se usar try-catch:

```php
try {
    $resultado = $manager->driver('melhor_envio')->gerarEtiqueta($solicitacao);
    // Sucesso
} catch (\RuntimeException $e) {
    // Tratar erro
    echo "Erro ao gerar etiqueta: " . $e->getMessage();
    
    // Verificar logs para mais detalhes
}
```

## 📚 Referências

- [Documentação Oficial da API Melhor Envio](https://docs.melhorenvio.com.br)
- [Fluxograma de Integração](https://melhorenvio.s3.sa-east-1.amazonaws.com/partners/manual/Diagrama+de+integra%C3%A7%C3%A3o+-+Fluxograma+de+compra+e+impress%C3%A3o+de+etiqueta+de+envio.pdf)


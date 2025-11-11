# Changelog - Implementação do Fluxo Correto do Melhor Envio

## 🎯 Resumo das Mudanças

Refatoração completa do `MelhorEnvioAdapter` para implementar corretamente o fluxo oficial da API do Melhor Envio, conforme documentado no [Diagrama de Integração](https://melhorenvio.s3.sa-east-1.amazonaws.com/partners/manual/Diagrama+de+integra%C3%A7%C3%A3o+-+Fluxograma+de+compra+e+impress%C3%A3o+de+etiqueta+de+envio.pdf).

## ❌ Problema Anterior

O código anterior estava **pulando etapas críticas** do fluxo:
- ❌ Não adicionava ao carrinho (`me/cart`)
- ❌ Não finalizava a compra/checkout (`me/shipment/checkout`)
- ⚠️ Ia direto para geração (`me/shipment/generate`)
- ⚠️ Usava endpoint incorreto para impressão (`shipping/labels` ao invés de `me/shipment/print`)

Isso resultava em **falhas** ou comportamento inesperado na API.

## ✅ Solução Implementada

### Novo Fluxo Completo (4 Etapas)

Agora o adapter implementa corretamente todas as etapas obrigatórias:

1. **Adicionar ao Carrinho** → `POST /api/v2/me/cart`
2. **Finalizar Compra (Checkout)** → `POST /api/v2/me/shipment/checkout`
3. **Gerar Etiqueta** → `POST /api/v2/me/shipment/generate`
4. **Imprimir Etiqueta** → `POST /api/v2/me/shipment/print`

### Arquitetura Modular

O código foi refatorado em métodos privados bem definidos:

```php
// Método público (interface mantida)
public function gerarEtiqueta(ShipmentRequest $solicitacaoRemessa): LabelResult

// Orquestração do fluxo completo
private function processarFluxoCompleto(ShipmentRequest $solicitacaoRemessa): LabelResult

// Etapa 1: Adicionar ao carrinho
private function adicionarAoCarrinho(ShipmentRequest $solicitacaoRemessa): string

// Etapa 2: Finalizar compra
private function finalizarCompra(string $cartItemId): array

// Etapa 3: Gerar etiqueta
private function gerarEtiquetaRemessa(array $purchaseData): array

// Etapa 4: Imprimir etiqueta
private function imprimirEtiquetaRemessa($orderId, array $opcoes): array

// Utilitários
private function downloadLabelAsBase64(string $url): ?string
private function extrairCodigoRastreamento(array $orderData): ?string
```

## 🔧 Melhorias Implementadas

### 1. Separação de Responsabilidades
- Cada etapa do fluxo é um método independente
- Facilita manutenção, debugging e testes
- Código mais legível e autodocumentado

### 2. Tratamento de Erros Aprimorado
- Logs detalhados em cada etapa
- Mensagens de erro específicas para cada ponto de falha
- Validações de dados em cada etapa

### 3. Conversão Automática Base64
- Se `label_type` for `'base64'`, a etiqueta PDF é baixada e convertida automaticamente
- Fallback para URL se o download falhar

### 4. Dados Brutos Completos
O `LabelResult` agora retorna informações de **todas as etapas**:

```php
LabelResult {
    bruto: [
        'cart_item' => '...', // ID do item no carrinho
        'purchase' => [...],  // Dados da compra
        'order' => [...],     // Dados da ordem
        'label' => [...],     // Dados da etiqueta
    ]
}
```

### 5. Suporte a Novas Opções

#### Agência dos Correios
```php
'opcoes' => [
    'agency' => 123, // ID da agência
]
```

#### Modo de Impressão
```php
'opcoes' => [
    'print_mode' => 'private', // ou 'public'
]
```

## 📝 Compatibilidade

### ✅ Interface Pública Mantida
A API pública permanece a mesma:
- `consultarPrecos()`
- `gerarEtiqueta()`
- `imprimirEtiqueta()`

### ⚠️ Mudanças Necessárias nas Opções

Agora é **obrigatório** fornecer os dados completos de `from` e `to`:

**Antes (não funcionava):**
```php
'opcoes' => [
    'service_id' => 1,
    'from' => ['zip_code' => '01001-000'],
    'to' => ['zip_code' => '20040-010'],
]
```

**Agora (correto):**
```php
'opcoes' => [
    'service_id' => 1,
    'from' => [
        'name' => 'Sua Empresa',
        'phone' => '11999999999',
        'email' => 'contato@empresa.com.br',
        'document' => '12345678901',
        'address' => 'Rua Exemplo',
        'number' => '123',
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
        'district' => 'Bela Vista',
        'city' => 'São Paulo',
        'state_abbr' => 'SP',
        'postal_code' => '04001000',
    ],
]
```

## 🧪 Testes Atualizados

Os testes foram completamente reescritos para validar o fluxo completo:
- ✅ Verifica todas as 4 etapas do processo
- ✅ Valida os payloads de cada requisição
- ✅ Confirma os endpoints corretos
- ✅ Testa conversão base64
- ✅ Valida dados brutos retornados

## 📚 Documentação Criada

### `EXEMPLO_MELHOR_ENVIO.md`
Documento completo com:
- Explicação do fluxo
- Exemplos de uso básico
- Exemplos avançados (múltiplos volumes, produtos, agência)
- Configuração de sandbox
- Tratamento de erros
- Referências

### `README.md` Atualizado
- Nova seção sobre o fluxo do Melhor Envio
- Link para documentação detalhada
- Índice atualizado

## 🎁 Benefícios

1. **Conformidade com a API Oficial**: Segue exatamente o fluxo documentado pelo Melhor Envio
2. **Código Mais Limpo**: Arquitetura modular e bem organizada
3. **Melhor Debugging**: Logs detalhados em cada etapa
4. **Facilidade de Uso**: API simples que abstrai a complexidade
5. **Manutenibilidade**: Fácil adicionar novos recursos ou corrigir bugs
6. **Testabilidade**: Testes completos garantem o funcionamento correto

## 🚀 Como Usar

Consulte o [EXEMPLO_MELHOR_ENVIO.md](EXEMPLO_MELHOR_ENVIO.md) para exemplos práticos e detalhados.

## ⚡ Performance

O fluxo agora executa 4 requisições HTTP (+ 1 opcional para download do PDF se `label_type` for `base64`):
1. `POST me/cart` (~100-200ms)
2. `POST me/shipment/checkout` (~200-500ms)
3. `POST me/shipment/generate` (~500-1000ms)
4. `POST me/shipment/print` (~100-300ms)
5. `GET [label_url]` (~200-500ms) - apenas se `label_type` = `base64`

**Total estimado**: 1-2 segundos para o fluxo completo.

## 🔒 Segurança

- Token de autenticação é enviado em todas as requisições
- Suporte a ambiente sandbox para testes seguros
- Validações de dados antes de enviar para a API
- Logs não expõem informações sensíveis

## 📞 Suporte

Para dúvidas ou problemas:
1. Consulte a [documentação oficial do Melhor Envio](https://docs.melhorenvio.com.br)
2. Verifique o arquivo [EXEMPLO_MELHOR_ENVIO.md](EXEMPLO_MELHOR_ENVIO.md)
3. Analise os logs da aplicação
4. Abra uma issue no repositório

---

**Data da Implementação**: Novembro 2025  
**Versão**: 2.0.0 (breaking change nas opções obrigatórias)


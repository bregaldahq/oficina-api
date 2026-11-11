# ADR-015 · Pagamento com Mercado Pago, antes do reparo

## Status

Aceita (Fase 4).

## Contexto

O sistema não tinha pagamento. A Fase 4 exige registrar e verificar pagamentos integrando com o
Mercado Pago, e que a OS avance depois do pagamento. Duas perguntas precisam de resposta: **em
que ponto do fluxo** se cobra, e **com qual produto** do Mercado Pago.

Sobre o ponto: uma oficina de bairro costuma cobrar na entrega. Mas cobrar depois do reparo
deixa a saga sem nada para desfazer no passo mais caro. Cobrar antes dá ao sistema o caso que
justifica a saga: se o reparo falha, o pagamento precisa ser estornado.

Sobre o produto: a demonstração precisa mostrar um pagamento sendo feito de ponta a ponta no
ambiente de teste.

## Decisão

- **O pagamento acontece depois da aprovação do orçamento e antes do reparo.** A OS fica em
  `AWAITING_PAYMENT` e só vai para `EXECUTING` com o pagamento confirmado.
- **Checkout Pro**: o Faturamento cria uma preferência de pagamento com `external_reference`
  igual ao id do orçamento e `notification_url` apontando para o webhook; o cliente paga pelo
  link (`payment_url`).
- **O webhook não é fonte de verdade.** `POST /api/billing/payments/webhook/mercadopago` valida
  a assinatura do cabeçalho `x-signature` com o `MP_WEBHOOK_SECRET` e, em seguida, **consulta o
  pagamento na API** do Mercado Pago. Só o resultado dessa consulta confirma ou recusa.
- **Estorno** pela API de reembolso, disparado pelo comando `billing.refund-payment` quando o
  reparo falha.
- Toda chamada de escrita leva `X-Idempotency-Key` derivada do id do orçamento, para que uma
  reentrega da mensagem não crie duas cobranças nem dois estornos.
- No domínio do Faturamento existe a porta `PaymentGateway`, com dois adaptadores: o do Mercado
  Pago e um falso, determinístico, usado nos testes, no BDD e no e2e.
- As credenciais ficam no segredo `oficina/<env>/faturamento/mercadopago`, gravado à mão. Em
  `hml` são as credenciais de teste.
- Os detalhes de cada chamada (caminhos, formato do manifesto da assinatura, campos de resposta)
  são conferidos na documentação oficial no momento da implementação e registrados no README
  do `oficina-faturamento`.

## Consequências

**Positivas**

- A saga tem uma compensação real e demonstrável: reparo falha, cliente é reembolsado.
- A oficina não executa serviço sem pagamento garantido.
- O adaptador falso permite testar todos os caminhos sem rede e sem conta.
- O sistema não toca em dados de cartão: o cliente paga na página do Mercado Pago.

**Negativas**

- O cliente paga antes de ver o serviço feito; num produto real isso pediria sinal parcial ou
  pré-autorização.
- O webhook só funciona com URL pública: o fluxo real só é exercitado no ambiente `hml`, não
  na máquina de desenvolvimento.
- Mais um segredo com valor mantido à mão.
- Dependência de um terceiro no caminho crítico: Mercado Pago fora do ar deixa OS em
  `AWAITING_PAYMENT` até o prazo.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **Cobrar na entrega do veículo** | Mais fiel ao negócio, mas o pagamento deixa de participar da saga: não haveria o que estornar. |
| **Pix pela API de pagamentos** | Integração mais curta (um QR code), porém difícil de "pagar" no ambiente de teste, o que comprometeria a demonstração. |
| **Checkout transparente (cartão direto na API)** | Colocaria dados de cartão passando pelo sistema. |
| **Confiar no corpo do webhook** | Qualquer um que descobrisse a URL poderia confirmar um pagamento. |

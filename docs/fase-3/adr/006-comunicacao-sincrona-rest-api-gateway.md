# ADR-006 · Comunicação síncrona REST via API Gateway

## Status

Aceita (Fase 3).

## Contexto

A Fase 3 quebra o que era um monolito containerizado em quatro artefatos com ciclos de vida
próprios: a rede e o banco (`oficina-infra-fundacao`), o cluster (`oficina-infra-cluster`), a função
de autenticação (`oficina-gateway-auth`) e a aplicação (`oficina-api`). Com
isso surge a pergunta de estilo de integração entre eles: chamadas síncronas ou mensageria?

A tentação de usar SQS/SNS/EventBridge é grande porque "microsserviços usam eventos". Mas os
fluxos reais deste sistema são todos **request/response com resposta imediata esperada pelo
usuário**:

- `POST /auth/cpf` — o cliente digita o CPF e precisa do token **agora** para continuar.
- `GET /api/service-orders/me` — o cliente quer ver as OS na tela.
- `POST /api/service-orders` — o atendente precisa do identificador da OS para imprimir.
- Autorização de cada requisição — por definição, bloqueante.

Não existe, no escopo atual, nenhum trabalho que possa ser aceito e concluído depois: não há
processamento pesado, não há integração com terceiros lenta, não há pico de escrita que exija
absorção por fila. O único fluxo assíncrono do domínio é a **aprovação de orçamento pelo
cliente**, e ela já chega de fora como webhook (`POST /api/service-orders/{id}/approval`) — o
sistema é o receptor, não o produtor, da assincronia.

Internamente, os eventos de domínio já existem (`InMemoryEventDispatcher`) e cobrem
desacoplamento *dentro* do processo: notificação por email, histórico de status e telemetria são
assinantes. Isso resolve extensibilidade sem infraestrutura de mensageria.

## Decisão

Adotar **comunicação síncrona HTTP/REST**, com o **API Gateway HTTP API** como único ponto de
entrada:

| Rota | Integração | Authorizer |
|---|---|---|
| `POST /auth/cpf` | Lambda `auth-cpf` (proxy) | nenhum |
| `ANY /api/{proxy+}` | `HTTP_PROXY` via VPC Link → NLB interno :80 | Lambda `jwt-authorizer` |

Consequências de desenho que acompanham a decisão:

- O NLB é **interno**; o cluster não é exposto diretamente à internet. Só o gateway fala com ele,
  pelo VPC Link.
- O `jwt-authorizer` usa cache de resultado de 300 s, o que evita uma invocação de Lambda por
  requisição em rajadas.
- Throttling e access log em JSON ficam no gateway, não na aplicação (motivo pelo qual o rate
  limit em arquivo do `AuthController` foi removido — WS-D14).
- Falhas são propagadas com códigos HTTP; não há retry automático entre componentes além do que o
  cliente fizer.

Mensageria fica **explicitamente fora de escopo desta fase**, e não por desconhecimento: é uma
decisão de proporcionalidade, registrada aqui para ser revisitada quando houver um caso de uso
que a justifique.

## Consequências

**Positivas**

- Modelo mental simples e depurável: uma requisição, um `correlation_id`, um trace distribuído no
  New Relic do gateway até o Pod.
- Latência mínima no caminho crítico — sem hop de fila, sem polling, sem espera por consumidor.
- Erros são imediatos e visíveis para o chamador; não há mensagem morta em DLQ silenciosa.
- Ponto único de entrada concentra autenticação, throttling, log de acesso e TLS.
- Custo operacional baixo: nada de tópicos, filas, DLQs e consumidores para monitorar.

**Negativas**

- **Acoplamento temporal**: se o Pod estiver indisponível, a requisição falha. Não há buffer. Mitigado
  por HPA (ADR-007), múltiplas réplicas, `readinessProbe` em `/api/ready` e health check do target
  group.
- Falha em cascata é possível: RDS lento → Pod lento → gateway com timeout. Mitigado por timeouts
  explícitos e alertas de p95 (E14), mas não eliminado — não há circuit breaker nesta fase.
- Picos de escrita são absorvidos por escalonamento, que leva dezenas de segundos, e não por uma
  fila, que absorve instantaneamente.
- O API Gateway vira ponto único de falha lógico (é gerenciado e multi-AZ, mas é uma dependência
  dura).
- Integrar um consumidor externo futuro exigirá polling ou webhook, não subscrição.

## Alternativas consideradas

| Alternativa | Avaliação | Veredito |
|---|---|---|
| **SQS entre gateway e aplicação** | Absorveria picos e desacoplaria disponibilidade. Mas nenhum fluxo tolera resposta assíncrona — exigiria inventar um protocolo de polling de resultado só para justificar a fila. | Rejeitada |
| **EventBridge para eventos de domínio** | Permitiria assinantes externos ao processo. Hoje todos os assinantes são internos e in-process; o barramento traria latência e custo sem consumidor real. | Adiada — reavaliar quando houver o primeiro consumidor externo |
| **gRPC entre componentes** | Ganho de performance irrelevante nesta escala, e o API Gateway HTTP API não integra gRPC nativamente. Perderíamos a interoperabilidade do REST/JSON com o avaliador e o Postman. | Rejeitada |
| **ALB no lugar do API Gateway** | Mais barato em alto volume e suporta autenticação OIDC nativa. Mas não oferece Lambda Authorizer REQUEST com contexto customizado, throttling por rota nem chave de API — e o custo fixo do ALB é maior que o do HTTP API neste volume. | Rejeitada (ver RFC-003) |
| **Service mesh (Istio/App Mesh)** | Resolveria retry, circuit breaker e mTLS interno. Complexidade e consumo de recursos incompatíveis com um cluster de 2 nodes `t3.small`. | Rejeitada |

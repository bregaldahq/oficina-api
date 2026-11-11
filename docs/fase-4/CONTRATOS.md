# Contratos compartilhados — Fase 4

> **Documento normativo da Fase 4.** Ele acrescenta ao
> [`../fase-3/CONTRATOS.md`](../fase-3/CONTRATOS.md): tudo o que não está alterado aqui continua
> valendo como está lá. Em caso de divergência entre este arquivo e uma implementação, este
> arquivo vence.
>
> **Estado: desenho (2026-11-10).** Cada seção diz o que muda. Até a implementação entrar em
> `develop`, o sistema em execução segue o contrato da Fase 3.

Nomes usados neste documento:

| Serviço | Repositório | Chave curta (`<servico>`) | `service` nos logs e no APM |
|---|---|---|---|
| OS | `oficina-api` | `os` | `oficina-api` |
| Faturamento | `oficina-faturamento` | `faturamento` | `oficina-faturamento` |
| Execução | `oficina-execucao` | `execucao` | `oficina-execucao` |

No código, os conceitos continuam em inglês (glossário do `AGENTS.md`): orçamento é `Budget`,
pagamento é `Payment`, tarefa da oficina é `WorkTask`.

---

## 1. Propriedade de recursos

Acrescenta à tabela da Fase 3. São seis repositórios (ADR-016).

| Recurso | Repositório dono |
|---|---|
| Tópico SNS `oficina-<env>-mensagens` | `oficina-infra-fundacao` |
| Chave `INTERNAL_TOKEN` no segredo `oficina/<env>/auth` | `oficina-infra-fundacao` |
| RDS do OS e as migrations dele (como na Fase 3) | `oficina-infra-fundacao` |
| Listener e target group de cada serviço no NLB interno | `oficina-infra-cluster` |
| RBAC de deploy dos três serviços | `oficina-infra-cluster` |
| Rotas por serviço no API Gateway | `oficina-gateway-auth` |
| Fila SQS, DLQ e assinatura do tópico de cada serviço | `infra/` do repo do serviço |
| Role IRSA de cada serviço | `infra/` do repo do serviço |
| ECR de `oficina-faturamento` e `oficina-execucao` | `infra/` do repo do serviço |
| RDS MySQL, segredos e migrations do Faturamento | `oficina-faturamento` |
| Tabela DynamoDB da Execução | `oficina-execucao` |
| Deployments `api` e `worker`, Service, HPA de cada serviço | `deploy/` do repo do serviço |

O ECR do OS continua no `oficina-infra-cluster`, como na Fase 3.

> **Adendo F4-1 (segredos).** A regra "todos os segredos nascem na fundação" passa a valer para
> os segredos **compartilhados** (`oficina/<env>/db`, do OS, e `oficina/<env>/auth`). Segredo
> que só um serviço lê nasce no `infra/` desse serviço.

Ordem de `apply`: **fundação → cluster → gateway → `infra/` de cada serviço → deploy de cada
serviço**. Os três serviços não dependem uns dos outros para subir. `destroy`: inversa.

---

## 2. SSM Parameter Store

Acrescenta aos parâmetros da Fase 3. Nenhum parâmetro existente muda de nome.

### Publicados por `oficina-infra-fundacao`

| Parâmetro | Conteúdo |
|---|---|
| `/oficina/<env>/messaging/topic_arn` | ARN do tópico SNS |

### Publicados por `oficina-infra-cluster`

| Parâmetro | Conteúdo |
|---|---|
| `/oficina/<env>/nlb/dns_name` | nome DNS do NLB interno (a `auth-cpf` chama o OS por ele) |
| `/oficina/<env>/nlb/faturamento/listener_arn` | listener `:81` |
| `/oficina/<env>/nlb/faturamento/target_group_arn` | target group do Faturamento |
| `/oficina/<env>/nlb/execucao/listener_arn` | listener `:82` |
| `/oficina/<env>/nlb/execucao/target_group_arn` | target group da Execução |

`/oficina/<env>/nlb/listener_arn` e `/oficina/<env>/nlb/target_group_arn` continuam sendo os
do OS (`:80`).

### Publicados pelo `infra/` de cada serviço

| Parâmetro | Quem publica | Conteúdo |
|---|---|---|
| `/oficina/<env>/<servico>/queue_url` | os três | URL da fila do serviço |
| `/oficina/<env>/<servico>/queue_arn` | os três | ARN da fila |
| `/oficina/<env>/<servico>/irsa_role_arn` | os três | role assumido pelo ServiceAccount do serviço |
| `/oficina/<env>/<servico>/ecr_repository_url` | `faturamento`, `execucao` | URL do ECR |
| `/oficina/<env>/faturamento/db_secret_arn` | `faturamento` | ARN do segredo de banco |
| `/oficina/<env>/faturamento/mercadopago_secret_arn` | `faturamento` | ARN do segredo do Mercado Pago |
| `/oficina/<env>/execucao/table_name` | `execucao` | nome da tabela DynamoDB |

Esses parâmetros são lidos pelo `deploy.yml` do próprio serviço. Nenhum serviço lê parâmetro de
outro serviço.

---

## 3. Segredos

| Segredo | Dono | Conteúdo | Quem lê |
|---|---|---|---|
| `oficina/<env>/db` | fundação | como na Fase 3 | **só o OS** (a `auth-cpf` deixa de ler, §7) |
| `oficina/<env>/auth` | fundação | as chaves da Fase 3 mais `INTERNAL_TOKEN` (32 caracteres, `random_password`) | os três serviços e as Lambdas |
| `oficina/<env>/faturamento/db` | `oficina-faturamento` | `host`, `port`, `dbname`, `username`, `password` (gerada) | só o Faturamento |
| `oficina/<env>/faturamento/mercadopago` | `oficina-faturamento` | `MP_ACCESS_TOKEN`, `MP_WEBHOOK_SECRET` | só o Faturamento |

O segredo do Mercado Pago é criado pelo Terraform com valor provisório e
`lifecycle { ignore_changes = [secret_string] }`. O valor real é gravado à mão pelo dono da
conta. Nenhum pipeline nem agente lê esse valor.

Cada serviço materializa os segredos com um `ExternalSecret` no `ClusterSecretStore`
`oficina-secretsmanager`. A policy do External Secrets precisa alcançar `oficina/<env>/*`.

A Execução não tem segredo de banco: acessa o DynamoDB com o role IRSA.

---

## 4. Rotas e autorização

### API Gateway (`oficina-gateway-auth`)

Cada serviço tem um prefixo. O API Gateway escolhe a rota mais específica.

| Rota | Integração | Authorizer |
|---|---|---|
| `ANY /api/billing/{proxy+}` | VPC Link → NLB `:81` (Faturamento) | `jwt-authorizer` |
| `ANY /api/execution/{proxy+}` | VPC Link → NLB `:82` (Execução) | `jwt-authorizer` |
| `ANY /api/{proxy+}` | VPC Link → NLB `:80` (OS) | `jwt-authorizer` |
| `GET /api/billing/health`, `GET /api/billing/ready` | NLB `:81` | nenhum |
| `GET /api/execution/health`, `GET /api/execution/ready` | NLB `:82` | nenhum |
| `POST /api/billing/budgets/{id}/approval` | NLB `:81` | nenhum (o serviço exige `X-Webhook-Token`) |
| `POST /api/billing/payments/webhook/mercadopago` | NLB `:81` | nenhum (o serviço valida a assinatura) |
| `GET /api/billing/docs`, `GET /api/billing/swagger.yaml` | NLB `:81` | nenhum |
| `GET /api/execution/docs`, `GET /api/execution/swagger.yaml` | NLB `:82` | nenhum |

As rotas públicas do OS (`/api/health`, `/api/ready`, `/api/auth/login`, `/docs`,
`/swagger.yaml`) e `POST /auth/cpf` continuam como na Fase 3. O Adendo 7 da Fase 3 continua
valendo: toda rota sem JWT tem rota dedicada sem authorizer.

**Removida:** a rota dedicada `POST /api/service-orders/{id}/approval`.

`/internal/*` **não** tem rota no gateway.

### OS (`oficina-api`)

| Rota | Acesso |
|---|---|
| `GET /api/health`, `GET /api/ready`, `POST /api/auth/login` | público, como na Fase 3 |
| `POST /api/service-orders` | `admin` — abre a OS e **inicia a saga** |
| `GET /api/service-orders`, `GET /api/service-orders/me`, `GET /api/service-orders/{id}` | como na Fase 3; a resposta ganha `saga` (passo atual e histórico) |
| `POST /api/service-orders/{id}/delivery` | `admin` — `FINISHED → DELIVERED` |
| `/api/customers/**`, `/api/vehicles/**` | `admin`, como na Fase 3 |
| `GET /internal/customers/by-document/{document}` | header `X-Internal-Token` obrigatório; devolve `id`, `name`, `document`, `status` |

**Removidas do OS:**

- `/api/parts/**` e `/api/service-items/**`: passam para o Faturamento;
- `POST /api/service-orders/{id}/items`: os itens vêm do diagnóstico, na Execução;
- `PATCH /api/service-orders/{id}/status`: o status passa a ser movido só pela saga (a entrega
  ganha rota própria);
- `POST /api/service-orders/{id}/approval`: passa para o Faturamento.

### Faturamento (`oficina-faturamento`)

| Rota | Acesso |
|---|---|
| `GET /api/billing/health`, `GET /api/billing/ready` | público |
| `/api/billing/service-items/**`, `/api/billing/parts/**` | `admin` (as mesmas operações que o OS tinha) |
| `GET /api/billing/budgets` | `admin` |
| `GET /api/billing/budgets/{id}`, `GET /api/billing/budgets/by-order/{orderId}` | `admin`, ou `customer` dono do orçamento |
| `POST /api/billing/budgets/{id}/approval` | header `X-Webhook-Token` obrigatório; corpo `{"approved": true\|false}` |
| `GET /api/billing/payments/{id}` | `admin`, ou `customer` dono |
| `POST /api/billing/payments/webhook/mercadopago` | assinatura `x-signature` válida |

### Execução (`oficina-execucao`)

| Rota | Acesso |
|---|---|
| `GET /api/execution/health`, `GET /api/execution/ready` | público |
| `GET /api/execution/tasks` (filtro `status`), `GET /api/execution/tasks/{id}` | `admin` |
| `POST /api/execution/tasks/{id}/diagnosis/start` | `admin` |
| `POST /api/execution/tasks/{id}/diagnosis/complete` | `admin` — corpo com os itens (`service_item_id` ou `part_id`, e `quantity`) |
| `POST /api/execution/tasks/{id}/diagnosis/fail` | `admin` — corpo com `reason` |
| `POST /api/execution/tasks/{id}/repair/start` | `admin` |
| `POST /api/execution/tasks/{id}/repair/complete` | `admin` |
| `POST /api/execution/tasks/{id}/repair/fail` | `admin` — corpo com `reason` |

### JWT

O token não muda (seção 4 da Fase 3 e `contract-token.md`). Os três serviços revalidam o JWT
localmente, com o mesmo `JWT_SECRET`, e cada repo tem o teste de contrato com o mesmo token
literal.

---

## 5. Mensagens

### Infraestrutura

- Um tópico SNS padrão, `oficina-<env>-mensagens`.
- Uma fila SQS padrão por serviço, `oficina-<env>-<servico>`, com DLQ
  `oficina-<env>-<servico>-dlq` e `maxReceiveCount = 5`.
- Cada fila assina o tópico com `raw_message_delivery = true` e filtro pelo atributo `type`,
  listando exatamente os tipos que o serviço consome.
- Todo serviço publica **só** no tópico. Nenhum serviço escreve na fila de outro.
- Entrega "pelo menos uma vez" e sem ordem garantida: todo consumidor é idempotente (§5.4).

### 5.1 Envelope

Corpo da mensagem (JSON). O atributo de mensagem `type` repete o campo `type`.

```json
{
  "id": "uuid v4, único por mensagem",
  "type": "budget.generated",
  "version": 1,
  "producer": "faturamento",
  "saga_id": "uuid da saga",
  "os_id": "uuid da OS",
  "occurred_at": "2026-11-10T14:03:22.512Z",
  "correlation_id": "o mesmo do request que abriu a OS",
  "trace": { "newrelic": "payload de trace distribuído, ou ausente" },
  "payload": {}
}
```

Campo novo no `payload` é compatível. Remover ou mudar o sentido de um campo exige `version`
nova e os dois lados aceitando as duas durante a troca.

### 5.2 Comandos (o orquestrador pede)

| `type` | De → para | `payload` |
|---|---|---|
| `execution.request-diagnosis` | os → execucao | `customer_name`, `vehicle` (`license_plate`, `brand`, `model`), `description` |
| `billing.generate-budget` | os → faturamento | `customer` (`id`, `name`, `email`), `items[]` (`kind`: `service`\|`part`, `id`, `quantity`) |
| `billing.charge` | os → faturamento | `budget_id` |
| `execution.start-repair` | os → execucao | `items[]` |
| `execution.cancel` | os → execucao | `reason` |
| `billing.cancel-budget` | os → faturamento | `budget_id`, `reason` |
| `billing.refund-payment` | os → faturamento | `budget_id`, `reason` |

Os três últimos são **compensações**.

### 5.3 Eventos (o serviço informa o que aconteceu)

| `type` | De → para | `payload` |
|---|---|---|
| `diagnosis.started` | execucao → os | `task_id` |
| `diagnosis.completed` | execucao → os | `task_id`, `items[]` |
| `diagnosis.failed` | execucao → os | `task_id`, `reason` |
| `repair.started` | execucao → os | `task_id` |
| `repair.completed` | execucao → os | `task_id` |
| `repair.failed` | execucao → os | `task_id`, `reason` |
| `execution.cancelled` | execucao → os | `task_id` |
| `budget.generated` | faturamento → os | `budget_id`, `total_amount` |
| `budget.failed` | faturamento → os | `reason` (`INSUFFICIENT_STOCK`, `ITEM_NOT_FOUND`) |
| `budget.approved` | faturamento → os | `budget_id` |
| `budget.rejected` | faturamento → os | `budget_id` |
| `budget.cancelled` | faturamento → os | `budget_id` |
| `payment.created` | faturamento → os | `payment_id`, `payment_url` |
| `payment.confirmed` | faturamento → os | `payment_id`, `amount` |
| `payment.failed` | faturamento → os | `payment_id`, `reason` |
| `payment.refunded` | faturamento → os | `payment_id` |

Todo tipo publicado tem pelo menos um consumidor, e todo tipo consumido tem produtor. O
`bin/check-contracts.py` do repositório principal vai conferir isso contra esta seção.

### 5.4 Entrega e idempotência (ADR-017)

- **Outbox.** A mensagem é gravada junto com a mudança de estado que a originou e publicada
  depois pelo worker. No MySQL é a tabela `outbox`, na mesma transação. No DynamoDB é um item
  de outbox gravado na mesma `TransactWriteItems`.
- **Inbox.** O consumidor registra o `id` de cada mensagem processada (`inbox`) na mesma
  transação do efeito. Mensagem com `id` já registrado é confirmada sem efeito.
- **Comando repetido devolve o mesmo resultado.** Receber `billing.generate-budget` duas vezes
  para a mesma `saga_id` não cria dois orçamentos.
- **Evento fora de hora é ignorado.** O orquestrador só aceita o evento que o passo atual da
  saga espera; os demais são registrados em log e confirmados.
- A mensagem que falha 5 vezes vai para a DLQ, que tem alerta (§8).

---

## 6. Saga da ordem de serviço

Orquestrada, com o orquestrador no OS (ADR-012). Estado em `sagas`, no banco do OS.

### 6.1 Passos

| Passo (`step`) | Ao entrar, o orquestrador | Espera | Efeito na OS |
|---|---|---|---|
| `DIAGNOSIS` | publica `execution.request-diagnosis` | `diagnosis.started`, depois `diagnosis.completed` | `diagnosis.started` → `DIAGNOSIS` |
| `BUDGET` | publica `billing.generate-budget` | `budget.generated` | → `AWAITING_APPROVAL`, grava `budget_id` e o total |
| `APPROVAL` | nada (espera o cliente), com prazo | `budget.approved` | → `AWAITING_PAYMENT` |
| `PAYMENT` | publica `billing.charge`, com prazo | `payment.created`, depois `payment.confirmed` | `payment.confirmed` → `EXECUTING` |
| `REPAIR` | publica `execution.start-repair` | `repair.completed` | → `FINISHED` |

A saga termina em `COMPLETED` quando a OS chega a `FINISHED`. A entrega (`DELIVERED`) é manual
e fica fora da saga.

### 6.2 Falhas e compensações

| Falha | Compensações, na ordem | Status final da OS |
|---|---|---|
| `diagnosis.failed` | nenhuma (nada foi feito fora da Execução) | `CANCELLED` |
| `budget.failed` | `execution.cancel` | `CANCELLED` |
| `budget.rejected` | `execution.cancel` (o Faturamento já liberou o estoque ao rejeitar) | `REJECTED` |
| prazo de aprovação vencido | `billing.cancel-budget`, `execution.cancel` | `CANCELLED` |
| `payment.failed` ou prazo de pagamento vencido | `billing.cancel-budget`, `execution.cancel` | `CANCELLED` |
| `repair.failed` | `billing.refund-payment`, `billing.cancel-budget` | `CANCELLED` |

- Durante a compensação a saga fica em `COMPENSATING`. Ela só passa a `COMPENSATED` quando cada
  compensação foi confirmada pelo evento correspondente (`execution.cancelled`,
  `budget.cancelled`, `payment.refunded`).
- Compensação é idempotente e reenviada com recuo até a confirmação. Esgotadas as tentativas, a
  saga fica em `FAILED`, com alerta: é o caso que pede uma pessoa.
- `billing.cancel-budget` libera a reserva de estoque. `billing.refund-payment` estorna no
  Mercado Pago.

### 6.3 Prazos

| Variável (OS) | Padrão | hml |
|---|---|---|
| `SAGA_APPROVAL_TIMEOUT_SECONDS` | 259200 (72 h) | 600 |
| `SAGA_PAYMENT_TIMEOUT_SECONDS` | 86400 (24 h) | 600 |

O worker do OS varre `sagas` procurando `deadline_at` vencido.

### 6.4 Status da OS

```
RECEIVED → DIAGNOSIS → AWAITING_APPROVAL → AWAITING_PAYMENT → EXECUTING → FINISHED → DELIVERED
                              └→ REJECTED
RECEIVED, DIAGNOSIS, AWAITING_APPROVAL, AWAITING_PAYMENT, EXECUTING → CANCELLED
```

`AWAITING_PAYMENT` e `CANCELLED` são novos. `approve()` passa a levar a `AWAITING_PAYMENT`.
Cada transição continua sendo um método nomeado do agregado (ADR-003); não há `setStatus`.

---

## 7. Banco de dados

**Nenhum serviço acessa o banco de outro.** Vale também para as Lambdas.

| Serviço | Banco | Migrations |
|---|---|---|
| OS | MySQL `oficina_mecanica`, RDS da fundação | `oficina-infra-fundacao/migrations/`, executadas pelo Job do OS (como na Fase 3) |
| Faturamento | MySQL `oficina_faturamento`, RDS próprio | `oficina-faturamento/migrations/`, executadas pelo Job do próprio serviço; mesmas regras de idempotência da Fase 3 |
| Execução | DynamoDB `oficina-<env>-execucao`, sob demanda | a tabela é criada pelo Terraform do serviço |

### OS — migrations novas na fundação

- `004_fase4_saga.sql`: `AWAITING_PAYMENT` e `CANCELLED` no status da OS e do histórico;
  `service_orders.budget_id`; tabelas `sagas`, `outbox` e `inbox`.
- `005_fase4_remove_catalogo.sql`: remove `service_catalog`, `parts_inventory`,
  `service_order_services` e `service_order_parts`. **Só entra depois** de o OS em produção
  ter parado de ler essas tabelas (remoção: primeiro o consumidor, depois o produtor).

Não há migração de dados: catálogo e estoque são dados de demonstração e nascem do seed do
Faturamento.

### `auth-cpf`

Deixa de ler `customers` no RDS. Passa a chamar
`GET http://<nlb dns_name>/internal/customers/by-document/{cpf}` com `X-Internal-Token`. Com
isso a Lambda deixa de usar o segredo `oficina/<env>/db`, os parâmetros `/oficina/<env>/db/*`
e o SG `db-client`. Os códigos de erro do `POST /auth/cpf` não mudam; OS indisponível responde
`503`.

### Execução — desenho da tabela

Tabela única. `PK = TASK#<os_id>`; `SK = META` (a tarefa), `EVENT#<timestamp>#<id>` (linha do
tempo), `OUTBOX#<id>` e `INBOX#<id>`. Índice `GSI1` por `status` e `created_at`, para a fila.

---

## 8. Observabilidade

Mantém o New Relic da Fase 3.

- `NEW_RELIC_APP_NAME`: `oficina-api-<env>`, `oficina-faturamento-<env>`,
  `oficina-execucao-<env>`. O worker usa o mesmo nome do serviço.
- **Trace distribuído pela fila.** Quem publica grava o payload de trace em `trace.newrelic`;
  o worker abre uma transação de background por mensagem e aceita esse payload. Sem a
  extensão, é no-op.
- **Log.** As linhas da Fase 3, mais `saga_id` e `os_id` quando existirem. O worker escreve uma
  linha `message.processed` por mensagem, com `message_id`, `message_type`, `duration_ms` e
  `outcome` (`ok`, `duplicate`, `ignored`, `error`).

### Custom events novos

| Evento | Emitido por | Atributos |
|---|---|---|
| `SagaStarted` | OS | `sagaId`, `orderId`, `correlationId`, `env` |
| `SagaStepCompleted` | OS | `sagaId`, `orderId`, `step`, `durationSeconds`, `env` |
| `SagaCompensated` | OS | `sagaId`, `orderId`, `failedStep`, `reason`, `env` |
| `SagaFailed` | OS | `sagaId`, `orderId`, `failedStep`, `reason`, `env` |
| `PaymentConfirmed` | Faturamento | `paymentId`, `orderId`, `amount`, `env` |

`ServiceOrderCreated` e `ServiceOrderStatusChanged` continuam.

### Alertas novos

Mensagem visível em qualquer DLQ; saga em `FAILED`; saga parada além do prazo do passo; taxa
de compensação; erro no webhook do Mercado Pago.

---

## 9. Qualidade e CI/CD

Vale para os três serviços, cada um com pipeline própria e independente.

| Workflow | Passos |
|---|---|
| `pr.yml` | php-cs-fixer, PHPStan, PHPUnit com cobertura, Behat, SonarCloud com quality gate, build da imagem, `kustomize build` dos overlays, `terraform fmt/validate/plan` do `infra/` |
| `deploy.yml` | OIDC → `terraform apply` do `infra/` → push da imagem → Job de migration (quando houver) → `kubectl apply -k` → smoke → marcação de deploy no New Relic |

- **Cobertura:** linhas de **todo** o `src/`, medida com pcov. O `pr.yml` falha abaixo de 80%.
- **BDD:** Behat, com as features em português em `features/`. Cada serviço tem ao menos um
  fluxo completo. No OS é a saga: o caminho feliz e as compensações de orçamento rejeitado,
  pagamento recusado e reparo que falha.
- **Qualidade:** SonarCloud; o quality gate é checagem obrigatória do PR.
- **Proteção:** `main` e `develop` exigem PR e as checagens do `pr.yml`.
- **Segredos de repositório:** os da Fase 3 mais `SONAR_TOKEN`.
- **Roles de CI:** `oficina-gha-oficina-faturamento` e `oficina-gha-oficina-execucao`, criados
  no `bootstrap/aws/` do repositório principal.
- **README de cada serviço:** badges do Sonar e da cobertura, diagrama do serviço, como rodar,
  link do Swagger. O do OS traz também a justificativa da saga.

---

## 10. Como validar localmente

A partir do repositório principal, sem AWS. Os itens abaixo descrevem o alvo da fase; as
ferramentas ganham cada capacidade junto com a etapa que a exige.

- `bin/ws verify`: os passos do `pr.yml` de cada repo, exceto o Sonar e o `terraform plan`;
- `bin/check-contracts.py`: além das checagens da Fase 3, o catálogo de mensagens (§5), as
  rotas por prefixo (§4) e a regra de banco por serviço (§7);
- `bin/ws e2e`: os três serviços e seus workers contra LocalStack (SNS, SQS, DynamoDB), dois
  MySQL e o adaptador falso do Mercado Pago, percorrendo o caminho feliz da saga e as
  compensações.

# Contratos compartilhados — Fase 3

> **Este é o documento normativo.** Os quatro repositórios são desenvolvidos em paralelo e só
> encaixam se todos obedecerem exatamente ao que está aqui. Em caso de divergência entre este
> arquivo e qualquer implementação, **este arquivo vence** — corrija a implementação.

Convenções globais:

| Item | Valor |
|---|---|
| Região AWS | `us-east-1` |
| Ambientes | `hml`, `prod` |
| Prefixo de recursos | `oficina-<env>` |
| Backend Terraform | bucket `oficina-tfstate-<sufixo>`, key `oficina/<repo>/<env>/terraform.tfstate`, lock DynamoDB `oficina-tflock` |
| Versionamento de ambiente | arquivos `envs/hml.tfvars` e `envs/prod.tfvars` (**não** usar workspaces) |
| Tags obrigatórias | `Project=oficina-mecanica`, `Environment=<env>`, `ManagedBy=terraform`, `Repo=<nome-do-repo>` |

---

## 1. Propriedade de recursos por repositório

O repositório de banco é a **camada de fundação**: possui tudo que é durável (rede, dados,
segredos). O cluster pode ser destruído e recriado sem tocar nele.

| Recurso | Repositório dono |
|---|---|
| VPC, subnets, IGW, route tables | `oficina-infra-fundacao` |
| Security groups de banco e de cliente de banco | `oficina-infra-fundacao` |
| RDS MySQL, subnet group, parameter group | `oficina-infra-fundacao` |
| **Todos** os segredos no Secrets Manager (banco e JWT) | `oficina-infra-fundacao` |
| Migrations SQL versionadas | `oficina-infra-fundacao` |
| EKS, node group, IRSA, add-ons Helm | `oficina-infra-cluster` |
| ECR | `oficina-infra-cluster` |
| NLB interno + Target Group | `oficina-infra-cluster` |
| Namespaces, ClusterSecretStore | `oficina-infra-cluster` |
| Lambdas (`auth-cpf`, `jwt-authorizer`) | `oficina-gateway-auth` |
| API Gateway HTTP API, rotas, VPC Link, authorizer | `oficina-gateway-auth` |
| Deployment, Service, HPA, Ingress da aplicação | `oficina-api` |
| Job de migration (executa os SQL do repo de banco) | `oficina-api` |

Ordem de `apply`: **fundação → cluster → gateway → api**. Ordem de `destroy`: inversa.

---

## 2. SSM Parameter Store — nomes exatos

Acoplamento entre repos é **só** por SSM. Nenhum repo usa `terraform_remote_state` de outro.

### Publicados por `oficina-infra-fundacao`

| Parâmetro | Tipo | Conteúdo |
|---|---|---|
| `/oficina/<env>/network/vpc_id` | String | id da VPC |
| `/oficina/<env>/network/private_subnet_ids` | StringList | ids separados por vírgula |
| `/oficina/<env>/network/public_subnet_ids` | StringList | ids separados por vírgula |
| `/oficina/<env>/network/vpc_cidr` | String | ex. `10.20.0.0/16` |
| `/oficina/<env>/db/client_sg_id` | String | SG que concede acesso ao RDS:3306 — **anexar em nodes do EKS e na Lambda** |
| `/oficina/<env>/db/endpoint` | String | host do RDS, sem porta |
| `/oficina/<env>/db/port` | String | `3306` |
| `/oficina/<env>/db/name` | String | `oficina_mecanica` |
| `/oficina/<env>/db/secret_arn` | String | ARN do segredo de banco |
| `/oficina/<env>/auth/secret_arn` | String | ARN do segredo de autenticação |

### Publicados por `oficina-infra-cluster`

| Parâmetro | Tipo | Conteúdo |
|---|---|---|
| `/oficina/<env>/eks/cluster_name` | String | nome do cluster |
| `/oficina/<env>/eks/cluster_endpoint` | String | endpoint da API |
| `/oficina/<env>/eks/oidc_provider_arn` | String | ARN do provider IRSA |
| `/oficina/<env>/eks/node_security_group_id` | String | SG dos nodes |
| `/oficina/<env>/ecr/repository_url` | String | URL do repositório ECR |
| `/oficina/<env>/nlb/arn` | String | ARN do NLB interno |
| `/oficina/<env>/nlb/listener_arn` | String | ARN do listener :80 — usado pela integração do API Gateway |
| `/oficina/<env>/nlb/target_group_arn` | String | ARN do target group (`target_type = ip`) — o `TargetGroupBinding` da aplicação aponta para ele |
| `/oficina/<env>/eks/namespace` | String | `oficina-hml` ou `oficina-prod` |

### Publicados por `oficina-gateway-auth`

| Parâmetro | Tipo | Conteúdo |
|---|---|---|
| `/oficina/<env>/apigw/endpoint` | String | URL base pública da API |
| `/oficina/<env>/apigw/api_id` | String | id do HTTP API |

> **Adendo 1 (integração, após WS-B).** O NLB interno, o target group e o listener são criados
> **pelo Terraform** do repo `oficina-infra-cluster`, não pelo AWS Load Balancer Controller. Motivo: o
> repo da Lambda aplica antes do repo da aplicação e precisa dos ARNs já existentes; se o NLB
> nascesse do `Service` da aplicação, os ARNs só existiriam depois — invertendo a ordem de apply
> contratada na seção 1. O LB Controller apenas **registra os IPs dos Pods como targets**, através
> de um recurso `TargetGroupBinding` declarado no `deploy/` da aplicação apontando para
> `/oficina/<env>/nlb/target_group_arn`.
>
> **Adendo 2.** O `ClusterSecretStore` criado pelo `oficina-infra-cluster` chama-se
> **`oficina-secretsmanager`**. O `ExternalSecret` da aplicação DEVE referenciar exatamente esse
> nome.
>
> **Adendo 3.** As subnets **públicas** precisam de rota `0.0.0.0/0` para o Internet Gateway —
> sem NAT, é por elas que os nodes alcançam a API do EKS, o ECR e o New Relic. Sem essa rota os
> nodes não entram no cluster.
>
> **Adendo 4.** O VPC Link do API Gateway deve usar **as mesmas subnets privadas** em que o NLB
> interno foi criado.

Leitura em Terraform:

```hcl
data "aws_ssm_parameter" "vpc_id" {
  name = "/oficina/${var.environment}/network/vpc_id"
}
```

---

## 3. Segredos no Secrets Manager

Ambos criados pelo `oficina-infra-fundacao`. Valores gerados por `random_password`, nunca
digitados nem commitados.

**`oficina/<env>/db`** — JSON:

```json
{
  "host": "...", "port": 3306, "dbname": "oficina_mecanica",
  "username": "oficina_user", "password": "..."
}
```

**`oficina/<env>/auth`** — JSON:

```json
{
  "JWT_SECRET": "<64 chars>",
  "JWT_EXPIRATION": "3600",
  "ADMIN_USERNAME": "admin",
  "ADMIN_PASSWORD": "<32 chars>",
  "WEBHOOK_TOKEN": "<32 chars>"
}
```

Consumo:
- **Lambda**: SDK do Secrets Manager, resultado cacheado em variável estática entre invocações.
- **Aplicação**: `ExternalSecret` (External Secrets Operator) materializa um `Secret` chamado
  `oficina-secret` no namespace, com as chaves acima + `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD`.

---

## 4. Contrato do JWT

**Algoritmo HS256.** Segredo = chave `JWT_SECRET` do segredo `oficina/<env>/auth`. A Lambda e a
aplicação PRECISAM produzir assinatura byte a byte idêntica.

Header: `{"alg":"HS256","typ":"JWT"}` (nesta ordem).

Claims:

| Claim | Tipo | Obrigatório | Valor |
|---|---|---|---|
| `iss` | string | sempre | `oficina-mecanica-api` (**não mudar** — já é o valor atual do `JwtProvider`) |
| `sub` | string | sempre | `customer_id` (uuid) quando `role=customer`; `ADMIN_USERNAME` quando `role=admin` |
| `role` | string | sempre | `customer` ou `admin` |
| `iat` | int | sempre | `time()` |
| `exp` | int | sempre | `iat + JWT_EXPIRATION` |
| `cpf` | string | só `customer` | 11 dígitos, sem máscara |
| `name` | string | só `customer` | nome do cliente |

Ordem de montagem em `generate()`: `array_merge($payload, ['iss','iat','exp'])` — ou seja, as
claims específicas vêm **primeiro** no JSON, depois `iss`, `iat`, `exp`. Manter essa ordem.

`base64url` = `rtrim(strtr(base64_encode($x), '+/', '-_'), '=')`.

> **Teste de contrato obrigatório.** O repositório da Lambda e o da aplicação devem ter, cada um,
> um teste que gera um token com segredo fixo `"contract-test-secret-do-not-use-in-prod"`, payload
> fixo e `iat` fixo `1767225600`, e compara com o **mesmo token literal** hardcoded. Se um lado
> mudar, o outro quebra. O token esperado está em `docs/fase-3/contract-token.md` (gerado na
> integração).

---

## 5. Rotas e autorização

### API Gateway (`oficina-gateway-auth`)

| Rota | Integração | Authorizer |
|---|---|---|
| `POST /auth/cpf` | Lambda `auth-cpf` | nenhum |
| `GET /api/health` | HTTP_PROXY via VPC Link → NLB:80 | nenhum |
| `GET /api/ready` | HTTP_PROXY via VPC Link → NLB:80 | nenhum |
| `POST /api/auth/login` | HTTP_PROXY via VPC Link → NLB:80 | nenhum |
| `POST /api/service-orders/{id}/approval` | HTTP_PROXY via VPC Link → NLB:80 | nenhum (a aplicação exige `X-Webhook-Token`) |
| `GET /docs` | HTTP_PROXY via VPC Link → NLB:80 | nenhum |
| `GET /docs/{proxy+}` | HTTP_PROXY via VPC Link → NLB:80 | nenhum |
| `GET /swagger.yaml` | HTTP_PROXY via VPC Link → NLB:80 | nenhum |
| `ANY /api/{proxy+}` | HTTP_PROXY via VPC Link → NLB:80 | Lambda `jwt-authorizer` |

O `jwt-authorizer` é do tipo **REQUEST**, `payload_format_version = "2.0"`,
`enable_simple_responses = true`, `authorizer_result_ttl_in_seconds = 300`,
`identity_sources = ["$request.header.Authorization"]`.

Retorno: `{"isAuthorized": true|false, "context": {"customerId": "...", "role": "..."}}`.

> **Adendo 7 (rotas dedicadas).** Com `identity_sources = ["$request.header.Authorization"]`,
> o HTTP API responde 401 **sem invocar** o `jwt-authorizer` quando o header falta. Por isso,
> liberar uma rota dentro do authorizer não basta: toda rota que a aplicação declara sem JWT
> (`requireAuth: false`) tem rota dedicada, sem authorizer, no `apigateway.tf`. É o caso das
> sondas, do login do admin (que antes passava pelo `{proxy+}` com isenção no authorizer) e do
> webhook de aprovação, protegido pela aplicação com `X-Webhook-Token`. A documentação
> (`/docs`, `/swagger.yaml`) também tem rota dedicada; o nginx da aplicação a serve. A lista
> `PUBLIC_ROUTES` do authorizer continua como defesa extra. O `bin/check-contracts.py` do
> repositório principal confere essa coerência (`ROTA-1` a `ROTA-4`).

### Aplicação — matriz de autorização

A aplicação **revalida o JWT localmente** (defesa em profundidade). Ela nunca confia em headers
injetados pelo gateway.

| Rota | Acesso |
|---|---|
| `GET /api/health` | público — liveness, **não toca no banco** |
| `GET /api/ready` | público — readiness, checa o banco |
| `POST /api/auth/login` | público (admin) |
| `POST /api/service-orders/{id}/approval` | header `X-Webhook-Token` **obrigatório** |
| `GET /api/service-orders/me` | `customer` ou `admin` — devolve só as OS do `sub` do token |
| `GET /api/service-orders/{id}` | `admin`, ou `customer` dono da OS |
| todo o resto de `/api/**` | `admin` |

**Removida:** a rota pública `GET /api/service-orders/status`, que hoje aceita `document` e
`license_plate` por query string e vaza dados de qualquer CPF. Substituída por
`GET /api/service-orders/me`.

### Códigos de erro do `POST /auth/cpf`

| Situação | Status | Corpo |
|---|---|---|
| CPF válido, cliente `ACTIVE` | 200 | `{"token":"...","expiresIn":3600,"customer":{"id":"...","name":"..."}}` |
| CPF com dígito verificador inválido | 400 | `{"error":"CPF inválido."}` |
| Campo `cpf` ausente ou vazio | 400 | `{"error":"O campo cpf é obrigatório."}` |
| CPF não cadastrado | 404 | `{"error":"Cliente não encontrado."}` |
| Cliente `INACTIVE` ou `BLOCKED` | 403 | `{"error":"Cliente inativo. Procure a oficina."}` |
| Falha de banco / erro interno | 500 | `{"error":"Erro interno."}` |

Só CPF (11 dígitos) é aceito nesta rota — CNPJ retorna 400.

---

## 6. Banco de dados

### Migrations

Diretório `migrations/` no repo `oficina-infra-fundacao`, arquivos `NNN_<slug>.sql`, aplicados em
ordem lexicográfica pelo runner `bin/migrate.php` da aplicação.

Tabela de controle (criada pelo próprio runner se não existir):

```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
    version    VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
) ENGINE=InnoDB;
```

Arquivos previstos:
- `001_initial_schema.sql` — as 7 tabelas atuais, **sem** `CREATE DATABASE` e **sem** `USE`.
- `002_fase3_ajustes.sql` — os 10 ajustes abaixo.
- `003_seed_demo.sql` — dados de demonstração, aplicado **só em hml** (o runner pula arquivos com
  sufixo `_demo` quando `APP_ENV=production`).

Cada arquivo é idempotente e não usa `DELIMITER`. Statements separados por `;` no fim da linha.

### Ajustes do `002`

1. `customers.status ENUM('ACTIVE','INACTIVE','BLOCKED') NOT NULL DEFAULT 'ACTIVE'`
2. `customers.email VARCHAR(255) NULL`, `customers.phone VARCHAR(20) NULL`
3. Tabela `service_order_status_history (id CHAR(36) PK, service_order_id CHAR(36) FK, from_status VARCHAR(30) NULL, to_status VARCHAR(30) NOT NULL, changed_at DATETIME(3), changed_by VARCHAR(255) NULL)` + índices `(service_order_id, changed_at)` e `(to_status, changed_at)`
4. `CREATE INDEX idx_orders_status_created ON service_orders (status, created_at)`
5. `CREATE INDEX idx_orders_customer ON service_orders (customer_id)`, `idx_orders_vehicle`, `idx_vehicles_customer`
6. `service_orders.status` → `ENUM('RECEIVED','DIAGNOSIS','AWAITING_APPROVAL','EXECUTING','REJECTED','FINISHED','DELIVERED')`
7. `parts_inventory` ganha `version INT NOT NULL DEFAULT 0` e `CHECK (stock_quantity >= 0)`
8. `UNIQUE (service_order_id, parts_inventory_id)` em `service_order_parts`; `UNIQUE (service_order_id, service_catalog_id)` em `service_order_services`
9. `vehicles.year` → `SMALLINT UNSIGNED NOT NULL`
10. `created_at`/`updated_at` → `DATETIME(3)` nas tabelas que os têm

> **Compatibilidade com os testes.** `tests/Integration/PdoServiceOrderRepositoryTest.php` roda em
> SQLite in-memory. Se ele montar o schema a partir do SQL, o `ENUM` e o `CHECK` quebram. Verifique
> e, se necessário, mantenha o schema de teste separado do de produção.

---

## 7. Observabilidade

### Log estruturado (stdout, uma linha JSON por request)

```json
{"timestamp":"2026-08-26T22:31:04.512Z","level":"info","message":"request.completed",
 "service":"oficina-api","env":"prod","correlation_id":"...","method":"POST",
 "path":"/api/service-orders","status":201,"duration_ms":42.7,
 "customer_id":"...","role":"admin"}
```

- `level` ∈ `debug|info|warn|error`.
- `correlation_id`: lido de `X-Request-Id`; se ausente, de `X-Amzn-Trace-Id`; se ausente, gerado
  (uuid v4). **Sempre** devolvido no header `X-Request-Id` da resposta.
- Erros não tratados logam `level=error` com `exception_class`, `exception_message`, `file`, `line`
  — e a resposta HTTP continua sem vazar detalhe quando `APP_DEBUG=false`.

### Custom events New Relic

| Evento | Atributos |
|---|---|
| `ServiceOrderCreated` | `orderId`, `customerId`, `vehicleId`, `correlationId`, `env` |
| `ServiceOrderStatusChanged` | `orderId`, `fromStatus`, `toStatus`, `durationSeconds`, `orderAgeSeconds`, `totalAmount`, `correlationId`, `env` |

Emitidos por um subscriber registrado no `InMemoryEventDispatcher` já existente. `durationSeconds`
= tempo desde a transição anterior, obtido da `service_order_status_history`.

> **Adendo 5 (integração final).** `totalAmount` foi acrescentado a `ServiceOrderStatusChanged`:
> os painéis de ticket médio e faturamento do dashboard de negócio dependem dele, e o valor já
> existe no agregado. No evento de domínio é um parâmetro **opcional** (default `0.00`) porque é
> enriquecimento de telemetria, não parte da identidade da transição de status.

> **Adendo 6 (dashboards).** `orderAgeSeconds` foi acrescentado a `ServiceOrderStatusChanged`:
> segundos desde a primeira transição registrada da OS, isto é, desde a abertura. Na transição
> para `DELIVERED` ele **é** o lead time. A versão anterior do painel somava os `durationSeconds`
> das transições dentro da janela e dividia pelo número de OS, o que contava pela metade qualquer
> OS aberta antes do início da janela.

A emissão usa `newrelic_record_custom_event()` **quando a extensão existe**; caso contrário é
no-op silencioso (para não quebrar testes nem o ambiente local).

### Variáveis de ambiente de APM

`NEW_RELIC_LICENSE_KEY`, `NEW_RELIC_APP_NAME` (`oficina-api-<env>`), `NEW_RELIC_DISTRIBUTED_TRACING_ENABLED=true`.

---

## 8. Imagem e deploy da aplicação

- Base: **`php:8.2-fpm-bookworm`** (não Alpine — o agente PHP do New Relic exige glibc).
- Stages: `vendor` (composer) → `dev` → `production`.
- Usuário não-root mantido.
- Imagem publicada no ECR lido de `/oficina/<env>/ecr/repository_url`, tag = `git sha`.
- Manifests em `deploy/base` + `deploy/overlays/{hml,prod}` (kustomize). O overlay define
  namespace, réplicas, recursos e a tag da imagem.
- `k8s/` e `infra/` do repositório atual são **removidos** (migram para os repos de IaC e para
  `deploy/`).

---

## 9. CI/CD — forma padrão nos quatro repos

Branches: `main` (produção) e `develop` (homologação). Feature branches → PR para `develop`.

| Workflow | Gatilho | Passos |
|---|---|---|
| `pr.yml` | `pull_request` para `develop` ou `main` | lint + testes + análise estática (+ `terraform fmt/validate/plan` nos repos de IaC, com plan comentado no PR) |
| `deploy.yml` | `push` em `develop` → env `homologacao`; `push` em `main` → env `producao` | OIDC → apply/deploy → smoke test → marcar deployment no New Relic |

Autenticação AWS: `aws-actions/configure-aws-credentials@v4` com `role-to-assume:
arn:aws:iam::<account>:role/oficina-gha-<repo>` e `permissions: id-token: write`. **Nenhuma access
key estática.**

Segredos de repositório esperados: `AWS_ROLE_ARN`, `AWS_ACCOUNT_ID`, `NEW_RELIC_LICENSE_KEY`,
`NEW_RELIC_ACCOUNT_ID`, `NEW_RELIC_API_KEY`, `TF_STATE_BUCKET`.

---

## 10. Como validar localmente (sem AWS)

Não há PHP nem Composer nesta máquina — use Docker:

```bash
scripts/php.sh vendor/bin/phpunit
scripts/php.sh vendor/bin/phpstan analyse --no-progress
terraform -chdir=repos/oficina-infra-fundacao fmt -check -recursive
terraform -chdir=repos/oficina-infra-fundacao init -backend=false && terraform ... validate
```

`terraform validate` sem credencial funciona com `-backend=false`. Isso é o teto de verificação
possível sem conta AWS — e é o critério de "pronto" para os repos de IaC nesta rodada.

# oficina-api — guia para agentes

Aplicação do sistema Oficina Mecânica: API REST em **PHP 8.2 puro**, sem framework (ADR-001), em
Clean Architecture e DDD. Nginx + PHP-FPM numa imagem `php:8.2-fpm-bookworm` (não Alpine,
porque o agente New Relic exige glibc), MySQL via PDO, PHPUnit 11 e PHPStan nível 8. É o repo
de mudança diária e o dono da documentação da Fase 3.

O sistema tem quatro repos; o mapa, as regras entre eles e as ferramentas (`bin/ws`) ficam no
repositório principal `oficina-mecanica`. O contrato entre os repos é
`docs/fase-3/CONTRATOS.md`, **neste** repo. Ele é normativo: se divergir de uma implementação,
o documento vence. Contrato que muda entra como adendo, no mesmo conjunto de PRs.

## Camadas (`src/`)

A dependência aponta sempre para dentro.

- **`Domain/`**, sem dependência externa.
  - `Aggregate/ServiceOrder.php` é a máquina de estados da OS (Fase 4):
    `RECEIVED → DIAGNOSIS → AWAITING_APPROVAL → AWAITING_PAYMENT → EXECUTING → FINISHED →
    DELIVERED`, mais `AWAITING_APPROVAL → REJECTED` e qualquer status antes de `FINISHED` →
    `CANCELLED`. A OS não guarda itens: o total e o `budget_id` chegam do Faturamento.
  - Cada transição é um método nomeado (`changeToDiagnosis`, `sendForApproval`, `approve`,
    `reject`, `recordPaymentLink`, `startExecution`, `finish`, `deliver`, `cancel`). **Nunca**
    existe `setStatus` (ADR-003). Transição inválida lança `InvalidStatusTransitionException`.
  - `Saga/` é a saga (ADR-012): `Saga.php` guarda o estado e os comandos a publicar;
    `SagaOrchestrator.php` é **o fluxo inteiro** (passos, compensações da tabela §6.2 do
    contrato da Fase 4, prazos). Regra de fluxo nova entra no orquestrador, não em use case.
- **`Application/`**: um use case por ação (`UseCase/`), com DTOs de entrada (`DTO/`).
  `UseCase/Saga/SagaCommit.php` é o fim comum de toda mudança na saga: grava saga, OS, outbox e
  inbox numa transação e, só depois, despacha os eventos de domínio e publica a outbox.
  `HandleIncomingMessageUseCase` trata os eventos dos outros serviços; `SweepSagasUseCase`
  varre os prazos a cada volta do worker (`Worker/Worker.php`).
- **`Infrastructure/`**:
  - repositórios PDO em `Repository/`; a transação fica dentro do repositório (ADR-004) e a
    hidratação usa `reconstitute()` (ADR-005). `PdoSagaRepository` abre a transação e o
    `PdoServiceOrderRepository::save` participa dela quando já há uma aberta;
  - `Aws/` (SNS, SQS) e `ServiceFactory.php`, a montagem da saga usada pela API e pelo worker;
  - `Security/JwtProvider.php` (o par do da Lambda) e `Logging/JsonLogger.php`, com
    `correlation_id`;
  - `Database/MigrationRunner.php`;
  - subscribers de evento: histórico de status, custom events do New Relic e e-mail.
- **`Presentation/`**: `Router` com `requireRole()`, `AuthMiddleware` (revalida o JWT
  localmente; nunca confia no contexto do gateway), controllers e `RequestValidator`.

**Composition root.** `public/index.php` monta as dependências e declara **todas as rotas com a
matriz de autorização**. Rota nova entra aqui e no `swagger.yaml`. Se for pública
(`requireAuth: false`), também precisa de rota dedicada sem authorizer no `apigateway.tf` do
`oficina-gateway-auth`.

## Glossário

| Negócio | Código |
|---|---|
| Ordem de serviço (OS) | `ServiceOrder` |
| Orçamento (aprovar/recusar) | `ReviewBudget`, `approve`/`reject` |
| Cliente | `Customer` |
| Veículo | `Vehicle` |
| Saga da OS | `Saga`, `SagaOrchestrator` |
| Orçamento, pagamento | no serviço de Faturamento (`Budget`, `Payment`); aqui só `budgetId` e `paymentUrl` |
| CPF/CNPJ | `Document` (value object) |
| Placa | `LicensePlate` |

Não crie um conceito novo em português ao lado de um que já existe em inglês.

## Infraestrutura própria (`infra/`, Fase 4)

Fila, DLQ e assinatura do tópico da saga e o role IRSA dos pods (adendo F4-2 do contrato da Fase
4). O `deploy.yml` aplica antes do deploy. A chave do state usa o **nome antigo** do repo
(`oficina/oficina-mecanica-tech-challenge/<env>/...`), como a tag `Repo` e o role de CI: não
"corrija". O filtro da assinatura (`local.consumed_message_types`) é a lista de eventos que o
orquestrador trata; tipo novo entra lá, no orquestrador e no catálogo, juntos.

## Deploy (`deploy/`, kustomize)

- `base/`: Deployment (probes em `/api/health` e `/api/ready`), Service, HPA, `ExternalSecret`
  (gera `oficina-secret` a partir do `ClusterSecretStore` `oficina-secretsmanager`),
  `TargetGroupBinding`, ConfigMaps e o Job `oficina-migrate`.
- `overlays/hml` e `overlays/prod`: namespace, réplicas (HPA de 2 a 10 em hml, de 3 a 10 em
  prod), recursos e tag da imagem; o prod tem PDB.
- Fase 4: `worker-deployment.yaml` (o worker da saga, mesma imagem) e `serviceaccount.yaml`
  (IRSA, usado pela API e pelo worker). Os prazos da saga ficam no ConfigMap (curtos em hml).
- **`TARGET_GROUP_ARN_PLACEHOLDER`, `APP_IMAGE_PLACEHOLDER` e `IRSA_ROLE_ARN_PLACEHOLDER` ficam
  como estão**: o `deploy.yml` substitui os três por `sed`.
- O Job de migration clona o `oficina-infra-fundacao` na ref de
  `deploy/base/migrations-configmap.yaml` e roda `bin/migrate.php`. O runner usa a tabela
  `schema_migrations` e pula os arquivos `_demo` em produção.

## Validar

- A partir do repositório principal: `bin/ws verify --repo api` (composer, php-cs-fixer, phpstan,
  phpunit e `kustomize build` dos dois overlays), e `--image` para construir a imagem de
  produção e conferir a extensão do New Relic.
- Avulso: `bin/ws exec api vendor/bin/phpunit --filter ServiceOrder`.
- Os testes de integração, de aplicação e o BDD rodam em SQLite in-memory, com o schema em
  `tests/Support/OsDatabase.php`, sem `ENUM` nem `CHECK`. Coluna nova usada por um repositório
  PDO precisa entrar ali também. `tests/Support/SagaHarness.php` monta o serviço inteiro (saga,
  worker, barramento em memória, relógio manual) e simula as respostas dos outros serviços.
- Cobertura: `scripts/php.sh sh -c 'vendor/bin/phpunit --coverage-clover coverage.xml && php
  bin/check-coverage.php coverage.xml 80'` (todo o `src/`). BDD: `scripts/php.sh vendor/bin/behat`.
- A saga contra os outros dois serviços de verdade: `bin/ws e2e --fase4`, no repositório principal.
- O schema de verdade são as migrations do `oficina-infra-fundacao`. Catálogo, estoque e itens
  da OS saíram do banco do OS na migration `005` (estão no Faturamento): não volte a lê-los.

## Armadilhas

| Sintoma | Causa | O que pega |
|---|---|---|
| Token do cliente recusado (401) | `JwtProvider` mudou de um lado só | `tests/Unit/Infrastructure/Security/JwtContractTest.php`, o espelho no gateway e `bin/check-contracts.py` (JWT-1/2) |
| Rota pública inalcançável de fora | `requireAuth: false` aqui sem rota dedicada no gateway | `bin/check-contracts.py` (ROTA-1) |
| Teste de integração passa, produção quebra | Coluna nova fora do schema SQLite dos testes | `bin/check-contracts.py` (MIG-2, aviso) |
| Deploy aplica a imagem errada ou nenhum TG | Placeholder de `deploy/base/` trocado por valor | `bin/check-contracts.py` (DEPLOY-1) |
| Deploy volta `forbidden` | CRD nova sem RBAC no `oficina-infra-cluster` | `bin/check-contracts.py` (RBAC-1) |
| Saga parada sem motivo aparente | Comando publicado fora da outbox, ou estado salvo sem passar pelo `SagaCommit` | `SagaFlowTest`, BDD e `bin/ws e2e --fase4` |
| Evento de outro serviço ignorado | Tipo fora de `local.consumed_message_types` (`infra/locals.tf`) ou fora do passo atual | o e2e da Fase 4 (a fila filtra de verdade no LocalStack) |
| Agente New Relic inerte e ninguém percebe | Base Alpine (sem glibc) ou license key trocada sem rollout | `pr.yml` (imagem) e o smoke de observabilidade do `deploy.yml` |

## Documentação

`docs/fase-3/` tem:

- `CONTRATOS.md` (normativo);
- 10 ADRs (`adr/`) e 3 RFCs (`rfc/`);
- `contract-token.md`;
- dashboards e alertas do New Relic (`newrelic/`, importados por `scripts/newrelic-import.py`);
- a coleção Postman.

`docs/fase-4/` tem o **desenho** da Fase 4 (três microsserviços, saga orquestrada, mensageria):
`CONTRATOS.md` (adendos ao da Fase 3), as ADRs 011 a 017 e os diagramas. Está implementado
nos seis repos; cada item só vale em um ambiente depois de entrar em `develop` (hml) ou `main`
(prod). Até lá, vale o contrato da Fase 3. A rota `GET /internal/customers/by-document/{document}`
é chamada pela Lambda `auth-cpf` (§7): resposta e códigos de erro dela são contrato com o
`HttpCustomerRepository` do `oficina-gateway-auth`.

`docs/DOMAIN-STORYTELLING.md` descreve os fluxos de negócio.

## Convenções

- Branches `feat/…`, `fix/…`, `chore/…`, `ci/…`, com PR para `develop`; `develop` faz deploy em
  hml e `main` em prod.
- Commits em português, sem acento no assunto, Conventional Commits com escopo
  (`feat(ordens): ...`), só com o nome do autor: sem `Co-Authored-By` nem rodapé de ferramenta.
- Agente nunca roda `kubectl apply`/`delete`, nunca lê `.env` nem valor de segredo.

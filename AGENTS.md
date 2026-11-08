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
  - `Aggregate/ServiceOrder.php` é a máquina de estados da OS:
    `RECEIVED → DIAGNOSIS → AWAITING_APPROVAL → EXECUTING → FINISHED → DELIVERED`, mais
    `AWAITING_APPROVAL → REJECTED`.
  - Cada transição é um método nomeado (`changeToDiagnosis`, `sendForApproval`, `approve`,
    `reject`, `finish`, `deliver`). **Nunca** existe `setStatus` (ADR-003). Transição inválida
    lança `InvalidStatusTransitionException`.
- **`Application/`**: um use case por ação (`UseCase/`), com DTOs de entrada (`DTO/`).
- **`Infrastructure/`**:
  - repositórios PDO em `Repository/`; a transação fica dentro do repositório (ADR-004) e a
    hidratação usa `reconstitute()` (ADR-005);
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
| Peça (estoque) | `Part` |
| Serviço do catálogo | `ServiceItem` |
| CPF/CNPJ | `Document` (value object) |
| Placa | `LicensePlate` |

Não crie um conceito novo em português ao lado de um que já existe em inglês.

## Deploy (`deploy/`, kustomize)

- `base/`: Deployment (probes em `/api/health` e `/api/ready`), Service, HPA, `ExternalSecret`
  (gera `oficina-secret` a partir do `ClusterSecretStore` `oficina-secretsmanager`),
  `TargetGroupBinding`, ConfigMaps e o Job `oficina-migrate`.
- `overlays/hml` e `overlays/prod`: namespace, réplicas (HPA de 2 a 10 em hml, de 3 a 10 em
  prod), recursos e tag da imagem; o prod tem PDB.
- **`TARGET_GROUP_ARN_PLACEHOLDER` e `APP_IMAGE_PLACEHOLDER` ficam como estão**: o `deploy.yml`
  substitui os dois por `sed`.
- O Job de migration clona o `oficina-infra-fundacao` na ref de
  `deploy/base/migrations-configmap.yaml` e roda `bin/migrate.php`. O runner usa a tabela
  `schema_migrations` e pula os arquivos `_demo` em produção.

## Validar

- A partir do repositório principal: `bin/ws verify --repo api` (composer, php-cs-fixer, phpstan,
  phpunit e `kustomize build` dos dois overlays), e `--image` para construir a imagem de
  produção e conferir a extensão do New Relic.
- Avulso: `bin/ws exec api vendor/bin/phpunit --filter ServiceOrder`.
- Os testes de integração (`tests/Integration/`) rodam em SQLite in-memory, com `CREATE TABLE`
  escritos dentro dos próprios testes, sem `ENUM` nem `CHECK`. Coluna nova usada por um
  repositório PDO precisa entrar ali também.
- `src/Infrastructure/Database/schema.sql` é legado e nada o referencia. O schema de verdade são
  as migrations do `oficina-infra-fundacao`.

## Armadilhas

| Sintoma | Causa | O que pega |
|---|---|---|
| Token do cliente recusado (401) | `JwtProvider` mudou de um lado só | `tests/Unit/Infrastructure/Security/JwtContractTest.php`, o espelho no gateway e `bin/check-contracts.py` (JWT-1/2) |
| Rota pública inalcançável de fora | `requireAuth: false` aqui sem rota dedicada no gateway | `bin/check-contracts.py` (ROTA-1) |
| Teste de integração passa, produção quebra | Coluna nova fora do schema SQLite dos testes | `bin/check-contracts.py` (MIG-2, aviso) |
| Deploy aplica a imagem errada ou nenhum TG | Placeholder de `deploy/base/` trocado por valor | `bin/check-contracts.py` (DEPLOY-1) |
| Deploy volta `forbidden` | CRD nova sem RBAC no `oficina-infra-cluster` | `bin/check-contracts.py` (RBAC-1) |
| Agente New Relic inerte e ninguém percebe | Base Alpine (sem glibc) ou license key trocada sem rollout | `pr.yml` (imagem) e o smoke de observabilidade do `deploy.yml` |

## Documentação

`docs/fase-3/` tem:

- `CONTRATOS.md` (normativo);
- 10 ADRs (`adr/`) e 3 RFCs (`rfc/`);
- `contract-token.md`;
- dashboards e alertas do New Relic (`newrelic/`, importados por `scripts/newrelic-import.py`);
- a coleção Postman.

`docs/DOMAIN-STORYTELLING.md` descreve os fluxos de negócio.

## Convenções

- Branches `feat/…`, `fix/…`, `chore/…`, `ci/…`, com PR para `develop`; `develop` faz deploy em
  hml e `main` em prod.
- Commits em português, sem acento no assunto, Conventional Commits com escopo
  (`feat(ordens): ...`), só com o nome do autor: sem `Co-Authored-By` nem rodapé de ferramenta.
- Agente nunca roda `kubectl apply`/`delete`, nunca lê `.env` nem valor de segredo.

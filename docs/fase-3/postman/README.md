# Coleção Postman — Fase 3

| Arquivo | Conteúdo |
|---|---|
| `oficina-fase3.postman_collection.json` | A coleção, com as pastas numeradas na ordem da demonstração |
| `hml.postman_environment.json` | Environment de homologação (preencher `baseUrl` e `adminPassword`) |
| `local.postman_environment.json` | Environment do `docker compose` local (`http://localhost:8080`) |

## Variáveis

| Variável | Origem |
|---|---|
| `baseUrl` | environment — em hml, o valor de `/oficina/hml/apigw/endpoint` no SSM |
| `adminUsername` / `adminPassword` | environment — chaves do segredo `oficina/<env>/auth` |
| `tokenCliente` | **preenchida automaticamente** pelo teste do `POST /auth/cpf` |
| `tokenAdmin` | **preenchida automaticamente** pelo teste do `POST /api/auth/login` |
| `customerId`, `novoClienteId`, `serviceOrderId` | encadeadas pelos scripts entre requests |
| `cpfDemo` | gerada no pre-request da coleção, com dígito verificador válido |

## Ordem da demonstração

0. `GET /api/health` e `GET /api/ready` — as sondas do Kubernetes.
1. `POST /auth/cpf` — sucesso (200), DV inválido (400), campo ausente (400), não
   cadastrado (404), `INACTIVE` (403), `BLOCKED` (403).
2. `POST /api/auth/login` — token do admin (200) e senha errada (401).
3. `GET /api/service-orders` **sem** `Authorization` — 401 devolvido pelo API Gateway.
4. `GET /api/service-orders/me` com o token do cliente; `GET /api/customers` com o
   mesmo token → 403.
5. CRUD de cliente como admin.
6. Abertura de OS, adição de serviços e peças, ciclo completo de status (incluindo
   uma transição inválida rejeitada).

## Dados

Os CPFs vêm do seed `003_seed_demo.sql` do repositório `oficina-infra-fundacao`
(aplicado apenas em hml):

| CPF | Cliente | Status | Uso |
|---|---|---|---|
| `78901428385` | Ana Paula Ribeiro | `ACTIVE` | caminho feliz |
| `94064914198` | Isabela Correia Braga | `INACTIVE` | 403 |
| `02407397878` | Joao Vitor Sampaio | `BLOCKED` | 403 |
| `12345678900` | — | — | DV inválido → 400 |
| `52998224725` | — | — | DV válido, não cadastrado → 404 |

## Rodar como smoke test

```bash
newman run docs/fase-3/postman/oficina-fase3.postman_collection.json \
  -e docs/fase-3/postman/hml.postman_environment.json
```

Cada request tem um `pm.test` conferindo o status esperado, então uma execução
verde da coleção é um smoke test do ambiente inteiro.

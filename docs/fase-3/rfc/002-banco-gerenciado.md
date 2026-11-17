# RFC-002 · Banco de dados gerenciado

- **Status:** Aceita — Amazon RDS MySQL 8.0
- **Decisões derivadas:** ADR-004, ADR-009
- **Depende de:** RFC-001 (AWS)

---

## Resumo

O banco sai do container MySQL do `docker-compose`/StatefulSet e passa a ser um serviço
gerenciado. Esta RFC compara **RDS MySQL**, **Aurora Serverless v2**, **RDS PostgreSQL** e
**DynamoDB**, e recomenda **RDS MySQL 8.0** em `db.t4g.micro`.

A conclusão curta: o modelo de dados é relacional, normalizado, com transação multi-tabela e
integridade referencial — DynamoDB está fora por natureza. Entre os relacionais, a escolha é entre
manter MySQL (custo de migração zero) e trocar por algo tecnicamente superior em alguns pontos;
o custo da troca não se paga nesta fase.

## Motivação

O banco é o único recurso **insubstituível** da arquitetura (ADR-009). A escolha define:

- o custo fixo mensal do ambiente;
- o esforço de migração do schema e do código de persistência existente;
- o comportamento sob a concorrência introduzida pelas 2–10 réplicas do HPA;
- a compatibilidade dos testes de integração, que rodam em SQLite in-memory.

## Contexto do modelo de dados

Sete tabelas, com relações N:M e consistência transacional obrigatória:

```
customers ──< vehicles
    └──────< service_orders >──┬── service_order_services >── service_catalog
                               └── service_order_parts    >── parts_inventory
                               └── service_order_status_history
```

Características que orientam a decisão:

- **Transação multi-tabela obrigatória.** Salvar uma OS grava a OS, seus serviços, suas peças e
  decrementa `parts_inventory.stock_quantity` — tudo ou nada (ADR-004).
- **Integridade referencial por FK.** Uma OS não pode referenciar cliente ou veículo inexistente.
- **Consultas por múltiplos padrões de acesso**: por status ordenado por prioridade, por cliente,
  por veículo, agregações de métricas por serviço. Padrões de acesso **não são conhecidos de
  antemão** — o dashboard de negócio (E13) inventa consultas novas.
- **Volume baixíssimo**: dezenas de OS por dia num cenário realista de oficina. O gargalo nunca
  será o banco.
- **Código existente em PDO/MySQL**, com testes de integração em SQLite.

## Alternativas avaliadas

| Critério | **RDS MySQL 8.0** | **Aurora Serverless v2** | **RDS PostgreSQL 16** | **DynamoDB** |
|---|---|---|---|---|
| Modelo | Relacional | Relacional (MySQL/PG compatível) | Relacional | Chave-valor / documento |
| Transação ACID multi-tabela | Sim | Sim | Sim | Só `TransactWriteItems`, **máx. 100 itens**, sem FK |
| Integridade referencial (FK) | Sim | Sim | Sim | **Não existe** |
| Consulta ad hoc / agregação | SQL completo | SQL completo | SQL completo, o mais rico dos quatro | **Não** — exige índice secundário planejado por consulta |
| Custo mínimo mensal | **~US$ 12** (`db.t4g.micro`) | **~US$ 43** (mínimo 0,5 ACU × 24 h × US$ 0,12) | ~US$ 12 (`db.t4g.micro`) | ~US$ 0 no free tier, on-demand |
| Escala a zero | Não | **Não** (v2 tem piso de 0,5 ACU; escalar a zero só em versões recentes e com latência de retomada) | Não | Sim, por natureza |
| Esforço de migração a partir do código atual | **Zero** | Zero (compatível com MySQL) | **Médio** — dialeto SQL, `AUTO_INCREMENT`, tipos, `ENUM`, funções de data | **Reescrita completa** do domínio de persistência |
| Compatibilidade com o teste em SQLite | Boa (já funciona) | Boa | Média — divergência maior entre PG e SQLite | Nenhuma |
| Recursos avançados | Suficientes | Storage auto-scaling, réplicas rápidas, backtrack | JSONB, CTE recursiva, índices parciais, `EXCLUDE` | Latência p99 baixíssima em acesso por chave |
| Multi-AZ | Opcional (dobra o custo) | Nativo | Opcional | Nativo |
| Adequação a este projeto | **Alta** | Média (superdimensionado) | Alta tecnicamente, baixa em custo-benefício de migração | **Inadequada** |

### Por que DynamoDB está fora

Não é preferência: é incompatibilidade estrutural. Sem FK, a integridade referencial viraria
responsabilidade do código. Sem transação relacional ampla, a operação de salvar a OS com baixa de
estoque teria que ser modelada como um item único desnormalizado ou como saga com compensação. E o
ponto que sela a questão: DynamoDB exige que os **padrões de acesso sejam conhecidos antes da
modelagem**, e este projeto tem consultas analíticas emergentes (funil de status, ticket médio,
tempo médio por status) que nasceram depois do schema. Cada consulta nova exigiria um GSI novo ou
uma exportação para outro sistema.

Escolher DynamoDB aqui seria escolher a tecnologia primeiro e o problema depois.

### Por que não Aurora Serverless v2

É tecnicamente superior ao RDS em quase tudo — storage auto-escalável, failover rápido, réplicas
baratas. O problema é o piso: **0,5 ACU cobradas 24 h por dia**, ~US$ 43/mês, mais de três vezes o
RDS `db.t4g.micro`, para um banco que fará dezenas de transações por dia. O argumento "escala a
zero quando não usa" não se aplica à v2 na configuração padrão. Pagar 3,5× por elasticidade que
não será exercida é o oposto de dimensionar para o problema.

### Por que não PostgreSQL

Tecnicamente é o banco mais rico dos quatro, e num projeto novo seria uma escolha defensável ou
até preferível. Mas o projeto **não é novo**: existe schema, existem repositórios PDO escritos,
existem testes de integração calibrados. Migrar exigiria revisar `ENUM`, `AUTO_INCREMENT`,
`ON DUPLICATE KEY UPDATE`, funções de data e o comportamento de `CHAR(36)` — trabalho puro de
tradução, sem nenhum recurso do PostgreSQL sendo efetivamente usado depois. O ganho seria
teórico; o custo, real e no caminho crítico do prazo.

Registrado: se o projeto precisasse de JSONB, CTEs recursivas ou índices parciais, a conta
inverteria.

## Proposta

**Amazon RDS MySQL 8.0**, propriedade do repositório `oficina-infra-fundacao` (ADR-009):

| Parâmetro | `hml` | `prod` |
|---|---|---|
| Engine | MySQL 8.0 | MySQL 8.0 |
| Classe | `db.t4g.micro` | `db.t4g.micro` |
| Storage | gp3, 20 GB, `storage_encrypted = true` | idem |
| Multi-AZ | não | não (custo) — registrado como dívida |
| Backup | retenção 7 dias | retenção 7 dias |
| Performance Insights | habilitado | habilitado |
| `deletion_protection` | `false` | **`true`** |
| Acesso | `publicly_accessible = false`, subnets privadas, SG `oficina-<env>-db` com ingress 3306 **apenas** do SG `oficina-<env>-db-client` | idem |
| Credenciais | Secrets Manager `oficina/<env>/db`, geradas por `random_password` | idem |

**Schema e migrations** vivem no mesmo repositório, em `migrations/NNN_<slug>.sql`, aplicados em
ordem lexicográfica pelo runner `bin/migrate.php` com controle em `schema_migrations`. Ajustes da
Fase 3 no `002_fase3_ajustes.sql`, incluindo `parts_inventory.version` e
`CHECK (stock_quantity >= 0)` — proteção contra a concorrência que o HPA introduz.

## Riscos

| Risco | Probabilidade | Impacto | Mitigação |
|---|---|---|---|
| `db.t4g.micro` (2 vCPU burstable, 1 GB) insuficiente sob carga da demo | Média | Médio | Carga da demo é modesta; monitorar `CPUCreditBalance` no dashboard de plataforma; a classe é alterável sem perda de dado |
| Ausência de Multi-AZ → indisponibilidade em falha de AZ | Baixa | Alto | Aceito nesta fase; backup diário com 7 dias limita a perda a RPO de 24 h. Ativar Multi-AZ é uma variável no `prod.tfvars` |
| Esgotamento de conexões com 10 réplicas × workers FPM | Média | Alto | `max_connections` do `db.t4g.micro` (~90) precisa ser conferido contra `réplicas × pm.max_children`; ajustar `pm.max_children` no `php-fpm` ou o parameter group |
| `ENUM` e `CHECK` do MySQL quebrarem o teste de integração em SQLite | **Alta** | Médio | Já sinalizado nos Contratos (seção 6): manter o schema de teste separado do de produção |
| Deleção acidental do banco | Baixa | Crítico | `deletion_protection` em prod, ADR-009 (banco fora do alcance da role da aplicação), snapshot final |
| Custo de Performance Insights além do free tier | Baixa | Baixo | 7 dias de retenção é gratuito |

## Plano de migração / saída

**Entrada (do estado atual):** o schema já existe em `src/Infrastructure/Database/schema.sql`; ele
vira `001_initial_schema.sql` sem `CREATE DATABASE` e sem `USE`, e o `002` aplica os ajustes. Não
há dado de produção a migrar — os ambientes nascem vazios, e `hml` recebe `003_seed_demo.sql`.

**Saída, se um dia for necessária:**

| Destino | Caminho | Esforço |
|---|---|---|
| Aurora MySQL | Snapshot do RDS → restore como cluster Aurora. Compatível, sem mudança de código | Baixo — horas |
| RDS PostgreSQL | AWS DMS ou dump + tradução do dialeto; revisar todos os repositórios PDO | Médio — dias |
| Outro provedor | `mysqldump` → import; a aplicação só muda `DB_HOST` | Baixo |
| Voltar para container | `mysqldump` → MySQL local | Baixo |

O ponto que garante isso é a interface `ServiceOrderRepositoryInterface` no domínio: trocar o
mecanismo de persistência é trocar uma implementação em `src/Infrastructure/Repository`.

## Questões em aberto

1. **`max_connections` × `réplicas × pm.max_children`** — precisa ser calculado e validado antes
   do teste de carga da demo. Se não fechar, a resposta é reduzir `pm.max_children` no overlay de
   produção, não aumentar a instância. *(Ação técnica, registrada em `PENDENCIAS.md`.)*
2. **Multi-AZ em `prod`** — fica desligado por custo (dobra o valor da instância). Reavaliar se o
   ambiente for usado para algo além da avaliação.
3. **Pooling de conexões** — o RDS Proxy (~US$ 15/mês) resolveria o item 1 de forma elegante e
   ainda daria failover mais rápido. Descartado por custo nesta fase; é a primeira coisa a
   acrescentar se a conexão virar gargalo.
4. **Locking otimista** — o campo `parts_inventory.version` foi criado no `002`, mas o uso efetivo
   pela aplicação (comparar e incrementar `version` no `UPDATE`) precisa ser confirmado no WS-D.
   Sem isso, o campo é decorativo e a proteção real fica só no `CHECK (stock_quantity >= 0)`.
5. **Retenção de backup** — 7 dias atende a fase. Produção real pediria 30 dias + snapshots
   mensais.

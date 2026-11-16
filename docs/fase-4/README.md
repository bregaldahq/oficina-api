# Fase 4 — microsserviços, Saga e CI/CD por serviço

A Fase 4 divide a aplicação em três microsserviços, cada um com repositório, infraestrutura e
banco próprios, coordenados por uma saga orquestrada sobre mensageria. Esta pasta guarda o
desenho. O documento normativo desta fase é o [`CONTRATOS.md`](CONTRATOS.md), que **acrescenta**
ao [`../fase-3/CONTRATOS.md`](../fase-3/CONTRATOS.md): o que ele não altera continua valendo.

> **Estado em 2026-11-10: desenho.** Nada do que está aqui foi implementado ainda. Enquanto um
> item não estiver implementado, o comportamento em produção é o da Fase 3.

| Documento | O que tem |
|---|---|
| [`CONTRATOS.md`](CONTRATOS.md) | donos de recursos, SSM, segredos, rotas, catálogo de mensagens, saga, banco, observabilidade, CI/CD |
| [`adr/`](adr/README.md) | ADRs 011 a 017 |
| [`diagramas/arquitetura.md`](diagramas/arquitetura.md) | serviços, bancos e comunicação |
| [`diagramas/saga.md`](diagramas/saga.md) | a saga da OS: caminho feliz, compensações e a máquina de estados |

## Os três serviços

| Serviço | Repositório | Responsável por | Banco |
|---|---|---|---|
| OS | `oficina-api` (este) | clientes, veículos, ordem de serviço, histórico de status, login do admin, orquestrador da saga | MySQL (RDS do `oficina-infra-fundacao`) |
| Faturamento | `oficina-faturamento` | catálogo de serviços, peças e estoque, orçamento, aprovação, pagamento pelo Mercado Pago, estorno | MySQL próprio (RDS do próprio repo) |
| Execução | `oficina-execucao` | fila de trabalho da oficina, diagnóstico, reparo | DynamoDB |

## Requisitos do enunciado e onde cada um é atendido

| Requisito | Onde |
|---|---|
| 3 microsserviços com repositório, infraestrutura e banco próprios | ADR-011, ADR-014, ADR-016 |
| Um banco SQL e um NoSQL | ADR-014 |
| REST quando necessário, mensageria para eventos | ADR-013, `CONTRATOS.md` §4 e §5 |
| Nenhum serviço acessa o banco de outro | ADR-014, `CONTRATOS.md` §7 (a `auth-cpf` deixa de ler o RDS) |
| Saga com rollback e compensação | ADR-012, `CONTRATOS.md` §6, `diagramas/saga.md` |
| Mercado Pago | ADR-015 |
| Testes unitários, BDD, cobertura de 80%, Sonar | `CONTRATOS.md` §9 |
| Pipeline independente por serviço, `main` protegida | `CONTRATOS.md` §9 |
| Observabilidade da Fase 3, com rastreamento distribuído | `CONTRATOS.md` §8 |

## Ponto de partida: cobertura de testes deste repo

Medida em 2026-11-10 sobre `develop` (`dd09be9`), com pcov, 192 testes, contando **todo** o
`src/` (o `phpunit.xml` de hoje só declara `Domain` e `Application` como fonte):

| Camada | Linhas cobertas |
|---|---|
| `Domain` | 211 de 223 (95%) |
| `Application` | 85 de 86 (99%) |
| `Infrastructure` | 448 de 613 (73%) — `Repository` 61%, `Database` 73%, `Logging` 72% |
| `Presentation` | 118 de 205 (58%) — `Controller` 38% |
| Classes sem nenhum teste | 329 linhas, 0% |
| **Total** | **862 de 1456 (59,2%)** |

Faltam cerca de 300 linhas cobertas para os 80%. O que está descoberto são os controllers e os
repositórios PDO. Parte deles (peças e serviços do catálogo) sai deste repo para o Faturamento,
então os testes que faltam são escritos no destino, e não aqui antes da mudança.

## Custo

A Fase 4 é desenvolvida e verificada **localmente** (LocalStack para SNS, SQS e DynamoDB; MySQL
em container; Mercado Pago com adaptador falso). O ambiente AWS só precisa existir para o teste
de integração final e para a gravação do vídeo, e é destruído em seguida. O que a fase
acrescenta à conta enquanto o ambiente está de pé:

| Item | Custo |
|---|---|
| SNS, SQS, DynamoDB sob demanda | centavos no volume de demonstração |
| Segunda instância RDS `db.t4g.micro`, single-AZ, sem backup | cerca de US$ 0,02 por hora |
| Listeners extras no NLB que já existe | sem custo fixo adicional |
| Pods a mais | cabem nos 2 nodes atuais com *prefix delegation* no vpc-cni; sem terceiro node |
| SonarCloud, sandbox do Mercado Pago | gratuitos |

O que pesa na conta já existia na Fase 3: o control plane do EKS (US$ 0,10 por hora) e os
nodes. Por isso a regra é manter o ambiente de pé o mínimo de dias, só em `hml`.

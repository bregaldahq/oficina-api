# ADRs da Fase 4

Continuam a numeração de [`../../fase-3/adr/`](../../fase-3/adr/README.md), com o mesmo
formato: `Status` · `Contexto` · `Decisão` · `Consequências` · `Alternativas consideradas`.

| ADR | Título | Status | Relação com as anteriores |
|---|---|---|---|
| [011](011-divisao-em-tres-microsservicos.md) | Divisão em três microsserviços | Aceita | — |
| [012](012-saga-orquestrada-no-servico-de-os.md) | Saga orquestrada, com o orquestrador no serviço de OS | Aceita | revê a alternativa "saga" descartada na ADR-004 |
| [013](013-mensageria-com-sns-e-sqs.md) | Mensageria com SNS e SQS | Aceita | substitui em parte a ADR-006 |
| [014](014-um-banco-por-servico.md) | Um banco por serviço: dois MySQL e um DynamoDB | Aceita | restringe a ADR-009 ao serviço de OS |
| [015](015-pagamento-com-mercado-pago.md) | Pagamento com Mercado Pago, antes do reparo | Aceita | — |
| [016](016-seis-repositorios-infra-junto-do-servico.md) | Seis repositórios, com a infraestrutura de cada serviço no próprio repo | Aceita | amplia a ADR-008 |
| [017](017-outbox-inbox-e-idempotencia.md) | Outbox, inbox e consumidores idempotentes | Aceita | complementa a ADR-004 |

```mermaid
flowchart TD
    A011["ADR-011<br/>3 microsserviços"] --> A012["ADR-012<br/>Saga orquestrada"]
    A011 --> A014["ADR-014<br/>Banco por serviço"]
    A011 --> A016["ADR-016<br/>6 repos"]
    A012 --> A013["ADR-013<br/>SNS + SQS"]
    A013 --> A017["ADR-017<br/>Outbox e inbox"]
    A014 --> A017
    A012 --> A015["ADR-015<br/>Mercado Pago"]
    A006["ADR-006<br/>REST síncrono"] -.->|"substituída em parte"| A013
    A008["ADR-008<br/>4 repos + SSM"] -.->|"ampliada"| A016
    A009["ADR-009<br/>Banco como fundação"] -.->|"restrita ao OS"| A014
```

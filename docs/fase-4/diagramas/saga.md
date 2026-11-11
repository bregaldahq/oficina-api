# Saga da ordem de serviço

Os passos, as mensagens e as compensações são os do [`CONTRATOS.md`](../CONTRATOS.md), §5 e §6.
A decisão por orquestração está na [ADR-012](../adr/012-saga-orquestrada-no-servico-de-os.md).

## Caminho feliz

```mermaid
sequenceDiagram
    autonumber
    actor A as Atendente
    actor M as Mecânico
    actor C as Cliente
    participant OS as OS (orquestrador)
    participant EX as Execução
    participant FA as Faturamento
    participant MP as Mercado Pago

    A->>OS: POST /api/service-orders
    Note over OS: OS RECEIVED · saga em DIAGNOSIS
    OS-->>EX: execution.request-diagnosis
    M->>EX: diagnosis/start
    EX-->>OS: diagnosis.started
    Note over OS: OS DIAGNOSIS
    M->>EX: diagnosis/complete (itens)
    EX-->>OS: diagnosis.completed
    OS-->>FA: billing.generate-budget
    Note over FA: orçamento criado, estoque reservado
    FA-->>OS: budget.generated
    Note over OS: OS AWAITING_APPROVAL
    C->>FA: POST budgets/{id}/approval (aprovado)
    FA-->>OS: budget.approved
    Note over OS: OS AWAITING_PAYMENT
    OS-->>FA: billing.charge
    FA->>MP: cria a preferência de pagamento
    FA-->>OS: payment.created (link)
    C->>MP: paga
    MP->>FA: webhook
    FA->>MP: consulta o pagamento
    FA-->>OS: payment.confirmed
    Note over OS: OS EXECUTING
    OS-->>EX: execution.start-repair
    M->>EX: repair/complete
    EX-->>OS: repair.completed
    Note over OS: OS FINISHED · saga COMPLETED
```

Setas cheias são HTTP; setas tracejadas são mensagens pelo tópico.

## Compensação: o reparo falha depois do pagamento

```mermaid
sequenceDiagram
    autonumber
    actor M as Mecânico
    participant OS as OS (orquestrador)
    participant EX as Execução
    participant FA as Faturamento
    participant MP as Mercado Pago

    Note over OS: OS EXECUTING · saga em REPAIR
    M->>EX: repair/fail (motivo)
    EX-->>OS: repair.failed
    Note over OS: saga COMPENSATING
    OS-->>FA: billing.refund-payment
    FA->>MP: estorno
    FA-->>OS: payment.refunded
    OS-->>FA: billing.cancel-budget
    Note over FA: reserva de estoque liberada
    FA-->>OS: budget.cancelled
    Note over OS: OS CANCELLED · saga COMPENSATED
```

## Todas as falhas

```mermaid
stateDiagram-v2
    [*] --> DIAGNOSIS: OS aberta
    DIAGNOSIS --> BUDGET: diagnosis.completed
    BUDGET --> APPROVAL: budget.generated
    APPROVAL --> PAYMENT: budget.approved
    PAYMENT --> REPAIR: payment.confirmed
    REPAIR --> COMPLETED: repair.completed
    COMPLETED --> [*]

    DIAGNOSIS --> COMPENSATING: diagnosis.failed
    BUDGET --> COMPENSATING: budget.failed
    APPROVAL --> COMPENSATING: budget.rejected ou prazo
    PAYMENT --> COMPENSATING: payment.failed ou prazo
    REPAIR --> COMPENSATING: repair.failed

    COMPENSATING --> COMPENSATED: todas as compensações confirmadas
    COMPENSATING --> FAILED: tentativas esgotadas
    COMPENSATED --> [*]
    FAILED --> [*]
```

| A saga falha em | O que já tinha sido feito | O que a compensação desfaz |
|---|---|---|
| `DIAGNOSIS` | tarefa criada na Execução (encerrada por ela mesma) | nada |
| `BUDGET` | tarefa na Execução | cancela a tarefa |
| `APPROVAL` (rejeitado) | tarefa, orçamento (o Faturamento libera o estoque ao rejeitar) | cancela a tarefa |
| `APPROVAL` (prazo) | tarefa, orçamento com estoque reservado | cancela o orçamento e a tarefa |
| `PAYMENT` | tarefa, orçamento com estoque reservado, cobrança criada | cancela o orçamento e a tarefa |
| `REPAIR` | tudo, inclusive o pagamento | estorna o pagamento e cancela o orçamento |

## Status da OS

```mermaid
stateDiagram-v2
    [*] --> RECEIVED
    RECEIVED --> DIAGNOSIS
    DIAGNOSIS --> AWAITING_APPROVAL
    AWAITING_APPROVAL --> AWAITING_PAYMENT: aprovado
    AWAITING_APPROVAL --> REJECTED: rejeitado
    AWAITING_PAYMENT --> EXECUTING: pago
    EXECUTING --> FINISHED
    FINISHED --> DELIVERED
    RECEIVED --> CANCELLED
    DIAGNOSIS --> CANCELLED
    AWAITING_APPROVAL --> CANCELLED
    AWAITING_PAYMENT --> CANCELLED
    EXECUTING --> CANCELLED
    REJECTED --> [*]
    CANCELLED --> [*]
    DELIVERED --> [*]
```

`AWAITING_PAYMENT` e `CANCELLED` são novos na Fase 4.

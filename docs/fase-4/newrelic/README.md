# Observabilidade da saga (Fase 4)

Soma ao que está em [`../../fase-3/newrelic/`](../../fase-3/newrelic/README.md). Tudo parte do
§8 de [`../CONTRATOS.md`](../CONTRATOS.md).

| Arquivo | O que é |
|---|---|
| `dashboard-saga.json` | Dashboard "Oficina Mecânica · Saga", em duas páginas. **Saga:** sagas abertas, desfecho, pagamentos, funil por passo, tempo por passo, compensações por motivo, taxa de compensação e sagas que falharam. **Mensageria:** mensagens por serviço e resultado, erros por tipo, duração, transações de mensagem (o trace pela fila), DLQs e sagas paradas. |
| `alertas-saga.json` | Política "Oficina Mecânica · saga · <env>" com seis condições: mensagem na DLQ, `SagaFailed`, saga parada além do prazo, taxa de compensação, erro no webhook do Mercado Pago e worker falhando. |

## Importar

O mesmo script da Fase 3 importa os dois, depois dos da Fase 3:

```bash
NEW_RELIC_API_KEY=NRAK-... python3 scripts/newrelic-import.py --env hml --endpoint https://<api-id>.execute-api.us-east-1.amazonaws.com --dry-run
```

Sem `--dry-run`, cria ou atualiza no lugar (rodar de novo não duplica). O script troca a conta,
o `appName` dos três serviços (`oficina-<serviço>-<env>`), o nome das filas
(`oficina-<env>-<serviço>-dlq`) e o `env` dos eventos e logs pelo valor do `APP_ENV`
(`homologacao` ou `production`).

## De onde vem cada dado

| Dado | Origem |
|---|---|
| `SagaStarted`, `SagaStepCompleted`, `SagaCompensated`, `SagaFailed` | custom events do serviço de OS, emitidos depois do commit (`SagaCommit` → `NewRelicSubscriber::onSaga`) |
| `PaymentConfirmed` | custom event do Faturamento, depois de gravar a confirmação |
| `message.processed`, `saga.stalled` | log JSON dos workers (atributos `service`, `env`, `message_type`, `outcome`, `duration_ms`, `saga_id`, `os_id`) |
| transações `message/<tipo>` | agente PHP no worker: uma transação de background por mensagem, aceitando o trace gravado no envelope por quem publicou |
| `QueueSample` (DLQs) | **integração AWS do New Relic com o SQS** (*Infrastructure → AWS → Link account*, por API polling). Sem ela, o painel e o alerta de DLQ ficam vazios; o alerta "worker falhando", que vem do log, cobre o mesmo problema mais cedo. |

## Validar

Com a saga rodando em hml (o roteiro do e2e serve: abrir uma OS, diagnosticar, aprovar, pagar
no sandbox do Mercado Pago), cada painel da página **Saga** precisa mostrar dado. Para ver o
trace atravessando a fila: *APM → oficina-api-hml → Distributed tracing*, filtrando por
`message/`; o trace de uma OS passa pelos três serviços.

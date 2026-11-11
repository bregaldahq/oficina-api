# ADR-017 · Outbox, inbox e consumidores idempotentes

## Status

Aceita (Fase 4). Complementa a ADR-004: a transação continua dentro do repositório, e passa a
incluir a mensagem que sai.

## Contexto

Com mensageria (ADR-013), cada passo da saga faz duas coisas: muda o estado no banco do serviço
e avisa os outros. São dois sistemas, e não há transação entre um banco e o SNS.

- Gravar no banco e depois publicar: se o processo cai entre os dois, o estado mudou e ninguém
  ficou sabendo. A saga para.
- Publicar e depois gravar: se a gravação falha, os outros serviços reagem a algo que não
  aconteceu.

Além disso, o SQS entrega cada mensagem **pelo menos uma vez** e sem ordem garantida. Um
consumidor que processa duas vezes o mesmo `billing.charge` cobra o cliente em dobro.

A ADR-004 já estabelece que os eventos só saem depois do commit. Dentro de um processo isso
bastava; entre processos, não.

## Decisão

**Outbox na escrita, inbox na leitura, e handlers idempotentes.**

- **Outbox.** O repositório grava a mensagem de saída **na mesma transação** da mudança de
  estado. No MySQL, numa tabela `outbox`. No DynamoDB, num item de outbox dentro da mesma
  `TransactWriteItems`. O worker do serviço lê a outbox, publica no SNS e marca como publicada.
  Se cair depois de publicar e antes de marcar, publica de novo: é duplicata, e a inbox do
  destino resolve.
- **Inbox.** O consumidor grava o `id` da mensagem na mesma transação do efeito que ela causa.
  Se o `id` já existe, a mensagem é confirmada sem efeito.
- **Chave de negócio além do `id`.** Um comando repetido com `id` diferente para a mesma saga
  não repete o efeito: há no máximo um orçamento, uma cobrança e um estorno por `saga_id`.
- **Evento fora de hora.** O orquestrador só aplica o evento que o passo atual espera. Os
  demais são registrados e confirmados, sem erro.
- **Falha.** Exceção no handler devolve a mensagem à fila. Na quinta falha ela vai para a DLQ,
  que tem alerta. Nada é descartado em silêncio.
- **Chamadas externas** (Mercado Pago) levam chave de idempotência derivada do id de negócio.

## Consequências

**Positivas**

- Não existe estado alterado sem mensagem, nem mensagem sem estado alterado.
- Reentrega, duplicata e reinício do worker deixam de ser casos especiais.
- A outbox é também um registro auditável do que cada serviço publicou.

**Negativas**

- A mensagem sai com atraso de até um ciclo do worker (da ordem de um segundo).
- Duas tabelas a mais por serviço, que crescem e precisam de limpeza periódica.
- Todo handler precisa ser escrito e testado para rodar duas vezes.
- No DynamoDB, a outbox dentro de `TransactWriteItems` limita o tamanho da operação e exige
  cuidado no desenho das chaves.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **Publicar logo depois do commit, sem outbox** | Simples, mas perde a mensagem se o processo cai no intervalo, e uma saga parada sem motivo aparente é o pior defeito deste desenho. |
| **CDC (binlog do MySQL, DynamoDB Streams)** | Elimina o polling da outbox, mas são dois mecanismos diferentes para os dois bancos e mais infraestrutura para um ganho de latência irrelevante aqui. |
| **Filas FIFO com deduplicação** | Deduplica só dentro de uma janela de cinco minutos e não protege contra o reprocessamento depois de falha parcial do consumidor. |
| **Confiar em "exatamente uma vez"** | Não existe nesta pilha. |

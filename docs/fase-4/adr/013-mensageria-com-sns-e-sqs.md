# ADR-013 · Mensageria com SNS e SQS

## Status

Aceita (Fase 4). Substitui em parte a ADR-006: o tráfego do cliente continua síncrono, por REST
e pelo API Gateway; a integração **entre serviços** passa a ser assíncrona. A ADR-006 deixou
mensageria fora de escopo "até haver um caso de uso que a justifique". A saga entre três
serviços é esse caso.

## Contexto

A saga (ADR-012) precisa de um meio para comandos e eventos que:

- guarde a mensagem enquanto o destinatário está fora do ar;
- reentregue em caso de falha e separe o que não consegue ser processado;
- não exija operar mais um componente com estado no cluster, que já esteve no limite de pods
  por node;
- custe perto de zero num ambiente que fica de pé poucos dias;
- rode localmente, sem AWS.

## Decisão

**Um tópico SNS e uma fila SQS por serviço.**

- Todos publicam no tópico `oficina-<env>-mensagens`. Cada serviço tem a fila
  `oficina-<env>-<servico>`, que assina o tópico com filtro pelo atributo `type`, mais uma DLQ
  com `maxReceiveCount = 5`.
- O tópico é da fundação (sobrevive ao cluster). Fila, DLQ e assinatura são do `infra/` de cada
  serviço (ADR-016).
- Tópico e filas **padrão**, não FIFO: a ordem é garantida pela máquina de estados da saga, e
  a duplicidade pela inbox (ADR-017).
- Os pods acessam SNS e SQS com um role IRSA por serviço, pelos endpoints públicos (os nodes
  têm saída para a internet, ADR-010). Nenhuma access key.
- Cada serviço roda um Deployment `worker`: um processo PHP em linha de comando, com *long
  polling* de 20 s.
- O cliente é o `aws/aws-sdk-php`. É a primeira dependência de runtime destes serviços; a
  ADR-001 recusa framework, não biblioteca de protocolo, e assinar SigV4 à mão seria reinventar
  o SDK.
- Local e no e2e: LocalStack.

## Consequências

**Positivas**

- Nada para operar: sem broker, sem volume, sem pod a mais.
- Custo desprezível no volume de demonstração.
- Um serviço fora do ar não perde mensagem nem derruba quem publicou.
- Um consumidor novo entra com uma assinatura, sem tocar em quem publica.

**Negativas**

- Entrega "pelo menos uma vez" e sem ordem: todo consumidor precisa ser idempotente.
- Acoplamento à AWS; trocar de nuvem exige reescrever os adaptadores de publicação e consumo.
- O trace distribuído não atravessa a fila sozinho: é preciso carregar o contexto no envelope.
- Não há painel visual da fila como o do RabbitMQ; a observação é pelo console da AWS e pelo
  New Relic.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **RabbitMQ no cluster** | Roteamento rico e painel didático, mas é um pod com estado, volume e senha para operar, num cluster dimensionado no limite. |
| **Kafka (MSK)** | Custo fixo alto e capacidade muito além do necessário. |
| **Só SQS, com o produtor escrevendo na fila do destino** | Mais simples, mas o produtor passa a conhecer a fila de cada consumidor, e um segundo consumidor exigiria mudar quem publica. |
| **EventBridge** | Bom roteamento, mas a reentrega e a DLQ por consumidor acabam exigindo uma SQS atrás de cada regra, sem ganho sobre o SNS. |
| **SNS e SQS FIFO** | Ordem e deduplicação nativas, com limite de vazão e custo maiores. A inbox já resolve duplicidade e a saga já resolve ordem. |

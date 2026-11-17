# ADR-012 · Saga orquestrada, com o orquestrador no serviço de OS

## Status

Aceita (Fase 4). Revê a alternativa "consistência eventual (saga)" que a ADR-004 descartou como
desproporcional: ela era, enquanto as escritas eram locais a um banco. Com um banco por serviço
(ADR-014) deixou de ser.

## Contexto

O fluxo de uma OS atravessa os três serviços: abrir a OS, diagnosticar, gerar o orçamento
(reservando estoque), esperar a aprovação do cliente, cobrar, executar o reparo. Não existe
transação que abranja três bancos. Se o reparo falhar depois de o cliente ter pago, alguém
precisa estornar o pagamento e devolver as peças ao estoque.

O fluxo tem três características que pesam na escolha:

- é **linear**: um passo depois do outro, sem ramificação paralela;
- é **longo e tem espera humana**: a aprovação e o pagamento podem levar horas, e precisam de
  prazo;
- tem **um dono natural**: tudo o que acontece muda o status da OS.

## Decisão

Saga **orquestrada**. O orquestrador é um componente do serviço de OS.

- O estado de cada saga fica na tabela `sagas`, no banco do OS: passo atual, prazo do passo,
  o que já foi feito e o que falta compensar.
- O orquestrador publica **comandos** (`billing.generate-budget`, `execution.start-repair`...)
  e reage a **eventos** dos outros serviços. Os passos, as compensações e os prazos estão no
  `CONTRATOS.md` da Fase 4, §6.
- Avançar a saga e mudar o status da OS acontecem na **mesma transação local**, junto com a
  gravação do próximo comando na outbox (ADR-017).
- Toda falha tem compensação definida. Compensação é idempotente e repetida até ser confirmada;
  se esgotar as tentativas, a saga fica em `FAILED` e dispara alerta.
- O orquestrador conhece só o contrato das mensagens, nunca o banco nem a API dos outros
  serviços.

## Consequências

**Positivas**

- O fluxo inteiro está num lugar: dá para ler a ordem dos passos e a compensação de cada falha
  num arquivo, e testar tudo em BDD com um barramento em memória.
- Prazos de aprovação e de pagamento têm onde morar (`sagas.deadline_at`).
- A pergunta "em que ponto está esta OS e por quê" tem resposta numa consulta.
- Faturamento e Execução não sabem um do outro.

**Negativas**

- O serviço de OS conhece os comandos dos outros dois: é o ponto de maior acoplamento do
  sistema.
- Com o worker do OS parado, nenhuma saga avança (as mensagens esperam na fila; nada se perde).
- O orquestrador é código de coordenação a manter além da regra de negócio.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **Coreografia** (cada serviço reage aos eventos dos outros) | Menos acoplamento central, mas a ordem dos passos e, principalmente, as compensações ficariam espalhadas por três repositórios. Prazo de aprovação não teria dono. Para um fluxo linear com espera humana, o custo de entender e depurar supera o ganho. |
| **Orquestrador como quarto serviço** | Separaria coordenação de negócio, mas o estado da saga e o status da OS deixariam de mudar na mesma transação, criando a inconsistência que a saga existe para evitar. |
| **Motor de workflow gerenciado (AWS Step Functions)** | Resolve prazos e reexecução, mas tira o fluxo do código testável localmente e acrescenta custo e uma dependência fora do cluster. |
| **Transação distribuída (2PC)** | Não existe entre MySQL, DynamoDB e uma API de pagamento externa. |

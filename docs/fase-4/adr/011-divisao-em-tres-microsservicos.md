# ADR-011 · Divisão em três microsserviços

## Status

Aceita (Fase 4).

## Contexto

Até a Fase 3 a regra de negócio vive numa aplicação só (`oficina-api`), com um banco. A Fase 4
exige ao menos três microsserviços independentes, cada um com repositório, infraestrutura e
banco próprios, sem que um acesse o banco do outro.

O domínio tem quatro áreas que já aparecem no fluxo da OS: atendimento (abrir a OS, cadastro de
cliente e veículo), diagnóstico e execução (o trabalho na oficina) e cobrança (orçamento e
pagamento). O agregado `ServiceOrder` de hoje mistura as três coisas: guarda o status, os itens
com preço e baixa o estoque na mesma transação (ADR-004).

## Decisão

Três serviços, cortados pelas áreas do negócio:

| Serviço | Repositório | Dono de |
|---|---|---|
| **OS** | `oficina-api` | clientes, veículos, a ordem de serviço e seu status, o histórico, o login do admin e o orquestrador da saga (ADR-012) |
| **Faturamento** | `oficina-faturamento` | catálogo de serviços, peças e estoque, orçamento, aprovação do cliente, pagamento e estorno |
| **Execução** | `oficina-execucao` | a fila de trabalho da oficina, o diagnóstico (que levanta os itens) e o reparo |

Regras que acompanham o corte:

- **Catálogo e estoque ficam no Faturamento.** É ele que põe preço no orçamento, e reservar o
  estoque na mesma transação do orçamento dá à saga uma compensação concreta (liberar a
  reserva).
- **A OS deixa de guardar itens e preços.** Guarda o status, o histórico, o `budget_id` e o
  total, que chegam por evento.
- **Os serviços não se chamam por HTTP.** O que um precisa do outro vai no payload da mensagem.
  A única chamada síncrona interna é a da Lambda `auth-cpf` ao OS, para buscar o cliente pelo
  CPF, porque o login exige resposta imediata.
- **O repositório `oficina-api` continua com esse nome** e passa a ser o serviço de OS. O state
  do Terraform, o role de CI, o ECR e o histórico de PRs apontam para ele, e os repositórios
  foram renomeados há pouco.
- **Os três seguem em PHP 8.2 sem framework** (ADR-001). O roteador, o `JwtProvider`, o logger
  e o Dockerfile são copiados para os repos novos na criação; não há biblioteca compartilhada.
  O que não pode divergir (o JWT e o envelope das mensagens) é travado por teste de contrato.

## Consequências

**Positivas**

- Cada serviço tem um motivo só para mudar e pode ser implantado sozinho.
- Uma falha no Faturamento ou na Execução não impede abrir nem consultar OS.
- O corte coincide com o exemplo do enunciado, o que simplifica a justificativa.

**Negativas**

- Consultar "a OS completa" (status, itens, orçamento, pagamento) passa a exigir duas chamadas
  do cliente, ao OS e ao Faturamento.
- O que era uma transação ACID vira uma saga, com estados intermediários visíveis.
- Três cópias do esqueleto (roteador, JWT, logger) para manter iguais onde importa.
- A `auth-cpf` passa a depender de o serviço de OS estar no ar.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **Quatro serviços, com Cadastro (clientes e veículos) separado do OS** | Mais um repositório, banco e pipeline para um CRUD que só o OS usa. Não melhora a história de resiliência nem a da saga. |
| **Estoque como serviço próprio** | Daria mais um participante à saga, mas a reserva deixaria de ser atômica com o orçamento, sem ganho para o negócio neste porte. |
| **Catálogo e estoque no OS** | O OS teria de responder ao Faturamento de forma síncrona para precificar, criando o acoplamento temporal que a mensageria evita. |
| **Biblioteca Composer compartilhada** | Um sétimo repositório com ciclo de release próprio, e acoplamento de versão entre serviços que deveriam ser independentes. |
| **Renomear `oficina-api` para `oficina-os`** | Mais claro para quem chega, mas quebra referências recém-estabilizadas. O README do repo resolve a clareza. |

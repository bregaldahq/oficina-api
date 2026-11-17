# ADR-016 · Seis repositórios, com a infraestrutura de cada serviço no próprio repo

## Status

Aceita (Fase 4). Amplia a ADR-008: os quatro repositórios viram seis, e o acoplamento entre
eles continua sendo **só** por SSM Parameter Store.

## Contexto

A ADR-008 separou o sistema em fundação, cluster, borda e aplicação. A Fase 4 exige um
repositório por microsserviço, cada um com infraestrutura e pipeline próprias. Falta decidir
onde nasce cada recurso novo: a fila de um serviço, o role que o pod assume, o banco do
Faturamento, a tabela da Execução.

Há duas forças. Recurso **compartilhado** (o tópico de mensagens, o NLB, o cluster) precisa
existir antes dos serviços e sobreviver a eles. Recurso **de um serviço só** deveria mudar no
mesmo PR que o código que o usa, e sumir junto com o serviço.

## Decisão

Seis repositórios:

| Repositório | Papel |
|---|---|
| `oficina-infra-fundacao` | rede, RDS do OS, segredos compartilhados, **tópico SNS** |
| `oficina-infra-cluster` | EKS, add-ons, NLB com **um listener e um target group por serviço**, RBAC de deploy |
| `oficina-gateway-auth` | Lambdas, API Gateway com **rota por prefixo de serviço** |
| `oficina-api` | serviço de OS |
| `oficina-faturamento` | serviço de Faturamento |
| `oficina-execucao` | serviço de Execução |

- Cada repo de serviço tem `src/`, `deploy/` (kustomize) e **`infra/`** (Terraform): fila, DLQ
  e assinatura do tópico, role IRSA, e o que for só dele (ECR, banco, segredos). É o mesmo
  desenho do `oficina-gateway-auth`, que já junta código e Terraform.
- Cada repo de serviço tem `pr.yml` e `deploy.yml` próprios. O deploy aplica o `infra/` e
  depois os manifests. Nenhum pipeline dispara ou espera o de outro repo.
- Ordem de `apply`: fundação → cluster → gateway → cada serviço (`infra/`, depois deploy). Os
  três serviços são independentes entre si.
- Os parâmetros que um serviço publica em SSM são lidos só pelo próprio pipeline. Serviço não
  lê parâmetro de serviço.
- O roteamento usa o NLB que já existe: como ele é de camada 4, cada serviço ganha uma porta
  (`:80` OS, `:81` Faturamento, `:82` Execução) e o API Gateway encaminha por prefixo de
  caminho (`/api/billing/`, `/api/execution/`, e o restante de `/api/` para o OS).

## Consequências

**Positivas**

- Mudança num serviço cabe num PR: código, manifesto e infraestrutura juntos.
- Remover um serviço remove a infraestrutura dele com um `destroy`.
- Os repos de plataforma só mudam quando entra ou sai um serviço.
- Nenhum balanceador novo.

**Negativas**

- Entrar um serviço novo ainda exige tocar três repos de plataforma (listener, RBAC, rota).
- O role de CI de cada serviço precisa de permissão para o Terraform do `infra/`, além do
  deploy: mais IAM para manter no `bootstrap/aws/`.
- Seis pipelines e seis conjuntos de proteção de branch.
- Portas por serviço no NLB são um registro manual; o `CONTRATOS.md` é o lugar dele.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **Toda a infraestrutura dos serviços nos repos de fundação e cluster** | Mantém os serviços "só código", mas cada fila ou tabela nova viraria uma mudança em dois repos e na ordem certa, e contraria o "infraestrutura própria" do enunciado. |
| **Um ALB com roteamento por caminho, no lugar das portas do NLB** | Mais elegante, mas é um segundo balanceador com custo fixo por hora. |
| **Ingress controller no cluster atrás do NLB** | Roteamento por caminho sem custo de balanceador, mas acrescenta um componente e pods ao cluster, e um ponto único por onde passam os três serviços. |
| **Um API Gateway por serviço** | Três domínios e três authorizers para o cliente lidar. |
| **Monorepo com os três serviços** | Contraria o requisito de um repositório por microsserviço. |

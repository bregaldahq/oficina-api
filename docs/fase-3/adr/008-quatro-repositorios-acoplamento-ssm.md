# ADR-008 · Quatro repositórios com acoplamento por SSM Parameter Store

## Status

Aceita (Fase 3) · Ampliada pela [ADR-016](../../fase-4/adr/016-seis-repositorios-infra-junto-do-servico.md) na Fase 4: os repositórios passam a seis; o acoplamento só por SSM continua.

## Contexto

O enunciado da Fase 3 pede a separação da entrega em repositórios independentes, cada um com seu
próprio pipeline. A divisão adotada segue os **ciclos de vida** dos recursos, não as camadas
técnicas:

| Repositório | Muda quando | Frequência |
|---|---|---|
| `oficina-infra-fundacao` | rede, banco ou segredos mudam | raríssimo |
| `oficina-infra-cluster` | versão do cluster, add-ons, node group | ocasional |
| `oficina-gateway-auth` | regra de autenticação, rotas do gateway | ocasional |
| `oficina-api` | qualquer mudança de produto | diário |

Separar repositórios cria imediatamente o problema de **como um stack descobre os recursos do
outro**: a aplicação precisa do endpoint do RDS, o cluster precisa da VPC e das subnets, o gateway
precisa do ARN do listener do NLB.

A resposta padrão do Terraform é `terraform_remote_state`. Ela funciona, mas tem três defeitos
sérios num arranjo multi-repositório:

1. **Acoplamento ao backend, não à interface.** O consumidor precisa saber o bucket, a key e a
   região do state alheio. Reorganizar o state de um repositório quebra os outros.
2. **Permissão excessiva.** Ler o state remoto significa ler o arquivo **inteiro** — inclusive
   atributos sensíveis de qualquer recurso lá dentro. Não há como conceder acesso a um único
   output.
3. **Acoplamento a Terraform.** Quem não é Terraform não consegue consumir: nem a Lambda em
   runtime, nem um script de CI, nem `kubectl`, nem um humano com `aws-cli`.

## Decisão

**Nenhum repositório usa `terraform_remote_state`.** O acoplamento entre stacks é feito
exclusivamente pelo **AWS Systems Manager Parameter Store**, tratado como o **contrato público**
de cada repositório.

- Cada stack **publica** seus outputs relevantes como parâmetros SSM sob `/oficina/<env>/...`, com
  nomes fixados na seção 2 do documento de Contratos.
- Cada stack **consome** o que precisa via `data "aws_ssm_parameter"`.
- Os nomes dos parâmetros são **API pública**: renomear é breaking change e exige coordenação —
  publicar o novo, migrar consumidores, só então remover o antigo.

```hcl
data "aws_ssm_parameter" "vpc_id" {
  name = "/oficina/${var.environment}/network/vpc_id"
}
```

Regras que acompanham a decisão:

- Um parâmetro por informação, tipado (`String` / `StringList`) — nada de JSON serializado num
  parâmetro só, que reintroduziria o acoplamento a formato.
- **Segredo nunca vai para o SSM como valor**; o SSM carrega o **ARN** do segredo
  (`/oficina/<env>/db/secret_arn`, `/oficina/<env>/auth/secret_arn`) e o valor fica no Secrets
  Manager (ver ADR-009).
- Cada repositório tem seu próprio state (`oficina/<repo>/<env>/terraform.tfstate`) no bucket
  comum, com lock em DynamoDB `oficina-tflock`.
- Ordem de `apply`: **fundação → cluster → gateway → api**. Ordem de `destroy`: inversa.
- Cada repositório assume uma role própria via OIDC (`oficina-gha-<repo>`), com permissão de
  escrita apenas sobre o seu prefixo de parâmetros e leitura sobre os que consome.

## Consequências

**Positivas**

- **Contrato explícito e legível.** `aws ssm get-parameters-by-path --path /oficina/prod` mostra a
  interface inteira entre os stacks — algo que nenhum `terraform_remote_state` oferece.
- **Menor privilégio de verdade:** dá para permitir leitura de `/oficina/<env>/network/*` sem dar
  acesso ao state do banco.
- **Agnóstico de ferramenta.** A Lambda, o `kubectl`, um script de smoke test ou um humano
  consomem o mesmo contrato. Se um stack migrar para CDK ou Pulumi, os consumidores não mudam.
- Reorganizar o state interno de um repositório não afeta ninguém, desde que os parâmetros
  publicados continuem os mesmos.
- Pipelines verdadeiramente independentes: cada repositório planeja e aplica sozinho.
- Custo zero — parâmetros Standard do SSM são gratuitos.

**Negativas**

- **O grafo de dependências deixa de ser automático.** O Terraform não sabe que o cluster depende
  do banco; a ordem de `apply` é conhecimento humano, documentado nos Contratos e em
  `PENDENCIAS.md`. Aplicar fora de ordem falha com "parameter not found", que é um erro claro —
  mas é um erro em tempo de `apply`, não de `plan`.
- **Risco de drift de contrato.** Um `output` publicado com nome errado só quebra o consumidor
  seguinte. Mitigação: os nomes são normativos nos Contratos e verificados na integração (WS-F).
- **Bootstrap e destruição em ordem manual.** Destruir o banco antes do cluster deixa o cluster
  órfão apontando para parâmetros inexistentes.
- Um `apply` que remove um parâmetro em uso quebra o consumidor no próximo `plan`, sem aviso
  prévio — não há verificação de referências.
- Mais repositórios significam mais pipelines, mais branch protections e mais secrets para manter
  em dia.
- Uma mudança que atravessa fronteiras (ex.: uma rota nova que exige política IAM nova) vira dois
  ou três PRs coordenados em vez de um.

## Alternativas consideradas

| Alternativa | Avaliação | Veredito |
|---|---|---|
| **`terraform_remote_state`** | Grafo automático e zero configuração extra. Rejeitada pelos três motivos do Contexto: acoplamento a backend, leitura integral do state (inclusive sensíveis) e inutilidade fora do Terraform. | Rejeitada |
| **Monorepo com um único state** | Ordem resolvida pelo próprio grafo e refactor atômico. Rejeitada porque contraria o requisito de repositórios separados, e porque um state único faz um `apply` de aplicação poder destruir banco. | Rejeitada |
| **Data sources por tag/nome (`aws_vpc` com filtro)** | Dispensa publicação, mas transforma convenção de nomenclatura em contrato implícito e frágil, e não cobre ARNs (listener, segredo). | Rejeitada |
| **Terragrunt** | Resolveria orquestração e ordem entre stacks. Adiciona uma ferramenta e um DSL a mais para instalar, aprender e explicar à banca, sem eliminar o acoplamento a state. | Rejeitada |
| **Secrets Manager para tudo (inclusive não-segredo)** | Uniformizaria o acesso, mas cobra por segredo armazenado e por chamada, e trataria dados públicos como sensíveis — ruim para auditoria. | Rejeitada |

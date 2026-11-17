# RFC-001 · Escolha do provedor de nuvem

- **Status:** Aceita — AWS
- **Autor:** time de arquitetura, Tech Challenge Fase 3
- **Decisões derivadas:** ADR-008, ADR-009, ADR-010

---

## Resumo

A Fase 3 exige levar a aplicação de um cluster kind local para uma nuvem pública, com Kubernetes
gerenciado, banco gerenciado, função serverless de autenticação, API gateway e observabilidade.
Esta RFC compara **AWS**, **Google Cloud** e **Azure** para esse conjunto, e recomenda **AWS**,
região `us-east-1`.

O critério decisivo não é técnico — os três provedores atendem tecnicamente. É a combinação de
**alinhamento com o material e a avaliação do curso**, **familiaridade do time** e **volume de
referência disponível para depurar sob prazo**.

## Motivação

Precisamos escolher **antes** de escrever qualquer linha de Terraform, porque a escolha determina:

- os serviços gerenciados de cada componente (Kubernetes, banco, serverless, gateway, segredos);
- o modelo de identidade da esteira (OIDC do GitHub Actions → role/service account);
- o mecanismo de acoplamento entre stacks (ADR-008 escolheu SSM Parameter Store, que é da AWS);
- a estimativa de custo do ambiente, que é pago pelo aluno.

Trocar de provedor depois significaria reescrever os três repositórios de infraestrutura.

## Requisitos

| # | Requisito | Peso |
|---|---|---|
| R1 | Kubernetes gerenciado com HPA, IRSA-equivalente e add-ons via Helm | Alto |
| R2 | Banco relacional MySQL 8.0 gerenciado, multi-AZ opcional, backup automático | Alto |
| R3 | Função serverless com runtime PHP viável e VPC access | Alto |
| R4 | API Gateway com authorizer customizado e integração privada a um load balancer interno | Alto |
| R5 | Cofre de segredos com integração ao Kubernetes (External Secrets) | Alto |
| R6 | Federação OIDC com GitHub Actions, sem chave estática | Alto |
| R7 | Custo compatível com orçamento pessoal (< US$ 150/mês por ambiente) | Alto |
| R8 | Free tier ou crédito inicial | Médio |
| R9 | Alinhamento com o conteúdo e os exemplos da pós-graduação | Alto |
| R10 | Integração com New Relic | Médio |

## Alternativas avaliadas

| Critério | **AWS** | **Google Cloud** | **Azure** |
|---|---|---|---|
| Kubernetes gerenciado (R1) | EKS — maduro, IRSA por OIDC, add-ons gerenciados. Plano de controle **US$ 73/mês** | GKE Autopilot/Standard — a melhor experiência de K8s do mercado; Workload Identity é superior ao IRSA. **1 cluster zonal grátis** no free tier | AKS — plano de controle **gratuito**; Workload Identity maduro |
| MySQL gerenciado (R2) | RDS MySQL 8.0 — `db.t4g.micro` ~US$ 12/mês | Cloud SQL MySQL — sem instância realmente barata; ~US$ 25/mês no menor porte | Azure Database for MySQL Flexible — B1ms ~US$ 15/mês |
| Serverless PHP (R3) | Lambda + **Bref** — padrão de fato para PHP, layers prontas, comunidade grande | Cloud Run/Functions — PHP via container, funciona bem, mas o modelo é container, não função | Azure Functions — **suporte a PHP é experimental**; caminho real é container |
| API Gateway (R4) | HTTP API — barato (US$ 1/milhão), **Lambda Authorizer REQUEST**, VPC Link para NLB interno | API Gateway/Apigee — Apigee é caro; o gateway "leve" é menos flexível em authorizer customizado | API Management — camada Consumption existe, mas o produto é pesado e caro nas camadas úteis |
| Segredos (R5) | Secrets Manager (US$ 0,40/segredo/mês) + SSM Parameter Store **gratuito** — a dupla que a ADR-008 usa | Secret Manager — bom, mas **não há equivalente gratuito do Parameter Store** para dados não sensíveis | Key Vault — bom; App Configuration para não-segredo, com custo |
| OIDC GitHub Actions (R6) | Nativo, `configure-aws-credentials@v4`, documentação abundante | Nativo via Workload Identity Federation | Nativo |
| Custo estimado do ambiente (R7) | **~US$ 110/mês** (EKS 73 + RDS 12 + 2× t3.small 30, sem NAT) | ~US$ 75/mês (cluster grátis + nodes + Cloud SQL) | ~US$ 60/mês (control plane grátis + nodes + MySQL) |
| Free tier (R8) | 12 meses limitado; **EKS não tem free tier** | US$ 300 de crédito por 90 dias + 1 cluster zonal grátis | US$ 200 de crédito por 30 dias + AKS grátis |
| Alinhamento com o curso (R9) | **Total** — o material, os exemplos e a avaliação da SOAT são em AWS | Baixo | Baixo |
| Familiaridade do time (R10) | **Alta** | Média | Baixa |
| Integração New Relic | Integração AWS nativa (CloudWatch metric streams), layer de Lambda pronta | Boa | Boa |

### Leitura da tabela

Se o critério fosse **só custo**, Azure venceria; se fosse **só qualidade de Kubernetes**, GCP
venceria com folga (Autopilot e Workload Identity são melhores que node group + IRSA). AWS é a
opção **mais cara** das três — o plano de controle do EKS sozinho custa mais que o AKS inteiro.

O que decide contra esses dois pontos:

1. **Requisito R9 é bloqueante na prática.** A avaliação, o material e o vocabulário do curso são
   AWS. Entregar em Azure exigiria que a banca traduzisse cada decisão.
2. **R3 elimina o Azure.** Suporte a PHP em Azure Functions é experimental; a Lambda com Bref é
   caminho batido, com layer publicada e exemplos. Num prazo curto, isso é decisivo.
3. **R4 favorece a AWS.** O Lambda Authorizer REQUEST com `enable_simple_responses` e contexto
   customizado é exatamente a peça que a RFC-003 precisa; os equivalentes em GCP e Azure são menos
   diretos.
4. **R5/ADR-008 dependem do Parameter Store gratuito.** O acoplamento entre stacks por parâmetros
   nomeados sem custo por leitura não tem equivalente exato nos outros dois.
5. **Familiaridade abrevia a depuração.** Sob prazo, errar em terreno conhecido custa horas; errar
   em terreno novo custa dias.

## Proposta

Adotar **AWS**, região **`us-east-1`**, com:

| Componente | Serviço |
|---|---|
| Kubernetes | **EKS 1.30**, managed node group 2–4 × `t3.small` |
| Banco | **RDS MySQL 8.0** `db.t4g.micro`, gp3 20 GB, encrypted (ver RFC-002) |
| Serverless | **Lambda** com layer **Bref** (`auth-cpf` e `jwt-authorizer`) |
| Entrada | **API Gateway HTTP API** + VPC Link → **NLB interno** |
| Segredos | **Secrets Manager** (valores) + **SSM Parameter Store** (contratos entre stacks) |
| Registry | **ECR** |
| IaC | **Terraform**, state em S3 + lock DynamoDB |
| CI/CD | GitHub Actions com **OIDC**, roles `oficina-gha-<repo>` |
| Observabilidade | **New Relic** (APM PHP, `nri-bundle` no cluster, layer de Lambda) |

Região `us-east-1` por ser a mais barata, a que recebe serviços novos primeiro e a de maior
disponibilidade de tipos de instância — a latência para o Brasil (~120 ms) é irrelevante para uma
demonstração. `sa-east-1` custaria de 20% a 50% a mais.

## Riscos

| Risco | Probabilidade | Impacto | Mitigação |
|---|---|---|---|
| Custo estourar o orçamento pessoal | Alta | Alto | AWS Budgets com alerta em US$ 50/100; `terraform destroy` do cluster fora das janelas de trabalho (viabilizado pela ADR-009); sem NAT Gateway (ADR-010) |
| Quota padrão de vCPU insuficiente para o node group | Média | Alto | Verificar e solicitar aumento **antes** do primeiro apply (ver `PENDENCIAS.md`) |
| Lock-in em serviços proprietários (SSM, Secrets Manager, API Gateway) | Alta | Médio | Aceito conscientemente; o **domínio** (`src/Domain`) não tem nenhuma dependência de nuvem, então uma migração afetaria só a infraestrutura |
| EKS ser caro demais para manter ligado | Média | Médio | O cluster é descartável por desenho (ADR-009); destruir e recriar é operação segura |
| Conta nova da AWS com limite de recursos ou verificação pendente | Média | Alto | Criar a conta com antecedência e validar com um `apply` pequeno |

## Plano de migração / saída

O que ficaria e o que mudaria numa eventual troca de provedor:

| Camada | Portabilidade |
|---|---|
| `src/Domain`, `src/Application` | **100% portável** — nenhuma dependência de nuvem |
| `src/Infrastructure` | Alta — PDO/MySQL é padrão; só o `SecretsProvider` mudaria |
| `deploy/` (kustomize) | **Alta** — manifests Kubernetes são portáveis; mudariam anotações de LB e o `ClusterSecretStore` |
| Imagem Docker | 100% portável |
| Lambdas | Média — a lógica é PHP puro; muda o adaptador de evento e o empacotamento |
| Terraform | **Baixa** — reescrita completa dos três stacks |
| Contratos SSM | Baixa — precisaria de um equivalente (Runtime Config no GCP, App Configuration no Azure) |

Estimativa de esforço para migrar para GKE: 3 a 5 dias de trabalho, concentrados em Terraform.
Nenhuma linha de domínio seria tocada — o que é, em si, uma validação da Clean Architecture.

**Gatilhos que reabririam esta RFC:** crédito educacional relevante em outro provedor; custo do
EKS se tornando proibitivo com o cluster permanentemente ligado; requisito de residência de dados
no Brasil (LGPD com dado real).

## Questões em aberto

1. `us-east-1` ou `sa-east-1`? — **decidido:** `us-east-1`, por custo. Reavaliar se houver dado
   real de cliente e exigência de residência.
2. Node group **On-Demand** ou **Spot**? — o Terraform do `oficina-infra-cluster` já suporta Spot por
   variável (WS-B2). Recomendação: Spot em `hml`, On-Demand em `prod`. A decisão final depende de
   observar interrupções durante a fase de testes.
3. Conta única com dois ambientes ou duas contas (`hml` e `prod`)? — **nesta fase, conta única**
   com separação por prefixo de recurso e por tag. Multi-conta com AWS Organizations é o correto
   para produção e fica registrado como evolução.
4. Habilitar **AWS Budgets** por ambiente ou por conta? — por conta nesta fase, dado o item 3.

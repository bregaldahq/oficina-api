# ADR-009 · O repositório de banco é a camada de fundação (rede, dados e segredos)

## Status

Aceita (Fase 3).

## Contexto

Com quatro repositórios (ADR-008), é preciso decidir **quem é dono de cada recurso**. A divisão
ingênua seria por afinidade técnica: a rede fica com o cluster, porque é o cluster que "usa" as
subnets; os segredos ficam com quem os consome — o de banco com o banco, o de autenticação com a
Lambda, que é quem emite o token.

Essa divisão quebra no cenário que mais importa: **destruir e recriar o EKS**. E esse cenário é
real, não hipotético. Num projeto acadêmico com orçamento limitado, o cluster é o recurso caro e
será derrubado fora das janelas de trabalho e recriado depois; ele também é o recurso que mais
muda (versão do Kubernetes, tipo de instância, add-ons), e recriar é frequentemente mais simples
do que migrar in-place.

Se a VPC pertencesse ao repositório do cluster, `terraform destroy` levaria a rede junto — e com
ela o subnet group do RDS, quando não o próprio banco. Se o segredo `oficina/<env>/auth`
pertencesse à Lambda, destruir o stack de autenticação **rotacionaria o `JWT_SECRET`**, invalidando
todos os tokens em circulação e, pior, dessincronizando a aplicação (que ainda leria o valor
antigo materializado no `Secret` do cluster) da Lambda (que passaria a assinar com o novo).

A pergunta certa, portanto, não é "quem usa?", mas **"o que é durável e o que é descartável?"**.

## Decisão

O repositório **`oficina-infra-fundacao` é a camada de fundação** e possui **tudo que é durável**:

| Recurso | Por que é fundação |
|---|---|
| VPC, subnets públicas e privadas, IGW, route tables | Endereçamento estável; recriar muda CIDRs e quebra referências |
| Security group de banco e SG de **cliente** de banco | O SG cliente é anexado por outros stacks (nodes do EKS, Lambda); é contrato de rede |
| RDS MySQL 8.0, subnet group, parameter group | Dado é insubstituível |
| **Todos** os segredos do Secrets Manager — `oficina/<env>/db` **e** `oficina/<env>/auth` | Rotação acidental quebra autenticação em produção |
| Migrations SQL versionadas (`migrations/*.sql`) | Evolução do schema pertence a quem é dono do schema |

Os demais repositórios possuem apenas o **descartável**: o cluster, os add-ons, o ECR, o NLB, as
Lambdas, o gateway e os manifestos da aplicação. Todos consomem a fundação por SSM (ADR-008) e
nenhum tem permissão de escrita sobre os recursos dela.

O `JWT_SECRET` ficar no repositório de banco parece contraintuitivo — não tem nada a ver com
banco. A razão é que ele é **compartilhado por dois consumidores** (a Lambda que emite e a
aplicação que valida) e, por HS256 (ADR-002), precisa ser exatamente o mesmo nos dois. Um segredo
com dois donos não tem dono; colocá-lo na camada mais estável garante que nenhum ciclo de vida
mais curto o rotacione por acidente. O agrupamento é por **durabilidade**, não por assunto.

O critério que fecha a decisão: **`terraform destroy` completo em `oficina-infra-cluster`,
`oficina-gateway-auth` e no stack da aplicação não pode causar perda de dado nem invalidar tokens.**

## Consequências

**Positivas**

- O cluster vira gado, não bicho de estimação: destruir para economizar e recriar depois é uma
  operação segura e ensaiada, não um evento de risco.
- Raio de explosão contido: a role de CI do repositório de cluster e a da aplicação simplesmente
  **não têm permissão** sobre RDS, VPC e Secrets Manager. Um `apply` errado não alcança o durável.
- `JWT_SECRET` com dono único e ciclo de vida longo elimina toda uma classe de incidente
  ("de repente todos os tokens ficaram inválidos").
- A ordem de operação fica óbvia e ensinável: fundação primeiro, resto depois; destruição na
  ordem inversa.
- O estado durável fica num state pequeno e raramente aplicado — menos oportunidade de erro.

**Negativas**

- **Coesão temática comprometida.** Um repositório chamado `-database` que possui a VPC e o
  segredo de JWT exige explicação; o nome mente um pouco. Considerou-se renomear para
  `oficina-infra-foundation`, mas o nome do repositório está fixado nos Contratos e no plano de
  entrega. Fica registrado como candidato a rename futuro.
- **Gargalo de mudança.** Qualquer subnet nova, regra de SG nova ou claim nova no segredo passa
  por um repositório que não é o de quem precisa da mudança — cria dependência entre times/PRs.
- O SG de cliente de banco é criado por um stack e **anexado** por outro; o `terraform plan` de
  nenhum dos dois mostra a relação inteira. É um acoplamento documentado, não verificado.
- Destruir a fundação é uma operação irreversível que exige `deletion_protection` em produção e
  disciplina humana — o Terraform não protege sozinho.
- Se a fundação for aplicada com um erro de rede, **todos** os stacks à frente ficam bloqueados.

## Alternativas consideradas

| Alternativa | Avaliação | Veredito |
|---|---|---|
| **Rede no repositório do cluster** | Coesão técnica melhor (quem usa subnet é o EKS). Rejeitada porque `terraform destroy` do cluster levaria a rede e, por dependência, o subnet group do RDS. Exatamente o cenário que a decisão existe para evitar. |Rejeitada |
| **Um quinto repositório só de rede (`oficina-infra-network`)** | Conceitualmente o mais limpo — três camadas de durabilidade. Rejeitada por custo de coordenação: mais um state, mais um pipeline, mais uma role, mais uma ordem de apply, para separar dois recursos que sempre mudam juntos. | Rejeitada |
| **Segredo de auth no repositório da Lambda** | Coeso por assunto. Rejeitada porque rotacionaria o `JWT_SECRET` a cada `destroy`/`recreate` do stack de autenticação, dessincronizando emissor e validador. | Rejeitada |
| **Segredos criados manualmente fora do IaC** | Elimina o risco de rotação por `apply`. Rejeitada porque tira o segredo do controle de versão da infraestrutura, exige passo manual em todo ambiente novo e abre espaço para valor fraco digitado à mão — os Contratos exigem `random_password`. | Rejeitada |
| **Banco fora do IaC (criado pelo console)** | Máxima proteção contra destruição acidental. Rejeitada por contrariar o requisito de IaC do desafio e por tornar o ambiente irreprodutível. | Rejeitada |

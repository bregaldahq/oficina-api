# ADR-014 · Um banco por serviço: dois MySQL e um DynamoDB

## Status

Aceita (Fase 4). Restringe a ADR-009: o banco da fundação passa a ser o banco **do serviço de
OS**, e não o banco do sistema.

## Contexto

A Fase 4 exige banco próprio por microsserviço, ao menos um relacional e um não relacional, e
proíbe que um serviço acesse o banco de outro. Hoje há um RDS MySQL, usado pela aplicação e
também pela Lambda `auth-cpf`, que lê a tabela `customers` diretamente.

Cada serviço tem um perfil de dados diferente:

- **OS**: clientes, veículos, ordens e o estado das sagas. Relacional, com integridade
  referencial, já em MySQL.
- **Faturamento**: catálogo, estoque, orçamentos e pagamentos. Dinheiro e estoque pedem
  transação ACID: reservar peças e gravar o orçamento têm de ser atômicos.
- **Execução**: a tarefa da oficina, com seus itens e a linha do tempo. É um documento lido
  por chave e listado por status. Não tem junção nem transação entre tarefas.

## Decisão

| Serviço | Banco | Onde nasce |
|---|---|---|
| OS | MySQL 8.0, o RDS que já existe | `oficina-infra-fundacao` (como na Fase 3) |
| Faturamento | MySQL 8.0, **instância RDS própria** (`db.t4g.micro`, single-AZ) | `infra/` do `oficina-faturamento` |
| Execução | **DynamoDB**, tabela única, sob demanda | `infra/` do `oficina-execucao` |

- As tabelas de catálogo, estoque e itens saem do banco do OS. Como são dados de
  demonstração, o Faturamento nasce com seed próprio e não há migração de dados.
- As migrations do OS continuam na fundação (ADR-009). As do Faturamento ficam no repo dele.
- A **`auth-cpf` deixa de acessar o RDS**. Passa a consultar o serviço de OS por um endpoint
  interno, autenticado por token de serviço.
- A Execução acessa o DynamoDB com o role IRSA, sem senha.
- Em `hml`, o RDS do Faturamento sobe sem backup automático e sem Multi-AZ.

## Consequências

**Positivas**

- Instâncias e credenciais distintas: nenhum serviço recebe a credencial do banco de outro.
- A Execução não tem banco para administrar, nem custo quando parada.
- Cada serviço evolui o próprio schema sem coordenar com os outros.

**Negativas**

- Uma segunda instância RDS: cerca de US$ 0,02 por hora enquanto o ambiente existe.
- Não há mais `JOIN` entre OS e itens; relatórios que cruzam os dois passam a ser composição no
  cliente ou projeção por evento.
- Dois modelos de persistência para dominar: PDO/MySQL e DynamoDB.
- O login do cliente passa a depender do serviço de OS, e não só do banco.
- O isolamento é por credencial, não por rede: os nodes carregam o SG que alcança os dois RDS,
  então um pod comprometido que obtivesse a senha de outro serviço chegaria ao banco dele.
  Fechar isso pede security groups por pod, que ficam fora desta fase.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **Banco lógico e usuário separados na instância da fundação** | Sem custo adicional, mas "banco próprio" ficaria discutível (mesma instância, mesmo ponto de falha), e criar banco e usuário exige um passo executado de dentro da VPC com a credencial mestra. A economia é de centavos por hora num ambiente efêmero. |
| **Faturamento em DynamoDB também** | Custo zero, mas reserva de estoque e orçamento atômicos entre vários itens ficam mais difíceis de escrever e de provar corretos. É o serviço em que o relacional mais se justifica. |
| **MongoDB (no cluster ou Atlas) para a Execução** | No cluster é mais um pod com estado; no Atlas é outra conta, outra rede e outro segredo. O DynamoDB já está na conta e no Terraform. |
| **PostgreSQL no Faturamento** | Sem ganho para este domínio, e perderia o reaproveitamento do runner de migrations e dos repositórios PDO já escritos para MySQL. |
| **Manter a `auth-cpf` lendo `customers`, como exceção documentada** | Viola a regra mais explícita do enunciado. |

# Diagramas — Fase 3

Todos os diagramas são **Mermaid** em arquivos Markdown, renderizados nativamente pelo GitHub.
A escolha é deliberada: diagrama versionado junto com o código, revisável em pull request e
sem dependência de ferramenta externa ou de imagem que envelhece sem ninguém perceber.

| Arquivo | O que mostra | Quando consultar |
|---|---|---|
| [`componentes.md`](componentes.md) | Componentes na AWS, fronteiras de rede e propriedade de cada recurso por repositório | Para entender **o que existe** e **quem é dono** |
| [`sequencia-autenticacao.md`](sequencia-autenticacao.md) | `POST /auth/cpf` com todos os ramos de erro, e o uso do token nas rotas protegidas | Para entender **como o cliente entra** |
| [`sequencia-abertura-os.md`](sequencia-abertura-os.md) | `POST /api/service-orders` do gateway ao evento de domínio e à telemetria | Para entender **o caminho de uma escrita** |

Diagramas complementares vivem onde pertencem:

- **Máquina de estados da OS** e camadas da Clean Architecture — [`README.md`](../../../README.md)
  da raiz.
- **Domain Storytelling** dos dois fluxos de negócio —
  [`docs/DOMAIN-STORYTELLING.md`](../../DOMAIN-STORYTELLING.md).
- **Relação entre as decisões arquiteturais** — [`adr/README.md`](../adr/README.md).
- **Modelo de dados (ER)** — repositório `oficina-infra-fundacao`.

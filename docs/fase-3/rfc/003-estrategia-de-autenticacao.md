# RFC-003 · Estratégia de autenticação e autorização

- **Status:** Aceita — Lambda `auth-cpf` emitindo JWT HS256 + Lambda Authorizer REQUEST
- **Decisões derivadas:** ADR-002, ADR-006
- **Depende de:** RFC-001 (AWS)

---

## Resumo

A Fase 3 introduz um requisito de produto novo: **o cliente da oficina se identifica pelo CPF** e
recebe um token com o qual acompanha as próprias Ordens de Serviço. Isso substitui a rota pública
`GET /api/service-orders/status`, que aceitava `document` e `license_plate` por query string e
vazava dados de qualquer CPF conhecido — uma falha de controle de acesso quebrada (OWASP A01).

Esta RFC compara três estratégias — **Lambda própria emitindo JWT HS256**, **Amazon Cognito** e
**mTLS** — e recomenda a primeira. Documenta também a razão técnica pela qual a validação do token
**não** pode usar o JWT Authorizer nativo do API Gateway HTTP API e precisa de um **Lambda
Authorizer do tipo REQUEST**, e o caminho de evolução para **RS256 com JWKS**, que é o que
permitiria voltar ao authorizer nativo.

## Motivação

Três problemas a resolver de uma vez:

1. **Fechar o vazamento.** Qualquer pessoa com um CPF válido conseguia consultar OS alheias sem
   autenticação. A rota é removida e substituída por `GET /api/service-orders/me`, que devolve
   somente as OS do `sub` do token.
2. **Autenticar o cliente final** com o dado que ele tem à mão — o CPF — sem inventar cadastro de
   senha para quem só quer acompanhar um conserto.
3. **Separar dois públicos**: o **admin** da oficina (usuário e senha, `POST /api/auth/login`,
   `role=admin`, acesso amplo) e o **cliente** (`POST /auth/cpf`, `role=customer`, acesso apenas
   ao que é dele).

Restrição forte herdada: a aplicação já emite e valida JWT HS256 com `iss` = `oficina-mecanica-api`
(ADR-002), e o contrato do token é normativo (seção 4 dos Contratos). Qualquer solução precisa ou
respeitar esse formato, ou substituí-lo nos dois lados ao mesmo tempo.

> **Nota de segurança, registrada explicitamente.** Autenticar por CPF sem segundo fator é
> **identificação, não autenticação forte**: CPF não é segredo. A mitigação nesta fase é de
> autorização — o token de `customer` só dá acesso a `GET /api/service-orders/me` e à própria OS,
> nunca a escrita. É aceitável para o escopo do desafio e **não seria aceitável** num sistema com
> dado real sem um segundo fator (OTP por SMS ou email). Ver "Questões em aberto".

## Requisitos

| # | Requisito | Peso |
|---|---|---|
| R1 | Cliente se autentica só com o CPF, sem cadastro prévio de senha | Alto |
| R2 | Token stateless, validável por qualquer réplica sem estado compartilhado | Alto |
| R3 | Compatível com o contrato de token já existente (seção 4) | Alto |
| R4 | Autorização aplicada na borda **e** revalidada na aplicação (defesa em profundidade) | Alto |
| R5 | Distinguir `role=customer` de `role=admin` e propagar `customerId` | Alto |
| R6 | Custo próximo de zero no volume do projeto | Alto |
| R7 | Implementável em PHP, coerente com o resto da stack | Médio |
| R8 | Caminho de evolução claro para chave assimétrica | Médio |

## Alternativas avaliadas

| Critério | **Lambda + JWT HS256** (proposta) | **Amazon Cognito** | **mTLS** |
|---|---|---|---|
| Login só com CPF (R1) | Sim — handler consulta o RDS e decide | Forçado: exigiria custom auth flow (Lambda triggers Define/Create/Verify) ou usuário com senha fictícia. Complexo e artificial | Não se aplica — identidade é o certificado, não o CPF |
| Token stateless (R2) | Sim | Sim (JWT RS256 assinado pelo user pool) | Não há token; a identidade é da conexão TLS |
| Compatibilidade com o contrato atual (R3) | **Total** — mesma classe `JwtProvider` | **Nenhuma** — claims do Cognito (`cognito:username`, `token_use`, `client_id`) não são as nossas; exigiria reescrever `AuthMiddleware` e o contrato | Nenhuma |
| Authorizer nativo do API Gateway | **Não** (ver seção seguinte) | **Sim** — é o caso de uso canônico do JWT Authorizer | Não suportado por HTTP API |
| Defesa em profundidade (R4) | Sim — o Pod revalida com o mesmo segredo | Sim, mas o Pod precisaria buscar e cachear o JWKS | Terminaria no LB; o Pod não veria o certificado |
| Propagar `customerId` (R5) | Sim, via `context` do authorizer + claim `sub` | Sim, via claims | Exigiria mapear certificado → cliente |
| Custo (R6) | **~US$ 0** — Lambda no free tier, milhares de invocações/mês | Free tier de 50 mil MAU generoso, mas o custom auth flow adiciona 3 Lambdas | Alto em operação: emitir, distribuir, renovar e revogar certificado por cliente |
| Esforço de implementação (R7) | Médio — 2 handlers PHP + Terraform | **Alto** — user pool, app client, 3 triggers, migração dos clientes existentes, reescrita da autorização | **Muito alto** |
| Evolução para assimétrico (R8) | Sim, planejada (ver adiante) | Já é RS256 | n/a |
| Adequação ao caso de uso | **Alta** | Baixa — resolve um problema (gestão de identidade) que não temos | **Inadequada** — mTLS autentica máquinas, não pessoas em navegador |

### Por que não Cognito

Cognito é a resposta certa quando o problema é **gestão de identidade**: cadastro, confirmação por
email, recuperação de senha, MFA, login social, rotação de refresh token. Nada disso é requisito
aqui. Nosso "login" é uma consulta a uma tabela `customers` que já existe, com uma regra de status
(`ACTIVE` / `INACTIVE` / `BLOCKED`).

Para atender R1 no Cognito seria preciso o **custom authentication flow**, com três Lambda
triggers (`DefineAuthChallenge`, `CreateAuthChallenge`, `VerifyAuthChallengeResponse`) — ou seja,
**mais** código Lambda que a proposta, para chegar ao mesmo lugar, e ainda por cima com um formato
de token incompatível com o contrato da seção 4, o que quebraria a aplicação e o teste de contrato
de ambos os repositórios.

O ganho real que Cognito traria é o authorizer nativo (sem Lambda no caminho) e RS256 com rotação
de chave gerenciada. São ganhos verdadeiros — e é exatamente por isso que o **caminho de evolução**
descrito adiante os incorpora sem adotar o Cognito inteiro.

### Por que não mTLS

mTLS autentica **máquinas**, não pessoas usando navegador ou aplicativo. Exigiria emitir,
distribuir, renovar e revogar um certificado por cliente da oficina — operacionalmente inviável
para o público-alvo. Além disso, o API Gateway HTTP API só suporta mTLS em **domínio customizado**,
o que obrigaria a registrar domínio e ACM, e a terminação aconteceria na borda: o Pod não veria o
certificado, então R4 (revalidação local) ficaria sem resposta. Avaliada e descartada por
inadequação ao ator, não por complexidade.

---

## O problema central: o JWT Authorizer nativo não valida HS256

Este é o ponto técnico que determina o desenho, e merece ser explícito porque é uma limitação
pouco documentada e que só aparece na hora do `terraform apply`.

O API Gateway **HTTP API** oferece dois tipos de authorizer:

| Tipo | Como funciona |
|---|---|
| **JWT Authorizer** (nativo) | Configurado com `issuer` e `audience`. O gateway **busca o documento de descoberta OIDC** em `<issuer>/.well-known/openid-configuration`, dele obtém a URL do **JWKS**, baixa as chaves públicas e valida a assinatura do token — tudo sem invocar código nosso |
| **Lambda Authorizer** | O gateway invoca uma função nossa, que recebe a requisição e devolve autorizado/negado |

O JWT Authorizer nativo tem duas restrições que nos excluem:

1. **Só valida algoritmos assimétricos publicados via JWKS** — RS256, RS384, RS512, ES256 e
   afins. Ele obtém a **chave pública** de um endpoint JWKS. **HS256 é simétrico**: validar exigiria
   que o API Gateway conhecesse o **segredo compartilhado**, e não existe campo de configuração
   para isso — nem poderia existir, porque um JWKS é, por definição, um documento **público**, e
   publicar um segredo HMAC nele seria publicar a capacidade de emitir tokens.
2. **Exige um emissor OIDC de verdade.** O `issuer` precisa responder em
   `<issuer>/.well-known/openid-configuration` com um documento de descoberta válido apontando um
   `jwks_uri`. Nosso `iss` é a string `oficina-mecanica-api` (seção 4 dos Contratos) — não é sequer
   uma URL, e não há servidor por trás dela.

Na prática, tentar configurar `aws_apigatewayv2_authorizer` com `authorizer_type = "JWT"` e
`issuer = "oficina-mecanica-api"` falha na validação do provider ou, quando aplicado, resulta em
`401` sistemático porque a descoberta OIDC não resolve.

**Consequência de desenho:** a validação do token vai para um **Lambda Authorizer do tipo
REQUEST**, com o `JwtProvider` em PHP fazendo exatamente a mesma verificação `hash_hmac` +
`hash_equals` que a aplicação faz. O tipo é `REQUEST` (e não `TOKEN`, que sequer existe em HTTP
API) porque precisamos acesso ao `$request.path` para liberar `POST /api/auth/login` sem token —
é onde o admin obtém o dele.

Configuração contratada (seção 5 dos Contratos):

```hcl
authorizer_type                   = "REQUEST"
authorizer_payload_format_version = "2.0"
enable_simple_responses           = true
authorizer_result_ttl_in_seconds  = 300
identity_sources                  = ["$request.header.Authorization"]
```

O **cache de 300 s** é o que torna a decisão barata: o resultado é chaveado pelo valor do header
`Authorization`, então requisições repetidas com o mesmo token **não invocam a Lambda**. Numa
sessão típica, uma invocação a cada 5 minutos por token, em vez de uma por requisição — elimina
tanto o custo quanto a latência de cold start do caminho quente.

O `enable_simple_responses = true` faz o retorno ser o objeto simples
`{"isAuthorized": true|false, "context": {"customerId": "...", "role": "..."}}`, em vez de uma
política IAM completa — mais simples de escrever e de testar.

**Custo do cache, dito com todas as letras:** um token revogado (ou um cliente que passe a
`BLOCKED`) continua sendo aceito pela borda por até 5 minutos. Como não há revogação de JWT nesta
arquitetura (ADR-002) e o `exp` é de 1 hora, isso não piora a garantia existente — mas é a razão
pela qual a **revalidação no Pod** (R4) não é redundância cerimonial: é a camada que consegue
aplicar regra de autorização mais fina e atual do que a borda.

---

## Proposta

### Componentes

| Componente | Repositório | Papel |
|---|---|---|
| Lambda `auth-cpf` | `oficina-gateway-auth` | Valida o CPF, consulta `customers` no RDS, checa `status`, emite o JWT |
| Lambda `jwt-authorizer` | `oficina-gateway-auth` | Valida a assinatura e o `exp`, libera `POST /api/auth/login`, devolve `isAuthorized` + contexto |
| `AuthController` / `AuthMiddleware` | aplicação | Login do admin e **revalidação local** do token em toda rota protegida |
| Segredo `oficina/<env>/auth` | `oficina-infra-fundacao` | `JWT_SECRET` compartilhado pelos três (ADR-009) |

### Fluxos

| Ator | Rota | Como obtém o token | `role` | Alcance |
|---|---|---|---|---|
| Cliente | `POST /auth/cpf` (sem authorizer) | Lambda `auth-cpf` | `customer` | `GET /api/service-orders/me`, `GET /api/service-orders/{id}` se for dono |
| Admin | `POST /api/auth/login` (liberado pelo authorizer via `$request.path`) | Aplicação | `admin` | Todo o `/api/**` |
| Webhook | `POST /api/service-orders/{id}/approval` | Não usa JWT — header `X-Webhook-Token` obrigatório | — | Só essa rota |

### Camadas de verificação

```
API Gateway  →  jwt-authorizer (assinatura + exp, cache 300 s)
             →  NLB interno → Pod
             →  AuthMiddleware (revalida assinatura + exp + iss)
             →  Router::requireRole (matriz de autorização, seção 5)
             →  Use Case (o cliente só enxerga o que é dele)
```

A aplicação **nunca confia** em header injetado pelo gateway: ela relê o `Authorization` e valida
do zero. Se um dia o NLB for alcançado por outro caminho, a autorização continua de pé.

Erros de `POST /auth/cpf` seguem a tabela da seção 5 dos Contratos — 400 para CPF inválido ou
ausente, 404 para não cadastrado, 403 para `INACTIVE`/`BLOCKED`, 500 para falha interna. Note que
**404 e 403 são distinguíveis**, o que é um vazamento de enumeração aceito conscientemente: sem
ele, o cliente bloqueado não saberia que precisa procurar a oficina. Já em
`GET /api/service-orders/{id}`, o contrato manda devolver **404 e não 403** quando o cliente não é
o dono — ali a existência da OS é informação sensível.

---

## Caminho de evolução: RS256 com JWKS

O destino desejado, e o que ele destrava:

| Hoje (HS256) | Amanhã (RS256 + JWKS) |
|---|---|
| Segredo simétrico distribuído a 3 consumidores | Chave **privada** só no emissor; **pública** em todo lugar |
| Quem valida também consegue emitir | Validar não confere poder de emitir |
| Lambda Authorizer REQUEST obrigatório | **JWT Authorizer nativo** — sem Lambda no caminho, sem cold start, sem custo |
| Rotação de chave quebra tudo ao mesmo tempo | Rotação suave por `kid`: publica-se a chave nova no JWKS antes de usá-la |
| `iss` = string opaca | `iss` = URL real com descoberta OIDC |

Passos, em ordem, cada um reversível:

1. **Gerar par de chaves RSA 2048** e guardar a privada no Secrets Manager, sob a propriedade do
   `oficina-infra-fundacao` (mesmo racional da ADR-009: rotação acidental quebraria a emissão).
2. **Publicar um endpoint JWKS** em `https://<dominio>/.well-known/jwks.json` com a chave pública
   e um `kid`. Pode ser um S3 estático com CloudFront, ou uma rota da própria API — S3 é
   preferível por não depender da disponibilidade do cluster.
3. **Publicar o documento de descoberta** em `/.well-known/openid-configuration` apontando o
   `jwks_uri`. Isso exige um **domínio real** (Route 53 + ACM), que hoje o projeto não tem — é o
   maior custo do plano.
4. **Emitir com `alg: RS256` e header `kid`**, mantendo o restante do contrato de claims idêntico.
5. **Período de dupla aceitação:** o `jwt-authorizer` e o `AuthMiddleware` aceitam HS256 **e**
   RS256 durante a janela de transição (uma hora basta — é o `exp` do token), rejeitando qualquer
   outro `alg`. Atenção ao clássico ataque de confusão de algoritmo: a decisão de qual chave usar
   vem do **`kid` esperado**, nunca do `alg` que o token declara.
6. **Trocar o authorizer** para `authorizer_type = "JWT"` com `issuer` e `audience`, e remover a
   Lambda `jwt-authorizer` — o ganho concreto: uma Lambda a menos, latência menor e o fim do
   problema de cache de 300 s.
7. **Remover a aceitação de HS256** dos dois lados e apagar o `JWT_SECRET` do segredo.

Passo 3 é o bloqueador: sem domínio próprio, o JWT Authorizer nativo não tem como funcionar, e o
plano para no passo 5 (que já entrega o ganho de segurança da chave assimétrica, mesmo mantendo o
Lambda Authorizer). **Fora do escopo desta fase**, registrado como dívida consciente.

## Riscos

| Risco | Probabilidade | Impacto | Mitigação |
|---|---|---|---|
| Divergência de implementação do JWT entre Lambda e aplicação | **Alta** (código duplicado por decisão) | **Crítico** — autenticação para de funcionar | Teste de contrato com segredo e `iat` fixos nos dois repositórios (seção 4); quebra no CI, não em produção |
| `JWT_SECRET` vazar por log, variável ou dump | Baixa | Crítico — permite forjar token de admin | Segredo só no Secrets Manager; nunca em manifesto; `APP_DEBUG=false` em produção; revisão do logger para não serializar `Authorization` |
| Cold start da Lambda authorizer degradar a latência | Média | Baixo | Cache de 300 s tira a Lambda do caminho quente; alerta de duração no dashboard de plataforma |
| Enumeração de CPF por `POST /auth/cpf` (404 vs 403) | Média | Médio | Throttling por rota no gateway; a resposta não devolve dado além do nome do próprio titular; aceito conscientemente |
| CPF como fator único de autenticação | **Alta** | Médio | `role=customer` só tem leitura do que é dele; nenhum endpoint de escrita. Ver questão em aberto 1 |
| Token de `customer` aceito por até 5 min após bloqueio do cliente | Média | Baixo | Revalidação no Pod aplica a autorização fina; `exp` de 1 hora já limita a janela |
| Erro na matriz de autorização expor OS alheia | Média | **Alto** | Testes por rota e por papel (WS-D); `GET /{id}` devolve 404 e não 403 para não vazar existência |

## Plano de migração / saída

**Da rota pública para a autenticada:**

1. `GET /api/service-orders/me` entra em produção junto com `POST /auth/cpf`.
2. `GET /api/service-orders/status` é **removida no mesmo deploy** — não há período de convivência,
   porque manter a rota vulnerável ativa por conveniência anularia o motivo da mudança.
3. O `swagger.yaml` e a coleção Postman são atualizados na mesma entrega (WS-D21, WS-D22).
4. Comunicação ao usuário final não se aplica: o sistema não tem base instalada.

**Saída, se a estratégia se mostrar errada:**

| Cenário | Caminho |
|---|---|
| Precisar de gestão de identidade completa | Migrar para Cognito; o `AuthMiddleware` passa a validar RS256 do user pool. O passo 5 do plano de RS256 já prepara o terreno |
| Precisar de MFA | Acrescentar OTP por email/SMS como segunda etapa do `auth-cpf`, sem trocar o formato do token |
| Lambda Authorizer virar gargalo | Concluir o plano de RS256 e usar o authorizer nativo |

## Questões em aberto

1. **Segundo fator para o cliente.** CPF sozinho é identificação, não autenticação. A proposta
   natural é um OTP de 6 dígitos por email ou SMS (os campos `customers.email` e `customers.phone`
   foram criados no `002_fase3_ajustes.sql` justamente para isso). **Fora do escopo da fase**, mas
   é o primeiro item a implementar antes de qualquer uso com dado real.
2. **Domínio próprio + ACM.** Bloqueia o passo 3 do plano de RS256 e também impediria mTLS. Custo
   de ~US$ 12/ano de domínio. Decisão de orçamento, não técnica.
3. **`JWT_EXPIRATION` de 1 hora vale para os dois papéis?** Hoje sim. Um token de `admin` com
   validade menor (15 min) e um de `customer` com validade maior seria mais defensável, mas exige
   dois valores no segredo e complica o contrato. Adiado.
4. **Refresh token.** Não existe: expirou, autentica de novo. Aceitável para o fluxo de consulta
   do cliente; incômodo para o admin em sessão longa. Reavaliar se houver front-end.
5. **Throttling por CPF, e não só por IP.** O gateway limita por rota; um atacante distribuído
   ainda poderia enumerar. Exigiria estado (DynamoDB ou ElastiCache) — desproporcional agora.
6. **Onde revogar.** Não há blocklist de token. Bloquear um cliente (`status = BLOCKED`) só surte
   efeito na emissão e na revalidação do Pod, não na borda. Uma blocklist em DynamoDB consultada
   pelo authorizer resolveria, ao custo de uma leitura por invocação.

# ADR-002 · JWT HS256 implementado manualmente

## Status

Aceita (Fase 1) · Mantida e ampliada na Fase 3 (ver ADR-006 e RFC-003).

## Contexto

A API precisa de autenticação stateless. JWT é o formato natural: o token carrega a identidade e
o papel, e cada réplica do Pod valida sem consultar estado compartilhado — requisito direto de um
Deployment que escala de 2 a 10 réplicas.

A implementação de HS256 é pequena e totalmente especificada pela RFC 7519: três segmentos
`base64url`, assinatura `HMAC-SHA256` sobre `header.payload`. O PHP traz `hash_hmac()` e
`hash_equals()` na biblioteca padrão. Puxar `firebase/php-jwt` traria um pacote inteiro para
executar duas chamadas nativas — e, num projeto que já decidiu não usar framework (ADR-001),
seria incoerente.

Na Fase 3 surgiu um requisito novo e decisivo: a Lambda `auth-cpf`, escrita em outro repositório,
precisa **produzir tokens que a aplicação aceite** e o `jwt-authorizer` precisa **validar tokens
que a aplicação produz**. Ou seja, o algoritmo deixou de ser um detalhe interno e virou um
contrato entre dois sistemas. Isso reforça a decisão, mas muda o que precisa ser garantido: não
basta o token ser válido — ele precisa ser **byte a byte idêntico** dos dois lados, porque a
ordem das claims no JSON altera a assinatura.

## Decisão

Manter o `JwtProvider` em PHP puro, com HS256, e elevá-lo à condição de **artefato de contrato**:

- Header fixo `{"alg":"HS256","typ":"JWT"}`, nessa ordem.
- Montagem do payload por `array_merge($payload, ['iss','iat','exp'])` — claims específicas
  primeiro, depois `iss`, `iat`, `exp`. A ordem é normativa (seção 4 dos Contratos).
- `base64url` = `rtrim(strtr(base64_encode($x), '+/', '-_'), '=')`.
- Comparação de assinatura sempre por `hash_equals()` (resistente a timing attack).
- Validação de `exp` e de `iss` (`oficina-mecanica-api`) em toda verificação.
- O segredo é **injetado por construtor** (mudança da Fase 3): antes era lido direto de `$_ENV`,
  o que impedia o teste de contrato com segredo fixo.
- O arquivo é **copiado byte a byte** para `oficina-gateway-auth`, e ambos os repositórios têm um
  **teste de contrato** que gera o token com segredo `"contract-test-secret-do-not-use-in-prod"`
  e `iat = 1767225600` e compara com o mesmo literal hardcoded. Se um lado divergir, o outro
  quebra no CI.

## Consequências

**Positivas**

- Zero dependência externa no caminho de autenticação — nenhuma CVE de terceiros nessa
  superfície, que é a mais sensível da aplicação.
- O código é curto e auditável; o `SECURITY_REPORT.md` consegue cobri-lo linha a linha.
- O teste de contrato transforma a duplicação entre repositórios num invariante verificável pelo
  CI, e não num acordo informal.
- Validação local no Pod continua barata (uma chamada de `hash_hmac`), permitindo defesa em
  profundidade mesmo com o gateway já autorizando.

**Negativas**

- **Código duplicado** entre a aplicação e a Lambda, deliberadamente. O custo é real: qualquer
  mudança exige alterar dois repositórios na mesma janela. Mitigado pelo teste de contrato — a
  duplicação quebra ruidosamente, não silenciosamente.
- HS256 é **segredo simétrico compartilhado**: quem valida também consegue emitir. Isso obriga a
  distribuir o `JWT_SECRET` para o Pod, para o `auth-cpf` e para o `jwt-authorizer`. Ver RFC-003
  para o caminho de evolução para RS256 com JWKS.
- Não há suporte a rotação de chave com `kid`, nem a revogação de token antes do `exp`
  (`JWT_EXPIRATION` de 1 hora limita a janela de exposição).
- Recursos do padrão que não implementamos (`nbf`, `aud`, JWE) precisariam ser escritos à mão se
  virarem requisito.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| **`firebase/php-jwt`** | Dependência inteira para duas chamadas nativas. Pior: a biblioteca normaliza a ordem das claims por conta própria, o que atrapalha o teste de contrato byte a byte. |
| **`lcobucci/jwt`** | Excelente e tipada, mas exige `ext-sodium`/OpenSSL e traz um modelo de builder que dificultaria replicar a montagem exata na Lambda. |
| **Sessão em servidor (Redis/ElastiCache)** | Adiciona um componente com estado e um ponto de falha ao caminho de toda requisição, contrariando a escalabilidade horizontal. Também traria custo fixo de infraestrutura. |
| **Tokens opacos + introspecção** | Toda requisição vira uma chamada de rede a um serviço de auth. Latência e acoplamento maiores, sem ganho para o escopo do trabalho. |
| **RS256 desde já** | É o destino desejado (RFC-003), mas exige gestão de par de chaves e endpoint JWKS público. Fora do escopo desta fase; a decisão está documentada como dívida consciente. |

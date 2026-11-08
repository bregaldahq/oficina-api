#!/usr/bin/env bash
#
# Cria os 3 repositórios novos da Fase 3 no GitHub, publica o conteúdo que está
# em repos/ e aplica as regras de proteção exigidas pelo enunciado.
#
# NÃO é executado automaticamente: cria recursos públicos na conta GitHub do
# usuário. Rode manualmente quando estiver pronto.
#
# Pré-requisitos:
#   - gh CLI instalado e autenticado  ->  gh auth login
#   - git configurado com nome e email
#
# Uso:
#   scripts/bootstrap-repos.sh                 # cria e publica
#   scripts/bootstrap-repos.sh --dry-run       # só mostra o que faria
#
set -euo pipefail

OWNER="${GITHUB_OWNER:-bregaldahq}"
COLLABORATOR="soat-architecture"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DRY_RUN=false
[[ "${1:-}" == "--dry-run" ]] && DRY_RUN=true

REPOS=(
  "oficina-infra-fundacao:Infraestrutura de banco de dados e rede (Terraform) - Tech Challenge Fase 3"
  "oficina-infra-cluster:Infraestrutura Kubernetes EKS e add-ons (Terraform) - Tech Challenge Fase 3"
  "oficina-gateway-auth:Function serverless de autenticacao por CPF e API Gateway - Tech Challenge Fase 3"
)

run() {
  if $DRY_RUN; then
    echo "  [dry-run] $*"
  else
    "$@"
  fi
}

require() {
  command -v "$1" >/dev/null 2>&1 || { echo "ERRO: '$1' não encontrado no PATH."; exit 1; }
}

require gh
require git

if ! gh auth status >/dev/null 2>&1; then
  echo "ERRO: gh não autenticado. Rode 'gh auth login' primeiro."
  exit 1
fi

echo "==> Owner: $OWNER"
$DRY_RUN && echo "==> MODO DRY-RUN: nada será criado."
echo

for entry in "${REPOS[@]}"; do
  name="${entry%%:*}"
  desc="${entry#*:}"
  src="$ROOT/repos/$name"

  echo "==> $name"

  if [[ ! -d "$src" ]]; then
    echo "  ERRO: $src não existe. Pulando."
    continue
  fi

  # 1. Cria o repositório (público: branch protection em repo privado exige plano pago)
  if gh repo view "$OWNER/$name" >/dev/null 2>&1; then
    echo "  repositório já existe, pulando criação"
  else
    run gh repo create "$OWNER/$name" --public --description "$desc"
  fi

  # 2. Inicializa o git local e publica main + develop
  if [[ ! -d "$src/.git" ]]; then
    run git -C "$src" init -b main
    run git -C "$src" remote add origin "https://github.com/$OWNER/$name.git"
  fi
  run git -C "$src" add -A
  run git -C "$src" commit -m "feat: estrutura inicial do repositório (Tech Challenge Fase 3)" || true
  run git -C "$src" push -u origin main
  run git -C "$src" branch -f develop main
  run git -C "$src" push -u origin develop

  # 3. Convida o avaliador
  run gh api -X PUT "repos/$OWNER/$name/collaborators/$COLLABORATOR" -f permission=push

  # 4. Regras de proteção em main e develop
  for branch in main develop; do
    run gh api -X PUT "repos/$OWNER/$name/branches/$branch/protection" \
      -H "Accept: application/vnd.github+json" \
      --input - <<'JSON'
{
  "required_status_checks": null,
  "enforce_admins": true,
  "required_pull_request_reviews": {
    "required_approving_review_count": 1,
    "dismiss_stale_reviews": true
  },
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false
}
JSON
  done

  echo "  OK -> https://github.com/$OWNER/$name"
  echo
done

# O repositório da aplicação já existe; só aplica proteção e convida o avaliador.
APP_REPO="oficina-api"
echo "==> $APP_REPO (repositório existente)"
run gh api -X PUT "repos/$OWNER/$APP_REPO/collaborators/$COLLABORATOR" -f permission=push
for branch in main develop; do
  run gh api -X PUT "repos/$OWNER/$APP_REPO/branches/$branch/protection" \
    -H "Accept: application/vnd.github+json" \
    --input - <<'JSON'
{
  "required_status_checks": null,
  "enforce_admins": true,
  "required_pull_request_reviews": {
    "required_approving_review_count": 1,
    "dismiss_stale_reviews": true
  },
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false
}
JSON
done

echo
echo "==> Concluído."
echo
echo "Próximos passos manuais:"
echo "  1. Em cada repositório, configure os secrets:"
echo "       gh secret set AWS_ROLE_ARN --repo $OWNER/<repo>"
echo "       gh secret set NEW_RELIC_LICENSE_KEY --repo $OWNER/<repo>"
echo "       gh secret set TF_STATE_BUCKET --repo $OWNER/<repo>"
echo "  2. Crie os GitHub Environments 'homologacao' e 'producao' (producao com required reviewer)."
echo "  3. Depois do primeiro pipeline verde, volte e marque os status checks como obrigatórios"
echo "     na proteção de branch (precisam ter rodado ao menos uma vez para aparecerem)."
echo "  4. Confirme o aceite do convite do usuário $COLLABORATOR nos 4 repositórios."

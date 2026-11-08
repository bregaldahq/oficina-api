#!/usr/bin/env bash
# Roda PHP 8.2 em container (a máquina de desenvolvimento não tem PHP instalado).
# Uso: scripts/php.sh vendor/bin/phpunit
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# A imagem sai de scripts/Dockerfile.tooling; constrói na primeira vez.
if ! docker image inspect oficina-php:8.2 >/dev/null 2>&1; then
  echo "construindo oficina-php:8.2 (só na primeira vez)..." >&2
  docker build -q -t oficina-php:8.2 -f "$ROOT/scripts/Dockerfile.tooling" "$ROOT/scripts" >/dev/null
fi
exec docker run --rm -v "$ROOT":/app -w /app oficina-php:8.2 "$@"

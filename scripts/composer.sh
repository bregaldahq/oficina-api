#!/usr/bin/env bash
# Roda Composer em container. Uso: scripts/composer.sh install
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec docker run --rm -v "$ROOT":/app -w /app composer:2.7 "$@"

#!/bin/bash
# Static analysis gate for Convomail: doli-check (Dolibarr develop rules: php -l, phpcs, phpstan, phan)
# with Document Browser read for its classes, since compose.php calls it when that module is enabled.
#
#   dev/check.sh [--refresh] [file.php ...]
#
# Document Browser is looked for next to this repository (../doli-docbrowser, as in docker-compose.yml);
# DOLI_DOCBROWSER_DIR overrides that. Exit code 0 = every check clean.

set -u

ROOT=$(cd -- "$(dirname -- "$0")/.." && pwd)
DOLI_CHECK="${DOLI_CHECK:-$HOME/.dolibarr-dev/bin/doli-check}"
DOCBROWSER="${DOLI_DOCBROWSER_DIR:-$ROOT/../doli-docbrowser}/module"

[ -x "$DOLI_CHECK" ] || { echo "Missing $DOLI_CHECK (toolchain: see ~/.claude/CLAUDE.md)" >&2; exit 2; }

scan=()
if [ -d "$DOCBROWSER" ]; then
	scan=(--scan "$DOCBROWSER")
else
	echo "WARNING: Document Browser not found at $DOCBROWSER: its calls in compose.php will show as unknown classes" >&2
fi

refresh=()
if [ "${1:-}" = "--refresh" ]; then
	refresh=(--refresh)
	shift
fi

exec "$DOLI_CHECK" ${refresh[@]+"${refresh[@]}"} ${scan[@]+"${scan[@]}"} "$ROOT/module" "$@"

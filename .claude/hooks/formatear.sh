#!/usr/bin/env bash
# PostToolUse (Edit/Write): formatea con Pint el archivo PHP que Claude acaba de editar.
# Corre Pint dentro del contenedor "api"; si Docker no está levantado, no hace nada.
set -uo pipefail

entrada=$(cat)
archivo=$(printf '%s' "$entrada" | grep -o '"file_path"[[:space:]]*:[[:space:]]*"[^"]*"' | head -n1 | sed 's/.*"\([^"]*\)"$/\1/')

[[ "$archivo" == *.php ]] || exit 0

proyecto="${CLAUDE_PROJECT_DIR:-$(pwd)}"
relativo="${archivo#"$proyecto"/}"
[[ "$relativo" != /* && -f "$proyecto/$relativo" ]] || exit 0

cd "$proyecto" || exit 0
docker compose exec -T -u www-data api php vendor/bin/pint --quiet "$relativo" >/dev/null 2>&1 || true
exit 0

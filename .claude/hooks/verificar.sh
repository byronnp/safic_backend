#!/usr/bin/env bash
# Stop: antes de dar la tarea por terminada, si hay cambios en PHP corre Pint --test,
# Larastan y Pest dentro de Docker. Si algo falla, devuelve el error a Claude (exit 2)
# para que lo corrija. Solo actúa una vez por turno (stop_hook_active).
set -uo pipefail

entrada=$(cat)
if printf '%s' "$entrada" | grep -q '"stop_hook_active"[[:space:]]*:[[:space:]]*true'; then
  exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(pwd)}" || exit 0

cambios=$(git status --porcelain --untracked-files=all -- app database routes tests config bootstrap 2>/dev/null | grep -E '\.php$' || true)
[[ -n "$cambios" ]] || exit 0

if ! docker compose ps --status running --services 2>/dev/null | grep -qx api; then
  echo "Hay cambios en PHP pero el contenedor api no está levantado: corre 'make up' y luego 'make lint' y 'make test'." >&2
  exit 0
fi

ejecutar() {
  local salida
  if ! salida=$(docker compose exec -T -u www-data api "$@" 2>&1); then
    printf 'Falló: %s\n%s\n' "$*" "$(printf '%s' "$salida" | tail -n 40)" >&2
    exit 2
  fi
}

ejecutar php vendor/bin/pint --test
ejecutar php vendor/bin/phpstan analyse --memory-limit=1G --no-progress
ejecutar php vendor/bin/pest --compact
exit 0

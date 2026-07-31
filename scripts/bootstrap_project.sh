#!/usr/bin/env bash
# =============================================================================
# scripts/bootstrap_project.sh — Clona la Bóveda Madre hacia un proyecto nuevo
# Uso: ./scripts/bootstrap_project.sh nombre_del_proyecto
# =============================================================================

set -euo pipefail

PROJECT_NAME="${1:-}"
HTDOCS_ROOT="/c/xampp/htdocs"
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ -z "$PROJECT_NAME" ]; then
    echo "Uso: $0 <nombre_del_proyecto>" >&2
    exit 1
fi

DEST_DIR="${HTDOCS_ROOT}/${PROJECT_NAME}"

if [ -d "$DEST_DIR" ]; then
    echo "Error: ${DEST_DIR} ya existe. Aborta para no sobrescribir trabajo existente." >&2
    exit 1
fi

echo "→ Clonando Bóveda Madre desde ${SOURCE_DIR} hacia ${DEST_DIR}"
mkdir -p "$DEST_DIR"
rsync -a \
    --exclude '.git' \
    --exclude '.env' \
    --exclude 'logs/*' \
    --exclude 'node_modules' \
    --exclude 'vendor' \
    "${SOURCE_DIR}/" "${DEST_DIR}/"

touch "${DEST_DIR}/logs/.gitkeep"

echo "→ Estructura clonada. Próximos pasos:"
echo "  1. cd ${DEST_DIR}"
echo "  2. php scripts/generate_env.php ${PROJECT_NAME} <host_bd_remoto_real>"
echo "  3. php scripts/generate_jwt_keys.php"
echo "  4. php scripts/install_permissions.php"
echo "  5. Completar CLAUDE.md §1 (Identidad del Proyecto)"
echo "  6. Levantar el proyecto y llamar a /api/status_check.php (Triple Handshake:"
echo "     filesystem / BD remota / SMTP) para validar las 4 capas inmutables"
echo "     (LAYER_0 Security, LAYER_1 Data, LAYER_2 Observability, LAYER_3 UX) antes"
echo "     de escribir el primer endpoint de negocio (ver FUENTEDEVERDAD_CONSOLIDADA.md §1)."
echo "✓ Bootstrap completo."

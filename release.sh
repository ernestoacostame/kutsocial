#!/usr/bin/env bash
# ============================================================================
# KutSocial · Release Script
# ============================================================================
# Uso:
#   ./release.sh 0.5.0              → release con notas vacías
#   ./release.sh 0.5.0 "Changelog"  → release con notas
#   ./release.sh patch               → auto-bump patch (0.4.2 → 0.4.3)
#   ./release.sh minor               → auto-bump minor (0.4.2 → 0.5.0)
#   ./release.sh major               → auto-bump major (0.4.2 → 1.0.0)
#
# Requisitos: git, gh (GitHub CLI autenticado)
# ============================================================================

set -euo pipefail

KUTSOCIAL_DIR="$(cd "$(dirname "$0")" && pwd)"
VERSION_FILE="$KUTSOCIAL_DIR/version.php"

# --- Leer versión actual ---
CURRENT=$(grep -oP "KUTSOCIAL_VERSION', '\K[0-9.]+" "$VERSION_FILE")
echo "📦 Versión actual: v$CURRENT"

# --- Calcular nueva versión ---
if [[ -z "${1:-}" ]]; then
  echo "Uso: $0 <version|patch|minor|major> [notas]"
  echo "  Ejemplos:"
  echo "    $0 patch              → $(echo "$CURRENT" | awk -F. '{print $1"."$2"."$3+1}')"
  echo "    $0 minor              → $(echo "$CURRENT" | awk -F. '{print $1"."$2+1".0"}')"
  echo "    $0 major              → $(echo "$CURRENT" | awk -F. '{print $1+1".0.0"}')"
  echo "    $0 1.0.1 \"Mi changelog\""
  exit 1
fi

INPUT="$1"
NOTES="${2:-}"

case "$INPUT" in
  patch) NEW=$(echo "$CURRENT" | awk -F. '{print $1"."$2"."$3+1}') ;;
  minor) NEW=$(echo "$CURRENT" | awk -F. '{print $1"."$2+1".0"}') ;;
  major) NEW=$(echo "$CURRENT" | awk -F. '{print $1+1".0.0"}') ;;
  *)     NEW="$INPUT" ;;
esac

# --- Validar formato de versión ---
if [[ ! "$NEW" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "❌ Error: La versión '$NEW' no es válida. Debe tener el formato X.Y.Z o usar: patch, minor, major."
  exit 1
fi

echo "🚀 Nueva versión: v$NEW"
echo ""

# --- Confirmar ---
read -rp "¿Continuar? (s/N) " confirm
if [[ "$confirm" != "s" && "$confirm" != "S" ]]; then
  echo "Cancelado."
  exit 0
fi

# --- 1. Actualizar version.php y version.json ---
sed -i "s/KUTSOCIAL_VERSION', '$CURRENT'/KUTSOCIAL_VERSION', '$NEW'/" "$VERSION_FILE"
sed -i "s/\"version\": \"[^\"]*\"/\"version\": \"$NEW\"/" "$KUTSOCIAL_DIR/version.json"
sed -i "s|\"zip_url\": \"[^\"]*\"|\"zip_url\": \"https://github.com/ernestoacostame/kutsocial/releases/download/v$NEW/kutsocial-v$NEW.zip\"|" "$KUTSOCIAL_DIR/version.json"
if [[ -n "$NOTES" ]]; then
  ESCAPED_NOTES=$(echo "$NOTES" | sed 's/"/\\"/g' | awk '{printf "%s\\n", $0}')
  sed -i "s/\"changelog\": \"[^\"]*\"/\"changelog\": \"$ESCAPED_NOTES\"/" "$KUTSOCIAL_DIR/version.json"
fi
echo "✅ Archivos de versión actualizados a $NEW"

# --- 2. Crear el ZIP ---
ZIP_NAME="kutsocial-v${NEW}.zip"
ZIP_PATH="/tmp/$ZIP_NAME"

STAGE_DIR=$(mktemp -d)
cd "$KUTSOCIAL_DIR"

# Copiar solo ficheros tracked por git (excluye data/, etc.)
git ls-files -z \
  | grep -zZv -e '^data/' -e '^\.htaccess$' -e '^scratch/' \
    -e '^test/' -e '^release\.sh$' \
  | xargs -0 -I{} install -D "{}" "${STAGE_DIR}/{}"

# Crear ZIP con bsdtar
cd "$STAGE_DIR"
bsdtar -cf "$ZIP_PATH" --format=zip .
rm -rf "$STAGE_DIR"

ZIP_SIZE=$(du -h "$ZIP_PATH" | cut -f1)
echo "✅ ZIP creado: $ZIP_NAME ($ZIP_SIZE)"

# --- 3. Git: commit + tag ---
if ! git -C "$KUTSOCIAL_DIR" rev-parse --is-inside-work-tree &>/dev/null; then
  echo "❌ No se detectó un repositorio git en $KUTSOCIAL_DIR o sus padres."
  exit 1
fi

GIT_ROOT=$(git -C "$KUTSOCIAL_DIR" rev-parse --show-toplevel)
REL_DIR=$(git -C "$KUTSOCIAL_DIR" rev-parse --show-prefix)

cd "$GIT_ROOT"
TARGET_DIR="${REL_DIR:-.}"

KUTSOCIAL_UNSTAGED=$(git diff --name-only -- "$TARGET_DIR" 2>/dev/null | grep -vE "(${REL_DIR}version.php|${REL_DIR}version.json)" | wc -l || true)

if [[ "$KUTSOCIAL_UNSTAGED" -gt 0 ]]; then
  echo "📋 Hay $KUTSOCIAL_UNSTAGED archivo(s) modificados en $TARGET_DIR sin stage."
  read -rp "¿Incluirlos en el release? (s/N) " inc
  if [[ "$inc" == "s" || "$inc" == "S" ]]; then
    git add "$TARGET_DIR"
  fi
fi

# Siempre incluir version.php y version.json
git add "${REL_DIR}version.php"
git add "${REL_DIR}version.json"

# Verificar que hay algo que commitear en el directorio destino
if git diff --cached --quiet -- "$TARGET_DIR"; then
  echo "⚠️  No hay cambios staged en $TARGET_DIR. Nada que publicar."
  git checkout "${REL_DIR}version.php"
  git checkout "${REL_DIR}version.json"
  exit 1
fi

git commit -m "Release KutSocial v$NEW" -- "$TARGET_DIR"
git tag -a "v$NEW" -m "KutSocial v$NEW"
echo "✅ Commit y tag v$NEW creados (solo cambios de $TARGET_DIR)"

# --- 4. Push ---
git push origin main
git push origin "v$NEW"
echo "✅ Pushed to GitHub"

# --- 5. Crear GitHub Release con el ZIP adjunto ---
if [[ -z "$NOTES" ]]; then
  PREV_TAG=$(git tag -l 'v*' --sort=-v:refname | grep -v "v$NEW" | head -1 || true)
  if [[ -n "$PREV_TAG" ]]; then
    NOTES=$(git log "${PREV_TAG}..v${NEW}" --oneline --no-merges -- "$TARGET_DIR" 2>/dev/null | head -20 || true)
    if [[ -z "$NOTES" ]]; then
      NOTES="Release v$NEW"
    fi
  else
    NOTES="Release v$NEW"
  fi
fi

gh release create "v$NEW" "$ZIP_PATH" \
  --repo "ernestoacostame/kutsocial" \
  --title "KutSocial v$NEW" \
  --notes "$NOTES"

echo ""
echo "✅ Release v$NEW publicada con $ZIP_NAME adjunto"
echo "   https://github.com/ernestoacostame/kutsocial/releases/tag/v$NEW"

# --- Limpiar ---
rm -f "$ZIP_PATH"


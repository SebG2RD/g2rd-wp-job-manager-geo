#!/usr/bin/env bash
#
# Vérifie que la version passée en argument est alignée dans TOUTES les sources
# de vérité du plugin (en-tête, constante, readme.txt). Adapté du script
# homonyme de g2rd-facturation / g2rd-connector ; le plugin vit dans
# g2rd-wpjm-geo/.
#
# Usage : bash tools/verify-release-version.sh 1.1.0
set -euo pipefail

VERSION="${1:-}"
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Usage: $0 <version>  (ex. 1.1.0)" >&2
  exit 2
fi

cd "$(dirname "$0")/.."
MAIN="g2rd-wpjm-geo/g2rd-wpjm-geo.php"
README="g2rd-wpjm-geo/readme.txt"

fail=0
check() {
  local label="$1" found="$2"
  if [ "$found" != "$VERSION" ]; then
    echo "ERREUR: $label = '${found:-<introuvable>}' (attendu '$VERSION')"
    fail=1
  else
    echo "OK   : $label = $found"
  fi
}

HEADER=$(grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9][0-9.]*' "$MAIN" || true)
CONST=$(grep -m1 -oP "G2RD_WPJM_GEO_VERSION'\s*,\s*'\K[0-9][0-9.]*" "$MAIN" || true)
STABLE=$(grep -m1 -oP '^Stable tag:\s*\K[0-9][0-9.]*' "$README" || true)
CHANGELOG=$(grep -m1 -oP '^=\s*\K[0-9][0-9.]*(?=\s*=)' <(sed -n '/^== Changelog ==/,$p' "$README") || true)

echo "Vérification de l'alignement des versions sur $VERSION"
check "g2rd-wpjm-geo.php (en-tête Version)"       "$HEADER"
check "g2rd-wpjm-geo.php (G2RD_WPJM_GEO_VERSION)" "$CONST"
check "readme.txt        (Stable tag)"            "$STABLE"
check "readme.txt        (dernière entrée du Changelog)" "$CHANGELOG"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "Au moins une source n'est pas alignée. Mets à jour les fichiers ci-dessus avant de taguer."
  exit 1
fi
echo ""
echo "Toutes les sources sont alignées sur $VERSION."

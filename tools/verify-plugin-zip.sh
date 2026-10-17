#!/usr/bin/env bash
#
# Valide le ZIP de release : intégrité, dossier racine unique, fichiers requis
# présents, fichiers de dev absents, et contenu identique aux fichiers suivis
# par git (hors tests/) — aucun fichier local non versionné ne peut s'y glisser.
# Adapté du script homonyme de g2rd-facturation / g2rd-connector.
#
# Usage : bash tools/verify-plugin-zip.sh dist/g2rd-wpjm-geo-1.1.0.zip
set -euo pipefail

ZIP="${1:-}"
if [ -z "$ZIP" ] || [ ! -f "$ZIP" ]; then
  echo "Usage: $0 <chemin/vers/plugin.zip>" >&2
  exit 2
fi
ZIP="$(cd "$(dirname "$ZIP")" && pwd)/$(basename "$ZIP")"
cd "$(dirname "$0")/.."

SLUG="g2rd-wpjm-geo"
fail=0

echo "Intégrité de l'archive"
unzip -tq "$ZIP" >/dev/null && echo "OK   : archive valide" || { echo "ERREUR: archive corrompue"; exit 1; }

LIST="$(unzip -Z1 "$ZIP" | grep -v '/$')"

echo "Dossier racine unique"
ROOTS="$(echo "$LIST" | awk -F/ '{print $1}' | sort -u)"
if [ "$ROOTS" != "$SLUG" ]; then
  echo "ERREUR: dossiers racine trouvés : $(echo "$ROOTS" | tr '\n' ' ') (attendu : $SLUG)"
  fail=1
else
  echo "OK   : racine $SLUG/"
fi

require() {
  if echo "$LIST" | grep -qxF "$SLUG/$1"; then
    echo "OK   : $1"
  else
    echo "ERREUR: fichier requis absent : $1"
    fail=1
  fi
}
forbid() {
  if echo "$LIST" | grep -qE "^$SLUG/$1"; then
    echo "ERREUR: fichier de dev présent : $1"
    fail=1
  else
    echo "OK   : absent $1"
  fi
}

echo "Fichiers requis"
require "g2rd-wpjm-geo.php"
require "uninstall.php"
require "readme.txt"
require "index.php"
require "includes/Plugin.php"
require "includes/RestController.php"
require "includes/PhotonGeocoder.php"
require "includes/ApplicationNormalizer.php"
require "assets/js/map-common.js"
require "assets/js/jobs-map.js"
require "assets/js/jobs-map-osm.js"
require "assets/js/location-autocomplete.js"
require "assets/vendor/maplibre-gl/maplibre-gl.js"
require "assets/vendor/maplibre-gl/LICENSE.txt"
require "assets/vendor/markerclusterer.min.js"
require "assets/vendor/markerclusterer.LICENSE.txt"
require "blocks/jobs-map/block.json"
require "languages/index.php"

echo "Fichiers de dev interdits"
forbid "tests/"
forbid "tools/"
forbid "docs/"
forbid "vendor/"
forbid "node_modules/"
forbid "(.*/)?\.git"
forbid "(.*/)?\.github/"
forbid "(.*/)?\.env"
forbid "(.*/)?\.DS_Store"
forbid "composer\.(json|lock)"
forbid "phpcs"
forbid "phpstan"
forbid ".*\.(zip|log|sh|ps1|pem|key|bak|orig|swp)$"

echo "Contenu identique aux fichiers suivis par git (hors tests/)"
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  EXPECTED="$(git ls-files -- "$SLUG" | grep -v "^$SLUG/tests/" | LC_ALL=C sort)"
  ACTUAL="$(echo "$LIST" | LC_ALL=C sort)"
  if [ "$EXPECTED" = "$ACTUAL" ]; then
    echo "OK   : $(echo "$ACTUAL" | wc -l | tr -d ' ') fichiers, identiques à git ls-files"
  else
    echo "ERREUR: écart entre l'archive et git ls-files :"
    diff <(echo "$EXPECTED") <(echo "$ACTUAL") | sed 's/^/       /' || true
    fail=1
  fi
else
  echo "ERREUR: hors dépôt git, comparaison impossible"
  fail=1
fi

echo "Taille"
SIZE=$(stat -c%s "$ZIP" 2>/dev/null || stat -f%z "$ZIP")
echo "     : $((SIZE / 1024)) Ko"
if [ "$SIZE" -gt $((5 * 1024 * 1024)) ]; then
  echo "ERREUR: ZIP > 5 Mo, vérifier les exclusions"
  fail=1
fi

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "Le ZIP n'est pas conforme."
  exit 1
fi
echo ""
echo "ZIP conforme."

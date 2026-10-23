# Plusieurs lieux et régions par offre (1.2.0) — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** en mode OpenStreetMap, une offre peut porter jusqu'à 10 lieux (villes et régions françaises), saisis par étiquettes dans le champ « Lieu » natif, reconnus côté serveur, et affichés sur la carte : un point par ville, une zone colorée par région.

**Architecture:** côté serveur, `PlaceParser` découpe le texte sur les séparateurs forts, `Regions` reconnaît les 18 régions sans réseau, `PlaceLocator` applique la règle de la virgule et géocode les villes avec `PhotonGeocoder` (budget d'appels, file d'attente au-delà). `GeocodingIntegration` écrit le premier lieu dans les métas `geolocation_*` de WP Job Manager et la liste complète dans `_g2rd_wpjm_geo_places`, que l'API REST expose en `places`. Côté navigateur, `location-tags.js` transforme le champ natif en étiquettes ; `map-common.js` et `jobs-map-osm.js` dessinent les points, les zones (contours Etalab, ODbL, chargés à la demande) et les pastilles.

**Tech Stack:** PHP 7.4+ (WordPress 6.4+, WP Job Manager 2.4.7), JavaScript ES5 sans build, MapLibre GL JS 5.24.0, Photon. Tests : `g2rd-wpjm-geo/tests/run.php` (WordPress simulé), Node + jsdom (`g2rd-wpjm-geo/tests/js/run.js`), Docker (`tools/docker/verify.sh`).

**Spécification :** `docs/superpowers/specs/2026-10-23-lieux-multiples-regions-design.md`.

## Écarts assumés par rapport à la spécification

| Spécification | Plan | Raison |
|---|---|---|
| L'API REST garde `lat` et `lng` | L'API garde `latitude` et `longitude` (noms actuels) ; les éléments de `places` ont `lat` et `lng`, en nombres | ce sont les noms réels des champs de l'API ; les nombres suivent `latitude`/`longitude` |
| `SHA256SUMS` porte les empreintes du fichier source et du fichier embarqué | `SHA256SUMS` ne porte que le fichier embarqué ; l'empreinte du fichier source est dans `extract-regions.js` (vérifiée à l'exécution) et dans `LICENSE-regions.txt` | `sha256sum -c` échouerait sur un fichier source absent du dépôt |
| Mode étiquettes en mode OpenStreetMap | Le mode étiquettes s'active par le drapeau `tags: true` de la configuration, que le PHP envoie en mode OpenStreetMap | le combobox Photon simple reste testable et utilisable sans étiquettes (section J des tests JS) |
| Variantes de régions listées | « Région » en tête est aussi accepté (« Région Bretagne ») | forme courante dans les offres, sans risque d'homonymie |
| L'indice de département se lit sur le morceau | Il se lit sur le premier segment | « Normandie, Rouen (76) » garde la zone Normandie et place Rouen |
| Légende « quand au moins une zone est visible » | Légende dès qu'une région est affichée (zone ou repère de secours) | le repère de secours a lui aussi besoin d'être expliqué |
| Pastille de région | Pastille en `<button>` posé par `maplibregl.Marker` | elle prend le focus au clavier, contrairement aux points du canevas |
| Aide « Séparez plusieurs lieux par ; » sans JavaScript | Ajoutée à la description du champ par les filtres officiels `submit_job_form_fields` et `job_manager_job_listing_data_fields`, quand l'autocomplétion est active | aucun gabarit de WP Job Manager n'est modifié |
| Pause entre deux appels réels | La pause (1 s) est faite par `PlaceLocator` après chaque appel réel ; `GeocodeQueue` et `RegeocodeTool` lui passent leur fonction de pause | une offre peut maintenant demander plusieurs appels |
| Outil de re-géocodage : reprise des offres 1.1.x | Nouveau compteur `update` (« libres, à mettre à jour ») ; ces offres restent comptées comme affichées | elles sont déjà sur la carte avec un seul point |
| Saisie interactive : « au plus une ville absente du cache » | La première ville absente consomme le budget ; dès qu'une deuxième en aurait besoin, l'offre part en file | on ne sait qu'une ville manque qu'en arrivant à elle ; au plus un appel, comme aujourd'hui |
| Tests existants | Dans les sections 17 et 20, « IDF » devient « Atlantide » ; la ligne 301 de la section 20 reçoit `places` ; la section H vérifie la nouvelle liste de fonctions ; la section 27 attend la dépendance au script des étiquettes | « IDF » est désormais une variante d'Île-de-France ; ces tests vérifiaient l'ancien comportement |

## Conventions

- Racine du dépôt : `G2RD-WP-Job-Manager-Geo/`. Le plugin est dans `g2rd-wpjm-geo/`. Branche : `feat/lieux-multiples-regions` (déjà créée, spécification commitée).
- Commandes lancées depuis la racine du dépôt, dans Git Bash. PHP : `php` (sur le poste : `C:/PHP/php.exe`, 8.4).
- Style du code existant : namespace `G2RD\WPJMGeo`, `array()` long, pas de types de retour, docblocks en français, `if ( ! defined( 'ABSPATH' ) ) { exit; }` en tête de chaque fichier PHP, pas d'opérateur `?:` (PHPCS), conditions Yoda, commentaires terminés par un point. JavaScript : ES5, `'use strict'`, `textContent` uniquement.
- Après chaque tâche :
  - `php g2rd-wpjm-geo/tests/run.php | tail -1` doit afficher `0 échoués` ;
  - `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1` doit afficher `0 echoues`.
- Après chaque tâche PHP : `composer run lint` et `composer run analyse` sans erreur.
- Nouvelles sections PHP : **juste avant** la ligne `$results = $GLOBALS['g2rd_results'];` de `g2rd-wpjm-geo/tests/run.php`. Nouvelles sections JS : **juste avant** la ligne `console.log('\nResultat JS : ' + pass + ' reussis, ' + fail + ' echoues');` de `g2rd-wpjm-geo/tests/js/run.js`.
- Messages de commit en français, identité `G2RD <gerardsebastien@live.fr>` (déjà configurée).
- Ne jamais modifier WP Job Manager, Go Fetch Jobs, Astra, WordPress, ni `functions.php`. Ne jamais ouvrir `tools/docker/cookies.txt`.

## Carte des fichiers

| Fichier | Statut | Responsabilité |
|---|---|---|
| `tools/regions/extract-regions.js` | nouveau | extraction reproductible des 18 régions, centres |
| `tools/regions/SHA256SUMS` | nouveau | empreinte du fichier embarqué |
| `g2rd-wpjm-geo/assets/data/regions-fr.geojson` | nouveau (généré) | contours des 18 régions (ODbL) |
| `g2rd-wpjm-geo/assets/data/LICENSE-regions.txt`, `index.php` | nouveaux | licence et provenance ; répertoire non listable |
| `g2rd-wpjm-geo/includes/Regions.php` | nouveau | 18 régions, variantes, anciens noms, centres ; `find()`, `get()`, `codes()`, `all()` |
| `g2rd-wpjm-geo/includes/PlaceParser.php` | nouveau | découpage sur les séparateurs forts |
| `g2rd-wpjm-geo/includes/PlaceLocator.php` | nouveau | règle de la virgule, placement, doublons, plafond, budget, pauses |
| `g2rd-wpjm-geo/includes/PhotonGeocoder.php` | modifié | `geocode_detailed()`, `is_cached()`, cache version 4 |
| `g2rd-wpjm-geo/includes/GeocodingIntegration.php` | modifié | `META_PLACES`, `write_places()`, budget interactif, effacements, filtre `the_job_location` |
| `g2rd-wpjm-geo/includes/GeocodeQueue.php` | modifié | placement de tous les lieux |
| `g2rd-wpjm-geo/includes/RegeocodeTool.php` | modifié | colonne `places`, compteur `update`, simulation détaillée |
| `g2rd-wpjm-geo/includes/RestController.php` | modifié | champ `places` |
| `g2rd-wpjm-geo/includes/GoogleAssets.php` | modifié | script `location-tags.js`, dépendance de l'autocomplétion |
| `g2rd-wpjm-geo/includes/LocationAutocomplete.php`, `AdminLocation.php` | modifiés | configuration des étiquettes, aide « ; » |
| `g2rd-wpjm-geo/includes/OsmAssets.php` | modifié | URL et crédit des contours |
| `g2rd-wpjm-geo/includes/JobsMap.php` | modifié | textes de la carte (« Aussi à », régions, légende) |
| `g2rd-wpjm-geo/assets/js/location-tags.js` | nouveau | champ à étiquettes, découpage, recherche des régions |
| `g2rd-wpjm-geo/assets/js/location-autocomplete.js` | modifié | propositions de régions, choix en étiquette |
| `g2rd-wpjm-geo/assets/js/map-common.js` | modifié | `splitPlaces()`, « Aussi à », fenêtre de région, liste |
| `g2rd-wpjm-geo/assets/js/jobs-map-osm.js` | modifié | zones, pastilles, secours, légende, cadrage, crédit |
| `g2rd-wpjm-geo/assets/js/admin-regeocode.js` | modifié | compteur `update` |
| `g2rd-wpjm-geo/assets/css/frontend.css` | modifié | étiquettes, pastilles, légende |
| `g2rd-wpjm-geo/uninstall.php` | modifié | suppression de `_g2rd_wpjm_geo_places` |
| `g2rd-wpjm-geo/tests/bootstrap.php`, `tests/run.php`, `tests/js/run.js` | modifiés | nouvelles classes chargées, nouvelles sections |
| `tools/verify-plugin-zip.sh`, `.github/workflows/ci.yml`, `.gitattributes` | modifiés | fichiers exigés, empreinte vérifiée |
| `tools/docker/verify.sh` | modifié | bout en bout multi-lieux |
| `g2rd-wpjm-geo/g2rd-wpjm-geo.php`, `readme.txt` | modifiés | version 1.2.0, documentation |

---

### Task 0: Ligne de base

**Files:** aucun.

- [ ] **Step 1: Vérifier la branche et l'état du dépôt**

```bash
git branch --show-current
git status --short
```

Expected: `feat/lieux-multiples-regions`, aucun fichier modifié.

- [ ] **Step 2: Vérifier les tests**

```bash
php g2rd-wpjm-geo/tests/run.php | tail -1
NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1
```

Expected: `Résultat : 249 réussis, 0 échoués` puis `Resultat JS : 99 reussis, 0 echoues`.

---

### Task 1: Contours des régions (données ODbL embarquées)

**Files:**
- Create: `tools/regions/extract-regions.js`
- Create: `tools/regions/SHA256SUMS`
- Create: `g2rd-wpjm-geo/assets/data/regions-fr.geojson` (généré)
- Create: `g2rd-wpjm-geo/assets/data/LICENSE-regions.txt`
- Create: `g2rd-wpjm-geo/assets/data/index.php`
- Modify: `.gitattributes`
- Modify: `tools/verify-plugin-zip.sh`
- Modify: `.github/workflows/ci.yml`

- [ ] **Step 1: Écrire le script d'extraction**

Créer `tools/regions/extract-regions.js` :

```js
#!/usr/bin/env node
/*
 * Extrait les 18 régions françaises du fichier « Contours administratifs »
 * (data.gouv.fr, millésime 2025, licence ODbL) et affiche leurs centres pour
 * g2rd-wpjm-geo/includes/Regions.php. Les géométries sont recopiées telles quelles.
 *
 * Usage : node tools/regions/extract-regions.js <regions-1000m.geojson> <sortie.geojson>
 * Source : https://etalab-datasets.geo.data.gouv.fr/contours-administratifs/2025/geojson/regions-1000m.geojson
 */
'use strict';

const fs = require('fs');
const crypto = require('crypto');

const SOURCE_SHA256 = '7f44a5c4deaeb710c9dc2b043ed4debcf50068a1b0740619a5873f9e878f9786';
const CODES = ['01', '02', '03', '04', '06', '11', '24', '27', '28', '32', '44', '52', '53', '75', '76', '84', '93', '94'];

function ringArea(ring) {
  let area = 0;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    area += ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1];
  }
  return area / 2;
}

function ringCentroid(ring) {
  let area = 0, x = 0, y = 0;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const f = ring[j][0] * ring[i][1] - ring[i][0] * ring[j][1];
    area += f;
    x += (ring[j][0] + ring[i][0]) * f;
    y += (ring[j][1] + ring[i][1]) * f;
  }
  return [x / (3 * area), y / (3 * area)];
}

function inRing(point, ring) {
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const [xi, yi] = ring[i], [xj, yj] = ring[j];
    if ((yi > point[1]) !== (yj > point[1]) && point[0] < (xj - xi) * (point[1] - yi) / (yj - yi) + xi) {
      inside = !inside;
    }
  }
  return inside;
}

function inPolygon(point, polygon) {
  return inRing(point, polygon[0]) && !polygon.slice(1).some(hole => inRing(point, hole));
}

function largestPolygon(geometry) {
  const polygons = 'Polygon' === geometry.type ? [geometry.coordinates] : geometry.coordinates;
  return polygons.reduce((best, p) => (Math.abs(ringArea(p[0])) > Math.abs(ringArea(best[0])) ? p : best));
}

// Centre de gravité du plus grand polygone ; s'il tombe dehors (contour concave),
// point intérieur d'une grille 60 × 60 le plus proche de lui.
function centre(geometry) {
  const polygon = largestPolygon(geometry);
  const c = ringCentroid(polygon[0]);
  if (inPolygon(c, polygon)) {
    return c;
  }
  const xs = polygon[0].map(p => p[0]), ys = polygon[0].map(p => p[1]);
  const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
  let best = null, bestDistance = Infinity;
  for (let i = 1; i < 60; i++) {
    for (let j = 1; j < 60; j++) {
      const p = [minX + (maxX - minX) * i / 60, minY + (maxY - minY) * j / 60];
      const d = (p[0] - c[0]) ** 2 + (p[1] - c[1]) ** 2;
      if (d < bestDistance && inPolygon(p, polygon)) {
        best = p;
        bestDistance = d;
      }
    }
  }
  return best;
}

const [source, target] = process.argv.slice(2);
if (!source || !target) {
  console.error('Usage : node tools/regions/extract-regions.js <regions-1000m.geojson> <sortie.geojson>');
  process.exit(2);
}
const raw = fs.readFileSync(source);
const hash = crypto.createHash('sha256').update(raw).digest('hex');
if (hash !== SOURCE_SHA256) {
  console.error('Empreinte du fichier source inattendue : ' + hash);
  process.exit(1);
}
const data = JSON.parse(raw.toString('utf8'));
const features = data.features
  .filter(f => CODES.includes(f.properties.code))
  .map(f => ({ type: 'Feature', properties: { code: f.properties.code, nom: f.properties.nom }, geometry: f.geometry }));
if (features.length !== CODES.length) {
  console.error('Régions trouvées : ' + features.length + ' sur ' + CODES.length);
  process.exit(1);
}
fs.writeFileSync(target, JSON.stringify({ type: 'FeatureCollection', features }) + '\n');
features.forEach(f => {
  const [lng, lat] = centre(f.geometry);
  console.log("'" + f.properties.code + "' => array( '" + lat.toFixed(4) + "', '" + lng.toFixed(4) + "' ), // " + f.properties.nom);
});
```

- [ ] **Step 2: Télécharger la source hors du dépôt et générer le fichier embarqué**

Le fichier téléchargé est une donnée non fiable : il va dans un dossier temporaire vide, et le script vérifie son empreinte avant de le lire.

```bash
D="$(mktemp -d)"
curl -sSL --proto '=https' --max-redirs 2 -m 60 -o "$D/regions-1000m.geojson" "https://etalab-datasets.geo.data.gouv.fr/contours-administratifs/2025/geojson/regions-1000m.geojson"
mkdir -p g2rd-wpjm-geo/assets/data
node tools/regions/extract-regions.js "$D/regions-1000m.geojson" g2rd-wpjm-geo/assets/data/regions-fr.geojson
rm -rf "$D"
```

Expected : 18 lignes, exactement :

```
'01' => array( '16.1596', '-61.6805' ), // Guadeloupe
'02' => array( '14.6562', '-61.0217' ), // Martinique
'03' => array( '3.9150', '-53.2403' ), // Guyane
'04' => array( '-21.1334', '55.5322' ), // La Réunion
'06' => array( '-12.8202', '45.1424' ), // Mayotte
'11' => array( '48.7091', '2.5042' ), // Île-de-France
'24' => array( '47.4851', '1.6843' ), // Centre-Val de Loire
'27' => array( '47.2342', '4.8062' ), // Bourgogne-Franche-Comté
'28' => array( '49.1202', '0.1128' ), // Normandie
'32' => array( '49.9691', '2.7709' ), // Hauts-de-France
'44' => array( '48.6883', '5.6124' ), // Grand Est
'52' => array( '47.4801', '-0.8126' ), // Pays de la Loire
'53' => array( '48.1794', '-2.8331' ), // Bretagne
'75' => array( '45.2037', '0.2128' ), // Nouvelle-Aquitaine
'76' => array( '43.7026', '2.1453' ), // Occitanie
'84' => array( '45.5127', '4.5361' ), // Auvergne-Rhône-Alpes
'93' => array( '43.9588', '6.0645' ), // Provence-Alpes-Côte d'Azur
'94' => array( '42.1523', '9.1074' ), // Corse
```

- [ ] **Step 3: Inscrire et vérifier l'empreinte du fichier embarqué**

Créer `tools/regions/SHA256SUMS` (deux espaces entre l'empreinte et le chemin, fin de ligne LF) :

```
ab5375dabb7c4c38e394a666cbc032cfe7206c6663d0c7ff1786537c1f6835f0  g2rd-wpjm-geo/assets/data/regions-fr.geojson
```

```bash
sha256sum -c tools/regions/SHA256SUMS
wc -c < g2rd-wpjm-geo/assets/data/regions-fr.geojson
```

Expected : `g2rd-wpjm-geo/assets/data/regions-fr.geojson: OK` puis `119163`. Si l'empreinte diffère, ne pas la recopier : arrêter et signaler l'écart (source changée ou script modifié).

- [ ] **Step 4: Licence, répertoire non listable, fins de ligne**

Créer `g2rd-wpjm-geo/assets/data/LICENSE-regions.txt` :

```
Contours des régions françaises — assets/data/regions-fr.geojson

Source : « Contours administratifs », millésime 2025, publié par Etalab sur data.gouv.fr
https://www.data.gouv.fr/fr/datasets/contours-administratifs/
Fichier d'origine : 2025/geojson/regions-1000m.geojson
SHA-256 du fichier d'origine : 7f44a5c4deaeb710c9dc2b043ed4debcf50068a1b0740619a5873f9e878f9786

Extrait : les 18 régions (codes INSEE 01, 02, 03, 04, 06, 11, 24, 27, 28, 32,
44, 52, 53, 75, 76, 84, 93, 94) ; les 8 collectivités d'outre-mer (codes 975
à 989) sont retirées. Géométries recopiées sans modification ; seules les
propriétés « code » et « nom » sont gardées. Extraction reproductible :
tools/regions/extract-regions.js (dépôt du plugin).

Ce fichier de données est mis à disposition sous la licence Open Database
License (ODbL) 1.0 : https://opendatacommons.org/licenses/odbl/1-0/
Toute base de données dérivée de ce fichier doit rester sous ODbL et citer la
source. Le code du plugin reste distribué sous GPL v2 ou ultérieure.
```

Créer `g2rd-wpjm-geo/assets/data/index.php` :

```php
<?php
// Silence is golden.
```

Dans `.gitattributes`, après la ligne `*.json text eol=lf`, ajouter :

```
*.geojson text eol=lf
```

- [ ] **Step 5: Exiger les fichiers et vérifier l'empreinte dans l'archive de release**

Dans `tools/verify-plugin-zip.sh`, après la ligne `require "assets/vendor/markerclusterer.LICENSE.txt"`, ajouter :

```bash
require "assets/js/location-tags.js"
require "assets/data/regions-fr.geojson"
require "assets/data/LICENSE-regions.txt"
```

Puis, juste avant la ligne `echo "Fichiers de dev interdits"`, ajouter :

```bash
echo "Contours des régions (empreinte)"
EXPECTED_SUM="$(awk '$2 == "g2rd-wpjm-geo/assets/data/regions-fr.geojson" { print $1 }' tools/regions/SHA256SUMS)"
ACTUAL_SUM="$( (unzip -p "$ZIP" "$SLUG/assets/data/regions-fr.geojson" 2>/dev/null || true) | sha256sum | awk '{ print $1 }')"
if [ -n "$EXPECTED_SUM" ] && [ "$EXPECTED_SUM" = "$ACTUAL_SUM" ]; then
  echo "OK   : regions-fr.geojson conforme à tools/regions/SHA256SUMS"
else
  echo "ERREUR: empreinte de regions-fr.geojson inattendue"
  fail=1
fi
```

`assets/js/location-tags.js` est créé à la tâche 9 ; le contrôle de l'archive ne tourne qu'à la release.

- [ ] **Step 6: Vérifier l'empreinte en CI**

Dans `.github/workflows/ci.yml`, remplacer la ligne :

```yaml
          if echo "$CHANGED" | grep -qE '^(g2rd-wpjm-geo/(assets/js/|tests/js/)|tools/jsdom/package(-lock)?\.json$|\.github/workflows/ci\.yml$)'; then
```

par :

```yaml
          if echo "$CHANGED" | grep -qE '^(g2rd-wpjm-geo/(assets/js/|assets/data/|tests/js/)|tools/jsdom/package(-lock)?\.json$|tools/regions/|\.github/workflows/ci\.yml$)'; then
```

À la fin du job `js`, après l'étape `Tests JS (harnais jsdom)`, ajouter :

```yaml

      - name: Empreinte des contours des régions (données ODbL embarquées)
        run: grep ' g2rd-wpjm-geo/assets/data/regions-fr.geojson$' tools/regions/SHA256SUMS | sha256sum -c -
```

- [ ] **Step 7: Vérifier, puis commiter**

```bash
grep ' g2rd-wpjm-geo/assets/data/regions-fr.geojson$' tools/regions/SHA256SUMS | sha256sum -c -
git add tools/regions g2rd-wpjm-geo/assets/data .gitattributes tools/verify-plugin-zip.sh .github/workflows/ci.yml
git commit -m "feat(carte) : contours des 18 régions françaises (data.gouv.fr, ODbL)"
```

Expected : `g2rd-wpjm-geo/assets/data/regions-fr.geojson: OK`.

---

### Task 2: Régions françaises (`Regions`)

**Files:**
- Create: `g2rd-wpjm-geo/includes/Regions.php`
- Modify: `g2rd-wpjm-geo/tests/bootstrap.php:519`
- Test: `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Charger les nouvelles classes dans le banc de test**

Dans `g2rd-wpjm-geo/tests/bootstrap.php`, remplacer la liste de la boucle `foreach ( array( 'Settings', … 'PrivacyPolicy' ) as $class )` par :

```php
	foreach ( array( 'Settings', 'Logger', 'UrlChecker', 'ApplicationNormalizer', 'JobQueue', 'UrlCheckQueue', 'GoFetchIntegration', 'PhotonGeocoder', 'Regions', 'PlaceParser', 'PlaceLocator', 'GeocodingIntegration', 'GeocodeQueue', 'RestController', 'AdminMenu', 'RegeocodeTool', 'AdminScreen', 'Dependencies', 'GoogleAssets', 'OsmAssets', 'SettingsPage', 'MigrationTool', 'LocationAutocomplete', 'AdminLocation', 'PrivacyPolicy' ) as $class ) {
		if ( file_exists( dirname( __DIR__ ) . '/includes/' . $class . '.php' ) ) {
			require_once dirname( __DIR__ ) . '/includes/' . $class . '.php';
		}
	}
```

(`file_exists` : `PlaceParser` et `PlaceLocator` n'existent qu'aux tâches 3 et 5.)

- [ ] **Step 2: Écrire les tests (ils échouent)**

En tête de `g2rd-wpjm-geo/tests/run.php`, après `use G2RD\WPJMGeo\PrivacyPolicy;`, ajouter :

```php
use G2RD\WPJMGeo\Regions;
```

Puis insérer la section avant `$results = $GLOBALS['g2rd_results'];` :

```php
echo PHP_EOL . '32. Régions françaises' . PHP_EOL;
check( '18 régions, codes INSEE', array( '01', '02', '03', '04', '06', '11', '24', '27', '28', '32', '44', '52', '53', '75', '76', '84', '93', '94' ) === Regions::codes() );
$n = Regions::find( 'Normandie' );
check( 'nom officiel : code, nom, centre', is_array( $n ) && '28' === $n['code'] && 'Normandie' === $n['name'] && '49.1202' === $n['lat'] && '0.1128' === $n['lng'] );
check( 'accents, casse, tirets et article ignorés', '11' === Regions::find( 'ile de france' )['code'] && '11' === Regions::find( "l'Île-de-France" )['code'] && '04' === Regions::find( 'la réunion' )['code'] && '93' === Regions::find( 'provence alpes cote d azur' )['code'] );
check( 'sigles et variantes', '11' === Regions::find( 'IDF' )['code'] && '11' === Regions::find( 'Région parisienne' )['code'] && '93' === Regions::find( 'paca' )['code'] && '93' === Regions::find( 'Région Sud' )['code'] && '84' === Regions::find( 'AURA' )['code'] && '32' === Regions::find( 'HDF' )['code'] && '27' === Regions::find( 'BFC' )['code'] && '24' === Regions::find( 'Région Centre' )['code'] && '04' === Regions::find( 'Réunion' )['code'] && '03' === Regions::find( 'Guyane française' )['code'] );
check( 'anciennes régions rattachées à l’actuelle', '44' === Regions::find( 'Alsace' )['code'] && '75' === Regions::find( 'Poitou-Charentes' )['code'] && '84' === Regions::find( 'Rhône-Alpes' )['code'] && '27' === Regions::find( 'Franche-Comté' )['code'] && '76' === Regions::find( 'Midi-Pyrénées' )['code'] && '32' === Regions::find( 'Picardie' )['code'] && '28' === Regions::find( 'Haute-Normandie' )['code'] );
check( 'préfixe « Région » accepté', '53' === Regions::find( 'Région Bretagne' )['code'] );
check( 'texte qui n’est pas une région : null', null === Regions::find( 'Rouen' ) && null === Regions::find( 'Centre' ) && null === Regions::find( 'Bretagne (90)' ) && null === Regions::find( '' ) && null === Regions::get( '99' ) );
$all = Regions::all();
check( 'liste pour l’autocomplétion : 18 entrées, codes en chaîne, autres noms', 18 === count( $all ) && '11' === $all[5]['code'] && 'Île-de-France' === $all[5]['name'] && in_array( 'IDF', $all[5]['aliases'], true ) && in_array( 'Alsace', $all[10]['aliases'], true ) && array() === $all[0]['aliases'] );

$shapes = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/assets/data/regions-fr.geojson' ), true );
$codes  = array_map(
	function ( $feature ) {
		return $feature['properties']['code'];
	},
	$shapes['features']
);
sort( $codes );
check( 'contours embarqués : les 18 régions, propriétés code et nom seulement', Regions::codes() === $codes && array( 'code', 'nom' ) === array_keys( $shapes['features'][0]['properties'] ) );
function g2rd_in_ring( array $point, array $ring ) {
	$inside = false;
	for ( $i = 0, $j = count( $ring ) - 1; $i < count( $ring ); $j = $i++ ) {
		if ( ( $ring[ $i ][1] > $point[1] ) !== ( $ring[ $j ][1] > $point[1] ) && $point[0] < ( $ring[ $j ][0] - $ring[ $i ][0] ) * ( $point[1] - $ring[ $i ][1] ) / ( $ring[ $j ][1] - $ring[ $i ][1] ) + $ring[ $i ][0] ) {
			$inside = ! $inside;
		}
	}
	return $inside;
}
$inside = 0;
$names  = 0;
foreach ( $shapes['features'] as $feature ) {
	$region   = Regions::get( $feature['properties']['code'] );
	$polygons = 'Polygon' === $feature['geometry']['type'] ? array( $feature['geometry']['coordinates'] ) : $feature['geometry']['coordinates'];
	$names   += ( $region && $region['name'] === $feature['properties']['nom'] ) ? 1 : 0;
	foreach ( $polygons as $polygon ) {
		if ( $region && g2rd_in_ring( array( (float) $region['lng'], (float) $region['lat'] ), $polygon[0] ) ) {
			++$inside;
			break;
		}
	}
}
check( 'noms identiques à ceux des contours', 18 === $names );
check( 'chaque centre est à l’intérieur du contour de sa région', 18 === $inside );
```

- [ ] **Step 3: Lancer les tests (échec attendu)**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected : erreur fatale `Class "G2RD\WPJMGeo\Regions" not found`.

- [ ] **Step 4: Écrire `Regions`**

Créer `g2rd-wpjm-geo/includes/Regions.php` :

```php
<?php
/**
 * Régions françaises reconnues dans le champ « Lieu ».
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Les 18 régions (codes INSEE), leurs variantes et anciens noms, et un centre
 * fixe : centre de gravité du plus grand polygone du contour, à l'intérieur de
 * celui-ci (tools/regions/extract-regions.js). Aucun appel réseau.
 */
class Regions {

	/**
	 * Code INSEE => nom officiel, latitude et longitude du centre.
	 */
	const DATA = array(
		'01' => array( 'Guadeloupe', '16.1596', '-61.6805' ),
		'02' => array( 'Martinique', '14.6562', '-61.0217' ),
		'03' => array( 'Guyane', '3.9150', '-53.2403' ),
		'04' => array( 'La Réunion', '-21.1334', '55.5322' ),
		'06' => array( 'Mayotte', '-12.8202', '45.1424' ),
		'11' => array( 'Île-de-France', '48.7091', '2.5042' ),
		'24' => array( 'Centre-Val de Loire', '47.4851', '1.6843' ),
		'27' => array( 'Bourgogne-Franche-Comté', '47.2342', '4.8062' ),
		'28' => array( 'Normandie', '49.1202', '0.1128' ),
		'32' => array( 'Hauts-de-France', '49.9691', '2.7709' ),
		'44' => array( 'Grand Est', '48.6883', '5.6124' ),
		'52' => array( 'Pays de la Loire', '47.4801', '-0.8126' ),
		'53' => array( 'Bretagne', '48.1794', '-2.8331' ),
		'75' => array( 'Nouvelle-Aquitaine', '45.2037', '0.2128' ),
		'76' => array( 'Occitanie', '43.7026', '2.1453' ),
		'84' => array( 'Auvergne-Rhône-Alpes', '45.5127', '4.5361' ),
		'93' => array( "Provence-Alpes-Côte d'Azur", '43.9588', '6.0645' ),
		'94' => array( 'Corse', '42.1523', '9.1074' ),
	);

	/**
	 * Variantes, sigles et anciennes régions => code de la région actuelle.
	 */
	const ALIASES = array(
		'IDF'                  => '11',
		'Région parisienne'    => '11',
		'PACA'                 => '93',
		'Région Sud'           => '93',
		'AURA'                 => '84',
		'HDF'                  => '32',
		'BFC'                  => '27',
		'Région Centre'        => '24',
		'Réunion'              => '04',
		'Guyane française'     => '03',
		'Alsace'               => '44',
		'Lorraine'             => '44',
		'Champagne-Ardenne'    => '44',
		'Aquitaine'            => '75',
		'Limousin'             => '75',
		'Poitou-Charentes'     => '75',
		'Auvergne'             => '84',
		'Rhône-Alpes'          => '84',
		'Bourgogne'            => '27',
		'Franche-Comté'        => '27',
		'Midi-Pyrénées'        => '76',
		'Languedoc-Roussillon' => '76',
		'Nord-Pas-de-Calais'   => '32',
		'Picardie'             => '32',
		'Haute-Normandie'      => '28',
		'Basse-Normandie'      => '28',
	);

	/**
	 * Forme de comparaison => code, construit au premier appel.
	 *
	 * @var array<string,string>|null
	 */
	private static $index = null;

	/**
	 * Codes INSEE des 18 régions.
	 *
	 * @return list<string>
	 */
	public static function codes() {
		return array_map( 'strval', array_keys( self::DATA ) );
	}

	/**
	 * Région d'après son code.
	 *
	 * @param string $code Code INSEE.
	 * @return array{code:string,name:string,lat:string,lng:string}|null
	 */
	public static function get( $code ) {
		$code = (string) $code;
		if ( ! isset( self::DATA[ $code ] ) ) {
			return null;
		}
		return array(
			'code' => $code,
			'name' => self::DATA[ $code ][0],
			'lat'  => self::DATA[ $code ][1],
			'lng'  => self::DATA[ $code ][2],
		);
	}

	/**
	 * Région désignée par un texte : nom, variante, sigle ou ancien nom. Accents,
	 * casse, ponctuation, article et préfixe « Région » sont ignorés.
	 *
	 * @param string $text Texte.
	 * @return array{code:string,name:string,lat:string,lng:string}|null
	 */
	public static function find( $text ) {
		$key = self::key( $text );
		if ( '' === $key ) {
			return null;
		}
		$index = self::index();
		if ( ! isset( $index[ $key ] ) && 0 === strpos( $key, 'region ' ) ) {
			$key = self::key( substr( $key, 7 ) );
		}
		return isset( $index[ $key ] ) ? self::get( $index[ $key ] ) : null;
	}

	/**
	 * Liste transmise à l'autocomplétion : code, nom officiel et autres noms reconnus.
	 *
	 * @return list<array{code:string,name:string,aliases:list<string>}>
	 */
	public static function all() {
		$aliases = array();
		foreach ( self::ALIASES as $alias => $code ) {
			$aliases[ $code ][] = (string) $alias;
		}
		$out = array();
		foreach ( self::DATA as $code => $row ) {
			$code  = (string) $code;
			$out[] = array(
				'code'    => $code,
				'name'    => $row[0],
				'aliases' => isset( $aliases[ $code ] ) ? $aliases[ $code ] : array(),
			);
		}
		return $out;
	}

	/**
	 * Forme de comparaison (celle du géocodeur, sans article).
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	private static function key( $text ) {
		return PhotonGeocoder::without_article( PhotonGeocoder::normalize( (string) $text ) );
	}

	/**
	 * Index des noms reconnus.
	 *
	 * @return array<string,string>
	 */
	private static function index() {
		if ( null === self::$index ) {
			self::$index = array();
			foreach ( self::DATA as $code => $row ) {
				self::$index[ self::key( $row[0] ) ] = (string) $code;
			}
			foreach ( self::ALIASES as $alias => $code ) {
				self::$index[ self::key( (string) $alias ) ] = $code;
			}
		}
		return self::$index;
	}
}
```

- [ ] **Step 5: Lancer les tests**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -14`
Expected : les 11 contrôles de la section 32 en `[OK]`, `Résultat : 260 réussis, 0 échoués`.

- [ ] **Step 6: Lint, analyse, commit**

```bash
composer run lint && composer run analyse
git add g2rd-wpjm-geo/includes/Regions.php g2rd-wpjm-geo/tests/bootstrap.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat(lieux) : régions françaises reconnues sans appel réseau"
```

---

### Task 3: Découpage du texte (`PlaceParser`)

**Files:**
- Create: `g2rd-wpjm-geo/includes/PlaceParser.php`
- Test: `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Écrire les tests (ils échouent)**

En tête de `run.php`, après `use G2RD\WPJMGeo\PhotonGeocoder;`, ajouter :

```php
use G2RD\WPJMGeo\PlaceParser;
```

Section à insérer avant `$results = …` :

```php
echo PHP_EOL . '33. Découpage du champ « Lieu »' . PHP_EOL;
check( 'séparateurs forts : ; | / retour à la ligne', array( 'Paris', 'Lyon', 'Lille', 'Nantes', 'Brest' ) === PlaceParser::split( "Paris ; Lyon|Lille / Nantes\nBrest" ) );
check( '« et » entouré d’espaces, casse ignorée', array( 'Paris', 'Lyon', 'Caen' ) === PlaceParser::split( 'Paris et Lyon ET Caen' ) );
check( 'tirets et virgules ne séparent pas', array( 'Ille-et-Vilaine', 'Rouen, Normandie' ) === PlaceParser::split( 'Ille-et-Vilaine ; Rouen, Normandie' ) );
check( 'ponctuation de bord et morceaux vides retirés, parenthèses gardées', array( 'Laval (53)', 'Rouen' ) === PlaceParser::split( ' ; - Laval (53). ;; , Rouen, ' ) );
check( 'mentions vagues écartées', array( 'Paris' ) === PlaceParser::split( 'Télétravail / Paris ; Remote' ) );
check( 'doublons fusionnés (accents, casse)', array( 'Évreux', 'Lyon' ) === PlaceParser::split( 'Évreux ; EVREUX ; Lyon ; lyon' ) );
check( 'texte vide ou UTF-8 invalide : aucun morceau, sans avertissement', array() === PlaceParser::split( '' ) && array() === PlaceParser::split( "Lyon \xC3\x28" ) );
check( 'au plus 30 morceaux', 30 === count( PlaceParser::split( implode( ';', range( 1, 50 ) ) ) ) );
```

- [ ] **Step 2: Lancer les tests (échec attendu)**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected : erreur fatale `Class "G2RD\WPJMGeo\PlaceParser" not found`.

- [ ] **Step 3: Écrire `PlaceParser`**

Créer `g2rd-wpjm-geo/includes/PlaceParser.php` :

```php
<?php
/**
 * Découpage du champ « Lieu » en morceaux.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sépare le texte sur les séparateurs forts (« ; », « | », « / », retour à la
 * ligne, « et » entouré d'espaces). La virgule ne sépare rien ici : elle est
 * traitée par PlaceLocator (règle de la virgule).
 */
class PlaceParser {

	const SEPARATORS = '/[;|\/\r\n]|\s+et\s+/iu';

	/**
	 * Garde-fou : un texte importé démesuré ne déclenche pas des centaines de recherches.
	 */
	const MAX_PIECES = 30;

	/**
	 * Morceaux dans l'ordre du texte, sans vides, mentions vagues ni doublons.
	 *
	 * @param string $text Texte du champ « Lieu ».
	 * @return list<string>
	 */
	public static function split( $text ) {
		$parts  = preg_split( self::SEPARATORS, wp_check_invalid_utf8( (string) $text ) );
		$pieces = array();
		$seen   = array();
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			$piece = trim( (string) preg_replace( '/^[\s,.:\-–—]+|[\s,.:\-–—]+$/u', '', (string) $part ) );
			if ( '' === $piece ) {
				continue;
			}
			$key = PhotonGeocoder::normalize( $piece );
			if ( in_array( $key, PhotonGeocoder::VAGUE, true ) ) {
				continue;
			}
			if ( '' === $key ) {
				// Écriture non latine : comparée telle quelle.
				$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $piece, 'UTF-8' ) : strtolower( $piece );
			}
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$pieces[]     = $piece;
			if ( count( $pieces ) >= self::MAX_PIECES ) {
				break;
			}
		}
		return $pieces;
	}
}
```

- [ ] **Step 4: Lancer les tests**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -11`
Expected : 8 contrôles `[OK]` en section 33, `Résultat : 268 réussis, 0 échoués`.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer run lint && composer run analyse
git add g2rd-wpjm-geo/includes/PlaceParser.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat(lieux) : découpage du champ Lieu sur les séparateurs forts"
```

---

### Task 4: Géocodeur détaillé et cache version 4

**Files:**
- Modify: `g2rd-wpjm-geo/includes/PhotonGeocoder.php:34` (`CACHE_VERSION`), `:173-214` (`geocode()`)
- Test: `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Écrire les tests (ils échouent)**

Section à insérer avant `$results = …` :

```php
echo PHP_EOL . '34. Géocodeur : département et nom du résultat, cache' . PHP_EOL;
list( $s ) = boot();
$geo  = new PhotonGeocoder( $s );
$urls = array();
G2RD_Test_State::$http_responder = function ( $method, $url ) use ( &$urls ) {
	$urls[] = $url;
	if ( false !== strpos( $url, 'q=Rouen' ) ) {
		return photon_reply( array( photon_feature( 'Rouen', 'place', 'city', 1.0999, 49.4432, array( 'county' => 'Seine-Maritime', 'state' => 'Normandie', 'country' => 'France', 'countrycode' => 'FR' ) ) ) );
	}
	return photon_reply( array() );
};
check( 'texte jamais vu : pas en cache, aucune requête', false === $geo->is_cached( 'Rouen, Seine-Maritime' ) && 0 === count( $urls ) );
$d = $geo->geocode_detailed( 'Rouen, Seine-Maritime' );
check( 'résultat détaillé : adresse WPJM, département, nom', is_array( $d ) && '49.4432' === $d['address']['lat'] && 'Seine-Maritime' === $d['county'] && 'Rouen' === $d['name'] && ! isset( $d['address']['county'] ) && false === $geo->last_was_cached() );
check( 'puis en cache', $geo->is_cached( 'Rouen, Seine-Maritime' ) && 1 === count( $urls ) );
$again = $geo->geocode_detailed( 'Rouen, Seine-Maritime' );
check( 'département relu depuis le cache, sans requête', is_array( $again ) && 'Seine-Maritime' === $again['county'] && $geo->last_was_cached() && 1 === count( $urls ) );
$address = $geo->geocode( 'Rouen, Seine-Maritime' );
check( 'geocode() inchangé : adresse seule', is_array( $address ) && '1.0999' === $address['long'] && ! isset( $address['address'] ) );
check( 'introuvable : null, mis en cache', null === $geo->geocode_detailed( 'Atlantide' ) && $geo->is_cached( 'Atlantide' ) );
check( 'texte sans lieu (vague, vide) : rien à demander à Photon', $geo->is_cached( 'Télétravail' ) && $geo->is_cached( ' (75) ' ) );
check( 'version du cache : 4', '4' === PhotonGeocoder::CACHE_VERSION );
G2RD_Test_State::$http_responder = null;
```

- [ ] **Step 2: Lancer les tests (échec attendu)**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected : erreur fatale `Call to undefined method G2RD\WPJMGeo\PhotonGeocoder::is_cached()`.

- [ ] **Step 3: Modifier `PhotonGeocoder`**

Remplacer la ligne :

```php
	const CACHE_VERSION = '3'; // À incrémenter quand le choix du lieu ou la clé change (résultats gardés 30 jours).
```

par :

```php
	const CACHE_VERSION = '4'; // À incrémenter quand le choix du lieu, la clé ou le contenu du cache change (résultats gardés 30 jours).
```

Remplacer toute la méthode `geocode()` (docblock compris, de `/**` « Géocode une localisation. » jusqu'à son `}` final) par :

```php
	/**
	 * Géocode une localisation.
	 *
	 * @param string $text Localisation saisie.
	 * @return array<string,string>|null|\WP_Error Adresse au format WPJM, null si introuvable, WP_Error si le service est indisponible.
	 */
	public function geocode( $text ) {
		$result = $this->geocode_detailed( $text );
		return is_array( $result ) ? $result['address'] : $result;
	}

	/**
	 * Géocode une localisation et renvoie aussi le département et le nom du lieu
	 * trouvé (règle de la virgule de PlaceLocator, libellé sur la carte).
	 *
	 * @param string $text Localisation saisie.
	 * @return array{address:array<string,string>,county:string,name:string}|null|\WP_Error
	 */
	public function geocode_detailed( $text ) {
		$this->last_was_cached = true;
		$cleaned               = self::clean( $text );
		$key                   = $this->cache_key( $text, $cleaned );
		if ( null === $key ) {
			return null;
		}
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'r', $cached ) ) {
			return self::detailed( $cached );
		}

		$this->last_was_cached = false;
		$base                  = $this->base_url();
		$lang                  = self::language( get_locale() );
		$features              = $this->request( $base, $cleaned, $lang, true );
		if ( is_wp_error( $features ) && 'g2rd_photon_bad_request' === $features->get_error_code() ) {
			$features = $this->request( $base, $cleaned, $lang, false );
		}
		if ( is_wp_error( $features ) ) {
			return $features;
		}

		$feature = self::pick( $features, $cleaned, self::hint( $text ) );
		$address = $feature ? self::to_address( $feature ) : null;
		$entry   = array(
			'r' => $address,
			'c' => ( $feature && $address ) ? self::property( $feature, 'county' ) : '',
			'n' => ( $feature && $address ) ? self::property( $feature, 'name' ) : '',
		);
		set_transient( $key, $entry, $address ? self::CACHE_TTL : self::MISS_TTL );
		return self::detailed( $entry );
	}

	/**
	 * Le texte peut-il être géocodé sans appeler Photon (résultat en cache, ou
	 * texte qui ne désigne aucun lieu) ?
	 *
	 * @param string $text Localisation saisie.
	 * @return bool
	 */
	public function is_cached( $text ) {
		$key = $this->cache_key( $text, self::clean( $text ) );
		if ( null === $key ) {
			return true;
		}
		$cached = get_transient( $key );
		return is_array( $cached ) && array_key_exists( 'r', $cached );
	}

	/**
	 * Clé de cache, ou null si le texte ne doit pas être envoyé à Photon.
	 *
	 * @param string $text    Localisation saisie.
	 * @param string $cleaned Texte nettoyé (clean()).
	 * @return string|null
	 */
	private function cache_key( $text, $cleaned ) {
		$segments = array_map( array( __CLASS__, 'normalize' ), explode( ',', $cleaned ) );
		if ( '' === $segments[0] || in_array( $segments[0], self::VAGUE, true ) ) {
			// Rien de comparable (vide, écriture non latine) ou pas de lieu précis : inutile d'appeler Photon.
			return null;
		}
		// Clé = texte réellement envoyé à Photon (casse ignorée). Une clé tirée des segments
		// normalisés, qui perdent toute lettre non latine, laisserait une variante saisie par un
		// soumetteur (« Paris Москва », « Paris ＴＥＸＡＳ ») fixer le résultat des autres offres.
		$query = function_exists( 'mb_strtolower' ) ? mb_strtolower( $cleaned, 'UTF-8' ) : strtolower( $cleaned );
		return self::CACHE_PREFIX . md5( self::CACHE_VERSION . '|' . $this->base_url() . '|' . self::language( get_locale() ) . '|' . self::preferred_country() . '|' . $query . '|' . self::hint( $text ) );
	}

	/**
	 * Résultat détaillé d'une entrée de cache.
	 *
	 * @param array<string,mixed> $entry Entrée (r : adresse, c : département, n : nom).
	 * @return array{address:array<string,string>,county:string,name:string}|null
	 */
	private static function detailed( array $entry ) {
		if ( empty( $entry['r'] ) || ! is_array( $entry['r'] ) ) {
			return null;
		}
		return array(
			'address' => $entry['r'],
			'county'  => ( isset( $entry['c'] ) && is_string( $entry['c'] ) ) ? $entry['c'] : '',
			'name'    => ( isset( $entry['n'] ) && is_string( $entry['n'] ) ) ? $entry['n'] : '',
		);
	}

	/**
	 * Propriété texte d'une entité Photon, assainie et tronquée.
	 *
	 * @param array<string,mixed> $feature Entité GeoJSON.
	 * @param string              $key     Propriété.
	 * @return string
	 */
	private static function property( array $feature, $key ) {
		$props = ( isset( $feature['properties'] ) && is_array( $feature['properties'] ) ) ? $feature['properties'] : array();
		return ( isset( $props[ $key ] ) && is_scalar( $props[ $key ] ) ) ? self::truncate( sanitize_text_field( (string) $props[ $key ] ) ) : '';
	}
```

- [ ] **Step 4: Lancer les tests**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -11`
Expected : 8 contrôles `[OK]` en section 34, sections 16 et 31 toujours vertes, `Résultat : 276 réussis, 0 échoués`.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer run lint && composer run analyse
git add g2rd-wpjm-geo/includes/PhotonGeocoder.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat(géocodage) : département et nom du résultat, cache Photon version 4"
```

---

### Task 5: Placement des lieux (`PlaceLocator`)

**Files:**
- Create: `g2rd-wpjm-geo/includes/PlaceLocator.php`
- Test: `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Écrire les aides et les tests (ils échouent)**

En tête de `run.php`, après `use G2RD\WPJMGeo\PlaceParser;`, ajouter :

```php
use G2RD\WPJMGeo\PlaceLocator;
```

Section à insérer avant `$results = …` :

```php
echo PHP_EOL . '35. Lieux d’une offre : règle de la virgule' . PHP_EOL;

/** Villes connues du Photon simulé : longitude, latitude, département, région, pays, code pays, code postal. */
function g2rd_world_feature( $name ) {
	$cities = array(
		'Rouen'    => array( 1.0999, 49.4432, 'Seine-Maritime', 'Normandie', 'France', 'FR', '76000' ),
		'Caen'     => array( -0.3707, 49.1829, 'Calvados', 'Normandie', 'France', 'FR', '14000' ),
		'Le Havre' => array( 0.1079, 49.4938, 'Seine-Maritime', 'Normandie', 'France', 'FR', '76600' ),
		'Paris'    => array( 2.3522, 48.8566, 'Paris', 'Île-de-France', 'France', 'FR', '75001' ),
		'Lyon'     => array( 4.8357, 45.764, 'Métropole de Lyon', 'Auvergne-Rhône-Alpes', 'France', 'FR', '69001' ),
		'Brest'    => array( -4.4861, 48.3904, 'Finistère', 'Bretagne', 'France', 'FR', '29200' ),
		'Bretagne' => array( 6.9917, 47.5986, 'Territoire de Belfort', 'Bourgogne-Franche-Comté', 'France', 'FR', '90130' ),
		'London'   => array( -81.2497, 42.9837, 'Middlesex County', 'Ontario', 'Canada', 'CA', 'N6A' ),
	);
	list( $lng, $lat, $county, $state, $country, $code, $postcode ) = $cities[ $name ];
	return photon_feature( $name, 'place', 'city', $lng, $lat, array( 'county' => $county, 'state' => $state, 'country' => $country, 'countrycode' => $code, 'postcode' => $postcode ) );
}
/**
 * Photon simulé : $replies associe un texte exact envoyé à Photon aux villes renvoyées
 * (dans l'ordre de Photon) ; un texte égal au nom d'une ville connue renvoie cette ville.
 */
function g2rd_world( array $replies ) {
	$GLOBALS['g2rd_world_queries']   = array();
	G2RD_Test_State::$http_responder = function ( $method, $url ) use ( $replies ) {
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
		$q                                 = isset( $query['q'] ) ? (string) $query['q'] : '';
		$GLOBALS['g2rd_world_queries'][] = $q;
		$known                             = array( 'Rouen', 'Caen', 'Le Havre', 'Paris', 'Lyon', 'Brest', 'Bretagne', 'London' );
		$names                             = isset( $replies[ $q ] ) ? $replies[ $q ] : ( in_array( $q, $known, true ) ? array( $q ) : array() );
		return photon_reply( array_map( 'g2rd_world_feature', $names ) );
	};
}
/** « type:libellé » de chaque lieu, joints par « | ». */
function g2rd_labels( $result ) {
	if ( ! is_array( $result ) ) {
		return 'erreur';
	}
	return implode(
		' | ',
		array_map(
			function ( $place ) {
				return $place['type'] . ':' . $place['label'];
			},
			$result['places']
		)
	);
}

list( $s ) = boot();
$sleeps    = 0;
$locator   = new PlaceLocator(
	new PhotonGeocoder( $s ),
	function () use ( &$sleeps ) {
		$sleeps++;
	}
);
g2rd_world(
	array(
		'London, Ontario, Canada' => array( 'London' ),
		'Rouen, Normandie'        => array( 'Rouen' ),
		'Rouen, Seine-Maritime'   => array( 'Rouen' ),
		'Paris, Lyon'             => array( 'Paris', 'Lyon' ),
		'Rouen, Caen, Normandie'  => array( 'Caen' ), // Rouen absente des 5 premiers résultats.
		'Paris, Normandie'        => array( 'Paris' ),
	)
);
$cases = array(
	'London, Ontario, Canada'  => 'city:London',
	'Rouen, Normandie'         => 'city:Rouen',
	'Rouen, Seine-Maritime'    => 'city:Rouen',
	'Paris, Lyon'              => 'city:Paris | city:Lyon',
	'Rouen, Caen, Normandie'   => 'city:Rouen | city:Caen',
	'Paris, Normandie'         => 'city:Paris | region:Normandie',
	'Normandie, Bretagne'      => 'region:Normandie | region:Bretagne',
	'Paris ; Lyon ; Normandie' => 'city:Paris | city:Lyon | region:Normandie',
);
foreach ( $cases as $text => $expected ) {
	check( sprintf( '« %s » → %s', $text, $expected ), $expected === g2rd_labels( $locator->locate( $text ) ) );
}
check( 'une pause après chaque requête réelle à Photon, aucune sur le cache', $sleeps > 0 && count( $GLOBALS['g2rd_world_queries'] ) === $sleeps );

$r = $locator->locate( 'Rouen, Normandie ; Paris' );
check( 'premier lieu : adresse WPJM ; lieux en chaînes, sans le département', is_array( $r ) && '49.4432' === $r['address']['lat'] && 'Rouen' === $r['address']['city'] && array( 'type' => 'city', 'label' => 'Rouen', 'lat' => '49.4432', 'lng' => '1.0999' ) === $r['places'][0] && false === $r['deferred'] );
$before = count( $GLOBALS['g2rd_world_queries'] );
$r      = $locator->locate( 'Normandie' );
check( 'région seule : centre fixe, adresse régionale, sans requête', is_array( $r ) && array( 'type' => 'region', 'label' => 'Normandie', 'lat' => '49.1202', 'lng' => '0.1128', 'region' => '28' ) === $r['places'][0] && 'Normandie' === $r['address']['state_long'] && 'FR' === $r['address']['country_short'] && '' === $r['address']['city'] && 'Normandie, France' === $r['address']['formatted_address'] && count( $GLOBALS['g2rd_world_queries'] ) === $before );
check( 'homonyme avec indice : « Bretagne (90) » cherché comme une ville', 'city:Bretagne' === g2rd_labels( $locator->locate( 'Bretagne (90)' ) ) && in_array( 'Bretagne', $GLOBALS['g2rd_world_queries'], true ) );
check( 'doublons après placement : « Alsace ; Lorraine » donne une seule zone Grand Est', 'region:Grand Est' === g2rd_labels( $locator->locate( 'Alsace ; Lorraine' ) ) );
check( 'doublons après placement : même ville sous deux écritures', 'city:Rouen' === g2rd_labels( $locator->locate( 'Rouen ; Rouen, Normandie' ) ) );
$twelve = implode( ' ; ', array_slice( array_column( Regions::all(), 'name' ), 0, 12 ) );
check( 'plafond : 10 lieux au plus', 10 === count( $locator->locate( $twelve )['places'] ) );
$r = $locator->locate( 'Paris ; Atlantide' );
check( 'ville introuvable retirée et signalée, les autres restent', 'city:Paris' === g2rd_labels( $r ) && array( 'Atlantide' ) === $r['missing'] );
$r = $locator->locate( 'Paris, télétravail' );
check( 'mention vague après une virgule : ignorée', 'city:Paris' === g2rd_labels( $r ) && array() === $r['missing'] );
check( 'texte vide : aucun lieu', array() === $locator->locate( '' )['places'] && null === $locator->locate( '' )['address'] );

list( $s ) = boot();
$locator   = new PlaceLocator( new PhotonGeocoder( $s ) );
g2rd_world( array() );
$r = $locator->locate( 'Brest', 1 );
check( 'budget 1 : une ville absente du cache est placée', 'city:Brest' === g2rd_labels( $r ) && false === $r['deferred'] );
$r = $locator->locate( 'Caen ; Le Havre', 1 );
check( 'budget 1 : une deuxième ville absente du cache → à différer, au plus une requête', is_array( $r ) && true === $r['deferred'] && array() === $r['places'] && 2 === count( $GLOBALS['g2rd_world_queries'] ) );
$r = $locator->locate( 'Brest ; Normandie ; Caen', 1 );
check( 'budget 1 : villes en cache et régions ne comptent pas', 'city:Brest | region:Normandie | city:Caen' === g2rd_labels( $r ) && false === $r['deferred'] && 2 === count( $GLOBALS['g2rd_world_queries'] ) );
$r = $locator->locate( 'Le Havre', 0 );
check( 'budget 0 : texte absent du cache → à différer, sans requête', true === $r['deferred'] && 2 === count( $GLOBALS['g2rd_world_queries'] ) );
G2RD_Test_State::$http_responder = function () {
	return new WP_Error( 'http_request_failed', 'timeout' );
};
check( 'Photon en panne : WP_Error', is_wp_error( $locator->locate( 'Nantes ; Normandie' ) ) );
G2RD_Test_State::$http_responder = null;
```

- [ ] **Step 2: Lancer les tests (échec attendu)**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected : erreur fatale `Class "G2RD\WPJMGeo\PlaceLocator" not found`.

- [ ] **Step 3: Écrire `PlaceLocator`**

Créer `g2rd-wpjm-geo/includes/PlaceLocator.php` :

```php
<?php
/**
 * Lieux d'une offre : texte du champ « Lieu » → villes et régions placées.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Découpe le texte (PlaceParser), reconnaît les régions françaises (Regions,
 * sans réseau) et géocode les villes avec Photon.
 *
 * Règle de la virgule : les segments qui suivent le premier le précisent s'ils
 * égalent le pays, la région ou le département du lieu trouvé ; sinon chacun
 * devient un lieu à part. Si le texte entier ne donne rien (« Rouen, Caen,
 * Normandie » : Photon ne renvoie que 5 résultats), chaque segment est cherché
 * seul, et une région déjà couverte par une ville du même morceau est retirée.
 */
class PlaceLocator {

	const MAX_PLACES = 10;
	const UNLIMITED  = -1;

	/**
	 * Réponse interne : budget d'appels épuisé.
	 */
	const DEFER = 'defer';

	/**
	 * Géocodeur.
	 *
	 * @var PhotonGeocoder
	 */
	private $geocoder;

	/**
	 * Pause après chaque appel réel à Photon (files et outils), ou null.
	 *
	 * @var callable|null
	 */
	private $sleeper;

	/**
	 * Textes absents du cache encore autorisés pendant locate() (UNLIMITED : sans limite).
	 *
	 * @var int
	 */
	private $budget = self::UNLIMITED;

	/**
	 * Constructeur.
	 *
	 * @param PhotonGeocoder $geocoder Géocodeur.
	 * @param callable|null  $sleeper  Pause en secondes après chaque appel réel ; null : aucune.
	 */
	public function __construct( PhotonGeocoder $geocoder, $sleeper = null ) {
		$this->geocoder = $geocoder;
		$this->sleeper  = is_callable( $sleeper ) ? $sleeper : null;
	}

	/**
	 * Place les lieux d'un texte.
	 *
	 * @param string $text   Texte du champ « Lieu ».
	 * @param int    $budget Textes absents du cache que l'on peut envoyer à Photon (UNLIMITED : sans limite).
	 * @return array{places:list<array<string,string>>,address:array<string,string>|null,missing:list<string>,deferred:bool}|\WP_Error
	 */
	public function locate( $text, $budget = self::UNLIMITED ) {
		$this->budget = (int) $budget;
		$found        = array();
		$seen         = array();
		$missing      = array();
		$queue        = PlaceParser::split( $text );
		while ( $queue && count( $found ) < self::MAX_PLACES ) {
			$result = $this->piece( (string) array_shift( $queue ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! is_array( $result ) ) {
				return array(
					'places'   => array(),
					'address'  => null,
					'missing'  => array(),
					'deferred' => true,
				);
			}
			foreach ( $result['found'] as $entry ) {
				$key = 'region' === $entry['place']['type'] ? 'r:' . $entry['place']['region'] : 'c:' . $entry['place']['lat'] . ',' . $entry['place']['lng'];
				if ( ! isset( $seen[ $key ] ) && count( $found ) < self::MAX_PLACES ) {
					$seen[ $key ] = true;
					$found[]      = $entry;
				}
			}
			$missing = array_merge( $missing, $result['missing'] );
			// Les segments devenus des lieux à part passent juste après le lieu dont ils viennent.
			$queue = array_merge( $result['more'], $queue );
		}
		return array(
			'places'   => array_values( array_column( $found, 'place' ) ),
			'address'  => $found ? $found[0]['address'] : null,
			'missing'  => $missing,
			'deferred' => false,
		);
	}

	/**
	 * Place un morceau (texte sans séparateur fort).
	 *
	 * @param string $piece Morceau.
	 * @return array{found:list<array{place:array<string,string>,address:array<string,string>}>,missing:list<string>,more:list<string>}|\WP_Error|string DEFER si le budget est épuisé.
	 */
	private function piece( $piece ) {
		$segments = array_values( array_filter( array_map( 'trim', explode( ',', $piece ) ), 'strlen' ) );
		if ( ! $segments ) {
			return self::result( array(), array(), array() );
		}
		$first = $segments[0];
		$rest  = array_slice( $segments, 1 );

		$region = '' === PhotonGeocoder::hint( $first ) ? Regions::find( $first ) : null;
		if ( $region ) {
			$more = array();
			foreach ( $rest as $segment ) {
				if ( 'france' !== self::key( $segment ) ) {
					$more[] = $segment;
				}
			}
			return self::result( array( self::region_entry( $region ) ), array(), $more );
		}

		$detailed = $this->geocode( $piece );
		if ( is_wp_error( $detailed ) || self::DEFER === $detailed ) {
			return $detailed;
		}
		if ( is_array( $detailed ) ) {
			return self::result( array( self::city_entry( $detailed ) ), array(), self::unused( $detailed, $rest ) );
		}
		if ( ! $rest ) {
			return self::result( array(), array( $piece ), array() );
		}
		return $this->each_segment( $segments );
	}

	/**
	 * Seconde passe : chaque segment est cherché seul ; une région déjà couverte
	 * par une ville trouvée dans le même morceau est retirée.
	 *
	 * @param list<string> $segments Segments du morceau.
	 * @return array{found:list<array{place:array<string,string>,address:array<string,string>}>,missing:list<string>,more:list<string>}|\WP_Error|string
	 */
	private function each_segment( array $segments ) {
		$entries = array();
		$missing = array();
		$covered = array();
		foreach ( $segments as $segment ) {
			if ( in_array( PhotonGeocoder::normalize( $segment ), PhotonGeocoder::VAGUE, true ) ) {
				continue;
			}
			$region = '' === PhotonGeocoder::hint( $segment ) ? Regions::find( $segment ) : null;
			if ( $region ) {
				$entries[] = self::region_entry( $region );
				continue;
			}
			$detailed = $this->geocode( $segment );
			if ( is_wp_error( $detailed ) || self::DEFER === $detailed ) {
				return $detailed;
			}
			if ( ! is_array( $detailed ) ) {
				$missing[] = $segment;
				continue;
			}
			$entries[] = self::city_entry( $detailed );
			$own       = Regions::find( $detailed['address']['state_long'] );
			if ( $own ) {
				$covered[ $own['code'] ] = true;
			}
		}
		$found = array();
		foreach ( $entries as $entry ) {
			if ( 'region' !== $entry['place']['type'] || ! isset( $covered[ $entry['place']['region'] ] ) ) {
				$found[] = $entry;
			}
		}
		return self::result( $found, $missing, array() );
	}

	/**
	 * Segments qui ne précisent pas le lieu trouvé (ni son pays, ni sa région, ni son département).
	 *
	 * @param array{address:array<string,string>,county:string,name:string} $detailed Résultat de geocode_detailed().
	 * @param list<string>                                                   $rest     Segments suivant le premier.
	 * @return list<string>
	 */
	private static function unused( array $detailed, array $rest ) {
		$context = array();
		foreach ( array( $detailed['address']['country_long'], $detailed['address']['state_long'], $detailed['county'] ) as $value ) {
			if ( '' !== (string) $value ) {
				$context[] = self::key( $value );
			}
		}
		$own  = Regions::find( $detailed['address']['state_long'] );
		$more = array();
		foreach ( $rest as $segment ) {
			$named = Regions::find( $segment );
			if ( in_array( self::key( $segment ), $context, true ) || ( $own && $named && $own['code'] === $named['code'] ) ) {
				continue;
			}
			$more[] = $segment;
		}
		return $more;
	}

	/**
	 * Géocode un texte en respectant le budget, puis marque une pause après un appel réel.
	 *
	 * @param string $text Texte.
	 * @return array{address:array<string,string>,county:string,name:string}|null|\WP_Error|string DEFER si le budget est épuisé.
	 */
	private function geocode( $text ) {
		if ( ! $this->geocoder->is_cached( $text ) ) {
			if ( 0 === $this->budget ) {
				return self::DEFER;
			}
			if ( $this->budget > 0 ) {
				--$this->budget;
			}
		}
		$result = $this->geocoder->geocode_detailed( $text );
		if ( $this->sleeper && ! $this->geocoder->last_was_cached() ) {
			call_user_func( $this->sleeper, 1 );
		}
		return $result;
	}

	/**
	 * Lieu « ville ».
	 *
	 * @param array{address:array<string,string>,county:string,name:string} $detailed Résultat de geocode_detailed().
	 * @return array{place:array<string,string>,address:array<string,string>}
	 */
	private static function city_entry( array $detailed ) {
		$address = $detailed['address'];
		return array(
			'place'   => array(
				'type'  => 'city',
				'label' => '' !== $detailed['name'] ? $detailed['name'] : (string) $address['city'],
				'lat'   => (string) $address['lat'],
				'lng'   => (string) $address['long'],
			),
			'address' => $address,
		);
	}

	/**
	 * Lieu « région » : centre fixe, adresse régionale pour les champs de WP Job Manager.
	 *
	 * @param array{code:string,name:string,lat:string,lng:string} $region Région.
	 * @return array{place:array<string,string>,address:array<string,string>}
	 */
	private static function region_entry( array $region ) {
		return array(
			'place'   => array(
				'type'   => 'region',
				'label'  => $region['name'],
				'lat'    => $region['lat'],
				'lng'    => $region['lng'],
				'region' => $region['code'],
			),
			'address' => array(
				'lat'               => $region['lat'],
				'long'              => $region['lng'],
				'formatted_address' => $region['name'] . ', France',
				'city'              => '',
				'state_long'        => $region['name'],
				'postcode'          => '',
				'country_long'      => 'France',
				'country_short'     => 'FR',
			),
		);
	}

	/**
	 * Résultat d'un morceau.
	 *
	 * @param list<array{place:array<string,string>,address:array<string,string>}> $found   Lieux placés.
	 * @param list<string>                                                         $missing Textes introuvables.
	 * @param list<string>                                                         $more    Segments à traiter comme des morceaux à part.
	 * @return array{found:list<array{place:array<string,string>,address:array<string,string>}>,missing:list<string>,more:list<string>}
	 */
	private static function result( array $found, array $missing, array $more ) {
		return array(
			'found'   => $found,
			'missing' => $missing,
			'more'    => $more,
		);
	}

	/**
	 * Forme de comparaison (sans accents, casse, ponctuation ni article).
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	private static function key( $text ) {
		return PhotonGeocoder::without_article( PhotonGeocoder::normalize( (string) $text ) );
	}
}
```

- [ ] **Step 4: Lancer les tests**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -28`
Expected : 23 contrôles `[OK]` en section 35, `Résultat : 299 réussis, 0 échoués`. Si un des 8 exemples échoue, afficher `g2rd_labels()` et `$GLOBALS['g2rd_world_queries']` pour ce texte avant de corriger : le texte envoyé à Photon doit être celui du tableau `g2rd_world()`.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer run lint && composer run analyse
git add g2rd-wpjm-geo/includes/PlaceLocator.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat(lieux) : règle de la virgule, régions et budget d'appels à Photon"
```

---

### Task 6: Enregistrement des lieux, file d'attente, affichage du lieu

**Files:**
- Modify: `g2rd-wpjm-geo/includes/GeocodingIntegration.php`
- Modify: `g2rd-wpjm-geo/includes/GeocodeQueue.php`
- Modify: `g2rd-wpjm-geo/uninstall.php:27`
- Test: `g2rd-wpjm-geo/tests/run.php` (section 17 ajustée, nouvelle section 36)

- [ ] **Step 1: Ajuster le test existant de la section 17**

« IDF » est désormais une variante d'Île-de-France. Dans `run.php`, remplacer :

```php
do_action( 'job_manager_job_location_edited', 201, 'IDF' );
```

par :

```php
do_action( 'job_manager_job_location_edited', 201, 'Atlantide' );
```

- [ ] **Step 2: Écrire les tests (ils échouent)**

Section à insérer avant `$results = …` :

```php
echo PHP_EOL . '36. Plusieurs lieux sur une offre : enregistrement' . PHP_EOL;
$places = GeocodingIntegration::META_PLACES;
$types  = function ( $list ) {
	return array_map(
		function ( $place ) {
			return $place['type'] . ':' . $place['label'];
		},
		(array) $list
	);
};

geo_boot();
g2rd_world( array() );
job_with_google_coords( 500 );
G2RD_Test_State::$meta[500]['_job_location'] = 'Rouen ; Normandie';
do_action( 'job_manager_job_location_edited', 500, 'Rouen ; Normandie' );
$m = G2RD_Test_State::$meta[500];
check( 'saisie : premier lieu dans les champs WPJM, source osm', '49.4432' === $m['geolocation_lat'] && 'Rouen' === $m['geolocation_city'] && 'osm' === $m[ $src ] );
check( 'saisie : liste des lieux (ville puis région)', array( 'city:Rouen', 'region:Normandie' ) === $types( $m[ $places ] ) && '28' === $m[ $places ][1]['region'] );
check( 'saisie : texte saisi jamais réécrit', 'Rouen ; Normandie' === $m['_job_location'] );

job_with_google_coords( 501 );
do_action( 'job_manager_job_location_edited', 501, 'Normandie, France' );
$m = G2RD_Test_State::$meta[501];
check( 'région en premier : centre, région et pays dans les champs WPJM, sans ville', '49.1202' === $m['geolocation_lat'] && ! isset( $m['geolocation_city'] ) && 'Normandie' === $m['geolocation_state_long'] && 'FR' === $m['geolocation_country_short'] && 'Normandie, France' === $m['geolocation_formatted_address'] );

do_action( 'job_manager_job_location_edited', 500, 'Atlantide' );
check( 'aucun lieu trouvé : coordonnées et liste effacées', ! isset( G2RD_Test_State::$meta[500]['geolocated'] ) && ! isset( G2RD_Test_State::$meta[500][ $places ] ) );

check( 'affichage : « Paris ; Lyon ; Normandie » devient « Paris, Lyon, Normandie »', 'Paris, Lyon, Normandie' === apply_filters( 'the_job_location', 'Paris ; Lyon ; Normandie', null ) );
check( 'affichage : lieu simple inchangé', 'Rouen, Normandie, France' === apply_filters( 'the_job_location', 'Rouen, Normandie, France', null ) );

unset( G2RD_Test_State::$hooks['shutdown'] );
$bumps = G2RD_Test_State::$cache_bumps;
GeocodingIntegration::on_map_meta_changed( 0, 500, $places );
do_action( 'shutdown' );
check( 'liste de lieux modifiée : cache des offres renouvelé', 1 === G2RD_Test_State::$cache_bumps - $bumps );

list( , , $queue ) = geo_boot();
g2rd_world( array() );
job_with_google_coords( 502 );
do_action( 'job_manager_job_location_edited', 502, 'Caen ; Le Havre' );
$queue->flush();
check( 'deux villes absentes du cache : file d’attente, anciennes coordonnées effacées, une seule requête', array( 502 ) === get_option( GeocodeQueue::OPTION ) && ! isset( G2RD_Test_State::$meta[502]['geolocated'] ) && 1 === count( $GLOBALS['g2rd_world_queries'] ) );
G2RD_Test_State::$meta[502]['_job_location'] = 'Caen ; Le Havre';
unset( G2RD_Test_State::$scheduled[ GeocodeQueue::CRON_HOOK ] );
do_action( GeocodeQueue::CRON_HOOK );
check( 'file : tous les lieux placés', 'Caen' === G2RD_Test_State::$meta[502]['geolocation_city'] && array( 'city:Caen', 'city:Le Havre' ) === $types( G2RD_Test_State::$meta[502][ $places ] ) );
check( 'file : une pause après l’appel réel (Le Havre), aucune sur le cache (Caen)', 1 === $GLOBALS['g2rd_sleeps'] );

geo_boot( 'google' );
G2RD_Test_State::$post_types[503] = 'job_listing';
G2RD_Test_State::$meta[503]       = array( 'geolocated' => 1, 'geolocation_lat' => '49.4432', $src => 'osm', $places => array( array( 'type' => 'city', 'label' => 'Rouen', 'lat' => '49.4432', 'lng' => '1.0999' ) ) );
WP_Job_Manager_Geocode::save_location_data( 503, array( 'lat' => '48.85', 'long' => '2.35' ) );
check( 'coordonnées écrites par un tiers : source et liste effacées', ! isset( G2RD_Test_State::$meta[503][ $src ] ) && ! isset( G2RD_Test_State::$meta[503][ $places ] ) );
G2RD_Test_State::$post_types[504] = 'job_listing';
G2RD_Test_State::$meta[504]       = array( $src => 'osm', $places => array( array( 'type' => 'city', 'label' => 'Rouen', 'lat' => '49.4432', 'lng' => '1.0999' ) ) );
do_action( 'job_manager_job_location_edited', 504, 'Paris' );
check( 'mode Google : liste de lieux oubliée avec la source', ! isset( G2RD_Test_State::$meta[504][ $places ] ) && ! isset( G2RD_Test_State::$meta[504][ $src ] ) );
G2RD_Test_State::$http_responder = null;
```

- [ ] **Step 3: Lancer les tests (échec attendu)**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected : erreur fatale `Undefined constant G2RD\WPJMGeo\GeocodingIntegration::META_PLACES`.

- [ ] **Step 4: Modifier `GeocodingIntegration`**

Après `const META_SOURCE = '_g2rd_wpjm_geo_source';`, ajouter :

```php

	/**
	 * Lieux de l'offre en mode libre (villes et régions), le premier étant aussi dans les champs de WP Job Manager.
	 */
	const META_PLACES = '_g2rd_wpjm_geo_places';
```

Remplacer le bloc de la propriété `$geocoder` :

```php
	/**
	 * Géocodeur.
	 *
	 * @var PhotonGeocoder
	 */
	private $geocoder;
```

par :

```php
	/**
	 * Placement des lieux (sans pause : saisie interactive).
	 *
	 * @var PlaceLocator
	 */
	private $locator;
```

Dans le constructeur, remplacer `$this->geocoder = $geocoder;` par :

```php
		$this->locator  = new PlaceLocator( $geocoder );
```

Dans `register()`, juste après la boucle `foreach ( array( 'added_post_meta', … ) as $hook ) { … }`, ajouter :

```php
		// Dans les deux modes : « Paris ; Lyon » s'affiche « Paris, Lyon » (fiche, listes, colonne de l'administration).
		add_filter( 'the_job_location', array( __CLASS__, 'display_location' ), 10, 1 );
```

Remplacer le corps de `forget_source()` :

```php
		$job_id = absint( $job_id );
		if ( $job_id ) {
			delete_post_meta( $job_id, self::META_SOURCE );
			delete_post_meta( $job_id, self::META_PLACES );
		}
```

Dans `on_foreign_coords()`, remplacer :

```php
			delete_post_meta( $object_id, self::META_SOURCE );
```

par :

```php
			delete_post_meta( $object_id, self::META_SOURCE );
			delete_post_meta( $object_id, self::META_PLACES );
```

Dans `on_map_meta_changed()`, remplacer `array( self::META_SOURCE, 'geolocation_lat' )` par `array( self::META_SOURCE, self::META_PLACES, 'geolocation_lat' )`.

Dans `on_location_edited()`, remplacer depuis `$result = $this->geocoder->geocode( $location );` jusqu'à la fin de la méthode par :

```php
		// Budget d'un texte absent du cache : au plus un appel à Photon pendant l'enregistrement, comme pour un lieu unique.
		$result = $this->locator->locate( $location, 1 );
		if ( is_wp_error( $result ) ) {
			set_transient( self::DOWN_FLAG, 1, self::DOWN_TTL );
			self::write( $job_id, null, Settings::PROVIDER_OSM );
			$this->queue->push( $job_id );
			return;
		}
		if ( $result['deferred'] ) {
			// Plusieurs villes inconnues du cache : placées par la file, pour ne pas ralentir l'enregistrement.
			self::write( $job_id, null, Settings::PROVIDER_OSM );
			$this->queue->push( $job_id );
			return;
		}
		self::write_places( $job_id, $result );
	}
```

Dans `write()`, remplacer :

```php
			delete_post_meta( $job_id, self::META_SOURCE );
```

par :

```php
			delete_post_meta( $job_id, self::META_SOURCE );
			delete_post_meta( $job_id, self::META_PLACES );
```

Après la méthode `write()`, ajouter :

```php

	/**
	 * Écrit les lieux d'une offre : le premier dans les champs de WP Job Manager, tous dans META_PLACES.
	 *
	 * @param int                 $job_id ID de l'offre.
	 * @param array<string,mixed> $result Résultat de PlaceLocator::locate().
	 * @return bool false si WP Job Manager est absent.
	 */
	public static function write_places( $job_id, array $result ) {
		$address = ( isset( $result['address'] ) && is_array( $result['address'] ) ) ? $result['address'] : null;
		if ( ! self::write( $job_id, $address, Settings::PROVIDER_OSM ) ) {
			return false;
		}
		if ( $address && ! empty( $result['places'] ) && is_array( $result['places'] ) ) {
			update_post_meta( $job_id, self::META_PLACES, array_values( $result['places'] ) );
		}
		return true;
	}

	/**
	 * Lieu affiché : « Paris ; Lyon » devient « Paris, Lyon ». Le formulaire
	 * d'édition lit la métadonnée directement et garde les « ; ».
	 *
	 * @param mixed $location Lieu (filtre the_job_location).
	 * @return mixed
	 */
	public static function display_location( $location ) {
		if ( ! is_string( $location ) || false === strpos( $location, ';' ) ) {
			return $location;
		}
		return (string) preg_replace( '/\s*;\s*/', ', ', trim( $location, " ;\t\n\r" ) );
	}
```

- [ ] **Step 5: Modifier `GeocodeQueue`**

Remplacer le docblock de la classe :

```php
/**
 * Lot de 10 offres, une seconde de pause entre deux appels réels à Photon
 * (usage raisonnable de l'instance publique).
 */
```

par :

```php
/**
 * Lot de 10 offres ; tous les lieux de chaque offre sont placés, avec une
 * seconde de pause après chaque appel réel à Photon (usage raisonnable de
 * l'instance publique).
 */
```

Remplacer les propriétés `$geocoder` et `$sleeper`, le constructeur et `process_item()` par :

```php
	/**
	 * Placement des lieux.
	 *
	 * @var PlaceLocator
	 */
	private $locator;

	/**
	 * Constructeur.
	 *
	 * @param Settings       $settings Réglages.
	 * @param PhotonGeocoder $geocoder Géocodeur.
	 * @param callable|null  $sleeper  Pause en secondes ; défaut sleep().
	 */
	public function __construct( Settings $settings, PhotonGeocoder $geocoder, $sleeper = null ) {
		$this->settings = $settings;
		$this->locator  = new PlaceLocator( $geocoder, is_callable( $sleeper ) ? $sleeper : 'sleep' );
	}

	/**
	 * Place les lieux d'une offre de la file.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return bool false si Photon est indisponible.
	 */
	protected function process_item( $job_id ) {
		if ( Settings::PROVIDER_OSM !== $this->settings->get_provider() || 'job_listing' !== get_post_type( $job_id ) ) {
			return true;
		}
		$location = trim( (string) get_post_meta( $job_id, '_job_location', true ) );
		if ( '' === $location ) {
			GeocodingIntegration::write( $job_id, null, Settings::PROVIDER_OSM );
			return true;
		}

		$result = $this->locator->locate( $location );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		GeocodingIntegration::write_places( $job_id, $result );
		return true;
	}
```

- [ ] **Step 6: Nettoyer à la désinstallation**

Dans `g2rd-wpjm-geo/uninstall.php`, après `delete_post_meta_by_key( '_g2rd_wpjm_geo_source' );`, ajouter :

```php
delete_post_meta_by_key( '_g2rd_wpjm_geo_places' );
```

- [ ] **Step 7: Lancer les tests**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -18`
Expected : 13 contrôles `[OK]` en section 36, section 17 toujours verte, `Résultat : 312 réussis, 0 échoués`.

- [ ] **Step 8: Lint, analyse, commit**

```bash
composer run lint && composer run analyse
git add g2rd-wpjm-geo/includes/GeocodingIntegration.php g2rd-wpjm-geo/includes/GeocodeQueue.php g2rd-wpjm-geo/uninstall.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat(lieux) : enregistrement de tous les lieux, file d'attente au-delà d'une ville inconnue"
```

---

### Task 7: Outil de re-géocodage

**Files:**
- Modify: `g2rd-wpjm-geo/includes/RegeocodeTool.php`
- Modify: `g2rd-wpjm-geo/assets/js/admin-regeocode.js:33`
- Test: `g2rd-wpjm-geo/tests/run.php` (section 20 ajustée, nouvelle section 37)

- [ ] **Step 1: Ajuster les données de la section 20**

Dans le tableau `$rows` de la section 20, remplacer :

```php
	array( 'id' => '301', 'location' => 'Montréal', 'source' => 'osm', 'geolocated' => '1' ),
	array( 'id' => '302', 'location' => 'IDF', 'source' => null, 'geolocated' => null ),
```

par :

```php
	array( 'id' => '301', 'location' => 'Montréal', 'source' => 'osm', 'geolocated' => '1', 'places' => 'a:1:{i:0;a:0:{}}' ),
	array( 'id' => '302', 'location' => 'Atlantide', 'source' => null, 'geolocated' => null ),
```

Remplacer les libellés `'simulation : Rouen prévu, IDF non trouvée, Brest en erreur'` par `'simulation : Rouen prévu, Atlantide non trouvée, Brest en erreur'`, `'exécution : Rouen et Brest écrits, IDF non trouvée'` par `'exécution : Rouen et Brest écrits, Atlantide non trouvée'` et `'exécution : pause seulement après un vrai appel (Brest), pas sur le cache (Rouen, IDF)'` par `'exécution : pause seulement après un vrai appel (Brest), pas sur le cache (Rouen, Atlantide)'`.

- [ ] **Step 2: Écrire les tests (ils échouent)**

Section à insérer avant `$results = …` :

```php
echo PHP_EOL . '37. Re-géocodage : plusieurs lieux' . PHP_EOL;
$mrows = array(
	array( 'id' => '600', 'location' => 'Rouen ; Normandie ; Atlantide', 'source' => 'osm', 'geolocated' => '1', 'places' => null ),
	array( 'id' => '601', 'location' => 'Brest', 'source' => 'osm', 'geolocated' => '1', 'places' => 'a:1:{i:0;a:0:{}}' ),
);
$sum = RegeocodeTool::summarize( $mrows, 'osm' );
check( 'offre libre sans liste de lieux (1.1.x) : affichée, à mettre à jour', 2 === $sum['osm'] && 2 === $sum['shown'] && 1 === $sum['update'] && 1 === $sum['todo'] && array( 600 ) === $sum['todo_ids'] );
check( 'mode Google : aucune mise à jour d’offre libre', 0 === RegeocodeTool::summarize( $mrows, 'google' )['update'] );
list( $s ) = geo_boot();
g2rd_world( array() );
G2RD_Test_State::$post_types[600] = 'job_listing';
G2RD_Test_State::$meta[600]       = array( '_job_location' => 'Rouen ; Normandie ; Atlantide', 'geolocated' => 1, 'geolocation_lat' => '49.4432', $src => 'osm' );
$tool = new RegeocodeTool( $s, new PhotonGeocoder( $s ), '__return_false' );
$sim  = $tool->handle( 'simulate', 0, $mrows );
check( 'simulation : lieux trouvés et introuvables listés, aucune écriture', 'ok' === $sim['rows'][0]['status'] && 'Rouen, Normandie — introuvable : Atlantide' === $sim['rows'][0]['result'] && ! isset( G2RD_Test_State::$meta[600][ GeocodingIntegration::META_PLACES ] ) );
$run = $tool->handle( 'run', 0, $mrows );
check( 'exécution : liste de lieux écrite', 'done' === $run['rows'][0]['status'] && 2 === count( G2RD_Test_State::$meta[600][ GeocodingIntegration::META_PLACES ] ) );
G2RD_Test_State::$http_responder = null;
```

- [ ] **Step 3: Lancer les tests (échec attendu)**

Run: `php g2rd-wpjm-geo/tests/run.php | grep -E "FAIL|37\.|Résultat"`
Expected : `[FAIL]` sur les contrôles de la section 37 (clé `update` absente, résultat « Rouen, France »).

- [ ] **Step 4: Modifier `RegeocodeTool`**

Remplacer la propriété `$geocoder` :

```php
	/**
	 * Géocodeur libre.
	 *
	 * @var PhotonGeocoder
	 */
	private $geocoder;
```

par :

```php
	/**
	 * Placement des lieux (mode libre).
	 *
	 * @var PlaceLocator
	 */
	private $locator;
```

Dans le constructeur, remplacer :

```php
		$this->geocoder = $geocoder;
		$this->sleeper  = is_callable( $sleeper ) ? $sleeper : 'sleep';
```

par :

```php
		$this->sleeper  = is_callable( $sleeper ) ? $sleeper : 'sleep';
		$this->locator  = new PlaceLocator( $geocoder, $this->sleeper );
```

Dans `rows()`, remplacer le docblock `@return` par :

```php
	 * @return array<int,array<string,string|null>> Lignes id, status, location, source, geolocated, places.
```

et la requête par :

```php
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_status AS status, loc.meta_value AS location, src.meta_value AS source, geo.meta_value AS geolocated, pl.meta_value AS places
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} loc ON loc.post_id = p.ID AND loc.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} geo ON geo.post_id = p.ID AND geo.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pl ON pl.post_id = p.ID AND pl.meta_key = %s
				WHERE p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' ) AND loc.meta_value <> ''
				ORDER BY p.ID ASC",
				'_job_location',
				GeocodingIntegration::META_SOURCE,
				'geolocated',
				GeocodingIntegration::META_PLACES,
				'job_listing'
			),
			ARRAY_A
		);
```

Dans `summarize()` et `stats()`, remplacer dans les deux docblocks `@return` la forme `array{total:int,google:int,osm:int,none:int,shown:int,todo:int,todo_ids:list<int>,todo_keys:list<int>}` par `array{total:int,google:int,osm:int,none:int,shown:int,update:int,todo:int,todo_ids:list<int>,todo_keys:list<int>}`.

Dans `summarize()`, après `'shown'     => 0,`, ajouter `'update'    => 0,` ; puis remplacer :

```php
				++$summary[ $source ];
				if ( $source === $provider ) {
					++$summary['shown'];
					continue;
				}
```

par :

```php
				++$summary[ $source ];
				if ( $source === $provider ) {
					++$summary['shown'];
					if ( Settings::PROVIDER_OSM !== $provider || ! empty( $row['places'] ) ) {
						continue;
					}
					// Offre libre antérieure à la 1.2.0 (un seul point) : reprise pour placer tous ses lieux.
					++$summary['update'];
				}
```

Remplacer toute la méthode `process()` (docblock compris) par :

```php
	/**
	 * Re-géocode (ou simule) une offre.
	 *
	 * @param int    $job_id   ID de l'offre.
	 * @param bool   $write    Écrire le résultat.
	 * @param string $provider Fournisseur actif.
	 * @return array{id:int,location:string,result:string,status:string,edit:string} Statut : ok|done|not_found|error|pending.
	 */
	private function process( $job_id, $write, $provider ) {
		$location = trim( (string) get_post_meta( $job_id, '_job_location', true ) );
		$row      = array(
			'id'       => $job_id,
			'location' => $location,
			'result'   => '',
			'status'   => '',
			'edit'     => (string) get_edit_post_link( $job_id, 'raw' ),
		);
		if ( '' === trim( str_replace( array( ',', '?', '&', '=', '#' ), '', $location ) ) ) {
			// Rien de géocodable : listée « Non trouvée », sans arrêter le traitement.
			$row['status'] = 'not_found';
			return $row;
		}

		if ( Settings::PROVIDER_OSM === $provider ) {
			$located = $this->locator->locate( $location );
			if ( is_wp_error( $located ) ) {
				$row['status'] = 'error';
				$row['result'] = $located->get_error_message();
				return $row;
			}
			if ( ! $located['places'] ) {
				$row['status'] = 'not_found';
				return $row;
			}
			$row['result'] = self::describe( $located );
			if ( $write ) {
				GeocodingIntegration::write_places( $job_id, $located );
				$row['status'] = 'done';
			} else {
				$row['status'] = 'ok';
			}
			return $row;
		}

		if ( ! $write ) {
			$row['status'] = 'pending';
			$row['result'] = __( 'Géocodage par WP Job Manager (Google) à l’exécution', 'g2rd-wpjm-geo' );
			return $row;
		}
		$data = class_exists( 'WP_Job_Manager_Geocode' ) ? \WP_Job_Manager_Geocode::get_location_data( $location ) : false;
		// Message exact de WP Job Manager pour ZERO_RESULTS (même domaine, même traduction) ; tout autre
		// WP_Error vient d'une clé refusée, d'un quota ou du réseau.
		$zero = __( 'No results found', 'wp-job-manager' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		if ( is_wp_error( $data ) && $zero !== $data->get_error_message() ) {
			$row['status'] = 'error';
			/* translators: %s: WP Job Manager error message. */
			$row['result'] = sprintf( __( 'Géocodage Google en échec (clé refusée, quota ou réseau) : %s', 'g2rd-wpjm-geo' ), $data->get_error_message() );
			return $row;
		}
		if ( is_wp_error( $data ) ) {
			$row['status'] = 'not_found'; // Aucun résultat chez Google : offre listée « Non trouvée ».
			return $row;
		}
		if ( ! is_array( $data ) || ! $data ) {
			$row['status'] = 'error';
			$row['result'] = __( 'Géocodage Google indisponible (quota atteint ou géocodage désactivé).', 'g2rd-wpjm-geo' );
			return $row;
		}
		$label         = implode( ', ', array_filter( array( isset( $data['city'] ) ? $data['city'] : '', isset( $data['country_long'] ) ? $data['country_long'] : '' ) ) );
		$row['result'] = '' !== $label ? $label : (string) ( isset( $data['formatted_address'] ) ? $data['formatted_address'] : '' );
		GeocodingIntegration::write( $job_id, $data, $provider );
		$row['status'] = 'done';
		return $row;
	}

	/**
	 * Résultat lisible : « Rouen, France » pour une ville seule, sinon la liste des
	 * lieux ; puis les lieux introuvables.
	 *
	 * @param array<string,mixed> $located Résultat de PlaceLocator::locate().
	 * @return string
	 */
	private static function describe( array $located ) {
		$address = (array) $located['address'];
		$places  = (array) $located['places'];
		if ( 1 === count( $places ) && 'city' === $places[0]['type'] ) {
			$text = implode( ', ', array_filter( array( (string) $address['city'], (string) $address['country_long'] ) ) );
			$text = '' !== $text ? $text : (string) $address['formatted_address'];
		} else {
			$text = implode( ', ', array_column( $places, 'label' ) );
		}
		if ( ! empty( $located['missing'] ) ) {
			/* translators: %s: comma-separated locations that were not found. */
			$text .= ' — ' . sprintf( __( 'introuvable : %s', 'g2rd-wpjm-geo' ), implode( ', ', (array) $located['missing'] ) );
		}
		return $text;
	}
```

Dans `render()`, après la ligne `'osm'          => __( 'Géocodées par OpenStreetMap', 'g2rd-wpjm-geo' ),`, ajouter :

```php
			'update'       => __( 'Libres, à mettre à jour (plusieurs lieux)', 'g2rd-wpjm-geo' ),
```

et remplacer la description du mode libre :

```php
						? __( 'Recalcule avec Photon (OpenStreetMap) les coordonnées des offres qui ne viennent pas de la source libre. Les offres introuvables ne sont jamais effacées : elles sont listées pour correction.', 'g2rd-wpjm-geo' )
```

par :

```php
						? __( 'Recalcule avec Photon (OpenStreetMap) les coordonnées des offres qui ne viennent pas de la source libre, et place tous les lieux (villes et régions) des offres antérieures à la version 1.2.0. Les offres introuvables ne sont jamais effacées : elles sont listées pour correction.', 'g2rd-wpjm-geo' )
```

- [ ] **Step 5: Afficher le nouveau compteur**

Dans `g2rd-wpjm-geo/assets/js/admin-regeocode.js`, remplacer :

```js
			[ 'total', 'google', 'osm', 'none', 'todo' ].forEach( function ( key ) {
```

par :

```js
			[ 'total', 'google', 'osm', 'update', 'none', 'todo' ].forEach( function ( key ) {
```

- [ ] **Step 6: Lancer les tests**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -8`
Expected : 4 contrôles `[OK]` en section 37, sections 20 et 29 toujours vertes, `Résultat : 316 réussis, 0 échoués`. JS : `0 echoues`.

- [ ] **Step 7: Lint, analyse, commit**

```bash
composer run lint && composer run analyse
git add g2rd-wpjm-geo/includes/RegeocodeTool.php g2rd-wpjm-geo/assets/js/admin-regeocode.js g2rd-wpjm-geo/tests/run.php
git commit -m "feat(outils) : re-géocodage de tous les lieux, reprise des offres 1.1.x"
```

---

### Task 8: API REST — champ `places`

**Files:**
- Modify: `g2rd-wpjm-geo/includes/RestController.php:256-320`
- Test: `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Écrire les tests (ils échouent)**

Section à insérer avant `$results = …` :

```php
echo PHP_EOL . '38. Carte : lieux de chaque offre (REST)' . PHP_EOL;
boot();
G2RD_Test_State::$meta[700][ GeocodingIntegration::META_PLACES ] = array(
	array( 'type' => 'city', 'label' => 'Rouen', 'lat' => '49.4432', 'lng' => '1.0999' ),
	array( 'type' => 'region', 'label' => '<b>Normandie</b>', 'lat' => '49.1202', 'lng' => '0.1128', 'region' => '28' ),
	array( 'type' => 'region', 'label' => 'Inconnue', 'lat' => '1', 'lng' => '1', 'region' => '99' ),
	array( 'type' => 'city', 'label' => 'Hors bornes', 'lat' => '120', 'lng' => '1' ),
	array( 'type' => 'city', 'label' => '<img src=x onerror=alert(1)>Lyon', 'lat' => '45.764', 'lng' => '4.8357' ),
	'pas un tableau',
);
$p = RestController::places( 700, 49.4432, 1.0999, 'Rouen', true );
check( 'liste du plugin : villes et régions, coordonnées revérifiées, région inconnue écartée', 3 === count( $p ) && 'city' === $p[0]['type'] && 49.4432 === $p[0]['lat'] && 'region' === $p[1]['type'] && '28' === $p[1]['region'] && 0.1128 === $p[1]['lng'] && 'Lyon' === $p[2]['label'] );
check( 'libellés en texte brut ; région sous son nom officiel', 'Normandie' === $p[1]['label'] && false === strpos( wp_json_encode( $p ), '<' ) );
$p = RestController::places( 701, 48.8566, 2.3522, 'Paris', true );
check( 'offre sans liste (ancienne offre) : un lieu construit depuis les coordonnées', array( array( 'type' => 'city', 'label' => 'Paris', 'lat' => 48.8566, 'lng' => 2.3522 ) ) === $p );
$p = RestController::places( 700, 49.4432, 1.0999, 'Rouen', false );
check( 'mode Google : liste ignorée, point unique', 1 === count( $p ) && 'Rouen' === $p[0]['label'] );
```

- [ ] **Step 2: Lancer les tests (échec attendu)**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected : erreur fatale `Call to undefined method G2RD\WPJMGeo\RestController::places()`.

- [ ] **Step 3: Modifier `RestController`**

Dans `prepare_job()`, remplacer le bloc `$job = array( … );` par :

```php
		$location = self::plain( get_post_meta( $id, '_job_location', true ) );
		$city     = self::plain( get_post_meta( $id, 'geolocation_city', true ) );
		$job      = array(
			'id'        => (int) $id,
			'title'     => self::plain( get_the_title( $post ) ),
			'permalink' => esc_url_raw( get_permalink( $post ), array( 'http', 'https' ) ),
			'company'   => self::plain( function_exists( 'get_the_company_name' ) ? get_the_company_name( $post ) : '' ),
			'location'  => $location,
			'city'      => $city,
			'country'   => self::plain( get_post_meta( $id, 'geolocation_country_long', true ) ),
			'latitude'  => $lat,
			'longitude' => $lng,
			'job_type'  => implode( ', ', array_filter( $types ) ),
			'places'    => self::places( $id, $lat, $lng, '' !== $city ? $city : $location, Settings::PROVIDER_OSM === $this->settings->get_provider() ),
		);
```

Dans la boucle des types, remplacer `$types[] = $this->text( $type->name );` par `$types[] = self::plain( $type->name );`.

Remplacer la méthode `text()` (docblock compris) par :

```php
	/**
	 * Texte brut : le client n'insère ces valeurs que via textContent.
	 *
	 * @param mixed $value Valeur.
	 * @return string
	 */
	private static function plain( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Lieux de l'offre pour la carte : la liste du plugin (mode libre), sinon un
	 * seul lieu construit depuis les coordonnées de WP Job Manager.
	 *
	 * @param int    $id       ID de l'offre.
	 * @param float  $lat      Latitude du premier lieu.
	 * @param float  $lng      Longitude du premier lieu.
	 * @param string $label    Libellé du lieu unique (ville, ou texte du lieu).
	 * @param bool   $use_list Lire la liste du plugin (mode libre).
	 * @return list<array<string,string|float>>
	 */
	public static function places( $id, $lat, $lng, $label, $use_list ) {
		$places = array();
		$stored = $use_list ? get_post_meta( $id, GeocodingIntegration::META_PLACES, true ) : array();
		foreach ( is_array( $stored ) ? array_slice( $stored, 0, PlaceLocator::MAX_PLACES ) : array() as $place ) {
			$clean = self::clean_place( $place );
			if ( $clean ) {
				$places[] = $clean;
			}
		}
		if ( ! $places ) {
			$places[] = array(
				'type'  => 'city',
				'label' => (string) $label,
				'lat'   => (float) $lat,
				'lng'   => (float) $lng,
			);
		}
		return $places;
	}

	/**
	 * Lieu enregistré, revérifié : coordonnées bornées, région connue (nom officiel).
	 *
	 * @param mixed $place Élément de la métadonnée.
	 * @return array<string,string|float>|null
	 */
	private static function clean_place( $place ) {
		if ( ! is_array( $place ) || ! isset( $place['type'], $place['label'], $place['lat'], $place['lng'] ) || ! is_numeric( $place['lat'] ) || ! is_numeric( $place['lng'] ) ) {
			return null;
		}
		$lat = round( (float) $place['lat'], 6 );
		$lng = round( (float) $place['lng'], 6 );
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
			return null;
		}
		$clean = array(
			'type'  => 'city',
			'label' => self::plain( $place['label'] ),
			'lat'   => $lat,
			'lng'   => $lng,
		);
		if ( 'region' === $place['type'] ) {
			$region = isset( $place['region'] ) && is_scalar( $place['region'] ) ? Regions::get( (string) $place['region'] ) : null;
			if ( ! $region ) {
				return null;
			}
			$clean['type']   = 'region';
			$clean['label']  = $region['name'];
			$clean['region'] = $region['code'];
		}
		return $clean;
	}
```

(Vérifier avec `grep -n 'this->text' g2rd-wpjm-geo/includes/RestController.php` qu'il ne reste aucun appel à `$this->text(`.)

- [ ] **Step 4: Lancer les tests**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -7`
Expected : 4 contrôles `[OK]` en section 38, `Résultat : 320 réussis, 0 échoués`.

- [ ] **Step 5: Lint, analyse, commit**

```bash
composer run lint && composer run analyse
git add g2rd-wpjm-geo/includes/RestController.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat(api) : champ places (villes et régions) pour la carte"
```

---

### Task 9: Champ « Lieu » à étiquettes (`location-tags.js`)

**Files:**
- Create: `g2rd-wpjm-geo/assets/js/location-tags.js`
- Test: `g2rd-wpjm-geo/tests/js/run.js`

- [ ] **Step 1: Écrire les tests (ils échouent)**

Dans `g2rd-wpjm-geo/tests/js/run.js`, juste avant la ligne `(async () => {`, ajouter :

```js
const REGIONS = [
  { code: '11', name: 'Île-de-France', aliases: ['IDF', 'Région parisienne'] },
  { code: '28', name: 'Normandie', aliases: ['Haute-Normandie', 'Basse-Normandie'] },
  { code: '84', name: 'Auvergne-Rhône-Alpes', aliases: ['AURA', 'Auvergne', 'Rhône-Alpes'] },
  { code: '93', name: "Provence-Alpes-Côte d'Azur", aliases: ['PACA', 'Région Sud'] }
];
const TAGS_I18N = { remove: 'Retirer %s', added: 'Lieu ajouté : %s', removed: 'Lieu retiré : %s', duplicate: '%s figure déjà dans la liste.', limit: '3 lieux au plus.', required: 'Indiquez au moins un lieu.' };

// Champ à étiquettes seul (sans l'autocomplétion), plafond de 3 lieux.
function tagField({ value = '', required = false, max = 3 } = {}) {
  const dom = new JSDOM('<form><label for="job_location">Lieu</label><input type="text" id="job_location" name="job_location" placeholder="ex. Paris"' + (required ? ' required' : '') + ' value="' + value + '"></form>', { runScripts: 'outside-only' });
  const w = dom.window;
  w.eval(src('js/location-tags.js'));
  const d = w.document, native = d.getElementById('job_location');
  let changes = 0; native.addEventListener('change', () => changes++);
  const field = w.g2rdWpjmGeoTags.create(native, { regions: REGIONS, max, i18n: TAGS_I18N });
  return { w, d, native, field, input: field.input, tags: () => [...d.querySelectorAll('.g2rd-wpjm-geo-tags__tag')], live: () => d.querySelector('.g2rd-wpjm-geo-tags__live').textContent, changes: () => changes };
}
```

Section à insérer avant la ligne `console.log('\nResultat JS : …');` :

```js
  console.log('\nK. Champ Lieu a etiquettes (location-tags.js)');
  {
    const tw = new JSDOM('<p></p>', { runScripts: 'outside-only' }).window;
    tw.eval(src('js/location-tags.js'));
    const T = tw.g2rdWpjmGeoTags;
    check('decoupage : separateurs forts et « et », mentions vagues et doublons retires', 'Paris|Lyon|Caen' === T.split('Paris et Lyon / Télétravail ; paris ; Caen').join('|'));
    const idx = T.regionIndex(REGIONS);
    check('region exacte : nom, sigle, article, prefixe « Region » ; ville refusee', '11' === T.findRegion("l'Île-de-France", idx).code && '93' === T.findRegion('paca', idx).code && '28' === T.findRegion('Région Normandie', idx).code && null === T.findRegion('Rouen', idx));
    check('recherche : debut d un nom (variante « Region Sud » comprise), puis debut d un mot (3 lettres)', 'Normandie' === T.searchRegions('norm', idx, 6)[0].region.name && '93' === T.searchRegions('Région Sud', idx, 6)[0].region.code && 2 === T.searchRegions('alpes', idx, 6).length && 0 === T.searchRegions('al', idx, 6).length);
  }
  let f = tagField({});
  check('champ natif masque (attribut et style) mais envoye, saisie visible juste apres', f.native.hidden && 'none' === f.native.style.display && 'job_location' === f.native.name && f.native.nextElementSibling.classList.contains('g2rd-wpjm-geo-tags') && f.native.nextElementSibling.contains(f.input));
  check('libelle redirige vers la saisie, exemple repris, un seul champ nomme', f.d.querySelector('label').htmlFor === f.input.id && 'ex. Paris' === f.input.placeholder && 1 === f.d.querySelectorAll('input[name]').length);
  f.field.add('Île-de-France', true);
  check('ajout d une region : etiquette coloree, champ natif et evenement, annonce', 1 === f.tags().length && f.tags()[0].classList.contains('g2rd-wpjm-geo-tags__tag--region') && 'Île-de-France' === f.native.value && 1 === f.changes() && 'Lieu ajouté : Île-de-France' === f.live());
  f.field.add('Rouen, Normandie, France');
  const rouen = f.tags()[1];
  check('ajout d une ville : libelle complet garde, nom seul affiche', 'Île-de-France ; Rouen, Normandie, France' === f.native.value && 'Rouen' === rouen.querySelector('.g2rd-wpjm-geo-tags__text').textContent && 'Rouen, Normandie, France' === rouen.title && !rouen.classList.contains('g2rd-wpjm-geo-tags__tag--region'));
  check('doublon refuse (accents, casse) et annonce', false === f.field.add('rouen, normandie, france') && 2 === f.tags().length && 'rouen, normandie, france figure déjà dans la liste.' === f.live());
  const remove = f.tags()[0].querySelector('button');
  check('bouton de retrait nomme', 'Retirer Île-de-France' === remove.getAttribute('aria-label') && 'button' === remove.type);
  remove.click();
  check('retrait par le bouton : champ natif mis a jour, annonce', 'Rouen, Normandie, France' === f.native.value && 'Lieu retiré : Île-de-France' === f.live());
  key(f.w, f.input, 'Backspace');
  check('Retour arriere dans une saisie vide : derniere etiquette retiree', 0 === f.tags().length && '' === f.native.value);
  f.input.value = 'Normandie ; Lille / normandie'; f.input.dispatchEvent(new f.w.Event('input', { bubbles: true }));
  check('collage avec separateurs : plusieurs etiquettes, doublon ignore, region reconnue', 'Normandie ; Lille' === f.native.value && f.tags()[0].classList.contains('g2rd-wpjm-geo-tags__tag--region') && !f.tags()[1].classList.contains('g2rd-wpjm-geo-tags__tag--region') && '' === f.input.value);
  f.input.value = 'Brest'; f.field.commit();
  check('texte en cours valide : etiquette ajoutee, saisie videe', 'Normandie ; Lille ; Brest' === f.native.value && '' === f.input.value);
  f.input.value = 'Caen'; f.field.commit();
  const notice = f.d.querySelector('.g2rd-wpjm-geo-tags__notice');
  check('plafond : lieu refuse, message visible et annonce, texte garde', 3 === f.tags().length && !notice.hidden && '3 lieux au plus.' === notice.textContent && '3 lieux au plus.' === f.live() && 'Caen' === f.input.value);
  f.tags()[2].querySelector('button').click();
  check('retrait sous le plafond : message masque', notice.hidden);
  f = tagField({ value: '&lt;img src=x onerror=alert(1)&gt; ; Normandie ; Paris, Lyon' });
  check('reouverture : texte decoupe en etiquettes, region coloree, texte insere comme texte, aucun evenement', 3 === f.tags().length && 0 === f.d.querySelectorAll('.g2rd-wpjm-geo-tags img').length && f.tags()[1].classList.contains('g2rd-wpjm-geo-tags__tag--region') && 'Paris' === f.tags()[2].querySelector('.g2rd-wpjm-geo-tags__text').textContent && 0 === f.changes());
  f = tagField({ required: true });
  check('champ obligatoire : verifie sur la saisie des etiquettes', !f.native.required && !f.input.checkValidity() && 'Indiquez au moins un lieu.' === f.input.validationMessage);
  f.field.add('Brest');
  check('champ obligatoire : une etiquette suffit', f.input.checkValidity());
```

- [ ] **Step 2: Lancer les tests (échec attendu)**

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -3`
Expected : `ERREUR BANC DE TEST: Error: ENOENT … location-tags.js`.

- [ ] **Step 3: Écrire `location-tags.js`**

Créer `g2rd-wpjm-geo/assets/js/location-tags.js` :

```js
/**
 * Champ « Lieu » à étiquettes (mode OpenStreetMap) : plusieurs villes et régions
 * dans le champ natif de WP Job Manager, joints par « ; ». Le champ natif reste
 * celui qui est enregistré : il est masqué et mis à jour à chaque ajout ou retrait.
 * Fonctions de texte partagées avec l'autocomplétion : découpage (comme
 * PlaceParser::split() côté serveur) et recherche des régions (comme Regions::find()).
 */
( function ( window, document ) {
	'use strict';

	var SEPARATORS = /[;|\/\r\n]|\s+et\s+/i;
	var LIVE = /[;|\/\r\n]/;
	var EDGES = /^[\s,.:\-\u2013\u2014]+|[\s,.:\-\u2013\u2014]+$/g;
	var VAGUE = [ 'remote', 'full remote', 'teletravail', 'a distance', 'hybride' ];
	var ICON_CITY = '\uD83D\uDCCD';
	var ICON_REGION = '\uD83D\uDDFA\uFE0F';

	// Forme de comparaison de PhotonGeocoder::normalize() : sans accents, minuscules, ponctuation en espace.
	function normalize( text ) {
		var value = String( text || '' );
		if ( value.normalize ) {
			value = value.normalize( 'NFD' ).replace( /[\u0300-\u036f]/g, '' );
		}
		value = value.toLowerCase().replace( /[^a-z0-9]+/g, ' ' ).trim();
		return value.replace( /\bst\b/g, 'saint' ).replace( /\bste\b/g, 'sainte' );
	}

	function key( text ) {
		return normalize( text ).replace( /^(?:le|la|les|l) /, '' );
	}

	function split( text ) {
		var seen = {};
		var out = [];
		String( text || '' ).split( SEPARATORS ).forEach( function ( part ) {
			var piece = String( part || '' ).replace( EDGES, '' );
			var id = normalize( piece ) || piece.toLowerCase();
			if ( ! piece || VAGUE.indexOf( normalize( piece ) ) !== -1 || seen[ id ] ) {
				return;
			}
			seen[ id ] = true;
			out.push( piece );
		} );
		return out;
	}

	function regionIndex( regions ) {
		var entries = [];
		( Array.isArray( regions ) ? regions : [] ).forEach( function ( region ) {
			if ( ! region || 'string' !== typeof region.code || 'string' !== typeof region.name ) {
				return;
			}
			[ region.name ].concat( Array.isArray( region.aliases ) ? region.aliases : [] ).forEach( function ( name ) {
				if ( 'string' === typeof name && name ) {
					entries.push( { key: key( name ), name: name, region: region } );
				}
			} );
		} );
		return entries;
	}

	function findRegion( text, index ) {
		var wanted = key( text );
		var candidates = [ wanted, wanted.replace( /^region /, '' ) ];
		var found = null;
		if ( ! wanted ) {
			return null;
		}
		candidates.some( function ( candidate ) {
			index.some( function ( entry ) {
				if ( entry.key === candidate ) {
					found = entry.region;
					return true;
				}
				return false;
			} );
			return !! found;
		} );
		return found;
	}

	// Régions dont un nom commence par la saisie, puis dont un mot commence par elle (3 lettres au moins).
	// La saisie est comparée telle quelle (« Région Sud ») et sans le préfixe « Région » (« Région Bretagne »).
	function searchRegions( query, index, max ) {
		var full = key( query );
		var stripped = full.replace( /^region /, '' );
		var wanted = stripped && stripped !== full ? [ full, stripped ] : [ full ];
		var out = [];
		var seen = {};
		if ( ! full ) {
			return out;
		}
		[ 0, 1 ].forEach( function ( pass ) {
			index.forEach( function ( entry ) {
				if ( out.length >= max || seen[ entry.region.code ] ) {
					return;
				}
				var hit = wanted.some( function ( text ) {
					return 0 === pass ? 0 === entry.key.indexOf( text ) : text.length >= 3 && ( ' ' + entry.key ).indexOf( ' ' + text ) > 0;
				} );
				if ( hit ) {
					seen[ entry.region.code ] = true;
					out.push( { region: entry.region, matched: entry.name } );
				}
			} );
		} );
		return out;
	}

	function format( template, value ) {
		return String( template || '%s' ).replace( '%s', value );
	}

	/**
	 * Remplace à l'écran le champ natif par une zone à étiquettes.
	 *
	 * @return {{root: Element, input: HTMLInputElement, add: Function, commit: Function, values: Function}}
	 */
	function create( native, options ) {
		var opts = options || {};
		var i18n = opts.i18n || {};
		var max = opts.max || 10;
		var index = regionIndex( opts.regions );
		var required = !! native.required;
		var tags = [];

		var root = document.createElement( 'div' );
		root.className = 'g2rd-wpjm-geo-tags';
		var list = document.createElement( 'ul' );
		list.className = 'g2rd-wpjm-geo-tags__list';
		list.hidden = true;
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'g2rd-wpjm-geo-tags__input';
		input.id = ( native.id || 'g2rd-wpjm-geo-location' ) + '-g2rd-tags';
		input.setAttribute( 'autocomplete', 'off' );
		if ( native.placeholder ) {
			input.placeholder = native.placeholder;
		}
		var notice = document.createElement( 'p' );
		notice.className = 'g2rd-wpjm-geo-tags__notice';
		notice.hidden = true;
		var live = document.createElement( 'p' );
		live.className = 'g2rd-wpjm-geo-tags__live';
		live.setAttribute( 'aria-live', 'polite' );
		root.appendChild( list );
		root.appendChild( input );
		root.appendChild( notice );
		root.appendChild( live );

		native.parentNode.insertBefore( root, native.nextSibling );
		native.setAttribute( 'data-g2rd-wpjm-geo-tags', '1' );
		// Attribut et style : un thème qui impose display sur les champs texte ne le réaffiche pas. La valeur part toujours avec le formulaire.
		native.hidden = true;
		native.style.display = 'none';
		native.required = false;
		var label = null;
		try {
			label = native.id ? document.querySelector( 'label[for="' + native.id + '"]' ) : null;
		} catch ( e ) {
			label = null;
		}
		if ( label ) {
			label.htmlFor = input.id;
		}

		function announce( message ) {
			live.textContent = message || '';
		}

		function validate() {
			if ( required ) {
				input.setCustomValidity( tags.length ? '' : ( i18n.required || ' ' ) );
			}
		}

		function sync() {
			native.value = tags.map( function ( tag ) {
				return tag.text;
			} ).join( ' ; ' );
			native.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			native.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			validate();
		}

		function removeAt( position ) {
			var tag = tags.splice( position, 1 )[ 0 ];
			if ( ! tag ) {
				return;
			}
			notice.hidden = true;
			render();
			sync();
			announce( format( i18n.removed, tag.short ) );
		}

		function render() {
			list.textContent = '';
			tags.forEach( function ( tag, position ) {
				var item = document.createElement( 'li' );
				item.className = 'g2rd-wpjm-geo-tags__tag' + ( tag.region ? ' g2rd-wpjm-geo-tags__tag--region' : '' );
				item.title = tag.text;
				var icon = document.createElement( 'span' );
				icon.className = 'g2rd-wpjm-geo-tags__icon';
				icon.setAttribute( 'aria-hidden', 'true' );
				icon.textContent = tag.region ? ICON_REGION : ICON_CITY;
				var text = document.createElement( 'span' );
				text.className = 'g2rd-wpjm-geo-tags__text';
				text.textContent = tag.short;
				var remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'g2rd-wpjm-geo-tags__remove';
				remove.setAttribute( 'aria-label', format( i18n.remove, tag.short ) );
				remove.textContent = '\u2715';
				remove.addEventListener( 'click', function () {
					removeAt( position );
					input.focus();
				} );
				item.appendChild( icon );
				item.appendChild( text );
				item.appendChild( remove );
				list.appendChild( item );
			} );
			list.hidden = ! tags.length;
		}

		// Ajoute un lieu ; isRegion force la couleur des régions (proposition choisie).
		function add( text, isRegion, silent ) {
			var value = String( text || '' ).trim();
			if ( ! value ) {
				return false;
			}
			var id = normalize( value ) || value.toLowerCase();
			if ( tags.some( function ( tag ) {
				return tag.id === id;
			} ) ) {
				if ( ! silent ) {
					announce( format( i18n.duplicate, value ) );
				}
				return false;
			}
			if ( tags.length >= max ) {
				notice.textContent = i18n.limit || '';
				notice.hidden = false;
				announce( i18n.limit );
				return false;
			}
			var short = value.split( ',' )[ 0 ].trim() || value;
			tags.push( { text: value, short: short, id: id, region: !! ( isRegion || findRegion( value, index ) ) } );
			render();
			if ( ! silent ) {
				sync();
				announce( format( i18n.added, short ) );
			}
			return true;
		}

		// Texte en cours → étiquettes (Entrée, sortie du champ, séparateur tapé ou collé).
		function commit() {
			var rest = [];
			split( input.value ).forEach( function ( piece ) {
				if ( ! add( piece, false, false ) && tags.length >= max ) {
					rest.push( piece );
				}
			} );
			input.value = rest.join( ' ; ' );
		}

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Backspace' === event.key && '' === input.value && tags.length ) {
				removeAt( tags.length - 1 );
			}
		} );
		input.addEventListener( 'input', function () {
			if ( LIVE.test( input.value ) ) {
				commit();
			}
		} );

		split( native.value ).forEach( function ( piece ) {
			add( piece, false, true );
		} );
		validate();

		return {
			root: root,
			input: input,
			add: add,
			commit: commit,
			values: function () {
				return tags.map( function ( tag ) {
					return tag.text;
				} );
			}
		};
	}

	window.g2rdWpjmGeoTags = {
		create: create,
		split: split,
		normalize: normalize,
		regionIndex: regionIndex,
		findRegion: findRegion,
		searchRegions: searchRegions,
		icons: { city: ICON_CITY, region: ICON_REGION }
	};
}( window, document ) );
```

- [ ] **Step 4: Lancer les tests**

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1`
Expected : 18 contrôles `[OK]` en section K, `Resultat JS : 117 reussis, 0 echoues`.

- [ ] **Step 5: Commit**

```bash
git add g2rd-wpjm-geo/assets/js/location-tags.js g2rd-wpjm-geo/tests/js/run.js
git commit -m "feat(saisie) : champ Lieu à étiquettes (script autonome)"
```

---

### Task 10: Autocomplétion à étiquettes (navigateur et configuration)

**Files:**
- Modify: `g2rd-wpjm-geo/assets/js/location-autocomplete.js`
- Modify: `g2rd-wpjm-geo/includes/GoogleAssets.php`
- Modify: `g2rd-wpjm-geo/includes/LocationAutocomplete.php`
- Modify: `g2rd-wpjm-geo/includes/AdminLocation.php`
- Modify: `g2rd-wpjm-geo/assets/css/frontend.css`
- Test: `g2rd-wpjm-geo/tests/run.php` (section 27 ajustée, nouvelle section 39), `g2rd-wpjm-geo/tests/js/run.js` (section K)

- [ ] **Step 1: Ajuster la section 27, écrire les tests PHP et JS (ils échouent)**

Dans `run.php`, remplacer :

```php
check( 'mode libre : autocomplétion sans dépendance au chargeur Google', array() === G2RD_Test_State::$enqueued['register:g2rd-wpjm-geo-autocomplete'] );
```

par :

```php
check( 'mode libre : autocomplétion sans dépendance au chargeur Google, avec le script des étiquettes', array( GoogleAssets::HANDLE_TAGS ) === G2RD_Test_State::$enqueued['register:g2rd-wpjm-geo-autocomplete'] );
```

En tête du fichier, après `use G2RD\WPJMGeo\AdminScreen;`, ajouter :

```php
use G2RD\WPJMGeo\AdminLocation;
```

Section à insérer avant `$results = …` :

```php
echo PHP_EOL . '39. Autocomplétion à étiquettes : configuration' . PHP_EOL;
list( $s ) = boot( array( 'provider' => Settings::PROVIDER_OSM ) );
( new GoogleAssets( $s ) )->register_assets();
check( 'script des étiquettes enregistré, sans dépendance', array() === G2RD_Test_State::$enqueued[ 'register:' . GoogleAssets::HANDLE_TAGS ] );
( new LocationAutocomplete( $s ) )->enqueue();
list( , $c ) = g2rd_ac_config();
check( 'mode libre : étiquettes, plafond, 18 régions, textes', true === $c['tags'] && 10 === $c['maxPlaces'] && 18 === count( $c['regions'] ) && 'Normandie' === $c['regions'][8]['name'] && 'Région' === $c['i18n']['region'] && false !== strpos( $c['tagsI18n']['remove'], '%s' ) && '10 lieux au plus.' === $c['tagsI18n']['limit'] && '' !== $c['tagsI18n']['required'] );
$fields = ( new LocationAutocomplete( $s ) )->prepare_field( array( 'job' => array( 'job_location' => array( 'description' => 'Laissez vide si le lieu importe peu.' ) ) ) );
check( 'sans JavaScript : aide « ; » ajoutée à la description du champ', 0 === strpos( $fields['job']['job_location']['description'], 'Laissez vide' ) && false !== strpos( $fields['job']['job_location']['description'], '« ; »' ) );
$admin = ( new AdminLocation( $s ) )->admin_help( array( '_job_location' => array( 'label' => 'Lieu', 'description' => '' ) ) );
check( 'administration : aide « ; » dans l’infobulle du champ', false !== strpos( $admin['_job_location']['description'], '« ; »' ) );
list( $s ) = boot( array( 'provider' => Settings::PROVIDER_GOOGLE, 'browser_api_key' => 'BROWSER' ) );
( new LocationAutocomplete( $s ) )->enqueue();
list( , $c ) = g2rd_ac_config();
check( 'mode Google : ni étiquettes ni régions', ! isset( $c['tags'] ) && ! isset( $c['regions'] ) && ! isset( $c['tagsI18n'] ) );
$fields = ( new LocationAutocomplete( $s ) )->prepare_field( array( 'job' => array( 'job_location' => array( 'description' => 'D' ) ) ) );
check( 'mode Google : description inchangée', 'D' === $fields['job']['job_location']['description'] );
```

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected : erreur fatale `Undefined constant G2RD\WPJMGeo\GoogleAssets::HANDLE_TAGS`.

Dans `g2rd-wpjm-geo/tests/js/run.js`, juste après la fonction `tagField()` (tâche 9), ajouter :

```js
// Autocomplétion Photon en mode étiquettes, comme le PHP la configure en mode libre.
async function tagsAc({ features = null } = {}) {
  const dom = new JSDOM('<form id="f"><label for="job_location">Lieu</label><input type="text" id="job_location" name="job_location" placeholder="ex. Paris"><button id="go">Envoyer</button></form>', { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window, calls = [];
  w.g2rdWpjmGeoAutocomplete = { source: 'photon', photonUrl: 'https://photon.test/api/', lang: 'fr', selectors: ['#job_location'], minLength: 2, tags: true, maxPlaces: 10, regions: REGIONS, tagsI18n: TAGS_I18N,
    i18n: { poweredBy: 'Recherche (c) OpenStreetMap contributors', results: 'Suggestions', region: 'Région' } };
  w.AbortController = class { constructor() { this.signal = { aborted: false }; } abort() { this.signal.aborted = true; } };
  const feature = (name, county, state) => ({ properties: { name, county, state, country: 'France', osm_key: 'place' } });
  w.fetch = url => {
    calls.push(url);
    const q = new URL(url).searchParams.get('q').toLowerCase();
    const list = features || (q.indexOf('ro') === 0 ? [feature('Rouen', 'Seine-Maritime', 'Normandie'), feature('Roubaix', 'Nord', 'Hauts-de-France')] : []);
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ features: list }) });
  };
  w.eval(src('js/location-tags.js'));
  w.eval(src('js/location-autocomplete.js'));
  const d = w.document, native = d.getElementById('job_location');
  const input = d.querySelector('.g2rd-wpjm-geo-tags__input');
  if (input) { input.focus(); await wait(10); }
  return { w, d, native, input, calls, panel: d.querySelector('.g2rd-wpjm-geo-ac'), tags: () => [...d.querySelectorAll('.g2rd-wpjm-geo-tags__tag')], options: () => [...d.querySelectorAll('.g2rd-wpjm-geo-ac [role=option]')] };
}
```

Section à insérer avant la ligne `console.log('\nResultat JS : …');` :

```js
  console.log('\nL. Autocompletion a etiquettes (mode libre)');
  let g = await tagsAc({});
  check('etiquettes creees par l autocompletion, combobox sur la saisie visible', !!g.input && 'combobox' === g.input.getAttribute('role') && !g.native.hasAttribute('role') && g.native.hidden);
  await type(g, 'idf');
  check('sigle : region proposee en tete, avec le nom saisi et une icone', /Île-de-France/.test(g.options()[0].textContent) && /Région \(IDF\)/.test(g.options()[0].textContent) && !!g.options()[0].querySelector('.g2rd-wpjm-geo-ac__icon'));
  key(g.w, g.input, 'ArrowDown'); key(g.w, g.input, 'Enter'); await wait(10);
  check('choix au clavier : etiquette de region, saisie videe, liste fermee', 1 === g.tags().length && g.tags()[0].classList.contains('g2rd-wpjm-geo-tags__tag--region') && '' === g.input.value && 'Île-de-France' === g.native.value && g.panel.hidden);
  await type(g, 'Rou');
  check('villes Photon proposees', 2 === g.options().length && /Rouen/.test(g.options()[0].textContent));
  g.options()[0].dispatchEvent(new g.w.MouseEvent('mousedown', { bubbles: true, cancelable: true })); await wait(10);
  check('choix a la souris : ville avec son libelle complet', 'Île-de-France ; Rouen, Normandie, France' === g.native.value && '' === g.input.value);
  g.input.value = 'Brest';
  const enterFree = key(g.w, g.input, 'Enter');
  check('Entree sans proposition : texte libre ajoute, formulaire non envoye', enterFree.defaultPrevented && 'Île-de-France ; Rouen, Normandie, France ; Brest' === g.native.value);
  g.input.value = 'Le Havre'; g.input.blur(); await wait(10);
  check('sortie du champ : texte en cours ajoute', / ; Le Havre$/.test(g.native.value));
  g = await tagsAc({});
  await type(g, 'paca');
  check('PACA : Provence-Alpes-Cote d Azur', /Provence-Alpes-Côte d'Azur/.test(g.options()[0].textContent));
  await type(g, 'Région Sud');
  check('« Region Sud » : Provence-Alpes-Cote d Azur', /Provence-Alpes-Côte d'Azur/.test(g.options()[0].textContent));
  await type(g, 'alpes');
  check('mot du nom (alpes) : deux regions', 2 === g.options().filter(o => /Région/.test(o.textContent)).length);
  g = await tagsAc({ features: [] });
  g.w.fetch = () => Promise.reject(new Error('reseau'));
  await type(g, 'norm');
  check('Photon injoignable : les regions restent proposees', 1 === g.options().length && /Normandie/.test(g.options()[0].textContent));
```

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -3`
Expected : `[FAIL]` sur le premier contrôle de la section L, puis `ERREUR BANC DE TEST` (aucune saisie à étiquettes créée par l'autocomplétion).

- [ ] **Step 2: Enregistrer le script des étiquettes**

Dans `GoogleAssets.php`, après `const HANDLE_COMMON       = 'g2rd-wpjm-geo-map-common';`, ajouter :

```php
	const HANDLE_TAGS         = 'g2rd-wpjm-geo-location-tags';
```

Dans `register_assets()`, remplacer :

```php
		// En mode libre, le chargeur Google (et la clé navigateur) n'est jamais imprimé dans la page.
		$autocomplete_deps = Settings::PROVIDER_OSM === $this->settings->get_provider() ? array() : array( self::HANDLE_LOADER );
```

par :

```php
		wp_register_script( self::HANDLE_TAGS, $base . 'js/location-tags.js', array(), $version, true );
		// En mode libre, le chargeur Google (et la clé navigateur) n'est jamais imprimé dans la page ; le champ passe en étiquettes.
		$autocomplete_deps = Settings::PROVIDER_OSM === $this->settings->get_provider() ? array( self::HANDLE_TAGS ) : array( self::HANDLE_LOADER );
```

- [ ] **Step 3: Configuration et aide sans JavaScript**

Dans `LocationAutocomplete.php`, remplacer `prepare_field()` par :

```php
	public function prepare_field( $fields ) {
		$this->has_location_field = isset( $fields['job']['job_location'] );
		if ( $this->has_location_field && ( Settings::PROVIDER_OSM === $this->settings->get_provider() || GoogleAssets::has_key( $this->settings ) ) ) {
			$fields['job']['job_location']['autocomplete'] = false;
		}
		if ( $this->has_location_field && Settings::PROVIDER_OSM === $this->settings->get_provider() ) {
			$fields['job']['job_location']['description'] = self::with_help( isset( $fields['job']['job_location']['description'] ) ? $fields['job']['job_location']['description'] : '' );
		}
		return $fields;
	}

	/**
	 * Description du champ complétée par l'aide de saisie de plusieurs lieux (utile sans JavaScript).
	 *
	 * @param mixed $description Description existante.
	 * @return string
	 */
	protected static function with_help( $description ) {
		return trim( ( is_string( $description ) ? $description : '' ) . ' ' . __( 'Séparez plusieurs lieux par « ; ».', 'g2rd-wpjm-geo' ) );
	}
```

Dans `enqueue_autocomplete()`, juste après le tableau `$config = array( … );`, ajouter :

```php
		if ( $is_osm ) {
			// Plusieurs lieux (villes et régions françaises) en étiquettes, joints par « ; » dans le champ natif.
			$config['tags']            = true;
			$config['maxPlaces']       = PlaceLocator::MAX_PLACES;
			$config['regions']         = Regions::all();
			$config['i18n']['results'] = __( 'Suggestions de villes et de régions', 'g2rd-wpjm-geo' );
			$config['i18n']['region']  = __( 'Région', 'g2rd-wpjm-geo' );
			$config['tagsI18n']        = array(
				/* translators: %s: place name. */
				'remove'    => __( 'Retirer %s', 'g2rd-wpjm-geo' ),
				/* translators: %s: place name. */
				'added'     => __( 'Lieu ajouté : %s', 'g2rd-wpjm-geo' ),
				/* translators: %s: place name. */
				'removed'   => __( 'Lieu retiré : %s', 'g2rd-wpjm-geo' ),
				/* translators: %s: place name. */
				'duplicate' => __( '%s figure déjà dans la liste.', 'g2rd-wpjm-geo' ),
				/* translators: %d: maximum number of places. */
				'limit'     => sprintf( __( '%d lieux au plus.', 'g2rd-wpjm-geo' ), PlaceLocator::MAX_PLACES ),
				'required'  => __( 'Indiquez au moins un lieu.', 'g2rd-wpjm-geo' ),
			);
		}
```

Dans `AdminLocation.php`, remplacer `register()` par :

```php
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ), 20 );
		add_filter( 'job_manager_job_listing_data_fields', array( $this, 'admin_help' ), 99 );
	}

	/**
	 * Mode libre : aide de saisie de plusieurs lieux dans l'infobulle du champ (utile sans JavaScript).
	 *
	 * @param mixed $fields Champs de la boîte « Données de l'offre ».
	 * @return mixed
	 */
	public function admin_help( $fields ) {
		if ( is_array( $fields ) && isset( $fields['_job_location'] ) && is_array( $fields['_job_location'] ) && Settings::PROVIDER_OSM === $this->settings->get_provider() ) {
			$fields['_job_location']['description'] = self::with_help( isset( $fields['_job_location']['description'] ) ? $fields['_job_location']['description'] : '' );
		}
		return $fields;
	}
```

- [ ] **Step 4: Brancher les étiquettes dans l'autocomplétion**

Dans `location-autocomplete.js` :

1. Après `var PHOTON_TAGS = [ … ];`, ajouter :

```js
	// Mode étiquettes (configuration du plugin en mode libre) : plusieurs lieux, régions proposées sans réseau.
	var T = 'photon' === source && settings.tags ? window.g2rdWpjmGeoTags : null;
	var regionIndex = T ? T.regionIndex( settings.regions ) : [];
	var MAX_SUGGESTIONS = 6;
```

2. Dans `findInput()`, remplacer :

```js
			if ( node && 'INPUT' === node.tagName && ! node.hasAttribute( 'data-g2rd-wpjm-geo-ac' ) ) {
```

par :

```js
			if ( node && 'INPUT' === node.tagName && ! node.hasAttribute( 'data-g2rd-wpjm-geo-ac' ) && ! node.hasAttribute( 'data-g2rd-wpjm-geo-tags' ) ) {
```

3. Après la fonction `fromPhoton()`, ajouter :

```js
	function fromRegions( value ) {
		return T.searchRegions( value, regionIndex, MAX_SUGGESTIONS ).map( function ( hit ) {
			var kind = i18n.region || '';
			return {
				label: hit.region.name,
				main: hit.region.name,
				secondary: hit.matched !== hit.region.name ? kind + ' (' + hit.matched + ')' : kind,
				region: hit.region.code
			};
		} );
	}
```

4. Remplacer la signature `function attach( input ) {` par `function attach( input, field ) {`.

5. Dans `choose()`, juste après `if ( ! item ) { return; }`, ajouter :

```js
			if ( field ) {
				// Mode étiquettes : le lieu devient une étiquette, la saisie se vide pour le suivant.
				field.add( item.label, !! item.region );
				input.value = '';
				close();
				return;
			}
```

6. Dans `render()`, remplacer le début du bloc `if ( suggestion.main ) {` :

```js
				if ( suggestion.main ) {
					var strong = document.createElement( 'span' );
```

par :

```js
				if ( field ) {
					var icon = document.createElement( 'span' );
					icon.className = 'g2rd-wpjm-geo-ac__icon';
					icon.setAttribute( 'aria-hidden', 'true' );
					icon.textContent = suggestion.region ? T.icons.region : T.icons.city;
					option.appendChild( icon );
				}
				if ( suggestion.main ) {
					var strong = document.createElement( 'span' );
```

et remplacer `items.push( { label: suggestion.label } );` par :

```js
				items.push( { label: suggestion.label, region: suggestion.region || '' } );
```

7. Remplacer toute la fonction `search()` par :

```js
		function search() {
			var value = input.value.trim();
			// Régions françaises (mode étiquettes) : liste locale, sans appel réseau.
			var local = field ? fromRegions( value ) : [];
			if ( value.length < MIN_LENGTH || ( disabled && ! local.length ) ) {
				close();
				return;
			}
			var current = ++requestId;
			var pending;
			if ( 'photon' === source ) {
				pending = disabled ? Promise.resolve( [] ) : photonSearch( value );
			} else {
				pending = ensurePlaces().then( function ( library ) {
					return fetchSuggestions( library, value );
				} ).then( function ( result ) {
					return fromGoogle( result && result.suggestions );
				} );
			}

			pending.then( function ( suggestions ) {
				// Ignore les réponses obsolètes (l'utilisateur a continué à taper).
				if ( current === requestId && document.activeElement === input ) {
					render( field ? local.concat( suggestions ).slice( 0, MAX_SUGGESTIONS ) : suggestions );
				}
			} ).catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) {
					return; // Requête remplacée par une frappe plus récente.
				}
				// Service indisponible ou clé refusée : le champ redevient un simple champ texte (les régions restent proposées).
				disabled = true;
				if ( local.length && current === requestId && document.activeElement === input ) {
					render( local );
				} else {
					close();
				}
			} );
		}
```

8. Dans le gestionnaire `keydown`, remplacer :

```js
		input.addEventListener( 'keydown', function ( event ) {
			if ( panel.hidden ) {
				return;
			}
```

par :

```js
		input.addEventListener( 'keydown', function ( event ) {
			if ( panel.hidden ) {
				if ( field && 'Enter' === event.key ) {
					event.preventDefault(); // Entrée valide le texte en cours sans envoyer le formulaire.
					field.commit();
				}
				return;
			}
```

et remplacer le cas `Enter` :

```js
				case 'Enter':
					if ( active >= 0 ) {
						event.preventDefault(); // Ne soumet pas le formulaire pendant le choix d'une ville.
						choose( active );
					}
					break;
```

par :

```js
				case 'Enter':
					if ( active >= 0 ) {
						event.preventDefault(); // Ne soumet pas le formulaire pendant le choix d'une ville.
						choose( active );
					} else if ( field ) {
						event.preventDefault();
						close();
						field.commit();
					}
					break;
```

9. Dans le gestionnaire `blur`, après `close();`, ajouter :

```js
			if ( field ) {
				field.commit(); // Un texte laissé dans la saisie devient une étiquette.
			}
```

10. Dans `init()`, remplacer :

```js
		var input = findInput();
		if ( input ) {
			attach( input );
		}
```

par :

```js
		var input = findInput();
		if ( ! input ) {
			return;
		}
		if ( T ) {
			var field = T.create( input, { regions: settings.regions, max: settings.maxPlaces || 10, i18n: settings.tagsI18n || {} } );
			attach( field.input, field );
			return;
		}
		attach( input, null );
```

- [ ] **Step 5: Styles des étiquettes**

À la fin de `g2rd-wpjm-geo/assets/css/frontend.css`, ajouter :

```css

/* ---------- Champ « Lieu » à étiquettes (mode libre) ---------- */
.g2rd-wpjm-geo-tags {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	align-items: center;
}

.g2rd-wpjm-geo-tags__list {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.g2rd-wpjm-geo-tags__list[hidden],
.g2rd-wpjm-geo-tags__notice[hidden] {
	display: none;
}

.g2rd-wpjm-geo-tags__tag {
	display: inline-flex;
	gap: 4px;
	align-items: center;
	margin: 0;
	padding: 2px 4px 2px 10px;
	color: #1d2327;
	font: 400 13px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif;
	background: #e3f2fd;
	border: 1px solid #90caf9;
	border-radius: 14px;
}

.g2rd-wpjm-geo-tags__tag--region {
	background: #fff3e0;
	border-color: #ffb74d;
}

.g2rd-wpjm-geo-tags__remove {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 22px;
	height: 22px;
	margin: 0;
	padding: 0;
	color: #50575e;
	font: inherit;
	line-height: 1;
	background: transparent;
	border: 0;
	border-radius: 50%;
	cursor: pointer;
}

.g2rd-wpjm-geo-tags__remove:hover,
.g2rd-wpjm-geo-tags__remove:focus-visible {
	color: #1d2327;
	background: rgba(0, 0, 0, 0.08);
}

.g2rd-wpjm-geo-tags__input {
	flex: 1 1 12em;
	min-width: 8em;
}

.g2rd-wpjm-geo-tags__notice {
	flex-basis: 100%;
	margin: 0;
	color: #a93226;
	font-size: 13px;
}

.g2rd-wpjm-geo-tags__live {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}

.g2rd-wpjm-geo-ac__icon {
	margin-right: 6px;
}
```

- [ ] **Step 6: Lancer tous les tests**

```bash
php g2rd-wpjm-geo/tests/run.php | tail -9
NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1
```

Expected : 6 contrôles `[OK]` en section 39, `Résultat : 326 réussis, 0 échoués` ; `Resultat JS : 128 reussis, 0 echoues` (sections A à K inchangées, 11 contrôles en section L).

- [ ] **Step 7: Lint, analyse, commit**

```bash
composer run lint && composer run analyse
git add g2rd-wpjm-geo/assets/js/location-autocomplete.js g2rd-wpjm-geo/includes/GoogleAssets.php g2rd-wpjm-geo/includes/LocationAutocomplete.php g2rd-wpjm-geo/includes/AdminLocation.php g2rd-wpjm-geo/assets/css/frontend.css g2rd-wpjm-geo/tests/run.php g2rd-wpjm-geo/tests/js/run.js
git commit -m "feat(saisie) : autocomplétion à étiquettes, régions proposées sans réseau"
```

---

### Task 11: Carte — lieux multiples dans les fonctions communes

**Files:**
- Modify: `g2rd-wpjm-geo/assets/js/map-common.js`
- Test: `g2rd-wpjm-geo/tests/js/run.js` (section H)

- [ ] **Step 1: Écrire les tests (ils échouent)**

Dans la section H de `run.js`, remplacer le contrôle :

```js
    check('API exposee : un seul objet global, 10 fonctions', 'parseJson,sprintf,safeUrl,placeLabel,buildInfoContent,groupByCoordinates,createFetcher,observe,status,places' === Object.keys(C).join(','));
```

par :

```js
    check('API exposee : un seul objet global, 14 fonctions', 'parseJson,sprintf,safeUrl,placeLabel,buildInfoContent,buildRegionContent,regionTitle,jobCount,groupByCoordinates,splitPlaces,createFetcher,observe,status,places' === Object.keys(C).join(','));
```

Puis, section à insérer avant la ligne `console.log('\nResultat JS : …');` :

```js
  console.log('\nM. Lieux multiples (map-common.js)');
  {
    const lw = new JSDOM('<p></p>', { runScripts: 'outside-only', url: 'https://x.test/' }).window;
    lw.eval(src('js/map-common.js'));
    const C = lw.g2rdWpjmGeoMapCommon;
    const mi = { view: 'Voir', alsoAt: 'Aussi à :', regionTitle: '%s (toute la région)', oneJob: '1 offre', manyJobsShort: '%d offres' };
    const sp = C.splitPlaces([
      { id: 1, title: 'A', city: 'Paris', country: 'France', latitude: 48.85, longitude: 2.35, places: [{ type: 'city', label: 'Paris', lat: 48.85, lng: 2.35 }, { type: 'city', label: 'Lyon', lat: 45.76, lng: 4.83 }, { type: 'region', label: 'Normandie', lat: 49.12, lng: 0.11, region: '28' }] },
      { id: 2, title: '<img src=x onerror=alert(1)>', city: '', country: 'France', latitude: 49.12, longitude: 0.11, places: [{ type: 'region', label: 'Normandie', lat: 49.12, lng: 0.11, region: '28' }] },
      { id: 3, title: 'C', city: 'Rouen', country: 'France', latitude: 49.44, longitude: 1.09 },
      { id: 4, title: 'D', places: [{ type: 'region', label: 'Bretagne', lat: 'x', lng: 0, region: '53' }, { type: 'pays', label: 'France', lat: 46, lng: 2 }] }
    ]);
    check('un point par ville, une entree par region, offre sans liste gardee, lieux invalides ignores', 3 === sp.points.length && 1 === sp.regions.length && 2 === sp.regions[0].jobs.length && '28' === sp.regions[0].code && 49.12 === sp.regions[0].position.lat);
    check('point de Lyon : coordonnees et libelle du lieu, autres lieux de l offre', 45.76 === sp.points[1].latitude && 'Lyon' === sp.points[1].city && 'Paris, Normandie' === sp.points[1]._also.join(', '));
    check('offre sans liste : point d origine, aucun autre lieu', 49.44 === sp.points[2].latitude && 0 === sp.points[2]._also.length);
    const node = C.buildInfoContent([sp.points[1]], mi);
    check('fenetre : ligne « Aussi a » en texte', /Aussi à : Paris, Normandie/.test(node.textContent) && !!node.querySelector('.g2rd-wpjm-geo-iw__also'));
    const rnode = C.buildRegionContent(sp.regions[0], mi);
    check('fenetre de region : titre, nombre d offres, liste, titre malveillant en texte', /Normandie \(toute la région\) — 2 offres/.test(rnode.textContent) && 2 === rnode.querySelectorAll('li').length && 0 === rnode.querySelectorAll('img').length);
    check('nombre d offres : singulier et pluriel', '1 offre' === C.jobCount(1, mi) && '3 offres' === C.jobCount(3, mi));
    const box = lw.document.createElement('div');
    lw.document.body.appendChild(box);
    C.places(box, C.groupByCoordinates(sp.points), { places: 'Liste', alsoAt: 'Aussi à :', regionTitle: '%s (toute la région)', oneJob: '1 offre', manyJobsShort: '%d offres' }, sp.regions);
    check('liste sous la carte : regions comprises', /Normandie \(toute la région\) — 2 offres/.test(box.nextElementSibling.textContent));
  }
```

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | grep -E "FAIL|ERREUR|Resultat"`
Expected : `[FAIL]` sur « API exposee… » puis `ERREUR BANC DE TEST: TypeError: C.splitPlaces is not a function`.

- [ ] **Step 2: Modifier `map-common.js`**

Dans le docblock de tête, remplacer :

```js
 * Fonctions partagées par les moteurs de carte (Google et MapLibre) :
 * lecture paginée de l'endpoint REST, regroupement des offres aux coordonnées
 * identiques, contenu des fenêtres (DOM + textContent, jamais de HTML) et
 * démarrage paresseux à l'approche de la zone visible.
```

par :

```js
 * Fonctions partagées par les moteurs de carte (Google et MapLibre) :
 * lecture paginée de l'endpoint REST, lieux multiples (un point par ville, une
 * entrée par région), regroupement des offres aux coordonnées identiques,
 * contenu des fenêtres (DOM + textContent, jamais de HTML) et démarrage
 * paresseux à l'approche de la zone visible.
```

Dans `buildJobNode()`, remplacer :

```js
		addLine( wrapper, 'g2rd-wpjm-geo-iw__company', job.company );
		if ( ! compact ) {
			addLine( wrapper, 'g2rd-wpjm-geo-iw__place', placeLabel( job ) );
		}
		addLine( wrapper, 'g2rd-wpjm-geo-iw__type', job.job_type );
```

par :

```js
		addLine( wrapper, 'g2rd-wpjm-geo-iw__company', job.company );
		if ( ! compact ) {
			addLine( wrapper, 'g2rd-wpjm-geo-iw__place', placeLabel( job ) );
		}
		if ( Array.isArray( job._also ) && job._also.length ) {
			addLine( wrapper, 'g2rd-wpjm-geo-iw__also', ( i18n.alsoAt || '' ) + ' ' + job._also.join( ', ' ) );
		}
		addLine( wrapper, 'g2rd-wpjm-geo-iw__type', job.job_type );
```

Après la fonction `buildInfoContent()`, ajouter :

```js
	function jobCount( count, i18n ) {
		return 1 === count ? String( i18n.oneJob || '1' ) : sprintf( i18n.manyJobsShort, count );
	}

	function regionTitle( region, i18n ) {
		return String( i18n.regionTitle || '%s' ).replace( '%s', region.label || '' ) + ' — ' + jobCount( region.jobs.length, i18n );
	}

	function buildRegionContent( region, i18n ) {
		var root = document.createElement( 'div' );
		root.className = 'g2rd-wpjm-geo-iw g2rd-wpjm-geo-iw--region';
		addLine( root, 'g2rd-wpjm-geo-iw__heading', regionTitle( region, i18n ) );
		var list = document.createElement( 'ul' );
		list.className = 'g2rd-wpjm-geo-iw__list';
		region.jobs.forEach( function ( job ) {
			list.appendChild( buildJobNode( job, i18n, true ) );
		} );
		root.appendChild( list );
		return root;
	}

	// Lieux d'une offre (champ places de l'API) ; sans liste, l'offre garde son point unique.
	function placesOf( job ) {
		var list = Array.isArray( job.places ) ? job.places.filter( function ( place ) {
			return place && ( 'city' === place.type || ( 'region' === place.type && 'string' === typeof place.region && place.region ) );
		} ) : [];
		return list.length ? list : [ { type: 'city', label: '', lat: job.latitude, lng: job.longitude } ];
	}

	/**
	 * Une copie de l'offre par ville (coordonnées de la ville) et une entrée par
	 * région. Chaque copie porte `_also` : les autres lieux de l'offre.
	 */
	function splitPlaces( jobs ) {
		var points = [];
		var regions = {};
		var order = [];
		jobs.forEach( function ( job ) {
			var places = placesOf( job );
			places.forEach( function ( place, index ) {
				var copy = {};
				Object.keys( job ).forEach( function ( name ) {
					copy[ name ] = job[ name ];
				} );
				copy._also = places.filter( function ( other, i ) {
					return i !== index && other.label;
				} ).map( function ( other ) {
					return other.label;
				} );
				if ( 'region' === place.type ) {
					var lat = parseFloat( place.lat );
					var lng = parseFloat( place.lng );
					if ( ! isFinite( lat ) || ! isFinite( lng ) ) {
						return;
					}
					if ( ! regions[ place.region ] ) {
						regions[ place.region ] = { code: place.region, label: place.label || '', position: { lat: lat, lng: lng }, jobs: [] };
						order.push( place.region );
					}
					regions[ place.region ].jobs.push( copy );
					return;
				}
				copy.latitude = place.lat;
				copy.longitude = place.lng;
				if ( index > 0 ) {
					copy.city = place.label || '';
					copy.country = '';
				} else if ( ! copy.city && place.label ) {
					copy.city = place.label;
				}
				points.push( copy );
			} );
		} );
		return {
			points: points,
			regions: order.map( function ( code ) {
				return regions[ code ];
			} )
		};
	}
```

Dans `placesList()`, remplacer la signature et la boucle :

```js
	function placesList( container, groups, i18n ) {
```

par :

```js
	function placesList( container, groups, i18n, regions ) {
```

et, après la boucle `groups.forEach( … );`, ajouter :

```js
		( regions || [] ).forEach( function ( region ) {
			details.appendChild( buildRegionContent( region, i18n ) );
		} );
```

Remplacer l'objet exporté par :

```js
	window.g2rdWpjmGeoMapCommon = {
		parseJson: parseJson,
		sprintf: sprintf,
		safeUrl: safeUrl,
		placeLabel: placeLabel,
		buildInfoContent: buildInfoContent,
		buildRegionContent: buildRegionContent,
		regionTitle: regionTitle,
		jobCount: jobCount,
		groupByCoordinates: groupByCoordinates,
		splitPlaces: splitPlaces,
		createFetcher: createFetcher,
		observe: observe,
		status: statusController,
		places: placesList
	};
```

- [ ] **Step 3: Lancer les tests**

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1`
Expected : `Resultat JS : 135 reussis, 0 echoues` (7 contrôles en section M ; sections D et I inchangées).

- [ ] **Step 4: Commit**

```bash
git add g2rd-wpjm-geo/assets/js/map-common.js g2rd-wpjm-geo/tests/js/run.js
git commit -m "feat(carte) : un point par ville, « Aussi à », fenêtre et liste des régions"
```

---

### Task 12: Carte libre — zones, pastilles, secours, légende

**Files:**
- Modify: `g2rd-wpjm-geo/assets/js/jobs-map-osm.js`
- Modify: `g2rd-wpjm-geo/includes/OsmAssets.php:64-86`
- Modify: `g2rd-wpjm-geo/includes/JobsMap.php:135-147`
- Modify: `g2rd-wpjm-geo/assets/css/frontend.css`
- Test: `g2rd-wpjm-geo/tests/js/run.js` (aide `osmMap`, nouvelle section M), `g2rd-wpjm-geo/tests/run.php` (section 40)

- [ ] **Step 1: Étendre la fausse carte MapLibre du banc de test**

Dans `run.js`, fonction `osmMap()` :

1. Remplacer la signature :

```js
async function osmMap({ data, clustering = true, noWebgl = false, styleFail = false, restFail = false, preload = true, admin = '', narrow = false }) {
  const i18n = JSON.stringify({ loading: 'Chargement', error: 'ERREUR', empty: 'VIDE', view: 'Voir offre', manyJobs: '%d offres ici', regeocode: 'Re-geocoder', places: 'Liste' }).replace(/"/g, '&quot;');
```

par :

```js
async function osmMap({ data, clustering = true, noWebgl = false, styleFail = false, restFail = false, preload = true, admin = '', narrow = false, regionsFail = false }) {
  const i18n = JSON.stringify({ loading: 'Chargement', error: 'ERREUR', empty: 'VIDE', view: 'Voir offre', manyJobs: '%d offres ici', regeocode: 'Re-geocoder', places: 'Liste', alsoAt: 'Aussi à :', regionTitle: '%s (toute la région)', oneJob: '1 offre', manyJobsShort: '%d offres', legend: 'Point : offre dans une ville · zone orange : offre sur toute une région' }).replace(/"/g, '&quot;');
```

2. Remplacer la ligne `const st = { map: null, … noRotate: false };` par :

```js
  const st = { map: null, mapOpts: null, sources: {}, layers: [], layout: [], popups: [], fit: null, jump: null, ease: null, controls: [], noRotate: false, before: {}, states: [], markers: [], rendered: [], regionFetches: 0 };
```

3. Dans `class FakeMap`, remplacer la méthode `getStyle()` pour inclure les sources ajoutées :

```js
    getStyle() { return { sources: Object.assign({ openmaptiles: { type: 'vector', url: 'https://tiles.openfreemap.org/planet' } }, st.sources), layers: [
      { id: 'place-label', type: 'symbol', layout: { 'text-field': ['get', 'name'], 'text-font': ['Noto Sans Regular'] } },
      { id: 'road-ref', type: 'symbol', layout: { 'text-field': ['get', 'ref'], 'text-font': ['Noto Sans Bold'] } },
      { id: 'water', type: 'fill' }
    ] }; }
```

et remplacer `addLayer(l) { st.layers.push(l); }` par :

```js
    addLayer(l, before) { st.layers.push(l); if (before) st.before[l.id] = before; }
    getLayer(id) { return st.layers.find(l => l.id === id); }
    setFeatureState(f, s) { st.states.push({ f, s }); }
    queryRenderedFeatures() { return st.rendered; }
```

4. Après `class FullscreenControl { … }`, ajouter :

```js
  class Marker { constructor(o) { this.o = o; st.markers.push(this); } setLngLat(c) { this.c = c; return this; } addTo() { return this; } }
```

et remplacer `const fake = { Map: FakeMap, Popup, LngLatBounds, NavigationControl, FullscreenControl };` par :

```js
  const fake = { Map: FakeMap, Popup, LngLatBounds, NavigationControl, FullscreenControl, Marker };
```

5. Remplacer la ligne `w.g2rdWpjmGeoOsm = { … };` par :

```js
  w.g2rdWpjmGeoOsm = { styleUrl: 'https://tiles.openfreemap.org/styles/liberty', maplibreJs: 'https://x.test/vendor/maplibre-gl.js', maplibreCss: 'https://x.test/vendor/maplibre-gl.css', language: 'fr', locale: { 'Popup.Close': 'Fermer', 'Map.Title': '' }, regionsUrl: 'https://x.test/data/regions-fr.geojson?ver=1', regionsCredit: '<a href="https://www.data.gouv.fr/fr/datasets/contours-administratifs/">Contours des régions : data.gouv.fr (ODbL)</a>' };
```

6. Remplacer le début de `w.fetch = (url) => {` par :

```js
  w.fetch = (url) => {
    if (url.indexOf('https://x.test/data/regions-fr.geojson') === 0) {
      st.regionFetches++;
      if (regionsFail) return Promise.resolve({ ok: false, status: 404, json: () => Promise.reject(new Error('404')) });
      const square = (x, y) => ({ type: 'Polygon', coordinates: [[[x, y], [x + 1, y], [x + 1, y + 1], [x, y]]] });
      return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ type: 'FeatureCollection', features: [
        { type: 'Feature', properties: { code: '28', nom: 'Normandie' }, geometry: square(0, 49) },
        { type: 'Feature', properties: { code: '53', nom: 'Bretagne' }, geometry: square(-3, 48) },
        { type: 'Feature', properties: { code: '11', nom: 'Île-de-France' }, geometry: square(2, 48) }
      ] }) });
    }
```

(le reste de la fonction `fetch` est inchangé).

- [ ] **Step 2: Écrire les tests (ils échouent)**

Juste avant la ligne `(async () => {`, ajouter :

```js
const multiJobs = [
  { id: 11, title: 'Multi', permalink: 'https://x.test/job/11', company: 'M', city: 'Paris', country: 'France', latitude: 48.856614, longitude: 2.352222, job_type: '', places: [{ type: 'city', label: 'Paris', lat: 48.856614, lng: 2.352222 }, { type: 'city', label: 'Lyon', lat: 45.764, lng: 4.8357 }, { type: 'region', label: 'Normandie', lat: 49.1202, lng: 0.1128, region: '28' }] },
  { id: 12, title: '<img src=x onerror=alert(1)>Region', permalink: 'https://x.test/job/12', company: 'R', city: '', country: 'France', latitude: 49.1202, longitude: 0.1128, job_type: '', places: [{ type: 'region', label: 'Normandie', lat: 49.1202, lng: 0.1128, region: '28' }] },
  { id: 13, title: 'Bretagne seule', permalink: 'https://x.test/job/13', company: 'B', city: '', country: 'France', latitude: 48.1794, longitude: -2.8331, job_type: '', places: [{ type: 'region', label: 'Bretagne', lat: 48.1794, lng: -2.8331, region: '53' }] }
];
```

Section à insérer avant la ligne `console.log('\nResultat JS : …');` :

```js
  console.log('\nN. Carte libre : plusieurs lieux et regions');
  o = await osmMap({ data: multiJobs });
  check('une offre a Paris et Lyon : un point dans chaque ville', 2 === o.st.sources['g2rd-jobs'].data.features.length);
  o.st.map.fire('click:g2rd-points', { features: [{ properties: { gid: 1, count: 1 }, geometry: { coordinates: [4.8357, 45.764] } }] });
  check('fenetre de Lyon : « Aussi a : Paris, Normandie »', /Aussi à : Paris, Normandie/.test(o.st.popups[0].node.textContent));
  check('contours charges une fois, depuis le plugin', 1 === o.st.regionFetches);
  const zone = o.st.sources['g2rd-regions'];
  check('zones : source GeoJSON des seules regions affichees, identifiant code, credit ODbL', !!zone && 2 === zone.data.features.length && 'code' === zone.promoteId && /ODbL/.test(zone.attribution));
  const fill = o.st.layers.find(l => l.id === 'g2rd-region-fill'), line = o.st.layers.find(l => l.id === 'g2rd-region-line');
  check('zones : remplissage et contour, dessines sous les points', !!fill && 'fill' === fill.type && !!line && 'line' === line.type && 'g2rd-clusters' === o.st.before['g2rd-region-fill'] && 'g2rd-clusters' === o.st.before['g2rd-region-line']);
  const badges = o.st.markers.map(m => m.o.element);
  check('pastilles : une par region, nombre d offres, bouton nomme', 2 === badges.length && '2 offres' === badges[0].textContent && '1 offre' === badges[1].textContent && 'button' === badges[0].type && /Normandie \(toute la région\) — 2 offres/.test(badges[0].getAttribute('aria-label')) && 0.1128 === o.st.markers[0].c[0]);
  badges[0].click();
  const rp = o.st.popups[o.st.popups.length - 1];
  check('clic sur la pastille : fenetre des offres de la region, titre malveillant en texte', 2 === rp.node.querySelectorAll('li').length && 0 === rp.node.querySelectorAll('img').length && /toute la région/.test(rp.node.textContent));
  const before = o.st.popups.length;
  o.st.rendered = [{ layer: { id: 'g2rd-points' } }];
  o.st.map.fire('click:g2rd-region-fill', { point: {}, lngLat: [0.5, 49.5], features: [{ properties: { code: '28' } }] });
  check('clic sur un point dans une zone : la fenetre de la zone ne s ouvre pas', before === o.st.popups.length);
  o.st.rendered = [];
  o.st.map.fire('click:g2rd-region-fill', { point: {}, lngLat: [0.5, 49.5], features: [{ properties: { code: '28' } }] });
  check('clic dans la zone : fenetre de la region', before + 1 === o.st.popups.length);
  o.st.map.fire('mousemove:g2rd-region-fill', { features: [{ properties: { code: '53' } }] });
  check('survol : contour renforce (etat hover)', o.st.states.some(x => '53' === x.f.id && true === x.s.hover));
  check('cadrage : villes et centres des regions', 4 === o.st.fit.b.pts.length);
  const legend = o.el.querySelector('.g2rd-wpjm-geo-map__legend');
  check('legende affichee', !!legend && /zone orange/.test(legend.textContent));
  const mlist = o.w.document.querySelector('.g2rd-wpjm-geo-map__places');
  check('liste sous la carte : regions comprises', /Normandie \(toute la région\) — 2 offres/.test(mlist.textContent) && /Bretagne \(toute la région\) — 1 offre/.test(mlist.textContent));
  check('credit des contours dans l attribution, lien https', /ODbL/.test(o.st.attribNode.textContent) && [...o.st.attribNode.querySelectorAll('a')].some(a => 0 === a.href.indexOf('https://www.data.gouv.fr/')));
  o = await osmMap({ data: multiJobs, regionsFail: true });
  const fb = o.st.markers.map(m => m.o.element);
  check('contours injoignables : reperes etiquettes a leur centre, sans zone', !o.st.sources['g2rd-regions'] && 2 === fb.length && 'Normandie · 2 offres' === fb[0].textContent && fb[0].classList.contains('g2rd-wpjm-geo-region-badge--marker') && !o.el.classList.contains('has-error'));
  o = await osmMap({ data: jobs });
  check('aucune region : contours non charges, ni pastille ni legende', 0 === o.st.regionFetches && 0 === o.st.markers.length && !o.el.querySelector('.g2rd-wpjm-geo-map__legend'));
  o = await osmMap({ data: [multiJobs[2]] });
  check('une seule region : centrage sur la region (zoom 6)', !!o.st.jump && 6 === o.st.jump.zoom && !o.st.fit);
```

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | grep -E "^N|FAIL|ERREUR|Resultat"`
Expected : `[FAIL]` dans la section N, puis `ERREUR BANC DE TEST` (aucune pastille créée) ; sections A à M vertes.

- [ ] **Step 3: Modifier `jobs-map-osm.js`**

1. Dans le docblock de tête, après la ligne ` * - Fenêtres construites en DOM (textContent) par map-common.js.`, ajouter :

```js
 * - Plusieurs lieux par offre : un point par ville ; chaque région est une zone
 *   colorée (contours chargés à la demande depuis le plugin) avec une pastille,
 *   ou, si les contours manquent, un repère en étiquette à son centre.
```

2. Après `var maplibrePromise = null;`, ajouter :

```js
	var REGION_COLOR = '#ef6c00';
	var REGION_SOURCE = 'g2rd-regions';
	var shapesPromise = null;
```

3. Après la fonction `loadMaplibre()`, ajouter :

```js
	// Contours des régions (GeoJSON du plugin, ODbL), chargés une seule fois et seulement si une offre régionale est affichée.
	function loadShapes() {
		if ( ! shapesPromise ) {
			shapesPromise = cfg.regionsUrl ? window.fetch( cfg.regionsUrl, { credentials: 'same-origin' } ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} ) : Promise.reject( new Error( 'no-regions-url' ) );
			shapesPromise.catch( function () {
				shapesPromise = null; // Un échec n'est pas mémorisé.
			} );
		}
		return shapesPromise;
	}

	function openRegion( map, ml, region, i18n, lngLat ) {
		new ml.Popup( { maxWidth: '320px' } )
			.setLngLat( lngLat )
			.setDOMContent( C.buildRegionContent( region, i18n ) )
			.addTo( map );
	}

	// Pastille (avec zones) ou repère en étiquette (sans zones) au centre de chaque région : un bouton, accessible au clavier.
	function addBadges( map, ml, regions, i18n, fallback ) {
		regions.forEach( function ( region ) {
			var center = [ region.position.lng, region.position.lat ];
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'g2rd-wpjm-geo-region-badge' + ( fallback ? ' g2rd-wpjm-geo-region-badge--marker' : '' );
			button.textContent = fallback ? region.label + ' \u00b7 ' + C.jobCount( region.jobs.length, i18n ) : C.jobCount( region.jobs.length, i18n );
			button.setAttribute( 'aria-label', C.regionTitle( region, i18n ) );
			button.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
				openRegion( map, ml, region, i18n, center );
			} );
			new ml.Marker( { element: button } ).setLngLat( center ).addTo( map );
		} );
	}

	function addZones( map, ml, geojson, regions, i18n ) {
		var byCode = {};
		regions.forEach( function ( region ) {
			byCode[ region.code ] = region;
		} );
		var features = ( ( geojson && Array.isArray( geojson.features ) ) ? geojson.features : [] ).filter( function ( feature ) {
			return feature && feature.geometry && feature.properties && byCode[ feature.properties.code ];
		} ).map( function ( feature ) {
			return { type: 'Feature', properties: { code: String( feature.properties.code ) }, geometry: feature.geometry };
		} );
		if ( ! features.length ) {
			throw new Error( 'no-shapes' );
		}
		map.addSource( REGION_SOURCE, {
			type: 'geojson',
			data: { type: 'FeatureCollection', features: features },
			promoteId: 'code',
			attribution: cfg.regionsCredit || ''
		} );
		// Sous les points et les regroupements : une ville reste cliquable dans une zone.
		var before = map.getLayer( 'g2rd-clusters' ) ? 'g2rd-clusters' : undefined;
		var hover = [ 'boolean', [ 'feature-state', 'hover' ], false ];
		map.addLayer( { id: 'g2rd-region-fill', type: 'fill', source: REGION_SOURCE, paint: { 'fill-color': REGION_COLOR, 'fill-opacity': [ 'case', hover, 0.32, 0.18 ] } }, before );
		map.addLayer( { id: 'g2rd-region-line', type: 'line', source: REGION_SOURCE, paint: { 'line-color': REGION_COLOR, 'line-width': [ 'case', hover, 3, 1.5 ] } }, before );

		var hovered = null;
		function setHover( code ) {
			if ( hovered ) {
				map.setFeatureState( { source: REGION_SOURCE, id: hovered }, { hover: false } );
			}
			hovered = code;
			if ( hovered ) {
				map.setFeatureState( { source: REGION_SOURCE, id: hovered }, { hover: true } );
			}
		}
		map.on( 'mousemove', 'g2rd-region-fill', function ( event ) {
			var feature = event.features && event.features[ 0 ];
			var code = feature && feature.properties ? String( feature.properties.code ) : null;
			if ( code !== hovered ) {
				setHover( code );
			}
		} );
		map.on( 'mouseleave', 'g2rd-region-fill', function () {
			setHover( null );
		} );
		map.on( 'click', 'g2rd-region-fill', function ( event ) {
			// Un point ou un regroupement de la zone garde la priorité.
			if ( map.queryRenderedFeatures( event.point, { layers: [ 'g2rd-points', 'g2rd-clusters' ] } ).length ) {
				return;
			}
			var feature = event.features && event.features[ 0 ];
			var region = feature && feature.properties ? byCode[ feature.properties.code ] : null;
			if ( region ) {
				openRegion( map, ml, region, i18n, event.lngLat );
			}
		} );
		addBadges( map, ml, regions, i18n, false );
	}

	function addLegend( container, i18n ) {
		if ( ! i18n.legend || container.querySelector( '.g2rd-wpjm-geo-map__legend' ) ) {
			return;
		}
		var legend = document.createElement( 'p' );
		legend.className = 'g2rd-wpjm-geo-map__legend';
		legend.textContent = i18n.legend;
		container.appendChild( legend );
	}
```

4. Dans `initMap()`, remplacer :

```js
			var ml = results[ 0 ];
			var groups = C.groupByCoordinates( results[ 1 ] );
```

par :

```js
			var ml = results[ 0 ];
			var split = C.splitPlaces( results[ 1 ] );
			var groups = C.groupByCoordinates( split.points );
			var regions = split.regions;
			var shapes = regions.length ? loadShapes() : null;
			if ( shapes ) {
				shapes.catch( function () {} ); // Échec traité au chargement de la carte (repères de secours).
			}
```

5. Remplacer :

```js
				map.addControl( new AttributionControl( window.innerWidth < 782 ), 'bottom-right' );
```

par :

```js
				var attribution = new AttributionControl( window.innerWidth < 782 );
				map.addControl( attribution, 'bottom-right' );
```

6. Remplacer tout le bloc final du gestionnaire `load`, depuis `if ( ! features.length ) {` jusqu'à `container.classList.add( 'is-ready' );` inclus, par :

```js
					if ( shapes ) {
						shapes.then( function ( geojson ) {
							addZones( map, ml, geojson, regions, i18n );
							attribution.render(); // Crédit des contours (ODbL).
						} ).catch( function () {
							addBadges( map, ml, regions, i18n, true );
						} );
					}

					var coords = features.map( function ( feature ) {
						return feature.geometry.coordinates;
					} ).concat( regions.map( function ( region ) {
						return [ region.position.lng, region.position.lat ];
					} ) );
					if ( ! coords.length ) {
						ui.empty();
					} else {
						ui.set( '' );
						// Les points du canevas ne prennent pas le focus : liste des offres sous la carte pour le clavier et les lecteurs d'écran.
						C.places( container, groups, i18n, regions );
						if ( regions.length ) {
							addLegend( container, i18n );
						}
						if ( 1 === coords.length ) {
							map.jumpTo( { center: coords[ 0 ], zoom: features.length ? 10 : 6 } );
						} else {
							var bounds = new ml.LngLatBounds();
							coords.forEach( function ( coord ) {
								bounds.extend( coord );
							} );
							map.fitBounds( bounds, { padding: 48, maxZoom: 12, duration: 0 } );
						}
					}
					container.classList.add( 'is-ready' );
```

- [ ] **Step 4: Configuration et textes de la carte**

Dans `OsmAssets.php`, dans `config()`, après la ligne `'maplibreCss' => …,`, ajouter :

```php
			'regionsUrl'    => esc_url_raw( G2RD_WPJM_GEO_URL . 'assets/data/regions-fr.geojson?ver=' . G2RD_WPJM_GEO_VERSION, array( 'http', 'https' ) ),
			// Obligation de la licence ODbL : citer la source des contours, affichée par le contrôle d'attribution du plugin.
			'regionsCredit' => '<a href="' . esc_url( 'https://www.data.gouv.fr/fr/datasets/contours-administratifs/' ) . '">' . esc_html__( 'Contours des régions : data.gouv.fr (ODbL)', 'g2rd-wpjm-geo' ) . '</a>',
```

(Le docblock `@return` de `config()` ne change pas : les deux nouvelles valeurs sont des chaînes.)

Dans `JobsMap.php`, dans le tableau `$i18n` de `render()`, après `'places'    => __( 'Liste des offres de la carte', 'g2rd-wpjm-geo' ),`, ajouter :

```php
			'alsoAt'        => __( 'Aussi à :', 'g2rd-wpjm-geo' ),
			/* translators: %s: region name. */
			'regionTitle'   => __( '%s (toute la région)', 'g2rd-wpjm-geo' ),
			'oneJob'        => __( '1 offre', 'g2rd-wpjm-geo' ),
			/* translators: %d: number of jobs (2 or more). */
			'manyJobsShort' => __( '%d offres', 'g2rd-wpjm-geo' ),
			'legend'        => __( '📍 offre dans une ville · zone orange : offre sur toute une région', 'g2rd-wpjm-geo' ),
```

À la fin de `frontend.css`, ajouter :

```css

/* ---------- Régions : pastilles, repères de secours, légende ---------- */
.g2rd-wpjm-geo-region-badge {
	margin: 0;
	padding: 3px 10px;
	color: #fff;
	font: 700 12px/1.3 system-ui, -apple-system, "Segoe UI", sans-serif;
	white-space: nowrap;
	background: #ef6c00;
	border: 2px solid #fff;
	border-radius: 12px;
	box-shadow: 0 1px 4px rgba(0, 0, 0, 0.35);
	cursor: pointer;
}

.g2rd-wpjm-geo-region-badge:focus-visible {
	outline: 3px solid #1d2327;
	outline-offset: 2px;
}

.g2rd-wpjm-geo-map__legend {
	position: absolute;
	bottom: 8px;
	left: 8px;
	z-index: 2;
	max-width: calc(100% - 16px);
	margin: 0;
	padding: 4px 8px;
	color: #1d2327;
	font: 400 12px/1.4 system-ui, -apple-system, "Segoe UI", sans-serif;
	background: rgba(255, 255, 255, 0.92);
	border-radius: 4px;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}

.g2rd-wpjm-geo-iw__also {
	color: #50575e;
	font-size: 13px;
}
```

- [ ] **Step 5: Test PHP de la configuration**

Section à insérer dans `run.php` avant `$results = …` :

```php
echo PHP_EOL . '40. Carte libre : contours des régions' . PHP_EOL;
list( $s ) = boot( array( 'provider' => Settings::PROVIDER_OSM ) );
$cfg = ( new \G2RD\WPJMGeo\OsmAssets( $s ) )->config();
check( 'URL des contours servie par le plugin, versionnée', 0 === strpos( $cfg['regionsUrl'], G2RD_WPJM_GEO_URL . 'assets/data/regions-fr.geojson?ver=' ) );
check( 'crédit ODbL avec lien https vers la source', false !== strpos( $cfg['regionsCredit'], 'ODbL' ) && false !== strpos( $cfg['regionsCredit'], 'href="https://www.data.gouv.fr/' ) );
```

- [ ] **Step 6: Lancer tous les tests**

```bash
php g2rd-wpjm-geo/tests/run.php | tail -4
NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1
```

Expected : `Résultat : 328 réussis, 0 échoués` ; `Resultat JS : 152 reussis, 0 echoues` (17 contrôles en section N).

- [ ] **Step 7: Lint, analyse, commit**

```bash
composer run lint && composer run analyse
git add g2rd-wpjm-geo/assets/js/jobs-map-osm.js g2rd-wpjm-geo/includes/OsmAssets.php g2rd-wpjm-geo/includes/JobsMap.php g2rd-wpjm-geo/assets/css/frontend.css g2rd-wpjm-geo/tests/js/run.js g2rd-wpjm-geo/tests/run.php
git commit -m "feat(carte) : régions en zones colorées avec pastilles, repères de secours et légende"
```

---

### Task 13: Version 1.2.0, documentation, bout en bout

**Files:**
- Modify: `g2rd-wpjm-geo/g2rd-wpjm-geo.php:6,24`
- Modify: `g2rd-wpjm-geo/readme.txt`
- Modify: `tools/docker/verify.sh`

- [ ] **Step 1: Version**

Dans `g2rd-wpjm-geo/g2rd-wpjm-geo.php`, remplacer `* Version:           1.1.1` par `* Version:           1.2.0` et `define( 'G2RD_WPJM_GEO_VERSION', '1.1.1' );` par `define( 'G2RD_WPJM_GEO_VERSION', '1.2.0' );`.

- [ ] **Step 2: Readme**

Dans `g2rd-wpjm-geo/readme.txt` :

1. Remplacer `Stable tag: 1.1.1` par `Stable tag: 1.2.0`.
2. Après la puce « Carte interactive des offres… », ajouter :

```
* Mode libre : plusieurs lieux par offre (10 au plus), villes et régions françaises, saisis en étiquettes dans le champ « Lieu » natif (enregistrés séparés par « ; »). Le texte libre et les imports sont reconnus aussi : « Paris, Lyon » donne deux villes, « Rouen, Normandie » une seule. Sur la carte, un point par ville et une zone colorée par région.
```

3. Dans « Options WordPress », après la ligne de `_g2rd_wpjm_geo_source`, ajouter :

```
* Métadonnée `_g2rd_wpjm_geo_places` : lieux de l'offre en mode libre (villes et régions) ; le premier est aussi écrit dans les champs `geolocation_*` de WP Job Manager.
```

4. Après la section « Hooks pour développeurs », ajouter :

```
== Données tierces ==

* `assets/data/regions-fr.geojson` : contours des 18 régions françaises, extraits du jeu « Contours administratifs » (millésime 2025) publié par Etalab sur data.gouv.fr, sous licence ODbL 1.0 (voir `assets/data/LICENSE-regions.txt`). La source est citée sur la carte quand des régions sont affichées.
```

5. Dans la FAQ, avant « = Multisite = », ajouter :

```
= Une offre concerne plusieurs villes ou une région =
En mode libre, saisir chaque lieu dans le champ « Lieu » (étiquettes) ; sans JavaScript, les séparer par « ; ». Les régions françaises (noms actuels, anciens noms, sigles comme IDF ou PACA) s'affichent en zone colorée. Les offres créées avant la 1.2.0 gardent un seul point jusqu'au passage de l'outil Re-géocoder les offres (compteur « Libres, à mettre à jour »). En mode Google, une offre garde un seul point.
```

6. En tête du « Changelog », ajouter :

```
= 1.2.0 =
* Mode libre : plusieurs lieux par offre (10 au plus), villes et régions françaises, avec une autocomplétion à étiquettes sur le champ « Lieu » natif.
* Reconnaissance côté serveur des saisies libres et des imports : séparateurs « ; », « / », « | », « et », règle de la virgule.
* Carte libre : un point par ville, ligne « Aussi à », régions en zones colorées avec le nombre d'offres, légende, liste des régions sous la carte.
* Contours des régions embarqués (data.gouv.fr, ODbL), chargés seulement si une offre régionale est affichée.
* Saisie : au plus un appel à Photon pendant l'enregistrement ; au-delà, les lieux sont placés par la file d'attente.
* Outil de re-géocodage : reprise des offres libres antérieures pour placer tous leurs lieux.
* Cache du géocodage Photon renouvelé (version 4).
```

- [ ] **Step 3: Contrôle de bout en bout (Docker)**

Dans `tools/docker/verify.sh`, juste avant la ligne `echo "10. Retour vers Google"`, ajouter :

```bash
echo "10. Plusieurs lieux et régions (mode libre)"
JOBM=$(wp post create --post_type=job_listing --post_status=publish --post_title='Poste multi-lieux' --porcelain)
curl -s -b cookies.txt -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' -X POST -d '{"meta":{"_job_location":"Paris ; Lyon ; Normandie"}}' "$B/wp-json/wp/v2/job-listings/$JOBM" >/dev/null
wp cron event run g2rd_wpjm_geo_geocode >/dev/null
ok 'trois lieux enregistrés' "$(is "$(wp eval "echo count( (array) get_post_meta( $JOBM, '_g2rd_wpjm_geo_places', true ) );")" 3)"
ok 'premier lieu dans WP Job Manager' "$(is "$(wp post meta get "$JOBM" geolocation_city 2>/dev/null)" Paris)"
ok 'texte saisi conservé' "$(is "$(wp post meta get "$JOBM" _job_location 2>/dev/null)" 'Paris ; Lyon ; Normandie')"
R=$(curl -s "$B/wp-json/g2rd-wpjm-geo/v1/jobs")
ok 'API REST : champ places avec la région' "$(has "$R" '"type":"region"')$(has "$R" '"region":"28"')"
ok 'contours embarqués servis' "$(is "$(curl -s -o /dev/null -w '%{http_code}' "$B/wp-content/plugins/g2rd-wpjm-geo/assets/data/regions-fr.geojson")" 200)"
ok 'fiche publique : « Paris, Lyon, Normandie »' "$(has "$(curl -sL "$B/?p=$JOBM")" 'Paris, Lyon, Normandie')"
wp eval '$items = array( array( "title" => "Import multi " . wp_generate_password( 6, false ), "description" => "Offre importee sur plusieurs villes, pour le test de bout en bout.", "link" => "https://source.example/job/2", "date" => gmdate( "Y-m-d" ), "jobkey" => md5( microtime() ), "jobfeed" => "test", "provider_id" => "custom" ) ); GoFetch_Importer::import( $items, array( "rss_feed_import" => "https://source.example/feed.xml", "post_author" => 1, "meta" => array( "_job_location" => "Rouen / Caen" ), "source" => array( "name" => "Test" ) ) );' >/dev/null
JOB4=$(wp post list --post_type=job_listing --meta_key=_goft_wpjm_is_external --field=ID --posts_per_page=1 --orderby=ID --order=DESC)
wp cron event run g2rd_wpjm_geo_geocode >/dev/null
ok 'import Go Fetch : deux villes placées' "$(is "$(wp eval "echo count( (array) get_post_meta( $JOB4, '_g2rd_wpjm_geo_places', true ) );")" 2)"
F=$(curl -sL "$B/?page_id=$FORM")
ok 'formulaire : script des étiquettes et régions' "$(has "$F" 'location-tags.js')$(has "$F" '"tags":true')$(has "$F" '"code":"28"')"

```

Puis renuméroter les deux sections suivantes : `echo "10. Retour vers Google"` devient `echo "11. Retour vers Google"` et `echo "11. Journal PHP"` devient `echo "12. Journal PHP"`.

- [ ] **Step 4: Vérifications complètes**

```bash
php g2rd-wpjm-geo/tests/run.php | tail -1
NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1
composer run lint && composer run lint:security && composer run analyse
bash tools/verify-release-version.sh 1.2.0
php tools/build-zip.php && bash tools/verify-plugin-zip.sh g2rd-wpjm-geo.zip; rm -f g2rd-wpjm-geo.zip
bash tools/docker/verify.sh
```

Expected :
- `Résultat : 328 réussis, 0 échoués` ; `Resultat JS : 152 reussis, 0 echoues` ;
- PHPCS (normes WordPress et audit de sécurité) et PHPStan sans erreur ;
- versions alignées sur 1.2.0 ;
- `ZIP conforme.`, avec `OK   : regions-fr.geojson conforme à tools/regions/SHA256SUMS` ;
- `Résultat Docker : 40 réussis, 0 échoués` (Docker démarré ; si Docker n'est pas disponible, le signaler sans marquer l'étape comme faite).

- [ ] **Step 5: Commit**

```bash
git add g2rd-wpjm-geo/g2rd-wpjm-geo.php g2rd-wpjm-geo/readme.txt tools/docker/verify.sh
git commit -m "chore(version) : 1.2.0, documentation et contrôle de bout en bout multi-lieux"
```

---

## Vérification navigateur (propriétaire, site de test)

Après installation de la 1.2.0 sur le site de test, en mode OpenStreetMap :

1. Formulaire de dépôt : taper « norm », choisir Normandie ; taper « Rou », choisir Rouen ; vérifier les deux étiquettes, le ✕, Retour arrière, le plafond, puis l'enregistrement.
2. Éditeur d'offre (`job_listing`) : mêmes gestes dans la boîte « Données de l'offre ».
3. Carte : un point par ville, « Aussi à » dans la fenêtre, zone orange et pastille de la Normandie, fenêtre de région, légende, liste sous la carte, crédit « data.gouv.fr (ODbL) ».
4. Mobile : pastilles et fenêtres lisibles, légende sans recouvrir les crédits.
5. Outils → Re-géocoder les offres : « Analyser » puis « Simuler » ; vérifier le compteur « Libres, à mettre à jour ».

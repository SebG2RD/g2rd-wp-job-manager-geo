# G2RD WP Job Manager Geo

Extension WordPress qui complète [WP Job Manager](https://wordpress.org/plugins/wp-job-manager/) :

- **autocomplétion des villes** du monde entier sur le champ « Lieu » natif, au dépôt d'une offre comme dans wp-admin ;
- **carte des offres** (shortcode `[wpjm_jobs_map]` ou bloc « Carte des offres d'emploi »), avec regroupement des points et liste des offres sous la carte ;
- **plusieurs lieux par offre** en mode libre (villes et régions françaises) ;
- **candidatures Go Fetch Jobs** : les URL de candidature des offres importées sont remplacées par une adresse email générique.

Deux fournisseurs au choix, qui s'appliquent ensemble à la carte, à l'autocomplétion et au géocodage :

| | Google Maps | OpenStreetMap (libre) |
| --- | --- | --- |
| Carte | Maps JavaScript API | MapLibre GL JS 5.24.0 (embarqué), fonds OpenFreeMap |
| Autocomplétion | Places API (New) | Photon |
| Géocodage des offres | celui de WP Job Manager | le plugin, avec Photon |
| Clé ou compte | clé navigateur Google | aucun |
| Plusieurs lieux par offre | non (un seul point) | oui, 10 au plus |

Le plugin ne modifie ni WP Job Manager, ni Go Fetch Jobs, ni le thème.

Version : **1.3.0**. Licence : GPL-2.0-or-later.

## Documentation

| Question | Document |
| --- | --- |
| Je reprends le projet après une pause, par où commencer ? | [docs/reprendre-le-projet.md](docs/reprendre-le-projet.md) |
| Comment le plugin est-il construit, qui appelle quoi ? | [docs/architecture.md](docs/architecture.md) |
| Ça ne marche pas, où regarder ? | [docs/depannage.md](docs/depannage.md) |
| Pourquoi ce comportement, comment a-t-il été conçu ? | [docs/superpowers/specs/](docs/superpowers/specs/) |
| Options, hooks, limites connues, historique des versions | [g2rd-wpjm-geo/readme.txt](g2rd-wpjm-geo/readme.txt) |

Point d'entrée de la documentation : [docs/index.md](docs/index.md).

## Prérequis

- WordPress 6.4 ou plus récent, PHP 7.4 ou plus récent (vérifié jusqu'à PHP 8.4).
- **WP Job Manager actif** : sans lui, le plugin ne charge rien et le shortcode n'affiche rien.
- Go Fetch Jobs : facultatif, seulement pour le remplacement des candidatures.
- Mode Google : une clé « navigateur » avec Maps JavaScript API et Places API (New) activées.
- Mode libre : le serveur doit pouvoir joindre le serveur Photon en HTTPS (`photon.komoot.io` par défaut), et le navigateur des visiteurs doit gérer WebGL.

## Installation

Le plugin n'est pas publié sur wordpress.org : il s'installe depuis les [releases GitHub](https://github.com/SebG2RD/g2rd-wp-job-manager-geo/releases).

1. Téléchargez `g2rd-wpjm-geo-X.Y.Z.zip` et son fichier `.sha256`, puis vérifiez l'empreinte :
   ```sh
   sha256sum -c g2rd-wpjm-geo-X.Y.Z.zip.sha256
   ```
2. Dans WordPress : Extensions → Ajouter → Téléverser une extension, puis activez-la.
3. Mise à jour : même procédure avec la nouvelle archive (WordPress propose de remplacer la version installée). Le plugin n'a pas de mise à jour automatique ; son en-tête `Update URI` empêche WordPress de chercher une mise à jour sur wordpress.org pour ce slug.
4. Après une mise à jour, videz le cache des pages (WP Rocket ou autre) pour que la carte reçoive sa nouvelle configuration.

À l'activation, le plugin crée la table du journal et l'option de l'adresse générique de candidature (`reconversion@pompiersparis.fr` par défaut, à changer sur un autre site). Aucune offre n'est modifiée.

## Configuration

La page de réglages se trouve dans **G2RD → Offres géolocalisées** si le thème G2RD (console v2) est actif, sinon dans le menu **G2RD Geo**, juste sous « Offres d'emploi ». Elle est réservée aux administrateurs (`manage_options`).

| Section | Contenu |
| --- | --- |
| Cartographie | Fournisseur ; clé navigateur et Map ID (Google) ; style de carte et serveur Photon (libre) |
| Autocomplétion | Interrupteur général, formulaire public, wp-admin |
| Carte | Activation, hauteur (200 à 1200 px), regroupement des offres proches |
| Candidatures Go Fetch Jobs | Adresse générique, remplacement, URL concernées (toutes, ou seulement les invalides) |
| Journal | Activation, durée de conservation (90 jours par défaut, 5 000 entrées au plus) |
| Outils | Re-géocoder les offres ; analyser, simuler et corriger les candidatures Go Fetch Jobs |

**Mode Google** : restreignez la clé navigateur au référent HTTP du site et aux deux API. La clé serveur de WP Job Manager n'est jamais envoyée au navigateur. Un Map ID est facultatif : il active les marqueurs avancés.

**Mode libre** : après être passé en OpenStreetMap, lancez Outils → Re-géocoder les offres, en commençant par « Simuler » (les résultats sont mis en cache). Tant que l'outil n'a pas terminé, la carte n'affiche que les offres déjà géocodées par Photon. Un texte suggéré pour la politique de confidentialité est proposé dans Réglages → Confidentialité.

## Utilisation

### Carte des offres

```text
[wpjm_jobs_map]
[wpjm_jobs_map height="500"]
```

Le bloc « Carte des offres d'emploi » fait la même chose dans l'éditeur. La carte ne se charge qu'à l'approche de la zone visible. Elle lit les offres sur `/wp-json/g2rd-wpjm-geo/v1/jobs`, une route publique qui suit les règles de WP Job Manager (offres expirées, pourvues ou protégées masquées) et répond 404 quand la carte est désactivée.

Sous la carte, une liste reprend toutes les offres, classées par lieu, avec les régions à la fin : c'est aussi l'accès au clavier aux offres de la carte.

### Plusieurs lieux par offre (mode libre)

Le champ « Lieu » devient un champ à étiquettes : chaque ville ou région est une étiquette, enregistrée dans le champ natif séparée par « ; ». Le texte libre et les imports sont reconnus côté serveur (« Paris, Lyon » donne deux villes, « Rouen, Normandie » une seule). Sur la carte, un point par ville et une zone colorée par région. Les cas ambigus sont listés dans la FAQ du [readme.txt](g2rd-wpjm-geo/readme.txt), section « Limites connues des lieux multiples ».

### Candidatures Go Fetch Jobs

Seules les offres importées par Go Fetch Jobs sont concernées. Une adresse email valide n'est jamais modifiée, une valeur vide reste vide.

- **Toutes les URL** (recommandé) : toute valeur qui n'est pas une adresse email est remplacée à l'import, sans requête HTTP.
- **Uniquement les URL invalides ou inaccessibles** : chaque URL est vérifiée plus tard par WP-Cron, puis remplacée si elle ne répond pas.

Pour les offres importées avant l'installation, utilisez Outils → Analyser, Simuler, puis Corriger. Chaque remplacement est inscrit au journal.

### Données et désinstallation

Les options, métadonnées, transients et hooks pour développeurs sont listés dans le [readme.txt](g2rd-wpjm-geo/readme.txt). La désinstallation supprime les données propres au plugin et ne touche à aucune offre ni métadonnée de WP Job Manager ou de Go Fetch Jobs. En multisite, elle ne nettoie que le site courant.

## Développement

### Structure du dépôt

```text
g2rd-wpjm-geo/          le plugin, seul dossier livré (sans tests/)
  includes/             classes PHP, autoload PSR-4 G2RD\WPJMGeo\Foo → includes/Foo.php
  assets/js/            scripts sans étape de compilation
  assets/css/           styles publics, console d'administration
  assets/data/          contours des régions françaises (ODbL)
  assets/vendor/        MapLibre GL JS, MarkerClusterer
  blocks/jobs-map/      bloc « Carte des offres d'emploi » (sans JSX)
  tests/                harnais PHP (run.php) et JS (js/run.js)
tools/                  construction du ZIP, vérifications de release, Docker, jsdom
docs/                   documentation, conceptions et plans
.github/workflows/      CI, audit de sécurité, Plugin Check, tag et release
```

Le plugin n'a aucune dépendance à l'exécution : Composer et npm ne servent qu'à l'outillage.

### Installer l'outillage

```sh
composer install
npm ci --prefix tools/jsdom --ignore-scripts
```

`npm ci` installe exactement les versions de `tools/jsdom/package-lock.json` (jsdom 30), sans exécuter de script.

### Commandes

| Commande | Rôle |
| --- | --- |
| `composer test` | Tests PHP (harnais maison, sans PHPUnit ; tout avertissement PHP compte comme un échec) |
| `composer test:js` | Tests JS dans jsdom |
| `composer lint` | WordPress Coding Standards (`phpcs.xml.dist`) |
| `composer lint:security` | PHPCS Security Audit (`phpcs-security.xml`) |
| `composer analyse` | PHPStan niveau 6 |
| `composer ci` | `test`, `lint`, `lint:security` et `analyse` à la suite |
| `php tools/build-zip.php` | Construit `g2rd-wpjm-geo.zip` à la racine, sans les tests |

### Vérification de bout en bout (Docker)

`tools/docker/` monte un WordPress jetable avec WP Job Manager et Go Fetch Jobs, puis vérifie les deux fournisseurs, un vrai import Go Fetch Jobs et de vrais appels Photon :

```sh
cd tools/docker
./fetch-plugins.sh   # une fois : télécharge les extensions tierces de test
./verify.sh          # la base est remise à zéro à chaque exécution
```

Le site de test répond sur <http://localhost:8899>. Docker Desktop doit être démarré.

## CI/CD

| Workflow | Déclencheur | Rôle |
| --- | --- | --- |
| `ci.yml` | push et PR vers `main` ou `develop` | Tests PHP (matrice de versions) et JS, selon les fichiers modifiés ; empreinte des contours des régions |
| `phpcs-security.yml` | push et PR vers `main` | PHPCS Security Audit et PHPCompatibility, bloquants |
| `plugin-check.yml` | push sur `main`, toute PR | Plugin Check de WordPress.org sur l'archive construite, bloquant sur les erreurs |
| `auto-tag.yml` | push sur `main` | Crée l'étiquette `vX.Y.Z` quand le sujet du commit commence par `version: X.Y.Z` |
| `release.yml` | étiquette `vX.Y.Z` ou lancement manuel | Rejoue les contrôles, construit et valide le ZIP, teste l'activation, publie la release avec le ZIP et son SHA-256 |

Les actions sont épinglées par SHA. Dependabot propose chaque semaine les mises à jour de Composer, de jsdom et des actions, 14 jours au moins après leur publication.

### Publier une version

1. Alignez la version dans `g2rd-wpjm-geo/g2rd-wpjm-geo.php` (en-tête `Version` et constante `G2RD_WPJM_GEO_VERSION`) et dans `g2rd-wpjm-geo/readme.txt` (`Stable tag` et changelog), puis vérifiez :
   ```sh
   bash tools/verify-release-version.sh X.Y.Z
   ```
2. Fusionnez dans `main`, puis créez l'étiquette, au choix :
   - un commit sur `main` dont le sujet commence par `version: X.Y.Z` : `auto-tag.yml` pose l'étiquette. Il faut le secret `GH_PAT` (jeton à grain fin limité au dépôt, permission Contents en écriture), car une étiquette poussée avec le jeton par défaut ne déclenche pas `release.yml` ;
   - ou depuis le poste : `git tag vX.Y.Z && git push origin vX.Y.Z` (méthode utilisée pour la 1.2.0).
3. `release.yml` publie la release avec `g2rd-wpjm-geo-X.Y.Z.zip` et son `.sha256`.

## Sécurité

- Tout contenu issu des offres est inséré dans la page par `textContent`, jamais comme du HTML.
- La route REST ne renvoie que les champs utiles à la carte, en texte brut.
- Les URL du style de carte et du serveur Photon doivent être en `https` ; les vérifications d'URL de candidature passent par `wp_safe_remote_*()` (protection SSRF).
- Le remplacement des candidatures n'écrit que la métadonnée `_application`, sans `wp_update_post()` ni hook `save_post` : les offres déposées par un employeur ou un administrateur ne passent jamais par ce code.
- Le cache du géocodage est indexé sur le texte réellement interrogé, pour qu'une saisie ne puisse pas déplacer les offres des autres employeurs.

Le détail est dans [docs/architecture.md](docs/architecture.md#sécurité-par-construction).

## Licence et données tierces

Plugin sous licence GPL-2.0-or-later.

| Élément | Licence |
| --- | --- |
| MapLibre GL JS 5.24.0 (`assets/vendor/maplibre-gl/`) | BSD-3-Clause |
| MarkerClusterer (`assets/vendor/markerclusterer.min.js`) | Apache-2.0 |
| Polices Instrument Sans et IBM Plex Mono (`assets/fonts/`) | SIL Open Font License 1.1 |
| Contours des régions (`assets/data/regions-fr.geojson`), Etalab, data.gouv.fr, millésime 2025 | ODbL 1.0 |
| Fonds de carte OpenFreeMap, données © contributeurs OpenStreetMap | ODbL, attribution affichée sur la carte |

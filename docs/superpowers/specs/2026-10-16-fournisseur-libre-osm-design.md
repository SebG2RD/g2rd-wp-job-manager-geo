# Fournisseur cartographique libre (OpenStreetMap) et administration G2RD (1.1.0)

Validé avec le propriétaire le 2026-10-16, section par section.

## Constat

- Le plugin 1.0.0 dépend entièrement de Google : carte (Maps JavaScript API),
  autocomplétion (Places API New) et, via WP Job Manager, géocodage serveur
  (Geocoding API).
- Le propriétaire veut pouvoir utiliser un équivalent gratuit et open source.
- La page de réglages vit sous « Offres d'emploi » avec l'habillage WordPress
  par défaut, alors que les autres outils G2RD (thème, facturation, connecteur)
  ont leur propre console.

## Faits vérifiés (2026-10-16)

| Sujet | Constat | Source |
|---|---|---|
| Google | « Les résultats de la Geocoding API affichés sur une carte doivent l'être sur une Google Map. » Mélanger fond libre et coordonnées Google est exclu. | developers.google.com/maps/documentation/geocoding/policies |
| Nominatim | 1 requête/s maximum ; autocomplétion côté client interdite ; scripts récurrents limités à 4 requêtes/min ; cache obligatoire. Non retenu. | operations.osmfoundation.org/policies/nominatim |
| Photon | Apache-2.0, conçu pour la saisie au fil de la frappe. Instance publique `photon.komoot.io` gratuite « dans une limite raisonnable », sans garantie, usage abusif limité ou banni. Langues acceptées : `default`, `de`, `en`, `fr` (autre valeur : HTTP 400). | github.com/komoot/photon, essais en direct |
| Photon, filtres | `layer=city` écarte Londres (classée `district`). `osm_tag=place:city&osm_tag=place:town…` donne les bons résultats. La syntaxe non dépréciée `include=place.city` ne renvoie rien sur l'instance publique. | essais en direct |
| Photon, texte libre | Sans garde-fou, « IDF » donne une entreprise en Occitanie, puis Idfu (Égypte) avec un filtre de lieu ; « Paris 6 » donne Notre-Dame. | essais en direct |
| Photon, aller-retour | Un libellé choisi dans la liste (« London, Ontario, Canada », « Paris, Texas, États-Unis d'Amérique », « Montréal, Québec, Canada ») est regéocodé au bon endroit. | essais en direct |
| OpenFreeMap | MIT, usage commercial autorisé, sans inscription, sans clé, sans limite de vues. Tuiles vectorielles : MapLibre GL JS requis. Styles `liberty`, `bright`, `positron`. Attribution « OpenFreeMap © OpenMapTiles Data from OpenStreetMap », ajoutée automatiquement par MapLibre. | openfreemap.org, /quick_start |
| Serveur de tuiles OSM | Aucune garantie, accès « bloqué sans préavis » en cas d'usage intensif ou commercial. Non retenu. | operations.osmfoundation.org/policies/tiles |
| MapLibre GL JS | 6.14.0, BSD-3-Clause, ~1 Mo non compressé, ~276 Ko gzip. Exige WebGL 2. | registry.npmjs.org |
| WP Job Manager 2.4.7 | Filtre `job_manager_geolocation_enabled` ; action `job_manager_job_location_edited( $job_id, $new_location )` déclenchée sur `add_post_meta` et `update_post_meta` de `_job_location`, **avant** l'écriture ; méthodes publiques statiques `WP_Job_Manager_Geocode::clear_location_data()`, `save_location_data()`, `generate_location_data()`. | code source |
| Thème G2RD | Menu « G2RD » (slug `g2rd`), action `g2rd_console_menu( $parent )`, `\G2RD\AdminConsole::open()` / `close()`, contrat `CONTRACT_VERSION = 2`, feuille chargée sur les hooks `g2rd_page_*`. Introduit en 1.41.0, non publié (thème local en 1.40.1). | G2RD-theme `classes/class-admin-console.php`, `docs/internal/console-admin-extensions.md` |
| Site cible | Thème actif « G2RD Child », enfant d'**Astra** (`Template: astra`) : le thème G2RD n'y est pas installé. | style.css local et en ligne |

## Objectif

1. Un réglage « Fournisseur cartographique » bascule ensemble la carte,
   l'autocomplétion et le géocodage serveur entre **Google** (comportement
   1.0.0, inchangé) et **OpenStreetMap** (MapLibre + OpenFreeMap + Photon).
2. Le géocodage libre écrit les mêmes métadonnées que WP Job Manager.
3. Un outil déclenché par l'administrateur re-géocode les offres existantes
   avec le fournisseur actif.
4. L'administration du plugin adopte la console G2RD : sous-menu du menu
   « G2RD » si le thème la fournit, menu autonome à la même charte sinon.

## Hors périmètre

- Installation d'un serveur Photon ou de tuiles (seules les URL sont
  configurables).
- Nominatim, cartes hors ligne, éditeur de style de carte.
- Champ caché de coordonnées dans les formulaires.
- Redirection de l'ancienne adresse de réglages (le plugin n'a jamais été
  installé sur le site).
- Toute modification de WP Job Manager, Go Fetch Jobs, Astra, du thème G2RD
  ou de WordPress.

## 1. Architecture et réglages

### Réglages (option `g2rd_wpjm_geo_settings`)

| Clé | Valeurs | Défaut |
|---|---|---|
| `provider` | `google`, `osm` | `google` |
| `osm_style_url` | URL `https://` avec hôte | `https://tiles.openfreemap.org/styles/liberty` |
| `photon_url` | URL `https://` avec hôte | `https://photon.komoot.io/api/` |

- Défaut `google` : une mise à jour du plugin ne change rien tant que
  l'administrateur ne bascule pas.
- `osm_style_url` et `photon_url` : assainies par `esc_url_raw( $url, [ 'https' ] )`,
  refusées (valeur précédente conservée, message d'erreur) si le schéma n'est
  pas `https` ou si l'hôte est vide.
- La clé navigateur Google n'est exigée (avertissements, carte, autocomplétion)
  que si `provider = google`.
- `Settings::get_provider(): string` retourne `google` ou `osm`.

### Métadonnée de source

`_g2rd_wpjm_geo_source` = `google` ou `osm`, posée à chaque géocodage par le
plugin. Absente : l'offre compte comme `google` (géocodée par WP Job Manager).

### Unités

| Fichier | Statut | Rôle |
|---|---|---|
| `includes/Settings.php` | modifié | nouveaux réglages, `get_provider()`, rendu en lignes de console |
| `includes/AdminMenu.php` | nouveau | rattachement au menu « G2RD » ou menu autonome, hook de page |
| `includes/AdminScreen.php` | nouveau | `open()` / `close()` : délègue à `\G2RD\AdminConsole` ou rend le même balisage ; charge la feuille de repli |
| `includes/OsmAssets.php` | nouveau | enregistre MapLibre et la configuration du mode libre |
| `includes/PhotonGeocoder.php` | nouveau | texte → tableau d'adresse au format WPJM |
| `includes/GeocodingIntegration.php` | nouveau | en mode libre : coupe le géocodage Google de WPJM, géocode chaque modification de `_job_location` |
| `includes/GeocodeQueue.php` | nouveau | file WP-Cron des géocodages différés |
| `includes/RegeocodeTool.php` | nouveau | outil « Re-géocoder les offres » (analyse, simulation, exécution) |
| `includes/PrivacyPolicy.php` | nouveau | texte suggéré pour la politique de confidentialité |
| `includes/RestController.php` | modifié | filtre par source, fournisseur dans la clé de cache |
| `includes/JobsMap.php` | modifié | script selon le fournisseur, lien admin dans l'état vide |
| `includes/LocationAutocomplete.php`, `AdminLocation.php` | modifiés | configuration Google ou Photon |
| `includes/MigrationTool.php` | modifié | balisage console, hook de page |
| `includes/Plugin.php` | modifié | câblage |
| `assets/js/map-common.js` | nouveau (extrait de `jobs-map.js`) | lecture REST paginée, regroupement par coordonnées, contenu des fenêtres |
| `assets/js/jobs-map.js` | modifié | moteur Google, utilise `map-common.js` |
| `assets/js/jobs-map-osm.js` | nouveau | moteur MapLibre |
| `assets/js/location-autocomplete.js` | modifié | interface commune, sources `google` et `photon` |
| `assets/vendor/maplibre-gl/` | nouveau | `maplibre-gl.js`, `maplibre-gl.css`, `LICENSE.txt` (6.14.0) |
| `assets/css/console.css` | nouveau | copie réduite de la feuille de la console G2RD (repli) |
| `assets/fonts/` | nouveau | Instrument Sans et IBM Plex Mono (woff2 latin) + `OFL-*.txt`, repris de g2rd-facturation |
| `assets/css/admin.css` | supprimé | les résultats des outils passent en `g2rd-table` |
| `uninstall.php` | modifié | nouvelles options, métadonnée de source |

## 2. Carte en mode libre

- Même shortcode `[wpjm_jobs_map]`, même bloc, même conteneur, même CSS.
  `JobsMap` met en file `jobs-map.js` (Google) ou `jobs-map-osm.js` (libre).
- `jobs-map-osm.js` attend, comme aujourd'hui, que la carte approche de l'écran
  (`IntersectionObserver`, marge 300 px), puis injecte `maplibre-gl.css` et
  `maplibre-gl.js` depuis le plugin. Aucun CDN.
- Données : même endpoint REST (section 5 pour le filtre par source).
- Regroupement : les offres aux coordonnées identiques (5 décimales) forment
  une seule entité GeoJSON portant `count` et un identifiant ; la liste des
  offres reste en mémoire JavaScript, indexée par cet identifiant (jamais dans
  les propriétés GeoJSON).
- Clustering natif (source GeoJSON `cluster: true`,
  `clusterProperties: { jobs: [ '+', [ 'get', 'count' ] ] }`) : les cercles
  affichent le nombre d'**offres**. Clic sur un cluster :
  `getClusterExpansionZoom` puis `easeTo`. Réglage « Activer le clustering »
  respecté (`cluster: false`).
- Couche de points et de clusters en `#c0392b`, comme la carte Google.
  Les libellés des clusters utilisent la première police déclarée par une
  couche de texte du style chargé, pour fonctionner avec un style personnalisé.
- Clic sur un point : `maplibregl.Popup` avec `setDOMContent()` sur le contenu
  produit par `map-common.js` (titre, entreprise, « Ville, Pays », type de
  contrat, « Voir l'offre » ; liste si plusieurs offres). `textContent`
  uniquement, liens filtrés `^https?://`.
- Cadrage : `fitBounds` sur toutes les offres (marge 48 px) ; une seule offre :
  centrage, zoom 10. `cooperativeGestures: true`.
- Libellés du fond : pour chaque couche de texte du style, `text-field`
  remplacé par `[ 'coalesce', [ 'get', 'name:<langue du site>' ], [ 'get', 'name' ] ]`
  quand la langue du site est disponible.
- Attribution : contrôle d'attribution de MapLibre, compact sous 782 px.
- Échecs : pas de WebGL 2, style ou tuiles injoignables → « La carte n'a pas
  pu être chargée ». Aucune offre de la source active → « Aucune offre
  géolocalisée » ; pour les seuls administrateurs, lien vers l'outil de
  re-géocodage.

## 3. Autocomplétion en mode libre

- Même champ natif (`job_location` / `_job_location`), mêmes écrans de
  chargement, même liste, mêmes interactions clavier et ARIA.
- Source `photon` : `fetch` direct du navigateur vers `photon_url` avec
  `q`, `limit=6`, `osm_tag=place:city`, `place:town`, `place:village`,
  `place:municipality`, et `lang` = langue du site si elle vaut `fr`, `en` ou
  `de`, sinon `default`.
- Attente de 300 ms après la dernière frappe, `AbortController` sur la requête
  précédente, mémorisation des réponses pendant la session de saisie.
- Repli : réponse HTTP 400 → une nouvelle tentative sans `osm_tag`, avec
  filtrage côté navigateur sur `properties.osm_key === 'place'` ; repli
  mémorisé pour la page.
- Valeur écrite : nom, région, pays, sans doublon ni valeur vide, séparés par
  « , » (« Londres, Angleterre, Royaume-Uni »).
- Attribution en pied de liste : « Recherche © OpenStreetMap contributors ».
- Panne (réseau, réponse illisible) : liste désactivée, champ texte libre,
  aucun message à l'employeur.

## 4. Administration

### Rattachement

- `AdminMenu` écoute `g2rd_console_menu`. Si `\G2RD\AdminConsole` existe et
  `\G2RD\AdminConsole::CONTRACT_VERSION >= 2`, il ajoute un sous-menu :
  titre « Console G2RD — Offres géolocalisées », libellé « Offres
  géolocalisées », capacité `manage_options`, slug `g2rd-wpjm-geo`, et note
  qu'il est rattaché.
- À `admin_menu` priorité 20, s'il n'est pas rattaché : `add_menu_page`
  « G2RD Geo », icône `dashicons-location-alt`, position 31 (juste sous
  « Offres d'emploi », position 30).
- Le hook de page renvoyé par WordPress est mémorisé ;
  `Settings::is_settings_screen()` et les chargements d'assets s'y comparent.
- Le lien « Réglages » de la liste des extensions pointe sur
  `admin.php?page=g2rd-wpjm-geo`.

### Gabarit

- `AdminScreen::open( $args )` : rattaché → `\G2RD\AdminConsole::open()` ;
  sinon, même balisage (`.wrap#g2rd-options-root > .g2rd-app.g2rd-app--php`,
  bandeau, barre avec monogramme, titre « G2RD WP Job Manager Geo » et version
  du plugin, titre de page, jusqu'à trois tuiles). `AdminScreen::close()`
  ferme.
- Mode autonome uniquement : `assets/css/console.css` (classes utilisées
  seulement, sélecteurs sous `.g2rd-app`, en-tête indiquant la version et le
  commit du thème d'origine) et les polices du plugin, chargées sur la seule
  page du plugin, plus le style `wp-components` de WordPress pour les
  interrupteurs.
- Classes employées (contrat v2) : `g2rd-section` (+ `__head`, `__title`,
  `__desc`), `g2rd-setting-list`, `g2rd-setting-row` (+ `__main`, `__title`,
  `__desc`, `__control`), `g2rd-field__label|input|hint`, `g2rd-table`,
  `g2rd-tag--success|warning|danger|info|neutral`,
  `g2rd-alert--success|warning|danger|info`, `g2rd-empty`, `g2rd-muted`,
  `g2rd-savebar`.

### Contenu de la page

- Bandeau : tuiles « Fournisseur » (Google Maps / OpenStreetMap), « Offres
  géolocalisées » (`n / total`, source active, hors corbeille), « Remplacements
  sur 30 jours » (journal ; « — » si le journal est désactivé). Puces : « Go Fetch Jobs actif » (ok),
  « Clé navigateur manquante » (avertissement, mode Google seulement),
  « Offres à re-géocoder : n » (avertissement si n > 0).
- Sommaire d'ancres : Cartographie, Autocomplétion, Carte, Candidatures
  Go Fetch Jobs, Journal, Outils.
- Cartes de réglages en lignes ; cases à cocher rendues en interrupteurs ;
  barre d'enregistrement flottante (`g2rd-savebar`).
- Outils : « Re-géocoder les offres » (section 5), « Offres Go Fetch Jobs
  existantes » (outil 1.0.0, rehabillé).
- Journal des remplacements : `g2rd-table`, raisons en `g2rd-tag`.
- Inchangés : Settings API, `options.php`, nonces, capacité, validation de
  l'adresse email générique.

## 5. Géocodage serveur et re-géocodage

### Intégration (`GeocodingIntegration`, actif seulement si `provider = osm`)

- `add_filter( 'job_manager_geolocation_enabled', '__return_false' )`.
- Écoute `job_manager_job_location_edited( $job_id, $new_location )` et
  travaille sur `$new_location` (la métadonnée n'est pas encore écrite).
- Contexte différé si `defined( 'GOFJ_IMPORTING' )`, `wp_doing_cron()` ou
  WP-CLI : l'offre est ajoutée à `GeocodeQueue`. Sinon : géocodage immédiat
  (délai réseau 5 s).
- Localisation vide : `clear_location_data()` et suppression de la source.

### `PhotonGeocoder::geocode( string $text ): array|null|WP_Error`

1. **Nettoyage** : retrait des parenthèses et de leur contenu, des nombres
   isolés, des séparateurs en bord ; espaces fusionnés.
2. **Requête** : `wp_safe_remote_get( photon_url )`, `q` = texte nettoyé,
   `limit=5`, `osm_tag=place`, `osm_tag=boundary:administrative`, `lang` comme
   en section 3, délai 5 s, User-Agent
   `G2RD-WPJM-Geo/<version> (+<url du site>)`. HTTP 400 → une nouvelle
   tentative sans `osm_tag`, en ne gardant que `osm_key` `place` ou `boundary`.
3. **Garde-fou** : premier résultat dont le nom normalisé (`remove_accents`,
   minuscules, non-alphanumériques remplacés par une espace) est égal au
   premier segment normalisé du texte nettoyé (avant la première virgule).
   Aucun → `null`.
4. **Conversion** au format WPJM : `lat`, `long` (numériques, bornés à ±90 /
   ±180, 7 décimales), `formatted_address` (nom, région, pays), `city` (le nom
   si `osm_value` ∈ city, town, village, hamlet, municipality, suburb, locality,
   sinon `properties.city`), `state_long`, `postcode`, `country_long`,
   `country_short` (majuscules). Toutes les chaînes par `sanitize_text_field`.
5. **Cache** : transient `g2rd_geo_ph_` + md5( langue + texte nettoyé ), 30
   jours ; résultat `null` mis en cache un jour.
6. Erreur réseau ou réponse illisible → `WP_Error`.

### Écriture

`WP_Job_Manager_Geocode::clear_location_data()`, puis, si un résultat existe,
`save_location_data()` et `_g2rd_wpjm_geo_source = osm`. Si la classe de WPJM
est absente, l'intégration ne fait rien.

| Résultat | Saisie / import | Outil de re-géocodage |
|---|---|---|
| trouvé | effacement puis écriture | effacement puis écriture |
| `null` (introuvable) | effacement | **aucune écriture**, offre listée « Non trouvée » |
| `WP_Error` | effacement, offre mise en file | aucune écriture, offre listée « Erreur » |

### `GeocodeQueue`

- Option `g2rd_wpjm_geo_geocode_queue` (IDs), événement unique WP-Cron
  `g2rd_wpjm_geo_geocode`, verrou transitoire de 5 minutes.
- Lot de 10 ; chaque offre est retirée de la file (relecture de l'option)
  avant son traitement ; pause d'une seconde entre deux appels non servis par
  le cache.
- `WP_Error` : l'offre est remise en file, le lot s'arrête, prochain passage
  dans 10 minutes.
- File plafonnée à 5 000 IDs, écrite une seule fois en fin de requête.

### Règle d'affichage

L'endpoint REST n'expose que les offres dont la source correspond au
fournisseur actif : `osm` → `_g2rd_wpjm_geo_source = osm` ; `google` →
métadonnée absente ou égale à `google`. Le fournisseur fait partie de la clé de
cache.

### `RegeocodeTool`

- Action admin-ajax `g2rd_wpjm_geo_regeocode`, capacité `manage_options`,
  nonce, tâches `analyze`, `simulate`, `run`, curseur sur l'ID, lots de 5.
- Périmètre : offres `job_listing` hors `trash` et `auto-draft`, à
  `_job_location` non vide.
- `analyze` : nombres par source (Google, OpenStreetMap, sans coordonnées) et
  nombre « à re-géocoder » (source différente du fournisseur actif ou sans
  coordonnées). Aucun appel réseau.
- `simulate` : pour chaque offre à re-géocoder, « localisation → résultat
  prévu (ville, pays) », « Non trouvée » ou « Erreur ». Appels réseau, aucune
  écriture ; réponses mises en cache.
- `run` : fournisseur `osm` → `PhotonGeocoder` puis écriture (tableau
  ci-dessus) ; fournisseur `google` → `clear_location_data()`,
  `generate_location_data()` de WPJM, `_g2rd_wpjm_geo_source = google`.
- Résultat affiché : compteurs et liste des offres « Non trouvée » /
  « Erreur » avec lien de modification.
- Jamais lancé automatiquement : après une bascule, une alerte de la page
  propose l'outil.

## 6. Sécurité, RGPD, tests

### Sécurité

- `wp_safe_remote_get` refuse les adresses privées. Le filtre
  `g2rd_wpjm_geo_photon_allow_private_host` (booléen, défaut `false`) autorise,
  via `http_request_host_is_external`, **uniquement l'hôte de `photon_url`**,
  pour un Photon auto-hébergé sur le même serveur.
- URL transmises au navigateur par `wp_json_encode`, jamais concaténées dans
  du HTML.
- Réponses Photon non fiables : coordonnées vérifiées et bornées, textes
  assainis côté serveur ; `textContent` côté navigateur.
- REST, nonces, capacités : inchangés.

### RGPD

`PrivacyPolicy` appelle `wp_add_privacy_policy_content()` avec un texte
adapté au fournisseur actif :

- Google : carte et autocomplétion chargées depuis Google ;
- OpenStreetMap : tuiles OpenFreeMap pour les visiteurs de la carte,
  recherche Photon (komoot, Allemagne) pour les personnes qui saisissent une
  localisation, géocodage serveur sans donnée personnelle.

### Tests

Automatiques, sans WordPress :

- `tests/run.php` :
  - `PhotonGeocoder` : nettoyage (« Lyon (69) », « 75008 Paris »), garde-fou
    (« IDF » refusé, « Londres, Angleterre, Royaume-Uni » accepté), conversion,
    bornes, cache positif et négatif, repli HTTP 400, `WP_Error` ;
  - `GeocodeQueue` : lot, pause, retrait avant traitement, arrêt et
    replanification sur erreur ;
  - `Settings` : `https` obligatoire, fournisseur ;
  - règle de source de l'endpoint (fonction pure de construction de la
    requête).
- `tests/js/run.js` (jsdom) :
  - les 34 tests existants, après l'extraction de `map-common.js` ;
  - `jobs-map-osm.js` avec un faux `maplibregl` : comptage des offres dans
    les clusters, fenêtres, cadrage, état vide, échec ;
  - source Photon : attente, annulation, repli HTTP 400, langue, format du
    libellé, panne.

Docker (WordPress 6.9, WPJM 2.4.7, Go Fetch Jobs 1.8.5.2) :

- bascule Google → OSM : géocodage WPJM coupé, saisie wp-admin géocodée par
  Photon, source posée ;
- import Go Fetch Jobs → file → cron → coordonnées ;
- outil de re-géocodage : analyse, simulation sans écriture, exécution, retour
  vers Google ;
- MapLibre chargé uniquement sur la page de la carte ;
- administration avec le thème G2RD local (sous-menu, feuille du thème) et
  sans (menu autonome, feuille de repli).

Contrôle réel : requêtes Photon pour Londres, London Ontario, Montréal, Tokyo,
Rouen et retour au bon lieu.

À la charge du propriétaire : rendu dans un vrai navigateur (WebGL), gestes
sur mobile.

## Découpage

1. Réglages, métadonnée de source, `AdminMenu`, `AdminScreen`, feuille de
   repli, polices ; page de réglages réhabillée.
2. `PhotonGeocoder` et ses tests.
3. `GeocodingIntegration`, `GeocodeQueue` et leurs tests.
4. Règle de source dans `RestController`.
5. `RegeocodeTool`.
6. `map-common.js` (extraction, non-régression), `jobs-map-osm.js`, MapLibre
   embarqué, `OsmAssets`, `JobsMap`.
7. Source Photon de l'autocomplétion.
8. `PrivacyPolicy`, `uninstall.php`, readme, version 1.1.0.
9. Vérification Docker complète, ZIP.

## Risques

- **Instances publiques sans garantie** : URL modifiables, retour à Google en
  un réglage.
- **`osm_tag` déprécié dans Photon** : repli automatique sans filtre.
- **Dérive de la feuille de repli** par rapport au thème : version d'origine
  notée dans le fichier.
- **Console du thème non publiée** (1.41) : sans incidence sur le site cible
  (Astra) ; testée avec le thème local.
- **WebGL 2 et workers `blob:`** requis par MapLibre : une politique CSP stricte
  les bloquerait (aucune sur le site aujourd'hui).
- **Localisations vagues** (« IDF », listes de villes) : non géocodées en mode
  libre, listées par l'outil pour correction manuelle.

# Architecture

## Vue d'ensemble

Le plugin se greffe sur WP Job Manager sans le modifier. Il passe par les
hooks publics de WP Job Manager et de Go Fetch Jobs, et par les métadonnées
que WP Job Manager lit déjà (`_job_location`, `geolocation_lat`,
`geolocation_long`, `_application`).

```text
Saisie du lieu ──► autocomplétion (Google Places ou Photon), dans le navigateur
      │
      ▼
_job_location ──► géocodage : WP Job Manager (Google) ou le plugin (Photon, mode libre)
      │
      ▼
geolocation_* + _g2rd_wpjm_geo_places ──► GET /wp-json/g2rd-wpjm-geo/v1/jobs ──► carte + liste

Import Go Fetch Jobs ──► goft_wpjm_after_insert_job ──► _application remplacée ──► journal
```

Le plugin n'a aucune étape de compilation : PHP 7.4 sans dépendance Composer à
l'exécution, JavaScript sans JSX ni bundler, MapLibre et MarkerClusterer
embarqués dans `assets/vendor/`.

## Démarrage

`g2rd-wpjm-geo.php` déclare les constantes, un autoload PSR-4 minimal
(`G2RD\WPJMGeo\Foo` → `includes/Foo.php`) et lance `Plugin::instance()` sur
`plugins_loaded`. `Plugin::boot()` instancie les modules :

- sans WP Job Manager, seul le shortcode est déclaré, et il ne rend rien
  (pour ne pas afficher `[wpjm_jobs_map]` en clair) ;
- l'autocomplétion n'est chargée que si ses interrupteurs sont actifs ;
- le shortcode, le bloc et la route REST sont toujours enregistrés, même
  carte désactivée ;
- les hooks Go Fetch Jobs sont toujours branchés : sans Go Fetch Jobs, ils ne
  sont jamais déclenchés ;
- l'administration (`SettingsPage`, outils, menu) n'est chargée que dans
  wp-admin.

Activation : création de la table du journal et de l'option de l'adresse
générique. Désactivation : retrait des trois tâches WP-Cron. Désinstallation
(`uninstall.php`) : suppression des options, métadonnées, transients et de la
table propres au plugin.

## Modules

| Classe | Rôle |
| --- | --- |
| `Plugin` | Point d'entrée, instancie les modules |
| `Dependencies` | Détecte WP Job Manager (requis) et Go Fetch Jobs (facultatif), notice d'administration |
| `Settings` | Option `g2rd_wpjm_geo_settings` : valeurs par défaut, assainissement, purge du cache WP Rocket quand un réglage public change |
| `SettingsPage`, `AdminMenu`, `AdminScreen` | Page de réglages à la charte de la console G2RD ; menu « G2RD » si le thème G2RD (console v2) est actif, menu « G2RD Geo » sinon |
| `GoogleAssets`, `OsmAssets` | Enregistrent les scripts du fournisseur actif et leur configuration |
| `LocationAutocomplete`, `AdminLocation` | Autocomplétion sur le formulaire public et dans wp-admin |
| `LocationHelp` | Aide « ; » sous le champ « Lieu » (mode libre) |
| `JobsMap` | Shortcode `[wpjm_jobs_map]` et bloc `blocks/jobs-map` |
| `RestController` | Route `g2rd-wpjm-geo/v1/jobs` |
| `GeocodingIntegration` | Géocodage des offres en mode libre, affichage des lieux multiples |
| `PhotonGeocoder` | Appels à Photon côté serveur, choix entre homonymes, cache |
| `PlaceParser`, `PlaceLocator`, `Regions`, `Departments` | Découpe du champ « Lieu », règle de la virgule, régions et départements français |
| `JobQueue`, `GeocodeQueue`, `UrlCheckQueue` | Files d'attente WP-Cron |
| `GoFetchIntegration`, `ApplicationNormalizer`, `UrlChecker` | Remplacement des candidatures Go Fetch Jobs |
| `Logger` | Table `{prefix}g2rd_wpjm_geo_log`, purge quotidienne |
| `RegeocodeTool`, `MigrationTool` | Outils de la section « Outils », par lots via admin-ajax |
| `AdminListColumn` | Colonne « Carte » de la liste des offres : même règle que l'API de la carte (`RestController::coordinates()`, source du fournisseur actif) ; sinon lieu vide, en attente (file de géocodage), coordonnées de l'autre fournisseur ou introuvable |
| `PrivacyPolicy` | Texte suggéré pour la politique de confidentialité |
| `Updater` | Mises à jour proposées par WordPress depuis la dernière release GitHub (filtre `update_plugins_github.com` de l'en-tête `Update URI`, limité au fichier de ce plugin ; paquet accepté seulement s'il vient des releases du dépôt ; cache 6 h) |

## Les deux fournisseurs

Le réglage « Fournisseur cartographique » s'applique à la fois à la carte, à
l'autocomplétion et au géocodage.

**Google** : WP Job Manager géocode lui-même chaque offre avec sa propre clé
serveur. Le plugin n'envoie au navigateur que la clé navigateur.
`google-loader.js` charge l'API Google une seule fois, ou réutilise celle
qu'une autre extension a déjà chargée.

**OpenStreetMap** : le plugin coupe le géocodage Google de WP Job Manager
(filtre `job_manager_geolocation_enabled`) et géocode lui-même. Il écrit les
coordonnées par les méthodes publiques `WP_Job_Manager_Geocode::save_location_data()`
et `clear_location_data()`. La métadonnée `_g2rd_wpjm_geo_source` (`google` ou
`osm`) indique quel fournisseur a placé l'offre : la carte libre n'affiche que
les offres placées par Photon.

## Cycle de vie d'une offre en mode libre

1. WP Job Manager déclenche `job_manager_job_location_edited` avant d'écrire
   `_job_location`, avec la nouvelle valeur en argument.
2. `GeocodingIntegration::on_location_edited()` efface les anciennes
   coordonnées, puis :
   - pendant un import, une tâche WP-Cron ou WP-CLI, ou si Photon vient
     d'échouer (drapeau `g2rd_wpjm_geo_photon_down`, 5 minutes), l'offre part
     directement dans la file ;
   - sinon, `PlaceLocator::locate()` place tous les lieux pendant
     l'enregistrement : chaque ville a son point sur la carte dès
     l'enregistrement, sans attendre WP-Cron. Le temps accordé à Photon est
     borné (`GeocodingIntegration::SAVE_SECONDS`, 8 secondes ; un appel déjà
     parti peut encore durer 5 secondes) : au-delà, les villes restantes ne
     sont plus envoyées à Photon et l'offre part dans la file. Jusqu'en 1.2.1,
     un seul appel était permis : dès deux villes absentes du cache, l'offre
     attendait le passage de WP-Cron.
3. `PlaceLocator` découpe le texte (`PlaceParser` : « ; », « | », « / »,
   retour à la ligne, « et »), reconnaît les régions et les départements sans réseau (`Regions`, `Departments`),
   applique la règle de la virgule et géocode les villes avec `PhotonGeocoder`
   (20 appels au plus par offre, 10 lieux au plus).
4. Le premier lieu est écrit dans les champs `geolocation_*` de WP Job Manager,
   la liste complète dans `_g2rd_wpjm_geo_places`.

`PhotonGeocoder` n'accepte que les lieux et limites administratives dont le
nom correspond au texte saisi : une offre reste sans coordonnées plutôt que
d'être placée sur un homonyme lointain. Entre deux lieux du même nom, il
favorise le département ou le code postal saisi, puis les autres segments du
texte, puis le pays du site. Les réponses sont gardées 30 jours en cache
(1 jour pour un lieu introuvable).

## Cycle de vie d'une offre Go Fetch Jobs

1. Go Fetch Jobs déclenche `goft_wpjm_after_insert_job` pour chaque offre,
   après avoir écrit ses métadonnées, à l'import manuel, planifié ou au
   réimport.
2. `ApplicationNormalizer` ne traite que les offres marquées
   `_goft_wpjm_is_external`. Une adresse email valide ou une valeur vide ne
   change pas.
   - Mode « toutes les URL » : toute autre valeur est remplacée tout de suite,
     sans requête HTTP.
   - Mode « URL invalides » : l'offre part dans `UrlCheckQueue`, et
     `UrlChecker` vérifie l'URL plus tard.
3. `goft_wpjm_after_insert_jobs_commit`, en fin de lot, repasse sur les offres
   au cas où une autre extension aurait réécrit `_application` entre-temps.
4. Chaque remplacement est inscrit par `Logger` et déclenche l'action
   `g2rd_wpjm_geo_application_replaced`.

Seule la métadonnée `_application` est écrite, par `update_post_meta()` : pas
de `wp_update_post()`, donc aucun `save_post` déclenché et aucune boucle
possible.

## La carte

`JobsMap` rend un conteneur vide. `map-common.js` attend que ce conteneur
approche de la zone visible, puis charge le moteur (`jobs-map.js` pour Google,
`jobs-map-osm.js` pour MapLibre) et lit la route REST page par page.

La route `GET /wp-json/g2rd-wpjm-geo/v1/jobs` (paramètres `page` et
`per_page`, 500 au plus) délègue la sélection à `get_job_listings()` de
WP Job Manager : offres expirées, pourvues ou protégées par mot de passe, langue
et filtres tiers suivent la logique native. Elle répond 404 si la carte est
désactivée, et refuse l'accès si WP Job Manager interdit de consulter les
offres. Chaque page est gardée 15 minutes en cache, et le cache est invalidé à
chaque modification d'offre.

Côté navigateur :

- les offres aux coordonnées identiques forment un seul point, avec la liste
  des offres dans sa fenêtre ;
- le regroupement des points proches utilise un rayon de 20 px pour MapLibre et
  de 40 px pour Supercluster côté Google, ce qui revient au même à l'écran ;
- en mode libre, une offre à plusieurs lieux donne un point par ville et une
  zone colorée par département ou région ; chaque fichier de contours
  (`assets/data/regions-fr.geojson`, `assets/data/departements-fr.geojson`)
  n'est chargé que si une de ses zones est affichée. Côté navigateur, le code
  d'un département est préfixé « D » (le département 28 n'est pas la région 28) ;
- la liste sous la carte reprend toutes les offres, une section par lieu,
  classées par ordre alphabétique, départements et régions à la fin.

## Files d'attente WP-Cron

`JobQueue` est la file générique : identifiants stockés dans une option
(5 000 au plus), verrou de 5 minutes, 25 secondes de travail par passage, puis
passage suivant une minute plus tard. Chaque offre est retirée de la file
avant son traitement, et un passage de secours est planifié dès la prise du
verrou. Quand un service ne répond pas, l'offre est remise en fin de file et
le passage suivant attend 10 minutes. Si le traitement d'une offre lève une
exception, l'offre est perdue, mais le verrou est libéré et la file repart.

| File ou tâche | Hook WP-Cron | Lot |
| --- | --- | --- |
| `GeocodeQueue` | `g2rd_wpjm_geo_geocode` | 10 offres |
| `UrlCheckQueue` | `g2rd_wpjm_geo_check_urls` | 2 offres |
| Purge du journal (`Logger`) | `g2rd_wpjm_geo_purge_log` | quotidienne |

Les outils de l'administration ne passent pas par WP-Cron mais par admin-ajax :
« Re-géocoder les offres » travaille par lots de 5 (20 secondes par requête),
offres publiées d'abord, et n'efface jamais de coordonnées ; l'outil Go Fetch
Jobs par lots de 50, ou de 2 quand il doit vérifier des URL.

## Sécurité par construction

- **Rendu** : tout contenu venant des offres est inséré par `textContent` ou
  par des nœuds DOM, jamais comme du HTML, sur la carte, dans la liste et dans
  les outils d'administration. Les crédits du style OpenStreetMap sont rendus
  en texte, avec des liens http(s) seulement.
- **REST** : la route ne renvoie que ce qu'affiche la carte (identifiant,
  titre, lien, entreprise, lieu, ville, pays, coordonnées, type de contrat,
  liste des lieux), en texte brut, et suit les règles de visibilité de
  WP Job Manager. Le filtre `g2rd_wpjm_geo_rest_job` permet de modifier ces
  données.
- **Requêtes sortantes** : les URL du style et de Photon doivent être en
  https, sans identifiants. Photon est interrogé avec un délai de 5 secondes
  et une réponse limitée à 256 Kio ; une adresse privée n'est acceptée qu'avec
  le filtre `g2rd_wpjm_geo_photon_allow_private_host`. Les URL de candidature
  ne sont vérifiées que par `wp_safe_remote_*()`, en http(s), avec 3
  redirections au plus.
- **Cache du géocodage** : il est indexé sur le texte réellement envoyé à
  Photon, pour qu'une variante saisie par un employeur ne puisse pas déplacer
  ou faire disparaître les offres des autres.
- **Clés** : seule la clé navigateur Google, à restreindre par référent, est
  envoyée au navigateur.
- **Administration** : capacité `manage_options`, nonces sur les requêtes
  admin-ajax, messages et titres échappés.
- **Écritures** : le plugin n'écrit que ses propres options et métadonnées,
  `_application` des offres Go Fetch Jobs et les coordonnées par les méthodes
  publiques de WP Job Manager.

## Tests

| Niveau | Où | Ce qui est couvert |
| --- | --- | --- |
| PHP | `g2rd-wpjm-geo/tests/run.php` | Harnais sans PHPUnit, fonctions WordPress simulées dans `tests/bootstrap.php` : réglages, imports Go Fetch Jobs simulés, géocodage, lieux multiples, REST, outils. Tout avertissement PHP compte comme un échec. |
| JS | `g2rd-wpjm-geo/tests/js/run.js` | Scripts publics et d'administration dans jsdom : autocomplétion, étiquettes, cartes (moteurs simulés), liste sous la carte, échappement. |
| Bout en bout | `tools/docker/verify.sh` | WordPress jetable avec WP Job Manager et Go Fetch Jobs : activation, les deux fournisseurs, vrai import, vrais appels Photon. |
| Release | `.github/workflows/release.yml` | ZIP construit, comparé aux fichiers suivis par git, activé dans un WordPress réel à côté de WP Job Manager. |

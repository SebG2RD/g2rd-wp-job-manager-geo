=== G2RD WP Job Manager Geo ===
Contributors: g2rd
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later

Complète WP Job Manager : autocomplétion mondiale des villes, carte des offres, candidatures Go Fetch Jobs.

== Description ==

* Fournisseur au choix : Google Maps, ou OpenStreetMap libre et gratuit (MapLibre GL JS 5.24.0, fonds OpenFreeMap, recherche et géocodage Photon), sans clé ni compte.
* Administration à la charte de la console G2RD : sous-menu du menu « G2RD » si le thème G2RD (console v2) est actif, menu « G2RD Geo » sinon.
* Autocomplétion des villes du monde entier (Google Places, ou Photon en mode libre) sur le champ de localisation NATIF de WP Job Manager : formulaire public (`job_location`) et wp-admin (`_job_location`). Aucun second champ.
* Carte interactive des offres publiées et géocodées par le fournisseur actif (`geolocation_lat` / `geolocation_long` de WP Job Manager), avec clustering et liste des offres partageant les mêmes coordonnées. Shortcode `[wpjm_jobs_map]` (attribut facultatif `height`) et bloc « Carte des offres d’emploi ».
* Mode libre : plusieurs lieux par offre (10 au plus), villes, départements et régions français, saisis en étiquettes dans le champ « Lieu » natif (enregistrés séparés par « ; »). Le texte libre et les imports sont reconnus aussi : « Paris, Lyon » donne deux villes, « Rouen, Normandie » une seule, « Calvados » le département. Sur la carte, un point par ville et une zone colorée par département ou région.
* Mises à jour : une release publiée sur le dépôt GitHub est proposée par WordPress (Extensions, Mises à jour), comme une extension de wordpress.org.
* Liste des offres dans wp-admin : colonne « Carte », ✓ vert si les villes de l'offre sont placées sur la carte ; sinon rouge, ✗ (lieu vide, ville introuvable, coordonnées d'un autre fournisseur de carte) ou horloge (géocodage en attente). L'infobulle donne les villes placées ou la raison, et un texte est lu par les lecteurs d'écran.
* Go Fetch Jobs (facultatif) : remplacement des URL de candidature des offres importées par une adresse email générique, outil d'analyse / simulation / correction par lots, journal avec rétention.

Le plugin ne modifie ni WP Job Manager, ni Go Fetch Jobs, ni le thème. En mode Google, le géocodage reste celui de WP Job Manager ; en mode libre, le plugin géocode les offres avec Photon et écrit les coordonnées par les méthodes publiques de WP Job Manager.

== Installation ==

1. Téléversez le dossier `g2rd-wpjm-geo` dans `wp-content/plugins/` puis activez l'extension (WP Job Manager doit être actif).
2. Mode Google : page « G2RD Geo » (ou G2RD → Offres géolocalisées si le thème G2RD est actif) → Cartographie : renseignez la « Google Maps Browser API Key ».
3. Mode Google : Google Cloud : activez « Maps JavaScript API » et « Places API (New) » ; restreignez la clé par référent HTTP (votre domaine) et à ces deux API.
4. Mode Google, facultatif : créez un Map ID pour utiliser les marqueurs avancés.
5. Mode libre : sur la même page, Cartographie → OpenStreetMap, puis Outils → Re-géocoder les offres (« Simuler » d'abord : les résultats sont mis en cache). Les offres publiées sont traitées en premier ; tant que l'outil n'a pas terminé, la carte libre n'affiche que les offres déjà géocodées par Photon.

== Options WordPress ==

* `g2rd_wpjm_geo_settings` : réglages généraux.
* `g2rd_wpjm_geo_fallback_email` : adresse email générique de candidature.
* Table `{prefix}g2rd_wpjm_geo_log` : journal des remplacements.
* Métadonnée `_g2rd_wpjm_geo_source` (google | osm) sur chaque offre géocodée par le plugin.
* Métadonnée `_g2rd_wpjm_geo_places` : lieux de l'offre en mode libre (villes, départements et régions) ; le premier est aussi écrit dans les champs `geolocation_*` de WP Job Manager.
* `g2rd_wpjm_geo_geocode_queue` : file des géocodages différés.
* `g2rd_wpjm_geo_db_version` : version du schéma du journal.
* `g2rd_wpjm_geo_url_queue` : file des vérifications d'URL différées.
* Transient `g2rd_wpjm_geo_github_release` : dernière release GitHub (6 heures ; 15 minutes après un échec).
* Transients `g2rd_wpjm_geo_*_lock`, `g2rd_wpjm_geo_photon_down`, `g2rd_geo_ph_*`, `g2rd_geo_url_*`, `jm_g2rdgeo_*` : verrous et caches temporaires.

== Hooks pour développeurs ==

* `g2rd_wpjm_geo_frontend_location_selectors`, `g2rd_wpjm_geo_admin_location_selectors` : sélecteurs du champ natif.
* `g2rd_wpjm_geo_rest_job` : données d'une offre envoyées à la carte.
* `g2rd_wpjm_geo_application_meta_key` : clé de la métadonnée de candidature (`_application`).
* `g2rd_wpjm_geo_application_replaced` : action déclenchée après un remplacement.
* `g2rd_wpjm_geo_photon_allow_private_host` : autorise un serveur Photon auto-hébergé à une adresse privée (seul son hôte).
* `g2rd_wpjm_geo_photon_preferred_country` : pays (code ISO à deux lettres) favorisé entre deux lieux du même nom ; défaut : région de la langue du site (FR pour fr_FR).
* `g2rd_wpjm_geo_defer_geocoding` : force (true) ou empêche (false) le géocodage différé par WP-Cron ; défaut : imports, WP-Cron et WP-CLI.

== Données tierces ==

* `assets/data/regions-fr.geojson` : contours des 18 régions françaises, extraits du jeu « Contours administratifs » (millésime 2025) publié par Etalab sur data.gouv.fr, sous licence ODbL 1.0 (voir `assets/data/LICENSE-regions.txt`). La source est citée sur la carte quand des régions sont affichées.
* `assets/data/departements-fr.geojson` : contours des 95 départements de métropole (sans Paris), même jeu, même licence ; chargés seulement quand un département est affiché.

== Tests ==

Depuis la racine du dépôt (les tests ne sont pas livrés dans l'archive) : `composer install` et `npm ci --prefix tools/jsdom --ignore-scripts` une fois, puis `composer test` (PHP), `composer test:js` (JS, jsdom 30 figé par `tools/jsdom/package-lock.json`) et `tools/docker/verify.sh` (WordPress jetable). Voir le README du dépôt.

== Frequently Asked Questions ==

= La carte libre reste vide ou affiche une erreur =
MapLibre a besoin de WebGL (WebGL 2, ou WebGL 1 à défaut) et crée un worker à partir d'une URL blob: : si le site envoie une politique de sécurité du contenu (CSP), elle doit autoriser `worker-src blob:` et, dans `connect-src` et `img-src`, les hôtes du style (tiles.openfreemap.org par défaut) et de Photon (photon.komoot.io par défaut).

= Réglages conseillés pour WP Rocket =
Le cache de pages est vidé automatiquement quand un réglage visible côté public change (fournisseur, style, serveur Photon, carte, autocomplétion). Avec « Retarder l'exécution du JavaScript », la carte ne démarre qu'à la première interaction : exclure `g2rd-wpjm-geo` si la carte est en haut de page. Avec « Supprimer le CSS inutilisé », ajouter `g2rd-wpjm-geo-` et `maplibregl-` à la liste des sélecteurs à conserver.

= Photon auto-hébergé =
Photon écoute par défaut en HTTP sur le port 2322 ; le plugin n'accepte qu'une URL https, sur le port 443 (proxy inverse). Pour une adresse privée (même serveur, réseau local), activer le filtre `g2rd_wpjm_geo_photon_allow_private_host`.

= Une offre n'apparaît pas sur la carte libre =
Le géocodage refuse un homonyme lointain plutôt que de mal placer l'offre ; « Télétravail » ou « Remote » ne sont jamais placés. Corriger la localisation de l'offre (de préférence via l'autocomplétion), puis lancer Outils → Re-géocoder les offres.

= Une offre concerne plusieurs villes, un département ou une région =
En mode libre, saisir chaque lieu dans le champ « Lieu » (étiquettes) ; sans JavaScript, les séparer par « ; » (une aide le rappelle sous le champ). Les régions françaises (noms actuels, anciens noms, sigles comme IDF ou PACA) et les départements de métropole (« Calvados », « Seine et Marne », « Département du Nord ») s'affichent en zone colorée, avec une étiquette « Normandie · 2 offres ». Paris est une ville ; les départements d'outre-mer (Guadeloupe, Martinique, Guyane, La Réunion, Mayotte) sont des régions. Au clavier, les offres de la carte et des régions sont accessibles par la liste placée sous la carte. Les offres créées avant la 1.2.0 gardent un seul point jusqu'au passage de l'outil Re-géocoder les offres (compteur « Libres, à mettre à jour »). En mode Google, une offre garde un seul point.

= Limites connues des lieux multiples =
* Texte libre et imports, règle de la virgule : « Calvados, Normandie » et « Caen, Calvados » ne donnent qu'un lieu (le département, la ville) ; « Vienne, Poitiers » ne donne que Poitiers (la Vienne est le département de Poitiers) ; « Normandie, Rouen, Caen » donne la zone Normandie plus Rouen et Caen, alors que « Rouen, Caen, Normandie » donne Rouen et Caen seulement. Pour lever le doute, séparer les lieux par « ; ».
* Les noms de régions et leurs variantes sont reconnus en français, ainsi que les noms anglais et allemands que Photon renvoie selon la langue du site (Normandy, Brittany, Korsika…). Les mentions vagues ne sont pas toutes reconnues : « Télétravail », « À distance » et « Hybride » en français, mais seulement « Remote » et « Full remote » en anglais ; « Hybrid », « Anywhere » et « Work from home » sont cherchées comme un nom de lieu.
* Une ancienne région (Alsace, Lorraine…) s'affiche comme la zone actuelle (Grand Est) ; deux anciennes régions de la même zone n'en font qu'une.
* Sans l'option de WP Job Manager « Display Location Address », la fiche affiche les noms de tous les lieux (« Paris, Lyon, Normandie ») et son lien Google Maps cherche ce texte. Avec l'option, la fiche et le lien portent sur l'adresse du premier lieu seulement. Les données structurées de WP Job Manager portent sur le premier lieu (champs de géolocalisation) ; à défaut de géolocalisation, elles reprennent le texte affiché.
* L'affichage public liste le nom court de chaque lieu, séparé par des virgules (« Rouen, Normandie, France ; Caen » devient « Rouen, Caen »), y compris en mode Google ; un lieu seul garde son texte complet.
* Sur la carte, le nombre d'un regroupement compte les lieux et non les offres distinctes ; à petite échelle, certaines étiquettes de région sont masquées pour éviter les chevauchements (la zone reste cliquable et la région figure dans la liste sous la carte).
* Retour au mode Google : la liste des lieux est ignorée et WP Job Manager géocode « Paris ; Lyon » comme une seule adresse.
* Une commune dont le nom contient « et » écrit avec des espaces plutôt qu'avec des tirets (« Saint Germain et Mons ») est coupée en deux. Seuls les départements et collectivités à « -et- » sont protégés.
* Une commune dont le nom est celui d'une région (« Bretagne », dans l'Indre) saisie à la main, sans indice de département ni code postal, est lue comme la région. Choisie dans la liste, elle reçoit un indice quand Photon donne un code postal à 5 chiffres : le département pour une commune française (« Bretagne (36), Centre-Val de Loire, France »), le code postal complet pour la Corse ou hors de France. Sans code postal à 5 chiffres, elle ne reçoit aucun indice et est lue comme la région.
* De même, un nom de département saisi seul désigne le département, même s'il est aussi celui d'une commune (« Vienne », « Mayenne ») ; choisie dans la liste, la commune reçoit son numéro (« Vienne (38) »). Suivi d'un autre lieu, le nom est d'abord cherché comme une commune : « Vienne, Isère » donne la ville de Vienne.

= Une mise à jour n'est pas proposée =
WordPress interroge le dépôt GitHub deux fois par jour, avec un cache de 6 heures dans le plugin : une release peut mettre jusqu'à 18 heures à apparaître. Tableau de bord → Mises à jour → « Vérifier à nouveau » vide les deux caches. Le site doit pouvoir joindre api.github.com et github.com. Une version antérieure à la 1.4.0 ne sait pas se mettre à jour : installer la 1.4.0 une fois à la main.

= Multisite =
Le plugin est prévu pour un site seul : en multisite, la désinstallation ne nettoie que le site courant.

== Changelog ==

= 1.4.0 =
* Mode libre : un ou plusieurs départements par offre, comme les régions : proposés par l'autocomplétion (mention « Département »), reconnus dans le texte libre et les imports (« Calvados », « Seine et Marne », « Département du Nord »), dessinés en zone colorée sur la carte, avec une bulle « Calvados (tout le département) », la liste sous la carte et la colonne « Carte » de l'administration. Une région ou un département couverts par un lieu plus précis du même morceau sont retirés (« Calvados, Normandie », « Caen, Calvados »).
* Mises à jour : les releases du dépôt GitHub sont proposées par WordPress sur les sites qui ont le plugin, avec la fiche « Voir les détails ». Installer cette version une fois à la main : les suivantes seront proposées automatiquement.

= 1.3.0 =
* Liste des offres dans wp-admin : nouvelle colonne « Carte ». ✓ vert : villes placées sur la carte. ✗ rouge : lieu vide, ville introuvable, ou coordonnées d'un autre fournisseur de carte. Horloge rouge : géocodage en attente. L'infobulle donne les villes placées ou la raison, et un texte est lu par les lecteurs d'écran.

= 1.2.2 =
* Correctif : une offre enregistrée avec plusieurs villes (étiquettes choisies dans l'autocomplétion, ou séparées par « ; ») a un point par ville sur la carte dès l'enregistrement. Auparavant, dès deux villes inconnues du cache, l'offre perdait ses coordonnées et attendait le passage de WP-Cron, sans point sur la carte en attendant, et pour toujours si WP-Cron ne tournait pas. Le temps accordé à Photon pendant l'enregistrement est borné à 8 secondes ; au-delà, la file d'attente prend le relais comme avant.

= 1.2.1 =
* Carte : points moins regroupés quand on dézoome (rayon de 20 px), sur la carte libre comme sur la carte Google : en vue France, Paris, Évreux, le Nord ou Lyon restent distincts.
* Liste des offres sous la carte : pleine largeur, une section par lieu (ordre alphabétique, régions à la fin), une ligne par offre, nombre d'offres dans la barre d'ouverture ; styles protégés contre ceux du thème.
* Console d'administration : sommaire des réglages plus lisible, sur le bandeau sombre comme sur écran étroit.

= 1.2.0 =
* Mode libre : plusieurs lieux par offre (10 au plus), villes et régions françaises, avec une autocomplétion à étiquettes sur le champ « Lieu » natif et une aide « ; » sous le champ, même sans autocomplétion.
* Reconnaissance côté serveur des saisies libres et des imports : séparateurs « ; », « / », « | », « et », règle de la virgule.
* Carte libre : un point par ville, ligne « Aussi à », régions en zones colorées avec une étiquette (nom et nombre d'offres), une seule fenêtre ouverte à la fois, légende, cadrage sur l'étendue des régions, liste des régions sous la carte.
* Contours des régions embarqués (data.gouv.fr, ODbL), chargés seulement si une offre régionale est affichée ; repères de secours s'ils manquent.
* Saisie : au plus un appel à Photon pendant l'enregistrement ; au-delà, les lieux sont placés par la file d'attente.
* Outil de re-géocodage : reprise des offres libres antérieures pour placer tous leurs lieux.
* Affichage public : quand une offre a plusieurs lieux, leurs noms sont listés séparés par des virgules (« Paris, Lyon, Normandie »), aussi en mode Google.
* Après la mise à jour, vider le cache des pages (WP Rocket ou autre) pour que la carte reçoive sa nouvelle configuration.
* Cache du géocodage Photon renouvelé (version 4).

= 1.1.1 =
* Sécurité : toute valeur de candidature d'une offre Go Fetch Jobs qui n'est pas une adresse email est désormais remplacée (ou vérifiée), y compris les formes « //site/… », « /site/… » ou le texte libre, que WP Job Manager affichait comme lien de candidature.
* Sécurité : en-tête « Update URI » vers le dépôt GitHub. WordPress ne propose plus de mise à jour ni de paquet de langue venant de wordpress.org pour ce slug.
* Sécurité : carte OpenStreetMap, crédits du style affichés en texte (liens http(s) seulement) au lieu du HTML fourni par l'hôte du style.
* Sécurité : messages des réglages et titres de menu traduits échappés.
* Sécurité : cache du géocodage Photon indexé sur le texte réellement interrogé. Une variante saisie par un soumetteur (« Paris Москва », « Paris ＴＥＸＡＳ ») ne peut plus faire disparaître de la carte, ni placer au Texas, les offres « Paris » des autres employeurs. Le cache existant est ignoré (version 3).
* Qualité : code conforme aux normes WordPress, à PHPCS Security Audit et à PHPStan niveau 6 ; CI/CD GitHub Actions.

= 1.1.0 =
* Fournisseur cartographique libre : MapLibre + OpenFreeMap + Photon.
* Géocodage libre des offres (différé pendant les imports), outil de re-géocodage.
* Administration à la charte de la console G2RD.
* Texte suggéré pour la politique de confidentialité.

= 1.0.0 =
* Version initiale.

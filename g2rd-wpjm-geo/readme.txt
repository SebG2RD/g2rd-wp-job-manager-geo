=== G2RD WP Job Manager Geo ===
Contributors: g2rd
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Complète WP Job Manager : autocomplétion mondiale des villes, carte des offres, candidatures Go Fetch Jobs.

== Description ==

* Fournisseur au choix : Google Maps, ou OpenStreetMap libre et gratuit (MapLibre GL JS 5.24.0, fonds OpenFreeMap, recherche et géocodage Photon), sans clé ni compte.
* Administration à la charte de la console G2RD : sous-menu du menu « G2RD » si le thème G2RD (console v2) est actif, menu « G2RD Geo » sinon.
* Autocomplétion Google Places (villes du monde entier) sur le champ de localisation NATIF de WP Job Manager : formulaire public (`job_location`) et wp-admin (`_job_location`). Aucun second champ.
* Carte interactive des offres publiées et géocodées par WP Job Manager (`geolocation_lat` / `geolocation_long`), avec clustering et liste des offres partageant les mêmes coordonnées. Shortcode `[wpjm_jobs_map]` (attribut facultatif `height`) et bloc « Carte des offres d’emploi ».
* Go Fetch Jobs (facultatif) : remplacement des URL de candidature des offres importées par une adresse email générique, outil d'analyse / simulation / correction par lots, journal avec rétention.

Le plugin ne modifie ni WP Job Manager, ni Go Fetch Jobs, ni le thème. Le géocodage reste celui de WP Job Manager.

== Installation ==

1. Téléversez le dossier `g2rd-wpjm-geo` dans `wp-content/plugins/` puis activez l'extension (WP Job Manager doit être actif).
2. Offres d'emploi → G2RD Geo : renseignez la « Google Maps Browser API Key ».
3. Google Cloud : activez « Maps JavaScript API » et « Places API (New) » ; restreignez la clé par référent HTTP (votre domaine) et à ces deux API.
4. Facultatif : créez un Map ID pour utiliser les marqueurs avancés.
5. Mode libre : Offres géolocalisées → Cartographie → OpenStreetMap, puis Outils → Re-géocoder les offres.

== Options WordPress ==

* `g2rd_wpjm_geo_settings` : réglages généraux.
* `g2rd_wpjm_geo_fallback_email` : adresse email générique de candidature.
* Table `{prefix}g2rd_wpjm_geo_log` : journal des remplacements.
* Métadonnée `_g2rd_wpjm_geo_source` (google | osm) sur chaque offre géocodée par le plugin.
* `g2rd_wpjm_geo_geocode_queue` : file des géocodages différés.

== Hooks pour développeurs ==

* `g2rd_wpjm_geo_frontend_location_selectors`, `g2rd_wpjm_geo_admin_location_selectors` : sélecteurs du champ natif.
* `g2rd_wpjm_geo_rest_job` : données d'une offre envoyées à la carte.
* `g2rd_wpjm_geo_application_meta_key` : clé de la métadonnée de candidature (`_application`).
* `g2rd_wpjm_geo_application_replaced` : action déclenchée après un remplacement.
* `g2rd_wpjm_geo_photon_allow_private_host` : autorise un serveur Photon auto-hébergé à une adresse privée (seul son hôte).
* `g2rd_wpjm_geo_photon_preferred_country` : pays (code ISO à deux lettres) favorisé entre deux lieux du même nom ; défaut : région de la langue du site (FR pour fr_FR).
* `g2rd_wpjm_geo_defer_geocoding` : force (true) ou empêche (false) le géocodage différé par WP-Cron ; défaut : imports, WP-Cron et WP-CLI.

== Tests ==

`php tests/run.php` ; `npm --prefix ../tools/jsdom i jsdom@24` puis `NODE_PATH=../tools/jsdom/node_modules node tests/js/run.js` ; `tools/docker/verify.sh` (dépôt).

== Frequently Asked Questions ==

= La carte libre reste vide ou affiche une erreur =
MapLibre a besoin de WebGL (WebGL 2, ou WebGL 1 à défaut) et crée un worker à partir d'une URL blob: : si le site envoie une politique de sécurité du contenu (CSP), elle doit autoriser `worker-src blob:` ainsi que les hôtes du style (tiles.openfreemap.org par défaut) et de Photon (photon.komoot.io par défaut).

= Réglages conseillés pour WP Rocket =
Le cache de pages est vidé automatiquement quand le fournisseur change. Avec « Retarder l'exécution du JavaScript », la carte ne démarre qu'à la première interaction : exclure `g2rd-wpjm-geo` si la carte est en haut de page. Avec « Supprimer le CSS inutilisé », ajouter `g2rd-wpjm-geo-` et `maplibregl-` à la liste des sélecteurs à conserver.

= Photon auto-hébergé =
Photon écoute par défaut en HTTP sur le port 2322 ; le plugin n'accepte qu'une URL https, sur le port 443 (proxy inverse). Pour une adresse privée (même serveur, réseau local), activer le filtre `g2rd_wpjm_geo_photon_allow_private_host`.

= Une offre n'apparaît pas sur la carte libre =
Le géocodage refuse un homonyme lointain plutôt que de mal placer l'offre ; « Télétravail » ou « Remote » ne sont jamais placés. Corriger la localisation de l'offre (de préférence via l'autocomplétion), puis lancer Outils → Re-géocoder les offres.

== Changelog ==

= 1.1.0 =
* Fournisseur cartographique libre : MapLibre + OpenFreeMap + Photon.
* Géocodage libre des offres (différé pendant les imports), outil de re-géocodage.
* Administration à la charte de la console G2RD.
* Texte suggéré pour la politique de confidentialité.

= 1.0.0 =
* Version initiale.

=== G2RD WP Job Manager Geo ===
Contributors: g2rd
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Complète WP Job Manager : autocomplétion mondiale des villes, carte des offres, candidatures Go Fetch Jobs.

== Description ==

* Autocomplétion Google Places (villes du monde entier) sur le champ de localisation NATIF de WP Job Manager : formulaire public (`job_location`) et wp-admin (`_job_location`). Aucun second champ.
* Carte interactive des offres publiées et géocodées par WP Job Manager (`geolocation_lat` / `geolocation_long`), avec clustering et liste des offres partageant les mêmes coordonnées. Shortcode `[wpjm_jobs_map]` (attribut facultatif `height`) et bloc « Carte des offres d’emploi ».
* Go Fetch Jobs (facultatif) : remplacement des URL de candidature des offres importées par une adresse email générique, outil d'analyse / simulation / correction par lots, journal avec rétention.

Le plugin ne modifie ni WP Job Manager, ni Go Fetch Jobs, ni le thème. Le géocodage reste celui de WP Job Manager.

== Installation ==

1. Téléversez le dossier `g2rd-wpjm-geo` dans `wp-content/plugins/` puis activez l'extension (WP Job Manager doit être actif).
2. Offres d'emploi → G2RD Geo : renseignez la « Google Maps Browser API Key ».
3. Google Cloud : activez « Maps JavaScript API » et « Places API (New) » ; restreignez la clé par référent HTTP (votre domaine) et à ces deux API.
4. Facultatif : créez un Map ID pour utiliser les marqueurs avancés.

== Options WordPress ==

* `g2rd_wpjm_geo_settings` : réglages généraux.
* `g2rd_wpjm_geo_fallback_email` : adresse email générique de candidature.
* Table `{prefix}g2rd_wpjm_geo_log` : journal des remplacements.

== Hooks pour développeurs ==

* `g2rd_wpjm_geo_frontend_location_selectors`, `g2rd_wpjm_geo_admin_location_selectors` : sélecteurs du champ natif.
* `g2rd_wpjm_geo_rest_job` : données d'une offre envoyées à la carte.
* `g2rd_wpjm_geo_application_meta_key` : clé de la métadonnée de candidature (`_application`).
* `g2rd_wpjm_geo_application_replaced` : action déclenchée après un remplacement.

== Tests ==

`php tests/run.php` (aucune installation WordPress requise).

== Changelog ==

= 1.0.0 =
* Version initiale.

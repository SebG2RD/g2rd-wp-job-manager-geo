#!/usr/bin/env bash
# Vérification de bout en bout du plugin dans un WordPress jetable.
# Prérequis : Docker, ./fetch-plugins.sh. Usage : ./verify.sh
set -u
cd "$(dirname "$0")"
export MSYS_NO_PATHCONV=1
B=http://localhost:8899
pass=0
fail=0
wp() { docker compose exec -T cli wp "$@"; }
# Indicateur : une suite de 1 (plusieurs contrôles concaténés) ; un seul 0 fait échouer la ligne.
ok() { if [ -n "$2" ] && [ -z "${2//1/}" ]; then echo "  [OK]   $1"; pass=$((pass + 1)); else echo "  [FAIL] $1"; fail=$((fail + 1)); fi; }
has() { case "$1" in *"$2"*) echo 1 ;; *) echo 0 ;; esac; }
hasnt() { case "$1" in *"$2"*) echo 0 ;; *) echo 1 ;; esac; }
is() { [ "$1" = "$2" ] && echo 1 || echo 0; }

docker compose up -d >/dev/null 2>&1
for i in $(seq 1 40); do docker compose exec -T cli test -f /var/www/html/wp-load.php 2>/dev/null && break; sleep 3; done
wp core is-installed >/dev/null 2>&1 || wp core install --url="$B" --title="G2RD Geo" --admin_user=admin --admin_password=admin-test-pw --admin_email=admin@example.test --skip-email >/dev/null
wp rewrite structure '/%postname%/' >/dev/null
wp theme activate twentytwentyfive >/dev/null 2>&1
wp plugin activate wp-job-manager go-fetch-jobs-wp-job-manager g2rd-wpjm-geo >/dev/null
# Remise à zéro : la base survit d'une exécution à l'autre, et les offres laissées par les passages
# précédents (géocodées en 1.1.x, sans liste de lieux) fausseraient les comptes et l'ordre de la section 8.
# Le cache des réponses Photon est conservé : il épargne le service public et ne fausse aucun contrôle.
OLD=$(wp post list --post_type=job_listing --post_status=publish,draft,pending,private,future,trash --field=ID 2>/dev/null | tr '
' ' ')
[ -n "${OLD// /}" ] && wp post delete $OLD --force >/dev/null
for k in g2rd_wpjm_geo_geocode_queue g2rd_wpjm_geo_url_queue; do wp option delete "$k" >/dev/null 2>&1; done
for k in g2rd_wpjm_geo_geocode_lock g2rd_wpjm_geo_url_queue_lock g2rd_wpjm_geo_photon_down; do wp transient delete "$k" >/dev/null 2>&1; done
wp option update job_manager_user_requires_account 0 >/dev/null
wp option update g2rd_wpjm_geo_settings '{"provider":"google"}' --format=json >/dev/null

rm -f cookies.txt
curl -s -c cookies.txt -o /dev/null "$B/wp-login.php"
curl -s -b cookies.txt -c cookies.txt -o /dev/null -d "log=admin&pwd=admin-test-pw&wp-submit=Log+In&redirect_to=$B/wp-admin/&testcookie=1" "$B/wp-login.php"
admin() { curl -s -b cookies.txt "$B/wp-admin/$1"; }

echo "1. Administration sans le thème G2RD"
P=$(admin 'admin.php?page=g2rd-wpjm-geo')
ok 'menu autonome' "$(has "$P" 'toplevel_page_g2rd-wpjm-geo')"
ok 'gabarit de la console' "$(has "$P" 'g2rd-app g2rd-app--php')"
ok 'feuille de repli du plugin' "$(has "$P" 'g2rd-wpjm-geo/assets/css/console.css')"
ok 'feuille du sommaire du plugin' "$(has "$P" 'g2rd-wpjm-geo/assets/css/admin-geo.css')"
ok 'cartes de réglages et outils' "$(has "$P" 'id="g2rd-geo-cartographie"')$(has "$P" 'id="g2rd-geo-outils"')"
ok 'aucune erreur fatale' "$(hasnt "$P" 'critical error')"

echo "2. Administration avec le thème G2RD"
if wp theme activate g2rd-theme >/dev/null 2>&1; then
  P=$(admin 'admin.php?page=g2rd-wpjm-geo')
  ok 'sous-menu du menu G2RD' "$(has "$P" 'g2rd_page_g2rd-wpjm-geo')"
  ok 'feuille de la console du thème' "$(has "$P" "id='g2rd-console-css'")"
  ok 'pas de feuille de repli' "$(hasnt "$P" 'assets/css/console.css')"
  ok 'feuille du sommaire du plugin, aussi avec le thème' "$(has "$P" 'assets/css/admin-geo.css')"
  wp theme activate twentytwentyfive >/dev/null
else
  echo "  [SKIP] thème G2RD absent (variable G2RD_THEME_DIR)"
fi

echo "3. Bascule vers OpenStreetMap"
wp option update g2rd_wpjm_geo_settings '{"provider":"osm"}' --format=json >/dev/null
ok 'géocodage Google de WP Job Manager coupé' "$(is "$(wp eval 'echo apply_filters( "job_manager_geolocation_enabled", true ) ? "on" : "off";')" off)"

echo "4. Saisie interactive (API REST, session administrateur)"
JOB=$(wp post create --post_type=job_listing --post_status=publish --post_title='Poste Montreal' --porcelain)
NONCE=$(admin 'post-new.php' | grep -o 'createNonceMiddleware( "[a-f0-9]*" )' | grep -o '[a-f0-9]\{10\}' | head -1)
curl -s -b cookies.txt -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' -X POST -d '{"meta":{"_job_location":"Montr\u00e9al, Qu\u00e9bec, Canada"}}' "$B/wp-json/wp/v2/job-listings/$JOB" >/dev/null
ok 'Montréal géocodée immédiatement par Photon' "$(is "$(wp post meta get "$JOB" geolocation_country_short 2>/dev/null)" CA)"
ok 'source osm' "$(is "$(wp post meta get "$JOB" _g2rd_wpjm_geo_source 2>/dev/null)" osm)"

echo "5. WP-CLI : géocodage différé"
JOB2=$(wp post create --post_type=job_listing --post_status=publish --post_title='Poste Rouen' --porcelain)
wp post meta update "$JOB2" _job_location 'Rouen, Normandie, France' >/dev/null
ok 'pas de coordonnées immédiates' "$(is "$(wp post meta get "$JOB2" geolocated 2>/dev/null)" '')"
ok 'offre en file' "$(has "$(wp option get g2rd_wpjm_geo_geocode_queue --format=json)" "$JOB2")"
wp cron event run g2rd_wpjm_geo_geocode >/dev/null
ok 'cron : Rouen géocodée' "$(is "$(wp post meta get "$JOB2" geolocation_city 2>/dev/null)" Rouen)"

echo "6. Import Go Fetch Jobs"
wp eval '$items = array( array( "title" => "Import Tokyo " . wp_generate_password( 6, false ), "description" => "Offre importee pour le test de bout en bout, assez longue.", "link" => "https://source.example/job/1", "date" => gmdate( "Y-m-d" ), "jobkey" => md5( microtime() ), "jobfeed" => "test", "provider_id" => "custom" ) ); GoFetch_Importer::import( $items, array( "rss_feed_import" => "https://source.example/feed.xml", "post_author" => 1, "meta" => array( "_job_location" => "Tokyo, Japon" ), "source" => array( "name" => "Test" ) ) );' >/dev/null
JOB3=$(wp post list --post_type=job_listing --meta_key=_goft_wpjm_is_external --field=ID --posts_per_page=1 --orderby=ID --order=DESC)
ok 'offre importée en file' "$(has "$(wp option get g2rd_wpjm_geo_geocode_queue --format=json)" "$JOB3")"
wp cron event run g2rd_wpjm_geo_geocode >/dev/null
ok 'import géocodé (Japon)' "$(is "$(wp post meta get "$JOB3" geolocation_country_short 2>/dev/null)" JP)"

echo "7. Carte : source active seulement"
JOBG=$(wp post create --post_type=job_listing --post_status=publish --post_title='Ancienne offre Google' --porcelain)
wp eval "remove_all_actions( 'job_manager_job_location_edited' ); foreach ( array( '_job_location' => 'Paris, France', 'geolocated' => 1, 'geolocation_lat' => '48.85', 'geolocation_long' => '2.35' ) as \$k => \$v ) { update_post_meta( $JOBG, \$k, \$v ); }" >/dev/null
R=$(curl -s "$B/wp-json/g2rd-wpjm-geo/v1/jobs")
ok 'offre libre présente' "$(has "$R" "\"id\":$JOB,")"
ok 'offre géocodée par Google exclue' "$(hasnt "$R" "\"id\":$JOBG,")"

echo "8. Outil de re-géocodage"
P=$(admin 'admin.php?page=g2rd-wpjm-geo')
RN=$(echo "$P" | tr -d '\n\t' | grep -o 'data-action="g2rd_wpjm_geo_regeocode"[^>]*' | grep -o 'data-nonce="[a-f0-9]*"' | grep -o '[a-f0-9]\{10\}')
ajax() { curl -s -b cookies.txt -d "action=g2rd_wpjm_geo_regeocode&nonce=$RN&task=$1&cursor=0" "$B/wp-admin/admin-ajax.php"; }
ok 'analyse : 1 offre à re-géocoder' "$(has "$(ajax analyze)" '"todo":1')"
ok 'simulation : résultat prévu' "$(has "$(ajax simulate)" '"status":"ok"')"
ok 'simulation : aucune écriture' "$(is "$(wp post meta get "$JOBG" _g2rd_wpjm_geo_source 2>/dev/null)" '')"
ok 'exécution : offre re-géocodée' "$(has "$(ajax run)" '"status":"done"')"
ok 'exécution : source osm' "$(is "$(wp post meta get "$JOBG" _g2rd_wpjm_geo_source 2>/dev/null)" osm)"

echo "9. Pages publiques"
MAP=$(wp post create --post_type=page --post_status=publish --post_title='Carte' --post_content='[wpjm_jobs_map]' --porcelain)
FORM=$(wp post create --post_type=page --post_status=publish --post_title='Deposer' --post_content='[submit_job_form]' --porcelain)
H=$(curl -sL "$B/?page_id=$MAP")
ok 'carte : moteur MapLibre et fonctions communes' "$(has "$H" 'jobs-map-osm.js')$(has "$H" 'map-common.js')"
ok 'carte : aucun script Google' "$(hasnt "$H" 'google-loader.js')$(hasnt "$H" 'maps.googleapis.com')"
ok 'carte : MapLibre non chargé d’avance' "$(hasnt "$H" 'vendor/maplibre-gl/maplibre-gl.js')"
F=$(curl -sL "$B/?page_id=$FORM")
ok 'formulaire : autocomplétion Photon' "$(has "$F" 'location-autocomplete.js')$(has "$F" '"source":"photon"')"
ok 'formulaire : aucun script Google' "$(hasnt "$F" 'google-loader.js')"
ok 'formulaire : un seul champ de localisation' "$(is "$(echo "$F" | grep -o 'name="job_location"' | wc -l | tr -d ' ')" 1)"

echo "10. Plusieurs lieux et régions (mode libre)"
JOBM=$(wp post create --post_type=job_listing --post_status=publish --post_title='Poste multi-lieux' --porcelain)
# Villes retirées du cache Photon : l'enregistrement doit les placer lui-même, sans attendre WP-Cron.
wp eval '$g = new G2RD\WPJMGeo\PhotonGeocoder( new G2RD\WPJMGeo\Settings() ); $k = new ReflectionMethod( $g, "cache_key" ); $k->setAccessible( true ); foreach ( array( "Paris", "Lyon" ) as $p ) { delete_transient( $k->invoke( $g, $p, G2RD\WPJMGeo\PhotonGeocoder::clean( $p ) ) ); }' >/dev/null
wp option delete g2rd_wpjm_geo_geocode_queue >/dev/null 2>&1
curl -s -b cookies.txt -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' -X POST -d '{"meta":{"_job_location":"Paris ; Lyon ; Normandie"}}' "$B/wp-json/wp/v2/job-listings/$JOBM" >/dev/null
ok 'trois lieux enregistrés dès l’enregistrement, sans passage WP-Cron' "$(is "$(wp eval "echo count( (array) get_post_meta( $JOBM, '_g2rd_wpjm_geo_places', true ) );")" 3)"
ok 'offre multi-lieux absente de la file' "$(hasnt "$(wp option get g2rd_wpjm_geo_geocode_queue --format=json 2>/dev/null)" "$JOBM")"
ok 'premier lieu dans WP Job Manager' "$(is "$(wp post meta get "$JOBM" geolocation_city 2>/dev/null)" Paris)"
ok 'texte saisi conservé' "$(is "$(wp post meta get "$JOBM" _job_location 2>/dev/null)" 'Paris ; Lyon ; Normandie')"
R=$(curl -s "$B/wp-json/g2rd-wpjm-geo/v1/jobs")
ok 'API REST : champ places avec la région et son étendue' "$(has "$R" '"type":"region"')$(has "$R" '"region":"28"')$(has "$R" '"bbox"')"
ok 'contours embarqués servis' "$(is "$(curl -s -o /dev/null -w '%{http_code}' "$B/wp-content/plugins/g2rd-wpjm-geo/assets/data/regions-fr.geojson")" 200)"
ok 'fiche publique : « Paris, Lyon, Normandie »' "$(has "$(curl -sL "$B/?p=$JOBM")" 'Paris, Lyon, Normandie')"
wp eval '$items = array( array( "title" => "Import multi " . wp_generate_password( 6, false ), "description" => "Offre importee sur plusieurs villes, pour le test de bout en bout.", "link" => "https://source.example/job/2", "date" => gmdate( "Y-m-d" ), "jobkey" => md5( microtime() ), "jobfeed" => "test", "provider_id" => "custom" ) ); GoFetch_Importer::import( $items, array( "rss_feed_import" => "https://source.example/feed.xml", "post_author" => 1, "meta" => array( "_job_location" => "Rouen / Caen" ), "source" => array( "name" => "Test" ) ) );' >/dev/null
JOB4=$(wp post list --post_type=job_listing --meta_key=_goft_wpjm_is_external --field=ID --posts_per_page=1 --orderby=ID --order=DESC)
wp cron event run g2rd_wpjm_geo_geocode >/dev/null
ok 'import Go Fetch : deux villes placées' "$(is "$(wp eval "echo count( (array) get_post_meta( $JOB4, '_g2rd_wpjm_geo_places', true ) );")" 2)"
F=$(curl -sL "$B/?page_id=$FORM")
ok 'formulaire : script des étiquettes, régions et aide « ; »' "$(has "$F" 'location-tags.js')$(has "$F" '"tags":true')$(has "$F" '"code":"28"')$(has "$F" 'Séparez plusieurs lieux')"

JOBE=$(wp post create --post_type=job_listing --post_status=publish --post_title='Poste sans lieu' --porcelain)
L=$(admin 'edit.php?post_type=job_listing')
ok 'liste des offres : colonne « Carte » et sa feuille' "$(has "$L" 'column-g2rd_wpjm_geo_map')$(has "$L" 'assets/css/admin-list.css')"
ok 'liste des offres : offre multi-lieux en vert, avec ses lieux' "$(has "$L" 'g2rd-wpjm-geo-mapcol--placed')$(has "$L" 'sur la carte : Paris, Lyon, Normandie')"
ok 'liste des offres : offre sans lieu en rouge' "$(has "$L" 'g2rd-wpjm-geo-mapcol--empty')"

JOBD=$(wp post create --post_type=job_listing --post_status=publish --post_title='Poste départements' --porcelain)
curl -s -b cookies.txt -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' -X POST -d '{"meta":{"_job_location":"Calvados ; Seine et Marne ; Vienne (38)"}}' "$B/wp-json/wp/v2/job-listings/$JOBD" >/dev/null
ok 'départements placés, ville homonyme avec son numéro gardée comme ville' "$(is "$(wp eval "echo implode( '|', array_map( function ( \$p ) { return \$p['type'] . ':' . \$p['label']; }, (array) get_post_meta( $JOBD, '_g2rd_wpjm_geo_places', true ) ) );")" 'department:Calvados|department:Seine-et-Marne|city:Vienne')"
ok 'département : région du département dans WP Job Manager' "$(is "$(wp post meta get "$JOBD" geolocation_state_long 2>/dev/null)" Normandie)"
R=$(curl -s "$B/wp-json/g2rd-wpjm-geo/v1/jobs")
ok 'API REST : départements avec leur code' "$(has "$R" '"type":"department"')$(has "$R" '"department":"14"')$(has "$R" '"department":"77"')"
ok 'contours des départements servis' "$(is "$(curl -s -o /dev/null -w '%{http_code}' "$B/wp-content/plugins/g2rd-wpjm-geo/assets/data/departements-fr.geojson")" 200)"
ok 'liste des offres : départements dans la colonne « Carte »' "$(has "$(admin 'edit.php?post_type=job_listing')" 'sur la carte : Calvados, Seine-et-Marne, Vienne')"
F=$(curl -sL "$B/?page_id=$FORM")
ok 'formulaire : liste des départements transmise' "$(has "$F" '"departments":[')$(has "$F" '"name":"Calvados"')"

echo "11. Retour vers Google"
wp option update g2rd_wpjm_geo_settings '{"provider":"google"}' --format=json >/dev/null
R=$(curl -s "$B/wp-json/g2rd-wpjm-geo/v1/jobs")
ok 'carte Google : offres libres exclues' "$(hasnt "$R" "\"id\":$JOB,")"
ok 'géocodage de WP Job Manager réactivé' "$(is "$(wp eval 'echo apply_filters( "job_manager_geolocation_enabled", true ) ? "on" : "off";')" on)"

echo "12. Mises à jour depuis GitHub (vraie API)"
# Version installée remplacée par 1.0.0 dans le cache de get_plugins() : WordPress doit trouver la dernière release.
U=$(wp eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; delete_site_transient( "update_plugins" ); $f = plugin_basename( G2RD_WPJM_GEO_FILE ); $all = get_plugins(); $all[ $f ]["Version"] = "1.0.0"; wp_cache_set( "plugins", array( "" => $all ), "plugins" ); wp_update_plugins(); $t = get_site_transient( "update_plugins" ); echo isset( $t->response[ $f ] ) ? $t->response[ $f ]->new_version . " " . $t->response[ $f ]->package : "aucune";')
ok 'mise à jour proposée par WordPress, paquet de la release GitHub' "$(has "$U" ' https://github.com/SebG2RD/g2rd-wp-job-manager-geo/releases/download/v')$(has "$U" '/g2rd-wpjm-geo-')"
P=${U#* }
ok 'paquet téléchargeable' "$(is "$(curl -sL -o /dev/null -w '%{http_code}' "$P")" 200)"
U=$(wp eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; delete_site_transient( "update_plugins" ); wp_update_plugins(); $t = get_site_transient( "update_plugins" ); $f = plugin_basename( G2RD_WPJM_GEO_FILE ); echo isset( $t->response[ $f ] ) ? "proposée" : "aucune";')
ok 'version installée à jour : rien de proposé' "$(is "$U" aucune)"
I=$(wp eval 'require_once ABSPATH . "wp-admin/includes/plugin-install.php"; $i = plugins_api( "plugin_information", array( "slug" => "g2rd-wpjm-geo" ) ); echo is_wp_error( $i ) ? "erreur" : $i->name . " " . $i->version;')
ok '« Voir les détails » : fiche de la release' "$(has "$I" 'G2RD WP Job Manager Geo ')"

echo "13. Journal PHP"
L=$(docker compose exec -T wp sh -c 'grep -E "PHP (Fatal error|Parse error|Warning|Notice|Deprecated)" /var/www/html/wp-content/debug.log 2>/dev/null | grep "plugins/g2rd-wpjm-geo" || true')
ok 'aucune erreur liée au plugin' "$(is "$L" '')"

echo
echo "Résultat Docker : $pass réussis, $fail échoués"
[ "$fail" = 0 ]

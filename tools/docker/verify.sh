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
ok 'cartes de réglages et outils' "$(has "$P" 'id="g2rd-geo-cartographie"')$(has "$P" 'id="g2rd-geo-outils"')"
ok 'aucune erreur fatale' "$(hasnt "$P" 'critical error')"

echo "2. Administration avec le thème G2RD"
if wp theme activate g2rd-theme >/dev/null 2>&1; then
  P=$(admin 'admin.php?page=g2rd-wpjm-geo')
  ok 'sous-menu du menu G2RD' "$(has "$P" 'g2rd_page_g2rd-wpjm-geo')"
  ok 'feuille de la console du thème' "$(has "$P" "id='g2rd-console-css'")"
  ok 'pas de feuille de repli' "$(hasnt "$P" 'assets/css/console.css')"
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

echo "10. Retour vers Google"
wp option update g2rd_wpjm_geo_settings '{"provider":"google"}' --format=json >/dev/null
R=$(curl -s "$B/wp-json/g2rd-wpjm-geo/v1/jobs")
ok 'carte Google : offres libres exclues' "$(hasnt "$R" "\"id\":$JOB,")"
ok 'géocodage de WP Job Manager réactivé' "$(is "$(wp eval 'echo apply_filters( "job_manager_geolocation_enabled", true ) ? "on" : "off";')" on)"

echo "11. Journal PHP"
L=$(docker compose exec -T wp sh -c 'grep -i "g2rd" /var/www/html/wp-content/debug.log 2>/dev/null || true')
ok 'aucune erreur liée au plugin' "$(is "$L" '')"

echo
echo "Résultat Docker : $pass réussis, $fail échoués"
[ "$fail" = 0 ]

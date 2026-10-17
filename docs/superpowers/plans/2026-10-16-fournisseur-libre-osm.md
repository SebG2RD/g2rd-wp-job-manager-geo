# Fournisseur cartographique libre (OpenStreetMap) et administration G2RD — plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** permettre au plugin `g2rd-wpjm-geo` de basculer carte, autocomplétion et géocodage de Google vers une pile libre (MapLibre + OpenFreeMap + Photon), et habiller son administration avec la console G2RD (sous-menu du thème G2RD s'il est présent, menu autonome sinon).

**Architecture:** un réglage `provider` (`google` | `osm`) pilote trois briques. Le géocodage libre (`PhotonGeocoder`) écrit les métadonnées de WP Job Manager via ses méthodes publiques et pose `_g2rd_wpjm_geo_source` ; la carte n'affiche que les offres dont la source correspond au fournisseur actif. Les imports passent par une file WP-Cron (`GeocodeQueue`, sur une base commune `JobQueue`). L'administration est rendue par `SettingsPage` avec le balisage de la console G2RD (`AdminScreen` délègue à `\G2RD\AdminConsole` ou rend le même balisage avec une copie de la feuille du thème).

**Tech Stack:** PHP 7.4+ (WordPress 6.4+, WP Job Manager 2.4.7, Go Fetch Jobs 1.8.5.2), JavaScript ES5 sans build, MapLibre GL JS 5.24.0 (UMD), Photon, OpenFreeMap. Tests : PHP en ligne de commande (`tests/run.php`, WordPress simulé), Node + jsdom (`tests/js/run.js`), Docker (WordPress réel).

**Spécification :** `docs/superpowers/specs/2026-10-16-fournisseur-libre-osm-design.md`.

## Écarts assumés par rapport à la spécification

| Spécification | Plan | Raison |
|---|---|---|
| `Settings.php` porte le rendu | `SettingsPage.php` (rendu) + `Settings.php` (stockage) | une responsabilité par fichier ; `Settings` reste testable sans HTML |
| — | `JobQueue.php` (base commune) ; `UrlCheckQueue` et `GeocodeQueue` en héritent | la file des URL a déjà le bon comportement (retrait avant traitement, écriture unique) |
| Lignes masquées par `hidden` | `style="display:none"` | la console donne `display:flex` aux lignes, ce qui l'emporte sur `[hidden]` |
| — | `JobQueue` relit l'option en base (purge du cache objet), libère son verrou dans un `finally`, planifie un passage de secours et impose `RETRY_DELAY` (correctifs de revue, tâche 3) | un ID perdu ou une file bloquée retirerait des offres de la carte |
| — | Photon : un HTTP 400 persistant (même sans filtre) vaut « introuvable », sauf s'il porte sur la langue ou les paramètres (erreur de configuration) | une offre au texte refusé bloquerait la file à chaque passage |
| Clé de cache md5( langue + texte nettoyé ) | md5( URL Photon + langue du site + segments normalisés + indice de département ) ; langue du site (`get_locale()`), pas celle de l'utilisateur, y compris pour l'autocomplétion | deux textes de même clé donnaient des résultats différents ; le nom renvoyé par Photon, que le garde-fou compare, dépend de la langue (correctifs de revue, tâche 4) |
| — | `GeocodingIntegration` retire la source dès que `geolocation_lat` est écrite hors de `write()` (WP Job Manager en mode Google, Go Fetch Jobs, WP All Import) ; drapeau « Photon en panne » de 5 min qui fait passer les saisies par la file ; `WP_IMPORTING` et le filtre `g2rd_wpjm_geo_defer_geocoding` diffèrent aussi le géocodage (correctifs de revue, tâche 5) | des coordonnées Google étiquetées `osm` s'afficheraient sur la carte libre ; chaque enregistrement attendrait 5 à 10 s pendant une panne |
| MapLibre « exige WebGL 2 » | WebGL 2 ou, à défaut, WebGL 1 (repli de MapLibre 5) ; interface MapLibre traduite, boutons zoom et plein écran, sans rotation ; liste repliable des offres sous la carte libre (clavier, lecteurs d'écran) ; cache de pages WP Rocket vidé au changement de fournisseur (correctifs de revue, tâche 12) | les points du canevas MapLibre ne prennent pas le focus ; des pages en cache garderaient l'ancien moteur |
| Garde-fou : nom = premier segment | idem, puis à nom égal : département ou code postal saisi (lieux français), autres segments (article ignoré), ville ou commune du pays du site (filtre `g2rd_wpjm_geo_photon_preferred_country`, inclus dans la clé de cache ; un village homonyme ne gagne pas : « Montréal » seul reste au Québec, mais « Laval » seul est en France) ; « Paris 15e », « St Etienne » reconnus ; « Remote », « Télétravail » jamais envoyés | « Laval (53) » ou « Valence (26) » seraient placées sur un homonyme étranger plus peuplé |

Les autres ajustements (MapLibre 5.24.0, copie intégrale de la feuille, chemin Google du re-géocodage) sont déjà reportés dans la spécification.

## Conventions

- Racine du dépôt : `G2RD-WP-Job-Manager-Geo/`. Le plugin est dans `g2rd-wpjm-geo/`.
- PHP : `php` (sur le poste de développement : `C:/PHP/php.exe`). Commandes lancées depuis la racine du dépôt, dans Git Bash.
- Style du code existant : namespace `G2RD\WPJMGeo`, `array()` long, pas de types de retour, docblocks en français, `if ( ! defined( 'ABSPATH' ) ) { exit; }` en tête de chaque fichier PHP du plugin.
- Après chaque tâche : `php g2rd-wpjm-geo/tests/run.php | tail -1` doit afficher `0 échoués`, et `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1` doit afficher `0 echoues`.
- Les nouvelles sections de tests PHP s'insèrent **juste avant** la ligne `$results = $GLOBALS['g2rd_results'];` de `g2rd-wpjm-geo/tests/run.php`. Les nouvelles sections JS s'insèrent **juste avant** la ligne `console.log('\nResultat JS : ' + pass + ' reussis, ' + fail + ' echoues');` de `g2rd-wpjm-geo/tests/js/run.js`.

## Carte des fichiers

| Fichier | Statut | Responsabilité |
|---|---|---|
| `g2rd-wpjm-geo/includes/Settings.php` | modifié | options, `get_provider()`, `https_url()`, assainissement |
| `g2rd-wpjm-geo/includes/SettingsPage.php` | nouveau | rendu de la page (cartes de réglages, bandeau) |
| `g2rd-wpjm-geo/includes/AdminMenu.php` | nouveau | sous-menu « G2RD » ou menu autonome, hook de page, lien « Réglages » |
| `g2rd-wpjm-geo/includes/AdminScreen.php` | nouveau | `open()` / `close()`, feuille de repli, polices, script de la page |
| `g2rd-wpjm-geo/includes/JobQueue.php` | nouveau | file WP-Cron générique |
| `g2rd-wpjm-geo/includes/UrlCheckQueue.php` | modifié | hérite de `JobQueue` |
| `g2rd-wpjm-geo/includes/PhotonGeocoder.php` | nouveau | texte → adresse au format WPJM |
| `g2rd-wpjm-geo/includes/GeocodeQueue.php` | nouveau | géocodages différés |
| `g2rd-wpjm-geo/includes/GeocodingIntegration.php` | nouveau | hooks WPJM, écriture, métadonnée de source |
| `g2rd-wpjm-geo/includes/RegeocodeTool.php` | nouveau | statistiques, analyse/simulation/exécution par lots |
| `g2rd-wpjm-geo/includes/OsmAssets.php` | nouveau | script de carte libre et sa configuration |
| `g2rd-wpjm-geo/includes/PrivacyPolicy.php` | nouveau | texte suggéré de confidentialité |
| `g2rd-wpjm-geo/includes/RestController.php` | modifié | filtre par source, fournisseur dans le cache |
| `g2rd-wpjm-geo/includes/JobsMap.php` | modifié | moteur selon le fournisseur, lien admin |
| `g2rd-wpjm-geo/includes/GoogleAssets.php` | modifié | `map-common.js`, dépendances de l'autocomplétion |
| `g2rd-wpjm-geo/includes/LocationAutocomplete.php` | modifié | configuration Google ou Photon |
| `g2rd-wpjm-geo/includes/MigrationTool.php` | modifié | balisage console |
| `g2rd-wpjm-geo/includes/Logger.php` | modifié | `count_since()` |
| `g2rd-wpjm-geo/includes/Plugin.php` | modifié | câblage |
| `g2rd-wpjm-geo/assets/js/map-common.js` | nouveau | REST paginé, regroupement, fenêtres, démarrage paresseux |
| `g2rd-wpjm-geo/assets/js/jobs-map.js` | modifié | moteur Google sur `map-common.js` |
| `g2rd-wpjm-geo/assets/js/jobs-map-osm.js` | nouveau | moteur MapLibre |
| `g2rd-wpjm-geo/assets/js/location-autocomplete.js` | modifié | sources Google et Photon |
| `g2rd-wpjm-geo/assets/js/admin-settings.js` | nouveau | interrupteurs, lignes par fournisseur, barre d'enregistrement |
| `g2rd-wpjm-geo/assets/js/admin-regeocode.js` | nouveau | outil de re-géocodage |
| `g2rd-wpjm-geo/assets/js/admin-tools.js` | modifié | résultats en tableau |
| `g2rd-wpjm-geo/assets/css/console.css` | nouveau (généré) | copie de la feuille de la console du thème |
| `g2rd-wpjm-geo/assets/css/admin.css` | supprimé | remplacé par les classes de la console |
| `g2rd-wpjm-geo/assets/css/frontend.css` | modifié | lien de l'état vide, fenêtre MapLibre |
| `g2rd-wpjm-geo/assets/fonts/*` | nouveau | Instrument Sans, IBM Plex Mono, licences OFL |
| `g2rd-wpjm-geo/assets/vendor/maplibre-gl/*` | nouveau | MapLibre 5.24.0 et sa licence |
| `g2rd-wpjm-geo/tests/bootstrap.php` | modifié | nouvelles fonctions simulées |
| `g2rd-wpjm-geo/tests/fixtures/fake-admin-console.php` | nouveau | console du thème simulée |
| `g2rd-wpjm-geo/tests/run.php`, `tests/js/run.js` | modifiés | nouvelles sections |
| `g2rd-wpjm-geo/uninstall.php`, `g2rd-wpjm-geo.php`, `readme.txt` | modifiés | nettoyage, version 1.1.0 |
| `tools/build-console-css.php`, `tools/build-zip.php` | nouveaux | régénération de la feuille, archive |
| `tools/docker/docker-compose.yml`, `fetch-plugins.sh`, `verify.sh` | nouveaux | vérification de bout en bout |

---

### Task 0: Préparation

**Files:**
- Modify: `.gitignore`

- [ ] **Step 1: Créer la branche**

```bash
git checkout -b feat/fournisseur-libre
```

- [ ] **Step 2: Installer jsdom hors du plugin**

```bash
printf 'tools/jsdom/\ntools/docker/plugins/\ntools/docker/cookies.txt\n' >> .gitignore
mkdir -p tools/jsdom && npm --prefix tools/jsdom init -y >/dev/null && npm --prefix tools/jsdom i jsdom@24
```

Expected: `added N packages`, aucune erreur.

- [ ] **Step 3: Vérifier la ligne de base**

```bash
php g2rd-wpjm-geo/tests/run.php | tail -1
NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1
```

Expected: `Résultat : 67 réussis, 0 échoués` puis `Resultat JS : 34 reussis, 0 echoues`.

- [ ] **Step 4: Commit**

```bash
git add .gitignore && git commit -m "chore: outillage de test hors du plugin"
```

---

### Task 1: Fonctions WordPress simulées pour les nouveaux tests

**Files:**
- Modify: `g2rd-wpjm-geo/tests/bootstrap.php`

- [ ] **Step 1: Ajouter l'état simulé**

Dans la classe `G2RD_Test_State`, après `public static $log_rows   = array();`, ajouter :

```php
		public static $http_responder = null; // callable( $method, $url ) : réponse, ou null pour la réponse par défaut.
		public static $doing_cron     = false;
		public static $locale         = 'fr_FR';
		public static $menus          = array();
		public static $wpjm_geocode   = array(); // texte => tableau d'adresse renvoyé par WP_Job_Manager_Geocode::get_location_data().
```

Dans `reset()`, après `self::$log_rows   = array();`, ajouter :

```php
			self::$http_responder = null;
			self::$doing_cron     = false;
			self::$locale         = 'fr_FR';
			self::$menus          = array();
			self::$wpjm_geocode   = array();
```

- [ ] **Step 2: Ajouter les fonctions simulées**

Juste après la fonction `add_settings_error()`, ajouter :

```php
	function remove_accents( $text ) {
		return strtr(
			(string) $text,
			array(
				'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e',
				'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
				'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'À' => 'A', 'Â' => 'A',
				'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U', 'Ü' => 'U',
			)
		);
	}
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '';
	}
	function delete_post_meta( $id, $key ) {
		unset( G2RD_Test_State::$meta[ $id ][ $key ] );
		return true;
	}
	function wp_doing_cron() {
		return G2RD_Test_State::$doing_cron;
	}
	function determine_locale() {
		return G2RD_Test_State::$locale;
	}
	function esc_url_raw( $url, $protocols = null ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( is_array( $protocols ) && ! in_array( $scheme, $protocols, true ) ) {
			return '';
		}
		return str_replace( ' ', '%20', $url );
	}
	function remove_filter( $hook, $callback, $priority = 10 ) {
		if ( isset( G2RD_Test_State::$hooks[ $hook ][ $priority ] ) ) {
			foreach ( G2RD_Test_State::$hooks[ $hook ][ $priority ] as $i => $entry ) {
				if ( $entry[0] === $callback ) {
					unset( G2RD_Test_State::$hooks[ $hook ][ $priority ][ $i ] );
				}
			}
		}
		return true;
	}
	function __return_true() {
		return true;
	}
	function __return_false() {
		return false;
	}
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . $path;
	}
	function get_edit_post_link( $id, $context = 'display' ) {
		return 'https://example.test/wp-admin/post.php?post=' . (int) $id . '&action=edit';
	}
	function add_menu_page( $page_title, $menu_title, $capability, $slug, $callback = '', $icon = '', $position = null ) {
		G2RD_Test_State::$menus[] = array( 'type' => 'top', 'slug' => $slug, 'title' => $menu_title, 'icon' => $icon, 'position' => $position );
		return 'toplevel_page_' . $slug;
	}
	function add_submenu_page( $parent, $page_title, $menu_title, $capability, $slug, $callback = '' ) {
		G2RD_Test_State::$menus[] = array( 'type' => 'sub', 'parent' => $parent, 'slug' => $slug, 'title' => $menu_title );
		return $parent . '_page_' . $slug;
	}
```

- [ ] **Step 3: Brancher le répondeur HTTP**

Dans `g2rd_test_http()`, juste après `G2RD_Test_State::$http_calls[] = $method . ' ' . $url;`, ajouter :

```php
		if ( is_callable( G2RD_Test_State::$http_responder ) ) {
			$reply = call_user_func( G2RD_Test_State::$http_responder, $method, $url );
			if ( null !== $reply ) {
				return $reply;
			}
		}
```

- [ ] **Step 4: Simuler `WP_Job_Manager_Geocode`**

Juste après la classe `G2RD_Test_Wpdb` (avant `$GLOBALS['wpdb'] = …`), ajouter :

```php
	class WP_Job_Manager_Geocode {
		public static function clear_location_data( $job_id ) {
			foreach ( array_keys( isset( G2RD_Test_State::$meta[ $job_id ] ) ? G2RD_Test_State::$meta[ $job_id ] : array() ) as $key ) {
				if ( 'geolocated' === $key || 0 === strpos( $key, 'geolocation_' ) ) {
					unset( G2RD_Test_State::$meta[ $job_id ][ $key ] );
				}
			}
		}
		public static function save_location_data( $job_id, $address_data ) {
			if ( is_array( $address_data ) && $address_data ) {
				foreach ( $address_data as $key => $value ) {
					if ( $value ) {
						G2RD_Test_State::$meta[ $job_id ][ 'geolocation_' . $key ] = $value;
					}
				}
				G2RD_Test_State::$meta[ $job_id ]['geolocated'] = 1;
			}
		}
		public static function get_location_data( $raw_address ) {
			return array_key_exists( $raw_address, G2RD_Test_State::$wpjm_geocode ) ? G2RD_Test_State::$wpjm_geocode[ $raw_address ] : new WP_Error( 'error', 'No results found' );
		}
	}
```

- [ ] **Step 5: Lancer les tests**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -1`
Expected: `Résultat : 67 réussis, 0 échoués`

- [ ] **Step 6: Commit**

```bash
git add g2rd-wpjm-geo/tests/bootstrap.php && git commit -m "test: fonctions WordPress simulées pour le fournisseur libre"
```

---

### Task 2: Réglages du fournisseur

**Files:**
- Modify: `g2rd-wpjm-geo/includes/Settings.php`
- Test: `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Écrire le test (section 15)**

```php
echo PHP_EOL . '15. Réglages du fournisseur' . PHP_EOL;
list( $s ) = boot();
check( 'fournisseur par défaut : google', 'google' === $s->get_provider() );
update_option( Settings::OPTION, array( 'provider' => 'osm' ) );
$s->flush_cache();
check( 'fournisseur osm lu', 'osm' === $s->get_provider() );
update_option( Settings::OPTION, array( 'provider' => 'autre' ) );
$s->flush_cache();
check( 'valeur inconnue : google', 'google' === $s->get_provider() );
check( 'URL https acceptée et rognée', 'https://photon.example.org/api/' === Settings::https_url( ' https://photon.example.org/api/ ' ) );
check( 'URL http refusée', '' === Settings::https_url( 'http://photon.example.org/api/' ) );
check( 'URL sans hôte refusée', '' === Settings::https_url( 'https:///api' ) );
check( 'javascript: refusé', '' === Settings::https_url( 'javascript:alert(1)' ) );
list( $s ) = boot();
G2RD_Test_State::$errors = array();
$c = $s->sanitize( array( 'provider' => 'osm', 'osm_style_url' => 'http://insecure.test/style', 'photon_url' => 'https://photon.mon-serveur.fr/api/' ) );
check( 'sanitize : fournisseur osm', 'osm' === $c['provider'] );
check( 'sanitize : style http refusé, valeur précédente gardée, message', Settings::DEFAULT_OSM_STYLE_URL === $c['osm_style_url'] && 'invalid_osm_style_url' === G2RD_Test_State::$errors[0]['code'] );
check( 'sanitize : URL Photon https acceptée', 'https://photon.mon-serveur.fr/api/' === $c['photon_url'] );
$c = $s->sanitize( array() );
check( 'sanitize : champs absents, valeurs par défaut sans message', 'google' === $c['provider'] && Settings::DEFAULT_PHOTON_URL === $c['photon_url'] && 1 === count( G2RD_Test_State::$errors ) );
```

- [ ] **Step 2: Vérifier l'échec**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -5`
Expected: erreur fatale `Call to undefined method G2RD\WPJMGeo\Settings::get_provider()`.

- [ ] **Step 3: Implémenter**

Dans `Settings.php`, après `const MODE_INVALID = 'invalid';`, ajouter :

```php

	const PROVIDER_GOOGLE = 'google';
	const PROVIDER_OSM    = 'osm';

	const DEFAULT_OSM_STYLE_URL = 'https://tiles.openfreemap.org/styles/liberty';
	const DEFAULT_PHOTON_URL    = 'https://photon.komoot.io/api/';
```

Dans `defaults()`, après `'log_retention_days'    => 90,`, ajouter :

```php
			'provider'              => self::PROVIDER_GOOGLE,
			'osm_style_url'         => self::DEFAULT_OSM_STYLE_URL,
			'photon_url'            => self::DEFAULT_PHOTON_URL,
```

Après la méthode `get_mode()`, ajouter :

```php
	/**
	 * Fournisseur cartographique actif.
	 *
	 * @return string PROVIDER_GOOGLE ou PROVIDER_OSM.
	 */
	public function get_provider() {
		return self::PROVIDER_OSM === $this->get( 'provider' ) ? self::PROVIDER_OSM : self::PROVIDER_GOOGLE;
	}

	/**
	 * URL https avec hôte, ou chaîne vide.
	 *
	 * @param mixed $raw Valeur saisie.
	 * @return string
	 */
	public static function https_url( $raw ) {
		$url  = esc_url_raw( trim( (string) $raw ), array( 'https' ) );
		$host = '' === $url ? '' : (string) wp_parse_url( $url, PHP_URL_HOST );
		return ( 0 === strpos( $url, 'https://' ) && '' !== $host ) ? $url : '';
	}
```

Dans `sanitize()`, juste avant `$this->cache = null;`, ajouter :

```php
		$clean['provider'] = ( isset( $input['provider'] ) && self::PROVIDER_OSM === $input['provider'] ) ? self::PROVIDER_OSM : self::PROVIDER_GOOGLE;

		$urls = array(
			'osm_style_url' => __( 'L’URL du style de carte doit commencer par https:// : elle n’a pas été enregistrée.', 'g2rd-wpjm-geo' ),
			'photon_url'    => __( 'L’URL du serveur Photon doit commencer par https:// : elle n’a pas été enregistrée.', 'g2rd-wpjm-geo' ),
		);
		foreach ( $urls as $key => $message ) {
			$raw   = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			$url   = self::https_url( $raw );
			$clean[ $key ] = '' !== $url ? $url : (string) $this->get( $key );
			if ( '' === $url && '' !== $raw ) {
				$this->notify( 'invalid_' . $key, $message );
			}
		}
```

- [ ] **Step 4: Vérifier le succès**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -15`
Expected: section 15 toute en `[OK]`, `0 échoués`.

- [ ] **Step 5: Commit**

```bash
git add g2rd-wpjm-geo/includes/Settings.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat: réglages du fournisseur cartographique (google | osm) et URL https"
```

---

### Task 3: File WP-Cron générique (`JobQueue`)

**Files:**
- Create: `g2rd-wpjm-geo/includes/JobQueue.php`
- Modify: `g2rd-wpjm-geo/includes/UrlCheckQueue.php` (réécrit)
- Modify: `g2rd-wpjm-geo/tests/bootstrap.php` (liste des classes)

- [ ] **Step 1: Créer `JobQueue.php`**

```php
<?php
/**
 * File d'attente WP-Cron générique, traitée par petits lots.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Les sous-classes définissent les constantes OPTION, CRON_HOOK, LOCK et
 * BATCH_SIZE, ainsi que process_item().
 *
 * Les IDs empilés pendant une requête sont écrits une seule fois, en fin de
 * requête. Chaque offre est retirée de la file (relecture de l'option) avant
 * son traitement : un dépassement de délai ne rejoue pas indéfiniment la même
 * offre, et les IDs ajoutés entre-temps par un import ne sont jamais écrasés.
 */
abstract class JobQueue {

	const MAX_QUEUE   = 5000;
	const RETRY_DELAY = 600;

	/**
	 * IDs empilés pendant la requête courante, écrits une seule fois à la fin.
	 *
	 * @var int[]
	 */
	protected $pending = array();

	/**
	 * Traite une offre.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return bool false si le service est indisponible : l'offre est remise en file et le lot s'arrête.
	 */
	abstract protected function process_item( $job_id );

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( static::CRON_HOOK, array( $this, 'process' ) );
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Empile une offre.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return void
	 */
	public function push( $job_id ) {
		$job_id = absint( $job_id );
		if ( $job_id ) {
			$this->pending[ $job_id ] = $job_id;
		}
	}

	/**
	 * Écrit la file en une seule fois (un import peut empiler des centaines d'offres).
	 *
	 * @return void
	 */
	public function flush() {
		if ( empty( $this->pending ) ) {
			return;
		}
		$queue         = array_slice( array_values( array_unique( array_merge( $this->get_queue(), array_values( $this->pending ) ) ) ), 0, self::MAX_QUEUE );
		$this->pending = array();
		update_option( static::OPTION, $queue, false );
		$this->schedule( MINUTE_IN_SECONDS );
	}

	/**
	 * Traite un lot.
	 *
	 * @return void
	 */
	public function process() {
		if ( get_transient( static::LOCK ) ) {
			return;
		}
		set_transient( static::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$retry = false;
		foreach ( array_slice( $this->get_queue(), 0, static::BATCH_SIZE ) as $job_id ) {
			$this->remove( $job_id );
			if ( false === $this->process_item( $job_id ) ) {
				$this->requeue( $job_id );
				$retry = true;
				break;
			}
		}

		delete_transient( static::LOCK );

		if ( $retry ) {
			$this->schedule( self::RETRY_DELAY );
		} elseif ( ! empty( $this->get_queue() ) ) {
			$this->schedule( MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Retire une offre en relisant l'option.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return void
	 */
	protected function remove( $job_id ) {
		update_option( static::OPTION, array_values( array_diff( $this->get_queue(), array( absint( $job_id ) ) ) ), false );
	}

	/**
	 * Remet une offre en fin de file.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return void
	 */
	protected function requeue( $job_id ) {
		$queue   = $this->get_queue();
		$queue[] = absint( $job_id );
		update_option( static::OPTION, array_slice( array_values( array_unique( $queue ) ), 0, self::MAX_QUEUE ), false );
	}

	/**
	 * Planifie le prochain lot s'il ne l'est pas déjà.
	 *
	 * @param int $delay Délai en secondes.
	 * @return void
	 */
	protected function schedule( $delay ) {
		if ( ! wp_next_scheduled( static::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, static::CRON_HOOK );
		}
	}

	/**
	 * File courante.
	 *
	 * @return int[]
	 */
	protected function get_queue() {
		$queue = get_option( static::OPTION, array() );
		return is_array( $queue ) ? array_values( array_filter( array_map( 'absint', $queue ) ) ) : array();
	}
}
```

- [ ] **Step 2: Réécrire `UrlCheckQueue.php`**

Remplacer tout le contenu par :

```php
<?php
/**
 * File d'attente WP-Cron pour la vérification différée des URL.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * En mode « uniquement les URL invalides ou inaccessibles », l'import se
 * contente d'empiler l'ID de l'offre : les requêtes HTTP sont effectuées plus
 * tard, par petits lots, pour ne jamais ralentir Go Fetch Jobs.
 */
class UrlCheckQueue extends JobQueue {

	const OPTION    = 'g2rd_wpjm_geo_url_queue';
	const CRON_HOOK = 'g2rd_wpjm_geo_check_urls';
	const LOCK      = 'g2rd_wpjm_geo_url_queue_lock';
	// 2 offres x (HEAD 5 s + GET 5 s) = 20 s au pire par exécution du cron, sous les 30 s habituels de PHP.
	const BATCH_SIZE = 2;

	/**
	 * Normaliseur.
	 *
	 * @var ApplicationNormalizer
	 */
	private $normalizer;

	/**
	 * Constructeur.
	 *
	 * @param ApplicationNormalizer $normalizer Normaliseur.
	 */
	public function __construct( ApplicationNormalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	/**
	 * Vérifie l'URL d'une offre. Une URL injoignable est un résultat, pas une panne : jamais de remise en file.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return bool
	 */
	protected function process_item( $job_id ) {
		$this->normalizer->handle_with_check( $job_id );
		return true;
	}
}
```

- [ ] **Step 3: Charger la base dans les tests**

Dans `tests/bootstrap.php`, remplacer la liste `array( 'Settings', 'Logger', 'UrlChecker', 'ApplicationNormalizer', 'UrlCheckQueue', 'GoFetchIntegration' )` par :

```php
array( 'Settings', 'Logger', 'UrlChecker', 'ApplicationNormalizer', 'JobQueue', 'UrlCheckQueue', 'GoFetchIntegration' )
```

- [ ] **Step 4: Lancer les tests (non-régression des sections 12 et 14)**

Run: `php g2rd-wpjm-geo/tests/run.php | grep -E "FAIL|Résultat"`
Expected: une seule ligne `Résultat : … 0 échoués`.

- [ ] **Step 5: Commit**

```bash
git add g2rd-wpjm-geo/includes/JobQueue.php g2rd-wpjm-geo/includes/UrlCheckQueue.php g2rd-wpjm-geo/tests/bootstrap.php
git commit -m "refactor: file WP-Cron générique JobQueue, UrlCheckQueue en hérite"
```

---

### Task 4: `PhotonGeocoder`

**Files:**
- Create: `g2rd-wpjm-geo/includes/PhotonGeocoder.php`
- Modify: `g2rd-wpjm-geo/tests/bootstrap.php`, `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Écrire le test (section 16)**

En tête de `tests/run.php`, ajouter aux `use` : `use G2RD\WPJMGeo\PhotonGeocoder;`. Puis la section :

```php
echo PHP_EOL . '16. PhotonGeocoder' . PHP_EOL;

function photon_feature( $name, $key, $value, $lng, $lat, $extra = array() ) {
	return array(
		'type'       => 'Feature',
		'geometry'   => array( 'type' => 'Point', 'coordinates' => array( $lng, $lat ) ),
		'properties' => array_merge( array( 'name' => $name, 'osm_key' => $key, 'osm_value' => $value ), $extra ),
	);
}
function photon_reply( array $features, $code = 200 ) {
	return array( 'response' => array( 'code' => $code ), 'body' => json_encode( array( 'type' => 'FeatureCollection', 'features' => $features ) ) );
}

check( 'clean : parenthèses retirées', 'Lyon' === PhotonGeocoder::clean( 'Lyon (69)' ) );
check( 'clean : numéro isolé retiré', 'Paris' === PhotonGeocoder::clean( '75008 Paris' ) );
check( 'clean : « Paris 6 » donne « Paris »', 'Paris' === PhotonGeocoder::clean( 'Paris 6' ) );
check( 'clean : chiffre accolé à une lettre conservé', 'A4 Sud' === PhotonGeocoder::clean( 'A4 Sud' ) );
check( 'clean : séparateurs de bord retirés', 'Rouen' === PhotonGeocoder::clean( ' - Rouen, ' ) );
check( 'normalize : accents, casse, ponctuation', 'ile de france' === PhotonGeocoder::normalize( 'Île-de-France' ) );
check( 'language : fr, de, sinon default', 'fr' === PhotonGeocoder::language( 'fr_FR' ) && 'de' === PhotonGeocoder::language( 'de_DE_formal' ) && 'default' === PhotonGeocoder::language( 'es_ES' ) );

list( $s ) = boot();
$geo  = new PhotonGeocoder( $s );
$urls = array();
G2RD_Test_State::$http_responder = function ( $method, $url ) use ( &$urls ) {
	$urls[] = $url;
	if ( false !== strpos( $url, 'q=Londres' ) ) {
		return photon_reply( array( photon_feature( 'Londres', 'place', 'city', -0.1276, 51.5073, array( 'state' => 'Angleterre', 'country' => 'Royaume-Uni', 'countrycode' => 'gb' ) ) ) );
	}
	if ( false !== strpos( $url, 'q=IDF' ) ) {
		return photon_reply( array( photon_feature( 'Idfu', 'place', 'town', 32.87, 24.98, array( 'country' => 'Égypte' ) ) ) );
	}
	if ( false !== strpos( $url, 'q=Brest' ) ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
	}
	if ( false !== strpos( $url, 'q=Atlantis' ) ) {
		return photon_reply( array( photon_feature( 'Atlantis', 'place', 'city', 10, 200 ) ) );
	}
	return photon_reply( array() );
};

$r = $geo->geocode( 'Londres, Angleterre, Royaume-Uni' );
check( 'Londres : coordonnées et ville', is_array( $r ) && '51.5073' === $r['lat'] && '-0.1276' === $r['long'] && 'Londres' === $r['city'] );
check( 'Londres : adresse au format WPJM', is_array( $r ) && 'Londres, Angleterre, Royaume-Uni' === $r['formatted_address'] && 'Angleterre' === $r['state_long'] && 'Royaume-Uni' === $r['country_long'] && 'GB' === $r['country_short'] );
check( 'requête : texte encodé, limite, langue, filtres de lieu', 1 === count( $urls ) && 0 === strpos( $urls[0], 'https://photon.komoot.io/api/?q=Londres%2C%20Angleterre%2C%20Royaume-Uni&limit=5&lang=fr' ) && false !== strpos( $urls[0], '&osm_tag=place&osm_tag=boundary%3Aadministrative' ) );
check( 'premier appel : réseau', false === $geo->last_was_cached() );
$geo->geocode( 'Londres, Angleterre, Royaume-Uni' );
check( 'deuxième appel : cache', 1 === count( $urls ) && $geo->last_was_cached() );
check( 'IDF : homonyme lointain refusé', null === $geo->geocode( 'IDF' ) );
$before = count( $urls );
check( 'IDF : refus mis en cache', null === $geo->geocode( 'IDF' ) && count( $urls ) === $before );
check( 'coordonnées hors bornes refusées', null === $geo->geocode( 'Atlantis' ) );
check( 'erreur réseau : WP_Error', is_wp_error( $geo->geocode( 'Brest' ) ) );
$before = count( $urls );
$geo->geocode( 'Brest' );
check( 'erreur réseau : non mise en cache', count( $urls ) === $before + 1 );
check( 'texte vide après nettoyage : null sans requête', null === $geo->geocode( ' (75) ' ) && count( $urls ) === $before + 1 );

list( $s ) = boot();
$geo  = new PhotonGeocoder( $s );
$urls = array();
G2RD_Test_State::$http_responder = function ( $method, $url ) use ( &$urls ) {
	$urls[] = $url;
	if ( false !== strpos( $url, 'osm_tag' ) ) {
		return array( 'response' => array( 'code' => 400 ), 'body' => '{"message":"bad"}' );
	}
	return photon_reply(
		array(
			photon_feature( 'Trappes', 'shop', 'bakery', 1.0, 1.0 ),
			photon_feature( 'Trappes', 'place', 'town', 2.0099, 48.7775, array( 'country' => 'France' ) ),
		)
	);
};
$r = $geo->geocode( 'TRAPPES' );
check( 'HTTP 400 : nouvel essai sans filtre', 2 === count( $urls ) && false === strpos( $urls[1], 'osm_tag' ) );
check( 'repli : seuls les lieux sont gardés', is_array( $r ) && '48.7775' === $r['lat'] && 'Trappes' === $r['city'] );

G2RD_Test_State::$http_responder = function () {
	return array( 'response' => array( 'code' => 400 ), 'body' => '{"message":"bad"}' );
};
check( 'HTTP 400 même sans filtre : introuvable, pas une panne', null === $geo->geocode( 'Texte refusé' ) );
G2RD_Test_State::$http_responder = function () {
	return array( 'response' => array( 'code' => 503 ), 'body' => '' );
};
check( 'HTTP 503 : panne (WP_Error)', is_wp_error( $geo->geocode( 'Nantes' ) ) );

list( $s ) = boot( array( 'photon_url' => 'https://photon.local/api/' ) );
$geo = new PhotonGeocoder( $s );
add_filter( 'g2rd_wpjm_geo_photon_allow_private_host', '__return_true' );
$during = array();
G2RD_Test_State::$http_responder = function () use ( &$during ) {
	$during = array( apply_filters( 'http_request_host_is_external', false, 'photon.local' ), apply_filters( 'http_request_host_is_external', false, 'evil.test' ) );
	return photon_reply( array() );
};
$geo->geocode( 'Rouen' );
check( 'Photon auto-hébergé : seul son hôte est autorisé pendant la requête', array( true, false ) === $during );
check( 'autorisation retirée après la requête', false === apply_filters( 'http_request_host_is_external', false, 'photon.local' ) );

// Clé de cache par segment, arrondissements, homonymes, textes vagues, erreurs, robustesse.
check( 'clean : « Paris 15e » donne « Paris »', 'Paris' === PhotonGeocoder::clean( 'Paris 15e' ) );
check( 'clean : « Lyon 1er » et « Marseille 13e Arrondissement »', 'Lyon' === PhotonGeocoder::clean( 'Lyon 1er' ) && 'Marseille' === PhotonGeocoder::clean( 'Marseille 13e Arrondissement' ) );
check( 'clean : UTF-8 invalide, chaîne vide sans avertissement', '' === PhotonGeocoder::clean( "Lyon \xC3\x28" ) );
check( 'normalize : St et Ste lus Saint et Sainte', 'saint etienne' === PhotonGeocoder::normalize( 'St-Étienne' ) && 'sainte genevieve des bois' === PhotonGeocoder::normalize( 'Ste-Geneviève-des-Bois' ) );
check( 'normalize : accents combinants retirés', 'saint etienne' === PhotonGeocoder::normalize( "Saint-E\u{0301}tienne" ) );
check( 'hint : département, code postal, Corse, outre-mer', '53' === PhotonGeocoder::hint( 'Laval (53)' ) && '75' === PhotonGeocoder::hint( '75008 Paris' ) && '20' === PhotonGeocoder::hint( 'Ajaccio (2A)' ) && '974' === PhotonGeocoder::hint( 'Saint-Denis (974)' ) && '' === PhotonGeocoder::hint( 'Lyon' ) );

list( $s ) = boot();
$geo  = new PhotonGeocoder( $s );
$urls = array();
G2RD_Test_State::$http_responder = function ( $method, $url ) use ( &$urls ) {
	$urls[] = $url;
	if ( false !== strpos( $url, 'q=Laval' ) ) {
		return photon_reply(
			array(
				photon_feature( 'Laval', 'place', 'city', -73.75, 45.57, array( 'postcode' => 'H7T', 'country' => 'Canada', 'countrycode' => 'CA' ) ),
				photon_feature( 'Laval', 'place', 'town', -0.77, 48.07, array( 'postcode' => '53000', 'country' => 'France', 'countrycode' => 'FR' ) ),
			)
		);
	}
	if ( false !== strpos( $url, 'q=Valence' ) ) {
		return photon_reply(
			array(
				photon_feature( 'Valence', 'place', 'city', 4.89, 44.93, array( 'country' => 'France', 'countrycode' => 'FR' ) ),
				photon_feature( 'Valence', 'place', 'city', -0.38, 39.47, array( 'country' => 'Espagne', 'countrycode' => 'ES' ) ),
			)
		);
	}
	if ( false !== strpos( $url, 'q=Saint-Denis' ) ) {
		return photon_reply(
			array(
				photon_feature( 'Saint-Denis', 'place', 'city', 55.4504, -20.8789, array( 'postcode' => '97400', 'state' => 'La Réunion', 'country' => 'France', 'countrycode' => 'FR' ) ),
				photon_feature( 'Saint-Denis', 'place', 'city', 2.3574, 48.9362, array( 'postcode' => '93200', 'state' => 'Île-de-France', 'country' => 'France', 'countrycode' => 'FR' ) ),
			)
		);
	}
	if ( false !== strpos( $url, 'q=Montr%C3%A9al' ) ) {
		return photon_reply(
			array(
				photon_feature( 'Montréal', 'place', 'city', -73.5698, 45.5031, array( 'state' => 'Québec', 'country' => 'Canada', 'countrycode' => 'CA' ) ),
				photon_feature( 'Montréal', 'place', 'village', 2.1431, 43.1997, array( 'state' => 'Occitanie', 'country' => 'France', 'countrycode' => 'FR' ) ),
			)
		);
	}
	if ( false !== strpos( $url, 'q=La%20D%C3%A9fense' ) ) {
		return photon_reply( array( photon_feature( 'La Défense', 'place', 'suburb', 2.2384, 48.892, array( 'city' => 'Puteaux', 'country' => 'France', 'countrycode' => 'FR' ) ) ) );
	}
	if ( false !== strpos( $url, 'q=Vanoise' ) ) {
		return photon_reply( array( photon_feature( 'Vanoise', 'boundary', 'national_park', 6.8, 45.4 ) ) );
	}
	if ( false !== strpos( $url, 'q=Paris' ) ) {
		return photon_reply( array( photon_feature( 'Paris', 'place', 'city', 2.3522, 48.8566, array( 'country' => 'France', 'countrycode' => 'FR' ) ) ) );
	}
	if ( false !== strpos( $url, 'q=Lyon' ) ) {
		return photon_reply( array( photon_feature( 'Lyon', 'amenity', 'bar', 2.35, 48.85 ) ) );
	}
	return photon_reply( array() );
};
$r = $geo->geocode( 'Laval (53)' );
check( 'homonymes : le département saisi l’emporte', is_array( $r ) && '48.07' === $r['lat'] && 'FR' === $r['country_short'] );
$r = $geo->geocode( 'Laval' );
check( 'homonymes : sans indice, le pays du site (fr_FR) l’emporte', is_array( $r ) && 'FR' === $r['country_short'] );
$r = $geo->geocode( 'Valence, Espagne' );
check( 'homonymes : le pays cité dans le texte l’emporte', is_array( $r ) && 'ES' === $r['country_short'] );
$r = $geo->geocode( 'Saint-Denis (93)' );
check( 'homonymes français : le département saisi départage', is_array( $r ) && '48.9362' === $r['lat'] );
$r = $geo->geocode( 'Saint-Denis, Réunion' );
check( 'homonymes : la région citée, sans article, départage', is_array( $r ) && '-20.8789' === $r['lat'] );
$r = $geo->geocode( 'Montréal' );
check( 'homonymes : un village du pays du site ne l’emporte pas sur la grande ville', is_array( $r ) && 'CA' === $r['country_short'] );
$r = $geo->geocode( 'La Défense' );
check( 'quartier : la ville est celle de Photon', is_array( $r ) && 'Puteaux' === $r['city'] );
check( 'limite non administrative ignorée', null === $geo->geocode( 'Vanoise' ) );
$prefer_ca = function () {
	return 'CA';
};
add_filter( 'g2rd_wpjm_geo_photon_preferred_country', $prefer_ca );
$r = $geo->geocode( 'Laval' );
check( 'filtre du pays préféré pris en compte, cache distinct', is_array( $r ) && 'CA' === $r['country_short'] );
remove_filter( 'g2rd_wpjm_geo_photon_preferred_country', $prefer_ca );
$before = count( $urls );
check( '« Paris France » : premier segment différent, introuvable', null === $geo->geocode( 'Paris France' ) );
$r = $geo->geocode( 'Paris, France' );
check( '« Paris, France » : clé de cache distincte, trouvé', is_array( $r ) && '48.8566' === $r['lat'] && count( $urls ) === $before + 2 );
$r = $geo->geocode( 'Paris 15e' );
check( '« Paris 15e » : trouvé', is_array( $r ) && 'Paris' === $r['city'] );
check( 'commerce renvoyé malgré le filtre : ignoré', null === $geo->geocode( 'Lyon' ) );
$before = count( $urls );
check( 'texte vague (Remote, Télétravail) : null sans requête', null === $geo->geocode( 'Remote' ) && null === $geo->geocode( 'Télétravail' ) && count( $urls ) === $before && $geo->last_was_cached() );
check( 'écriture non latine : null sans requête', null === $geo->geocode( '東京' ) && count( $urls ) === $before );

G2RD_Test_State::$http_responder = function () {
	return array( 'response' => array( 'code' => 429 ), 'body' => '' );
};
$e = $geo->geocode( 'Nancy' );
check( 'HTTP 429 : WP_Error g2rd_photon_http après un vrai appel', is_wp_error( $e ) && 'g2rd_photon_http' === $e->get_error_code() && false === $geo->last_was_cached() );
G2RD_Test_State::$http_responder = function () {
	return array( 'response' => array( 'code' => 200 ), 'body' => '<html>maintenance</html>' );
};
$e = $geo->geocode( 'Nancy' );
check( 'HTML au lieu de JSON : g2rd_photon_invalid', is_wp_error( $e ) && 'g2rd_photon_invalid' === $e->get_error_code() );
G2RD_Test_State::$http_responder = function ( $method, $url ) {
	if ( false !== strpos( $url, 'osm_tag' ) ) {
		return array( 'response' => array( 'code' => 400 ), 'body' => '' );
	}
	return array( 'response' => array( 'code' => 400 ), 'body' => '{"message":"Language is not supported. Supported are: default, en, de, fr"}' );
};
$e = $geo->geocode( 'Nancy' );
check( 'HTTP 400 sur la langue : erreur de configuration, pas « introuvable »', is_wp_error( $e ) && 'g2rd_photon_config' === $e->get_error_code() );
G2RD_Test_State::$http_responder = function () {
	return photon_reply( array( photon_feature( 'Greenwich', 'place', 'village', 0.00001, 51.4769, array( 'state' => str_repeat( 'a', 500 ) ) ) ) );
};
$r = $geo->geocode( 'Greenwich' );
check( 'coordonnée proche de zéro en notation décimale', is_array( $r ) && '0.00001' === $r['long'] );
check( 'champs tronqués à 200 caractères', is_array( $r ) && 200 === strlen( $r['state_long'] ) );
G2RD_Test_State::$http_responder = function () {
	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'features' => array( array( 'geometry' => array( 'coordinates' => array( 'a' => 1, 'b' => 2 ) ), 'properties' => array( 'name' => 'Metz', 'osm_key' => 'place', 'osm_value' => 'city' ) ) ) ) ) );
};
check( 'coordonnées mal formées : introuvable, sans avertissement', null === $geo->geocode( 'Metz' ) );
G2RD_Test_State::$locale         = 'en_US';
G2RD_Test_State::$user_locale    = 'de_DE';
G2RD_Test_State::$http_responder = function () {
	return photon_reply( array() );
};
$geo->geocode( 'Caen' );
check( 'langue du site (en_US), pas celle de l’utilisateur (de_DE) : lang=en', false !== strpos( end( G2RD_Test_State::$http_calls ), '&lang=en' ) );

list( $s ) = boot( array( 'photon_url' => 'https://photon.local/api/#ancre' ) );
$geo = new PhotonGeocoder( $s );
add_filter( 'g2rd_wpjm_geo_photon_allow_private_host', '__return_true' );
G2RD_Test_State::$http_responder = function () {
	throw new \RuntimeException( 'filtre tiers en erreur' );
};
$thrown = false;
try {
	$geo->geocode( 'Rouen' );
} catch ( \RuntimeException $e ) {
	$thrown = true;
}
check( 'exception pendant la requête : autorisation d’hôte retirée quand même', $thrown && false === apply_filters( 'http_request_host_is_external', false, 'photon.local' ) );
check( 'fragment retiré de l’URL Photon', 0 === strpos( end( G2RD_Test_State::$http_calls ), 'GET https://photon.local/api/?q=Rouen' ) );
```

- [ ] **Step 2: Charger la classe dans les tests et vérifier l'échec**

Dans `tests/bootstrap.php`, ajouter `'PhotonGeocoder'` à la fin de la liste des classes, et ces deux fonctions simulées (si elles n'existent pas encore) à côté de `determine_locale()` :

```php
	function get_locale() {
		return G2RD_Test_State::$locale;
	}
	function wp_check_invalid_utf8( $text, $strip = false ) {
		$text = (string) $text;
		return ( '' === $text || 1 === preg_match( '/^./us', $text ) ) ? $text : '';
	}
```

Toujours dans `tests/bootstrap.php`, distinguer la langue de l'utilisateur de celle du site : ajouter à `G2RD_Test_State` la propriété `public static $user_locale = null;` (remise à `null` dans `reset()`), et faire renvoyer à `determine_locale()` :

```php
		return null !== G2RD_Test_State::$user_locale ? G2RD_Test_State::$user_locale : G2RD_Test_State::$locale;
```

Dans `tests/run.php`, juste après la ligne `$GLOBALS['g2rd_results'] = array( 'pass' => 0, 'fail' => 0 );`, compter tout avertissement PHP comme un échec :

```php

// Tout avertissement PHP compte comme un échec (les tests « sans avertissement » le vérifient vraiment).
set_error_handler(
	function ( $errno, $errstr, $errfile, $errline ) {
		if ( ! ( error_reporting() & $errno ) ) {
			return false;
		}
		$GLOBALS['g2rd_results']['fail']++;
		echo '  [FAIL] PHP : ' . $errstr . ' (' . basename( $errfile ) . ':' . $errline . ')' . PHP_EOL;
		return true;
	}
);
```

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected: erreur `failed to open stream` sur `includes/PhotonGeocoder.php`.

- [ ] **Step 3: Créer `PhotonGeocoder.php`**

```php
<?php
/**
 * Géocodage serveur avec Photon (OpenStreetMap).
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transforme une localisation saisie en tableau d'adresse au format de
 * WP Job Manager (clés attendues par WP_Job_Manager_Geocode::save_location_data()).
 *
 * Seuls les lieux (place=*) et les limites administratives sont acceptés, et
 * seulement si leur nom correspond au premier segment du texte : une offre
 * reste sans coordonnées plutôt que d'être placée sur un homonyme lointain
 * (« IDF » donne Idfu, en Égypte). Entre plusieurs lieux du même nom, le
 * choix favorise le département ou code postal saisi (« Laval (53) »), puis
 * les autres segments du texte (« Valence, Espagne »), puis le pays du site.
 */
class PhotonGeocoder {

	const CACHE_PREFIX = 'g2rd_geo_ph_';
	const CACHE_TTL    = 2592000; // 30 jours.
	const MISS_TTL     = 86400;   // 1 jour.
	const TIMEOUT      = 5;
	const MAX_LENGTH   = 200;
	const MAX_RESPONSE = 262144;  // 256 Kio.

	/**
	 * Valeurs osm_value d'un lieu dont le nom est celui de la ville.
	 */
	const CITY_VALUES = array( 'city', 'town', 'village', 'hamlet', 'municipality', 'suburb', 'locality' );

	/**
	 * Lieux rattachés à une commune : la ville est celle de Photon (properties.city) quand elle est connue.
	 */
	const SUB_CITY_VALUES = array( 'hamlet', 'suburb', 'locality' );

	/**
	 * Lieux qui reçoivent le bonus du pays du site : ni les villages ni les
	 * lieux-dits (« Moscou », « Montréal » dans l'Aude), qui l'emporteraient
	 * sinon sur la grande ville étrangère du même nom.
	 */
	const PREFERRED_VALUES = array( 'city', 'town', 'municipality', 'administrative' );

	/**
	 * Pays des codes postaux français (métropole et outre-mer).
	 */
	const FRENCH_COUNTRIES = array( 'FR', 'RE', 'GP', 'MQ', 'GF', 'YT', 'PM', 'BL', 'MF' );

	/**
	 * Localisations sans lieu précis (forme normalisée) : jamais envoyées à Photon.
	 */
	const VAGUE = array( 'remote', 'full remote', 'teletravail', 'a distance', 'hybride' );

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Le dernier appel s'est-il passé de Photon ?
	 *
	 * @var bool
	 */
	private $last_was_cached = false;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Langue acceptée par l'instance publique de Photon (autre valeur : HTTP 400).
	 *
	 * @param string $locale Locale WordPress.
	 * @return string fr, en, de ou default.
	 */
	public static function language( $locale ) {
		$lang = strtolower( substr( (string) $locale, 0, 2 ) );
		return in_array( $lang, array( 'fr', 'en', 'de' ), true ) ? $lang : 'default';
	}

	/**
	 * Retire parenthèses, numéros d'arrondissement, nombres isolés et séparateurs de bord.
	 *
	 * @param string $text Localisation saisie.
	 * @return string
	 */
	public static function clean( $text ) {
		$text = wp_check_invalid_utf8( (string) $text );
		$text = preg_replace( '/\([^)]*\)/u', ' ', $text );
		// « Paris 15e », « Lyon 1er », « Marseille 13e arrondissement ».
		$text = preg_replace( '/(?<![\p{L}\p{N}])\p{N}+\s*(?:er|re|ère|ème|eme|e|è)(?:\s+arrondissement)?(?![\p{L}\p{N}])/iu', ' ', $text );
		$text = preg_replace( '/(?<![\p{L}\p{N}])\p{N}+(?![\p{L}\p{N}])/u', ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return self::truncate( (string) preg_replace( '/^[\s,;\/\-–—]+|[\s,;\/\-–—]+$/u', '', $text ) );
	}

	/**
	 * Forme de comparaison : sans accents, minuscules, ponctuation remplacée par
	 * une espace, « st » et « ste » lus « saint » et « sainte ».
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	public static function normalize( $text ) {
		// Les accents combinants (texte décomposé, sans l'extension intl) sont retirés à part.
		$text = (string) preg_replace( '/\p{Mn}+/u', '', remove_accents( (string) $text ) );
		$text = trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $text ) ) );
		return (string) preg_replace( array( '/\bst\b/', '/\bste\b/' ), array( 'saint', 'sainte' ), $text );
	}

	/**
	 * Département ou code postal français indiqué dans le texte.
	 *
	 * « Laval (53) » → 53, « 75008 Paris » → 75, « Ajaccio (2A) » → 20,
	 * « Saint-Denis (974) » → 974.
	 *
	 * @param string $text Localisation saisie.
	 * @return string Préfixe de code postal, ou chaîne vide.
	 */
	public static function hint( $text ) {
		if ( ! preg_match( '/\(\s*(\d{2}|97\d|2[AB])\s*\)|(?<!\d)(\d{5})(?!\d)/i', (string) $text, $m ) ) {
			return '';
		}
		$code = ! empty( $m[2] ) ? $m[2] : strtoupper( $m[1] );
		if ( in_array( substr( $code, 0, 2 ), array( '2A', '2B' ), true ) ) {
			return '20';
		}
		return '97' === substr( $code, 0, 2 ) ? substr( $code, 0, 3 ) : substr( $code, 0, 2 );
	}

	/**
	 * Pays favorisé entre deux lieux du même nom : celui de la langue du site.
	 *
	 * @return string Code ISO à deux lettres, ou chaîne vide.
	 */
	public static function preferred_country() {
		$country = preg_match( '/^[a-z]{2,3}_([A-Z]{2})/', (string) get_locale(), $m ) ? $m[1] : '';
		/**
		 * Pays (code ISO à deux lettres) favorisé quand plusieurs lieux portent le nom saisi.
		 *
		 * @param string $country Défaut : région de la langue du site (FR pour fr_FR), vide sinon.
		 */
		$country = (string) apply_filters( 'g2rd_wpjm_geo_photon_preferred_country', $country );
		return strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', $country ), 0, 2 ) );
	}

	/**
	 * Le dernier appel à geocode() s'est-il passé de Photon (cache, ou texte
	 * qui ne désigne aucun lieu) ? Sert à ne marquer une pause qu'après un vrai appel.
	 *
	 * @return bool
	 */
	public function last_was_cached() {
		return $this->last_was_cached;
	}

	/**
	 * Géocode une localisation.
	 *
	 * @param string $text Localisation saisie.
	 * @return array|null|\WP_Error Adresse au format WPJM, null si introuvable, WP_Error si le service est indisponible.
	 */
	public function geocode( $text ) {
		$this->last_was_cached = true;
		$cleaned               = self::clean( $text );
		$segments              = array_map( array( __CLASS__, 'normalize' ), explode( ',', $cleaned ) );
		if ( '' === $segments[0] || in_array( $segments[0], self::VAGUE, true ) ) {
			// Rien de comparable (vide, écriture non latine) ou pas de lieu précis : inutile d'appeler Photon.
			return null;
		}

		$base   = $this->base_url();
		$hint   = self::hint( $text );
		$lang   = self::language( get_locale() );
		$key    = self::CACHE_PREFIX . md5( $base . '|' . $lang . '|' . self::preferred_country() . '|' . implode( ',', $segments ) . '|' . $hint );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'r', $cached ) ) {
			return $cached['r'];
		}

		$this->last_was_cached = false;
		$features              = $this->request( $base, $cleaned, $lang, true );
		if ( is_wp_error( $features ) && 'g2rd_photon_bad_request' === $features->get_error_code() ) {
			$features = $this->request( $base, $cleaned, $lang, false );
		}
		if ( is_wp_error( $features ) ) {
			return $features;
		}

		$feature = self::pick( $features, $cleaned, $hint );
		$address = $feature ? self::to_address( $feature ) : null;
		set_transient( $key, array( 'r' => $address ), $address ? self::CACHE_TTL : self::MISS_TTL );
		return $address;
	}

	/**
	 * Meilleur résultat dont le nom correspond au premier segment du texte.
	 *
	 * À nom égal : lieu français dont le code postal commence par l'indice (+4),
	 * pays, région ou département cité dans les autres segments, article ignoré
	 * (+2), ville ou commune du pays du site (+1). À rang égal, l'ordre de
	 * Photon (pertinence) est conservé.
	 *
	 * @param array  $features Entités GeoJSON renvoyées par Photon.
	 * @param string $cleaned  Texte nettoyé.
	 * @param string $hint     Préfixe de code postal (voir hint()).
	 * @return array|null
	 */
	public static function pick( array $features, $cleaned, $hint = '' ) {
		$segments = array_map( array( __CLASS__, 'normalize' ), explode( ',', (string) $cleaned ) );
		$wanted   = array_shift( $segments );
		if ( '' === $wanted ) {
			return null;
		}
		$context   = array_map( array( __CLASS__, 'without_article' ), array_filter( $segments ) );
		$preferred = self::preferred_country();
		$best      = null;
		$best_rank = -1;
		foreach ( $features as $feature ) {
			$props = ( is_array( $feature ) && isset( $feature['properties'] ) && is_array( $feature['properties'] ) ) ? $feature['properties'] : array();
			$name  = ( isset( $props['name'] ) && is_string( $props['name'] ) ) ? $props['name'] : '';
			if ( '' === $name || self::normalize( $name ) !== $wanted || null === self::to_address( $feature ) ) {
				continue;
			}
			$code  = ( isset( $props['countrycode'] ) && is_string( $props['countrycode'] ) ) ? strtoupper( $props['countrycode'] ) : '';
			$value = ( isset( $props['osm_value'] ) && is_string( $props['osm_value'] ) ) ? $props['osm_value'] : '';
			$rank  = 0;
			if ( '' !== $hint && in_array( $code, self::FRENCH_COUNTRIES, true ) && isset( $props['postcode'] ) && is_scalar( $props['postcode'] ) && 0 === strpos( (string) $props['postcode'], $hint ) ) {
				$rank += 4;
			}
			foreach ( array( 'country', 'state', 'county' ) as $field ) {
				if ( isset( $props[ $field ] ) && is_string( $props[ $field ] ) && in_array( self::without_article( self::normalize( $props[ $field ] ) ), $context, true ) ) {
					$rank += 2;
					break;
				}
			}
			if ( '' !== $preferred && $code === $preferred && in_array( $value, self::PREFERRED_VALUES, true ) ) {
				++$rank;
			}
			if ( $rank > $best_rank ) {
				$best      = $feature;
				$best_rank = $rank;
			}
		}
		return $best;
	}

	/**
	 * Convertit une entité Photon au format de WP Job Manager.
	 *
	 * @param array $feature Entité GeoJSON.
	 * @return array|null null si les coordonnées sont absentes ou hors bornes.
	 */
	public static function to_address( array $feature ) {
		$coords = isset( $feature['geometry']['coordinates'] ) ? $feature['geometry']['coordinates'] : null;
		if ( ! is_array( $coords ) || ! isset( $coords[0], $coords[1] ) || ! is_numeric( $coords[0] ) || ! is_numeric( $coords[1] ) ) {
			return null;
		}
		$lng = (float) $coords[0];
		$lat = (float) $coords[1];
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}

		$props = ( isset( $feature['properties'] ) && is_array( $feature['properties'] ) ) ? $feature['properties'] : array();
		$text  = function ( $key ) use ( $props ) {
			return ( isset( $props[ $key ] ) && is_scalar( $props[ $key ] ) ) ? self::truncate( sanitize_text_field( (string) $props[ $key ] ) ) : '';
		};

		$name      = $text( 'name' );
		$state     = $text( 'state' );
		$country   = $text( 'country' );
		$osm_value = $text( 'osm_value' );
		$city      = $text( 'city' );
		if ( 'place' === $text( 'osm_key' ) && in_array( $osm_value, self::CITY_VALUES, true ) && ( '' === $city || ! in_array( $osm_value, self::SUB_CITY_VALUES, true ) ) ) {
			$city = $name;
		}

		return array(
			'lat'               => self::coordinate( $lat ),
			'long'              => self::coordinate( $lng ),
			'formatted_address' => implode( ', ', array_values( array_unique( array_filter( array( $name, $state, $country ) ) ) ) ),
			'city'              => $city,
			'state_long'        => $state,
			'postcode'          => $text( 'postcode' ),
			'country_long'      => $country,
			'country_short'     => strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', $text( 'countrycode' ) ), 0, 2 ) ),
		);
	}

	/**
	 * Forme normalisée sans article en tête (« la reunion » → « reunion »).
	 *
	 * @param string $text Texte normalisé.
	 * @return string
	 */
	public static function without_article( $text ) {
		return (string) preg_replace( '/^(?:le|la|les|l) /', '', (string) $text );
	}

	/**
	 * Lieu (place=*) ou limite administrative.
	 *
	 * @param mixed $feature Entité GeoJSON.
	 * @return bool
	 */
	public static function is_place( $feature ) {
		$props = ( is_array( $feature ) && isset( $feature['properties'] ) && is_array( $feature['properties'] ) ) ? $feature['properties'] : array();
		$key   = isset( $props['osm_key'] ) ? $props['osm_key'] : '';
		$value = isset( $props['osm_value'] ) ? $props['osm_value'] : '';
		return 'place' === $key || ( 'boundary' === $key && 'administrative' === $value );
	}

	/**
	 * Coordonnée en notation décimale (jamais « 1.0E-5 »), 7 décimales au plus.
	 *
	 * @param float $value Coordonnée.
	 * @return string
	 */
	private static function coordinate( $value ) {
		$text = rtrim( rtrim( sprintf( '%.7F', $value ), '0' ), '.' );
		return '-0' === $text ? '0' : $text;
	}

	/**
	 * Tronque un texte à MAX_LENGTH caractères.
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	private static function truncate( $text ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_LENGTH ) : substr( $text, 0, self::MAX_LENGTH );
	}

	/**
	 * URL Photon configurée, sans fragment (sinon les paramètres partiraient dans le fragment).
	 *
	 * @return string
	 */
	private function base_url() {
		$base = (string) $this->settings->get( 'photon_url' );
		$hash = strpos( $base, '#' );
		return false === $hash ? $base : substr( $base, 0, $hash );
	}

	/**
	 * Appelle Photon.
	 *
	 * @param string $base     URL Photon.
	 * @param string $query    Texte nettoyé.
	 * @param string $lang     Langue Photon.
	 * @param bool   $filtered Restreindre aux lieux et limites administratives.
	 * @return array|\WP_Error Entités, ou erreur (code g2rd_photon_bad_request pour un HTTP 400 avec filtre).
	 */
	private function request( $base, $query, $lang, $filtered ) {
		$url = $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . http_build_query(
			array(
				'q'     => $query,
				'limit' => 5,
				'lang'  => $lang,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		if ( $filtered ) {
			$url .= '&osm_tag=place&osm_tag=' . rawurlencode( 'boundary:administrative' );
		}

		$allow = $this->allow_private_host( $base );
		try {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => self::TIMEOUT,
					'redirection'         => 2,
					'sslverify'           => true,
					'limit_response_size' => self::MAX_RESPONSE,
					'user-agent'          => 'G2RD-WPJM-Geo/' . G2RD_WPJM_GEO_VERSION . ' (+' . home_url( '/' ) . ')',
				)
			);
		} finally {
			if ( $allow ) {
				remove_filter( 'http_request_host_is_external', $allow, 10 );
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 400 === $code && $filtered ) {
			return new \WP_Error( 'g2rd_photon_bad_request', __( 'Photon a refusé la requête (HTTP 400).', 'g2rd-wpjm-geo' ) );
		}
		if ( 400 === $code ) {
			$body = strtolower( (string) wp_remote_retrieve_body( $response ) );
			if ( false !== strpos( $body, 'language' ) || false !== strpos( $body, 'parameter' ) ) {
				// Langue ou paramètre refusé : la configuration du serveur est en cause, pas le texte.
				return new \WP_Error( 'g2rd_photon_config', __( 'Le serveur Photon refuse la langue ou les paramètres de la requête (HTTP 400) : vérifiez sa configuration.', 'g2rd-wpjm-geo' ) );
			}
			// Refusé même sans filtre : propre à ce texte, traité comme introuvable
			// (sinon l'offre bloquerait la file de géocodage à chaque passage).
			return array();
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return new \WP_Error( 'g2rd_photon_http', sprintf( __( 'Photon est indisponible (HTTP %d).', 'g2rd-wpjm-geo' ), $code ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['features'] ) || ! is_array( $data['features'] ) ) {
			return new \WP_Error( 'g2rd_photon_invalid', __( 'Photon a renvoyé une réponse illisible.', 'g2rd-wpjm-geo' ) );
		}
		// Filtré aussi côté serveur : une instance qui ignorerait osm_tag renverrait des commerces.
		return array_values( array_filter( $data['features'], array( __CLASS__, 'is_place' ) ) );
	}

	/**
	 * Autorise, le temps d'une requête, l'hôte d'un Photon auto-hébergé à une adresse privée.
	 *
	 * Photon écoute par défaut en HTTP sur le port 2322 : il doit être servi en
	 * https sur le port 443 (proxy inverse), seul port accepté avec 80 et 8080
	 * par wp_safe_remote_get().
	 *
	 * @param string $base URL Photon configurée.
	 * @return callable|null Callback à retirer après la requête.
	 */
	private function allow_private_host( $base ) {
		/**
		 * Autorise un serveur Photon auto-hébergé à une adresse privée (même serveur, réseau local).
		 * Seul l'hôte de l'URL Photon configurée est autorisé.
		 *
		 * @param bool $allow Défaut false.
		 */
		if ( ! apply_filters( 'g2rd_wpjm_geo_photon_allow_private_host', false ) ) {
			return null;
		}
		$photon_host = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
		$callback    = function ( $external, $host ) use ( $photon_host ) {
			return ( '' !== $photon_host && strtolower( (string) $host ) === $photon_host ) ? true : $external;
		};
		add_filter( 'http_request_host_is_external', $callback, 10, 2 );
		return $callback;
	}
}
```

- [ ] **Step 4: Vérifier le succès**

Run: `php g2rd-wpjm-geo/tests/run.php | sed -n '/^16\./,$p'`
Expected: toutes les lignes de la section 16 en `[OK]`, `0 échoués`.

- [ ] **Step 5: Commit**

```bash
git add g2rd-wpjm-geo/includes/PhotonGeocoder.php g2rd-wpjm-geo/tests/bootstrap.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat: géocodeur Photon avec garde-fou sur le nom et cache"
```

---

### Task 5: Intégration WP Job Manager et file de géocodage

**Files:**
- Create: `g2rd-wpjm-geo/includes/GeocodeQueue.php`, `g2rd-wpjm-geo/includes/GeocodingIntegration.php`
- Modify: `g2rd-wpjm-geo/includes/Plugin.php`, `g2rd-wpjm-geo/tests/bootstrap.php`, `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Écrire le test (section 17)**

Ajouter aux `use` de `tests/run.php` : `use G2RD\WPJMGeo\GeocodeQueue;` et `use G2RD\WPJMGeo\GeocodingIntegration;`. Puis :

```php
echo PHP_EOL . '17. Géocodage libre des offres' . PHP_EOL;

function geo_boot( $provider = 'osm' ) {
	list( $s ) = boot( array( 'provider' => $provider ) );
	$geocoder = new PhotonGeocoder( $s );
	$GLOBALS['g2rd_sleeps'] = 0;
	$queue = new GeocodeQueue(
		$s,
		$geocoder,
		function () {
			$GLOBALS['g2rd_sleeps']++;
		}
	);
	$queue->register();
	( new GeocodingIntegration( $s, $geocoder, $queue ) )->register();
	G2RD_Test_State::$http_responder = function ( $method, $url ) {
		if ( false !== strpos( $url, 'q=Montr%C3%A9al' ) ) {
			return photon_reply( array( photon_feature( 'Montréal', 'place', 'city', -73.5698, 45.5031, array( 'state' => 'Québec', 'country' => 'Canada', 'countrycode' => 'CA' ) ) ) );
		}
		if ( false !== strpos( $url, 'q=Rouen' ) ) {
			return photon_reply( array( photon_feature( 'Rouen', 'place', 'city', 1.0999, 49.4432, array( 'state' => 'Normandie', 'country' => 'France', 'countrycode' => 'FR' ) ) ) );
		}
		if ( false !== strpos( $url, 'q=Brest' ) ) {
			return new WP_Error( 'http_request_failed', 'timeout' );
		}
		return photon_reply( array() );
	};
	return array( $s, $geocoder, $queue );
}
function job_with_google_coords( $id ) {
	G2RD_Test_State::$post_types[ $id ]               = 'job_listing';
	G2RD_Test_State::$meta[ $id ]['geolocated']       = 1;
	G2RD_Test_State::$meta[ $id ]['geolocation_lat']  = '1.5';
	G2RD_Test_State::$meta[ $id ]['geolocation_long'] = '2.5';
}
$src = GeocodingIntegration::META_SOURCE;

geo_boot();
check( 'mode libre : géocodage Google de WP Job Manager coupé', false === apply_filters( 'job_manager_geolocation_enabled', true ) );
job_with_google_coords( 200 );
do_action( 'job_manager_job_location_edited', 200, 'Montréal, Québec, Canada' );
$m = G2RD_Test_State::$meta[200];
check( 'saisie : coordonnées Photon au format WPJM', '45.5031' === $m['geolocation_lat'] && '-73.5698' === $m['geolocation_long'] && 'Montréal' === $m['geolocation_city'] && 'CA' === $m['geolocation_country_short'] && 1 === $m['geolocated'] );
check( 'saisie : source osm', 'osm' === $m[ $src ] );

job_with_google_coords( 201 );
do_action( 'job_manager_job_location_edited', 201, 'IDF' );
check( 'introuvable : anciennes coordonnées effacées, pas de source', ! isset( G2RD_Test_State::$meta[201]['geolocation_lat'] ) && ! isset( G2RD_Test_State::$meta[201]['geolocated'] ) && ! isset( G2RD_Test_State::$meta[201][ $src ] ) );

job_with_google_coords( 202 );
do_action( 'job_manager_job_location_edited', 202, '' );
check( 'localisation vide : coordonnées effacées', ! isset( G2RD_Test_State::$meta[202]['geolocated'] ) );

G2RD_Test_State::$post_types[203]              = 'post';
G2RD_Test_State::$meta[203]['geolocation_lat'] = '9';
do_action( 'job_manager_job_location_edited', 203, 'Rouen' );
check( 'contenu qui n’est pas une offre : ignoré', '9' === G2RD_Test_State::$meta[203]['geolocation_lat'] );

list( , , $queue ) = geo_boot();
G2RD_Test_State::$doing_cron = true;
job_with_google_coords( 210 );
do_action( 'job_manager_job_location_edited', 210, 'Rouen' );
check( 'différé : aucune requête, anciennes coordonnées effacées', 0 === count( G2RD_Test_State::$http_calls ) && ! isset( G2RD_Test_State::$meta[210]['geolocated'] ) );
$queue->flush();
check( 'différé : offre en file, cron planifié', array( 210 ) === get_option( GeocodeQueue::OPTION ) && false !== wp_next_scheduled( GeocodeQueue::CRON_HOOK ) );
G2RD_Test_State::$meta[210]['_job_location'] = 'Rouen'; // WordPress écrit la valeur après le hook.
unset( G2RD_Test_State::$scheduled[ GeocodeQueue::CRON_HOOK ] ); // WordPress retire l'événement avant de l'exécuter.
do_action( GeocodeQueue::CRON_HOOK );
check( 'cron : offre géocodée, source osm, file vide', 'Rouen' === G2RD_Test_State::$meta[210]['geolocation_city'] && 'osm' === G2RD_Test_State::$meta[210][ $src ] && array() === get_option( GeocodeQueue::OPTION ) );
check( 'cron : pause d’une seconde après un appel réseau', 1 === $GLOBALS['g2rd_sleeps'] );

foreach ( array( 211 => 'Brest', 212 => 'Rouen' ) as $id => $loc ) {
	G2RD_Test_State::$post_types[ $id ]            = 'job_listing';
	G2RD_Test_State::$meta[ $id ]['_job_location'] = $loc;
	$queue->push( $id );
}
$queue->flush();
unset( G2RD_Test_State::$scheduled[ GeocodeQueue::CRON_HOOK ] );
do_action( GeocodeQueue::CRON_HOOK );
check( 'panne : lot arrêté, offre remise en fin de file', array( 212, 211 ) === get_option( GeocodeQueue::OPTION ) );
check( 'panne : nouvel essai dans 10 minutes', wp_next_scheduled( GeocodeQueue::CRON_HOOK ) >= time() + 590 );
G2RD_Test_State::$doing_cron = false;

list( , , $queue ) = geo_boot();
job_with_google_coords( 213 );
G2RD_Test_State::$meta[213][ $src ] = 'osm';
do_action( 'job_manager_job_location_edited', 213, 'Brest' );
check( 'saisie, Photon en panne : coordonnées et source effacées', ! isset( G2RD_Test_State::$meta[213]['geolocated'] ) && ! isset( G2RD_Test_State::$meta[213][ $src ] ) );
$queue->flush();
check( 'saisie, Photon en panne : offre mise en file', array( 213 ) === get_option( GeocodeQueue::OPTION ) && false !== wp_next_scheduled( GeocodeQueue::CRON_HOOK ) );
job_with_google_coords( 214 );
$calls = count( G2RD_Test_State::$http_calls );
do_action( 'job_manager_job_location_edited', 214, 'Rouen' );
check( 'Photon signalé en panne : saisie suivante différée, sans appel réseau', count( G2RD_Test_State::$http_calls ) === $calls && ! isset( G2RD_Test_State::$meta[214]['geolocated'] ) );

geo_boot();
add_filter( 'g2rd_wpjm_geo_defer_geocoding', '__return_true' );
job_with_google_coords( 215 );
do_action( 'job_manager_job_location_edited', 215, 'Rouen' );
check( 'filtre g2rd_wpjm_geo_defer_geocoding : géocodage différé', 0 === count( G2RD_Test_State::$http_calls ) && ! isset( G2RD_Test_State::$meta[215]['geolocated'] ) );

geo_boot( 'google' );
check( 'mode Google : géocodage de WP Job Manager actif', true === apply_filters( 'job_manager_geolocation_enabled', true ) );
G2RD_Test_State::$post_types[220]   = 'job_listing';
G2RD_Test_State::$meta[220][ $src ] = 'osm';
do_action( 'job_manager_job_location_edited', 220, 'Paris' );
check( 'mode Google : ancienne source osm oubliée', ! isset( G2RD_Test_State::$meta[220][ $src ] ) );

list( $s, , $queue ) = geo_boot( 'google' );
G2RD_Test_State::$post_types[221]              = 'job_listing';
G2RD_Test_State::$meta[221]['_job_location'] = 'Rouen';
update_option( GeocodeQueue::OPTION, array( 221 ) );
do_action( GeocodeQueue::CRON_HOOK );
check( 'file héritée du mode libre : vidée sans géocoder en mode Google', array() === get_option( GeocodeQueue::OPTION ) && ! isset( G2RD_Test_State::$meta[221]['geolocated'] ) );

geo_boot( 'google' );
G2RD_Test_State::$post_types[230] = 'job_listing';
G2RD_Test_State::$meta[230]       = array( 'geolocated' => 1, 'geolocation_lat' => '49.4432', $src => 'osm' );
WP_Job_Manager_Geocode::save_location_data( 230, array( 'lat' => '49.4431', 'long' => '1.0993' ) ); // update_location_data() de WP Job Manager, en mode Google.
check( 'coordonnées écrites par un tiers : source osm retirée', ! isset( G2RD_Test_State::$meta[230][ $src ] ) );
```

- [ ] **Step 2: Charger les classes et vérifier l'échec**

Dans `tests/bootstrap.php`, ajouter `'GeocodingIntegration', 'GeocodeQueue'` à la fin de la liste des classes.

Toujours dans `tests/bootstrap.php`, faire déclencher par les métadonnées simulées les actions de WordPress, et faire passer la classe `WP_Job_Manager_Geocode` simulée par ces fonctions (comme le vrai WP Job Manager) :

```php
	function update_post_meta( $id, $key, $value ) {
		if ( isset( G2RD_Test_State::$meta[ $id ][ $key ] ) && G2RD_Test_State::$meta[ $id ][ $key ] === $value ) {
			return false;
		}
		$added                                = ! isset( G2RD_Test_State::$meta[ $id ][ $key ] );
		G2RD_Test_State::$meta[ $id ][ $key ] = $value;
		do_action( $added ? 'added_post_meta' : 'updated_post_meta', 0, $id, $key, $value );
		return true;
	}
```

```php
	function delete_post_meta( $id, $key ) {
		if ( ! isset( G2RD_Test_State::$meta[ $id ][ $key ] ) ) {
			return false;
		}
		$value = G2RD_Test_State::$meta[ $id ][ $key ];
		unset( G2RD_Test_State::$meta[ $id ][ $key ] );
		do_action( 'deleted_post_meta', array(), $id, $key, $value );
		return true;
	}
```

```php
		public static function clear_location_data( $job_id ) {
			foreach ( array_keys( isset( G2RD_Test_State::$meta[ $job_id ] ) ? G2RD_Test_State::$meta[ $job_id ] : array() ) as $key ) {
				if ( 'geolocated' === $key || 0 === strpos( $key, 'geolocation_' ) ) {
					delete_post_meta( $job_id, $key );
				}
			}
		}
		public static function save_location_data( $job_id, $address_data ) {
			if ( is_array( $address_data ) && $address_data ) {
				update_post_meta( $job_id, 'geolocated', 1 );
				foreach ( $address_data as $key => $value ) {
					if ( $value ) {
						update_post_meta( $job_id, 'geolocation_' . $key, $value );
					}
				}
			}
		}
```

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected: erreur `failed to open stream` sur `GeocodingIntegration.php`.

- [ ] **Step 3: Créer `GeocodingIntegration.php`**

```php
<?php
/**
 * Géocodage des offres par la source libre, branché sur WP Job Manager.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mode libre : coupe le géocodage Google de WP Job Manager (filtre officiel) et
 * géocode chaque modification de `_job_location` avec Photon. WP Job Manager
 * déclenche `job_manager_job_location_edited` sur add_post_meta et
 * update_post_meta, AVANT l'écriture : la nouvelle valeur est l'argument.
 *
 * Mode Google : WP Job Manager géocode lui-même ; on oublie seulement une
 * ancienne source « osm » pour que l'offre reste visible sur la carte Google.
 */
class GeocodingIntegration {

	const META_SOURCE = '_g2rd_wpjm_geo_source';

	/**
	 * Drapeau posé quand Photon ne répond pas : les enregistrements suivants
	 * passent directement par la file, sans attendre le délai réseau.
	 */
	const DOWN_FLAG = 'g2rd_wpjm_geo_photon_down';
	const DOWN_TTL  = 300;

	/**
	 * Vrai pendant write() : nos propres écritures ne retirent pas la source.
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Géocodeur.
	 *
	 * @var PhotonGeocoder
	 */
	private $geocoder;

	/**
	 * File différée.
	 *
	 * @var GeocodeQueue
	 */
	private $queue;

	/**
	 * Constructeur.
	 *
	 * @param Settings       $settings Réglages.
	 * @param PhotonGeocoder $geocoder Géocodeur.
	 * @param GeocodeQueue   $queue    File différée.
	 */
	public function __construct( Settings $settings, PhotonGeocoder $geocoder, GeocodeQueue $queue ) {
		$this->settings = $settings;
		$this->geocoder = $geocoder;
		$this->queue    = $queue;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Dans les deux modes : des coordonnées écrites par un tiers (WP Job Manager,
		// Go Fetch Jobs, WP All Import) ne sont plus garanties par la source.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_foreign_coords' ), 10, 3 );
		}
		if ( Settings::PROVIDER_OSM === $this->settings->get_provider() ) {
			add_filter( 'job_manager_geolocation_enabled', '__return_false', 99 );
			add_action( 'job_manager_job_location_edited', array( $this, 'on_location_edited' ), 20, 2 );
			return;
		}
		add_action( 'job_manager_job_location_edited', array( $this, 'forget_source' ), 30, 1 );
	}

	/**
	 * Mode Google : la position va être recalculée par WP Job Manager.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return void
	 */
	public function forget_source( $job_id ) {
		$job_id = absint( $job_id );
		if ( $job_id ) {
			delete_post_meta( $job_id, self::META_SOURCE );
		}
	}

	/**
	 * Coordonnées écrites hors de write() : la source ne garantit plus leur origine.
	 *
	 * @param int|int[] $meta_id   ID(s) de la métadonnée.
	 * @param int       $object_id ID de l'offre.
	 * @param string    $meta_key  Clé.
	 * @return void
	 */
	public static function on_foreign_coords( $meta_id, $object_id, $meta_key ) {
		$object_id = absint( $object_id );
		if ( ! self::$writing && $object_id && 'geolocation_lat' === $meta_key ) {
			delete_post_meta( $object_id, self::META_SOURCE );
		}
	}

	/**
	 * Mode libre : géocode la nouvelle localisation.
	 *
	 * @param int    $job_id   ID de l'offre.
	 * @param string $location Nouvelle localisation.
	 * @return void
	 */
	public function on_location_edited( $job_id, $location ) {
		$job_id = absint( $job_id );
		if ( ! $job_id || 'job_listing' !== get_post_type( $job_id ) ) {
			return;
		}
		$location = is_string( $location ) ? trim( $location ) : '';

		if ( '' === $location ) {
			self::write( $job_id, null, Settings::PROVIDER_OSM );
			return;
		}
		if ( self::is_deferred_context() || get_transient( self::DOWN_FLAG ) ) {
			// Les anciennes coordonnées ne correspondent plus : effacées tout de suite, recalculées par la file.
			self::write( $job_id, null, Settings::PROVIDER_OSM );
			$this->queue->push( $job_id );
			return;
		}

		$result = $this->geocoder->geocode( $location );
		if ( is_wp_error( $result ) ) {
			set_transient( self::DOWN_FLAG, 1, self::DOWN_TTL );
			self::write( $job_id, null, Settings::PROVIDER_OSM );
			$this->queue->push( $job_id );
			return;
		}
		self::write( $job_id, $result, Settings::PROVIDER_OSM );
	}

	/**
	 * Import (Go Fetch Jobs, importeur WordPress), WP-Cron ou WP-CLI : pas d'appel réseau pendant l'enregistrement.
	 *
	 * @return bool
	 */
	public static function is_deferred_context() {
		$deferred = defined( 'GOFJ_IMPORTING' )
			|| ( defined( 'WP_IMPORTING' ) && WP_IMPORTING )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
		/**
		 * Géocodage différé (file WP-Cron) plutôt qu'immédiat.
		 *
		 * @param bool $deferred Contexte d'import, de WP-Cron ou de WP-CLI.
		 */
		return (bool) apply_filters( 'g2rd_wpjm_geo_defer_geocoding', $deferred );
	}

	/**
	 * Remplace les coordonnées d'une offre par les méthodes publiques de WP Job Manager.
	 *
	 * @param int        $job_id  ID de l'offre.
	 * @param array|null $address Adresse au format WPJM, ou null pour seulement effacer.
	 * @param string     $source  Fournisseur ayant produit l'adresse.
	 * @return bool false si WP Job Manager est absent.
	 */
	public static function write( $job_id, $address, $source ) {
		if ( ! class_exists( 'WP_Job_Manager_Geocode' ) ) {
			return false;
		}
		self::$writing = true;
		try {
			\WP_Job_Manager_Geocode::clear_location_data( $job_id );
			delete_post_meta( $job_id, self::META_SOURCE );
			if ( is_array( $address ) && $address ) {
				\WP_Job_Manager_Geocode::save_location_data( $job_id, $address );
				update_post_meta( $job_id, self::META_SOURCE, $source );
			}
		} finally {
			self::$writing = false;
		}
		return true;
	}
}
```

- [ ] **Step 4: Créer `GeocodeQueue.php`**

```php
<?php
/**
 * File WP-Cron des géocodages différés (imports, cron, WP-CLI).
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lot de 10 offres, une seconde de pause entre deux appels réels à Photon
 * (usage raisonnable de l'instance publique).
 */
class GeocodeQueue extends JobQueue {

	const OPTION     = 'g2rd_wpjm_geo_geocode_queue';
	const CRON_HOOK  = 'g2rd_wpjm_geo_geocode';
	const LOCK       = 'g2rd_wpjm_geo_geocode_lock';
	const BATCH_SIZE = 10;

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Géocodeur.
	 *
	 * @var PhotonGeocoder
	 */
	private $geocoder;

	/**
	 * Fonction de pause (remplaçable dans les tests).
	 *
	 * @var callable
	 */
	private $sleeper;

	/**
	 * Constructeur.
	 *
	 * @param Settings       $settings Réglages.
	 * @param PhotonGeocoder $geocoder Géocodeur.
	 * @param callable|null  $sleeper  Pause en secondes ; défaut sleep().
	 */
	public function __construct( Settings $settings, PhotonGeocoder $geocoder, $sleeper = null ) {
		$this->settings = $settings;
		$this->geocoder = $geocoder;
		$this->sleeper  = is_callable( $sleeper ) ? $sleeper : 'sleep';
	}

	/**
	 * Géocode une offre de la file.
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

		$result = $this->geocoder->geocode( $location );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		GeocodingIntegration::write( $job_id, $result, Settings::PROVIDER_OSM );
		if ( ! $this->geocoder->last_was_cached() ) {
			call_user_func( $this->sleeper, 1 );
		}
		return true;
	}
}
```

- [ ] **Step 5: Vérifier le succès**

Run: `php g2rd-wpjm-geo/tests/run.php | sed -n '/^17\./,$p'`
Expected: section 17 entièrement en `[OK]`, `0 échoués`.

- [ ] **Step 6: Brancher dans le plugin**

Dans `Plugin.php`, juste après `( new GoFetchIntegration( $this->settings, $normalizer, $queue ) )->register();`, ajouter :

```php

		// Géocodage libre : actif seulement si le fournisseur OpenStreetMap est choisi.
		$geocoder  = new PhotonGeocoder( $this->settings );
		$geo_queue = new GeocodeQueue( $this->settings, $geocoder );
		$geo_queue->register();
		( new GeocodingIntegration( $this->settings, $geocoder, $geo_queue ) )->register();
```

Dans `deactivate()`, après `wp_clear_scheduled_hook( UrlCheckQueue::CRON_HOOK );`, ajouter :

```php
		wp_clear_scheduled_hook( GeocodeQueue::CRON_HOOK );
```

- [ ] **Step 7: Lint et tests**

```bash
for f in g2rd-wpjm-geo/includes/*.php; do php -l "$f" | grep -v "^No syntax"; done
php g2rd-wpjm-geo/tests/run.php | tail -1
```

Expected: aucune sortie de lint, `0 échoués`.

- [ ] **Step 8: Commit**

```bash
git add g2rd-wpjm-geo/includes/GeocodingIntegration.php g2rd-wpjm-geo/includes/GeocodeQueue.php g2rd-wpjm-geo/includes/Plugin.php g2rd-wpjm-geo/tests/bootstrap.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat: géocodage libre des offres, différé pendant les imports"
```

---

### Task 6: Carte — filtre par source

**Files:**
- Modify: `g2rd-wpjm-geo/includes/RestController.php`, `g2rd-wpjm-geo/includes/GeocodingIntegration.php`
- Modify: `g2rd-wpjm-geo/tests/bootstrap.php`, `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Écrire le test (section 18)**

Ajouter aux `use` : `use G2RD\WPJMGeo\RestController;`. Puis :

```php
echo PHP_EOL . '18. Carte : filtre par source' . PHP_EOL;
$osm = RestController::source_meta_query( 'osm' );
check( 'mode libre : seulement la source osm', GeocodingIntegration::META_SOURCE === $osm['key'] && 'osm' === $osm['value'] && '=' === $osm['compare'] );
$google = RestController::source_meta_query( 'google' );
check( 'mode Google : source absente ou google', 'OR' === $google['relation'] && 'NOT EXISTS' === $google[0]['compare'] && 'google' === $google[1]['value'] );
list( $s ) = boot( array( 'provider' => 'osm' ) );
$args = ( new RestController( $s ) )->require_coordinates( array() );
check( 'requête : coordonnées exigées et filtre de source', 'AND' === $args['meta_query']['relation'] && $osm === $args['meta_query'][2] );
$args = ( new RestController( $s ) )->require_coordinates( array( 'meta_query' => array( 'relation' => 'OR', array( 'key' => '_featured', 'value' => '1' ) ) ) );
check( 'requête : un relation OR existant ne rend pas les clauses facultatives', 'AND' === $args['meta_query']['relation'] && 'OR' === $args['meta_query'][0]['relation'] && $osm === $args['meta_query'][1][2] );

geo_boot();
job_with_google_coords( 240 );
do_action( 'job_manager_job_location_edited', 240, 'Rouen' );
check( 'source modifiée : cache des offres de WP Job Manager pas encore renouvelé', 0 === G2RD_Test_State::$cache_bumps );
do_action( 'shutdown' );
check( 'source modifiée : cache des offres renouvelé une fois en fin de requête', 1 === G2RD_Test_State::$cache_bumps );
```

Dans `tests/bootstrap.php`, ajouter `'RestController'` à la fin de la liste des classes. Ajouter à `G2RD_Test_State` la propriété `public static $cache_bumps = 0;` (remise à `0` dans `reset()`), et, à côté de la classe `WP_Job_Manager_Geocode` simulée :

```php
	class WP_Job_Manager_Cache_Helper {
		public static function get_transient_version( $group, $refresh = false ) {
			if ( $refresh ) {
				G2RD_Test_State::$cache_bumps++;
			}
			return (string) G2RD_Test_State::$cache_bumps;
		}
	}
```

- [ ] **Step 2: Vérifier l'échec**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected: `Call to undefined method G2RD\WPJMGeo\RestController::source_meta_query()`.

- [ ] **Step 3: Implémenter**

Dans `RestController.php`, remplacer tout le corps de `require_coordinates()` (le docblock devient « Restreint la requête native aux offres géocodées par le fournisseur actif. ») par :

```php
		$ours = array(
			'relation' => 'AND',
			array(
				'key'     => 'geolocation_lat',
				'value'   => '',
				'compare' => '!=',
			),
			array(
				'key'     => 'geolocation_long',
				'value'   => '',
				'compare' => '!=',
			),
			self::source_meta_query( $this->settings->get_provider() ),
		);
		// Groupe à part : un relation OR imposé au premier niveau par une autre extension ne rend pas ces clauses facultatives.
		$query_args['meta_query'] = ( empty( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			? $ours
			: array( 'relation' => 'AND', $query_args['meta_query'], $ours );
		return $query_args;
```

Après la méthode `require_coordinates()`, ajouter :

```php
	/**
	 * La carte n'affiche que les offres géocodées par le fournisseur actif : Google exige
	 * que ses résultats de géocodage soient affichés sur une Google Map.
	 *
	 * @param string $provider Fournisseur actif.
	 * @return array Clause meta_query.
	 */
	public static function source_meta_query( $provider ) {
		if ( Settings::PROVIDER_OSM === $provider ) {
			return array(
				'key'     => GeocodingIntegration::META_SOURCE,
				'value'   => Settings::PROVIDER_OSM,
				'compare' => '=',
			);
		}
		return array(
			'relation' => 'OR',
			array(
				'key'     => GeocodingIntegration::META_SOURCE,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => GeocodingIntegration::META_SOURCE,
				'value'   => Settings::PROVIDER_GOOGLE,
				'compare' => '=',
			),
		);
	}
```

Dans `cache_key()`, remplacer :

```php
		return 'jm_g2rdgeo_' . md5( $page . '|' . $per_page . '|' . (string) $lang . '|' . G2RD_WPJM_GEO_VERSION ) . $version;
```

par :

```php
		return 'jm_g2rdgeo_' . md5( $page . '|' . $per_page . '|' . (string) $lang . '|' . $this->settings->get_provider() . '|' . G2RD_WPJM_GEO_VERSION ) . $version;
```

Dans `GeocodingIntegration.php` : WP Job Manager garde la liste de `get_job_listings()` un jour en cache et ne renouvelle ce cache ni sur `add_post_meta` ni sur `delete_post_meta`. Dans `register()`, dans la boucle sur `added_post_meta`, `updated_post_meta` et `deleted_post_meta`, après la ligne `add_action( $hook, array( __CLASS__, 'on_foreign_coords' ), 10, 3 );`, ajouter :

```php
			add_action( $hook, array( __CLASS__, 'on_map_meta_changed' ), 10, 3 );
```

Après la méthode `on_foreign_coords()`, ajouter :

```php

	/**
	 * Source ou coordonnées modifiées : la liste des offres mise en cache par
	 * WP Job Manager est renouvelée une fois, en fin de requête.
	 *
	 * @param int|int[] $meta_id   ID(s) de la métadonnée.
	 * @param int       $object_id ID de l'offre.
	 * @param string    $meta_key  Clé.
	 * @return void
	 */
	public static function on_map_meta_changed( $meta_id, $object_id, $meta_key ) {
		if ( in_array( $meta_key, array( self::META_SOURCE, 'geolocation_lat' ), true ) ) {
			// WordPress n'enregistre qu'une fois le même rappel sur le même hook : un seul renouvellement par requête.
			add_action( 'shutdown', array( __CLASS__, 'bump_listings_cache' ) );
		}
	}

	/**
	 * Renouvelle la version du cache des offres de WP Job Manager (et donc celle de la carte).
	 *
	 * @return void
	 */
	public static function bump_listings_cache() {
		if ( class_exists( 'WP_Job_Manager_Cache_Helper' ) ) {
			\WP_Job_Manager_Cache_Helper::get_transient_version( 'get_job_listings', true );
		}
	}
```

Dans `tests/bootstrap.php`, rendre `add_filter()` simulée fidèle à WordPress sur ce point : un rappel identique (`===`) déjà enregistré à la même priorité est remplacé, pas ajouté une seconde fois. Corps de la fonction :

```php
		if ( isset( G2RD_Test_State::$hooks[ $hook ][ $priority ] ) ) {
			foreach ( G2RD_Test_State::$hooks[ $hook ][ $priority ] as $i => $entry ) {
				if ( $entry[0] === $callback ) {
					G2RD_Test_State::$hooks[ $hook ][ $priority ][ $i ] = array( $callback, $args );
					return true;
				}
			}
		}
		G2RD_Test_State::$hooks[ $hook ][ $priority ][] = array( $callback, $args );
		return true;
```

- [ ] **Step 4: Vérifier le succès**

Run: `php g2rd-wpjm-geo/tests/run.php | sed -n '/^18\./,$p'`
Expected: section 18 en `[OK]`, `0 échoués`.

- [ ] **Step 5: Commit**

```bash
git add g2rd-wpjm-geo/includes/RestController.php g2rd-wpjm-geo/includes/GeocodingIntegration.php g2rd-wpjm-geo/tests/bootstrap.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat: la carte n'affiche que les offres de la source active"
```

---

### Task 7: Menu, gabarit de console, feuille de repli et polices

**Files:**
- Create: `g2rd-wpjm-geo/includes/AdminMenu.php`, `g2rd-wpjm-geo/includes/AdminScreen.php`, `g2rd-wpjm-geo/tests/fixtures/fake-admin-console.php`, `tools/build-console-css.php`
- Create (copie/génération): `g2rd-wpjm-geo/assets/css/console.css`, `g2rd-wpjm-geo/assets/fonts/*`
- Modify: `g2rd-wpjm-geo/tests/bootstrap.php`, `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Créer la console simulée**

`g2rd-wpjm-geo/tests/fixtures/fake-admin-console.php` :

```php
<?php
/**
 * Console du thème G2RD simulée (contrat v2) pour les tests.
 *
 * @package G2RD\WPJMGeo
 */

// phpcs:disable -- fichier de test.

namespace G2RD;

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

class AdminConsole {
	const CONTRACT_VERSION = 2;
	const MENU_SLUG        = 'g2rd';
	public static $opened  = array();
	public static function open( array $args ) {
		self::$opened[] = $args;
	}
	public static function close() {
	}
}
```

Créer aussi `g2rd-wpjm-geo/tests/fixtures/index.php` avec `<?php` + `// Silence is golden.`

- [ ] **Step 2: Écrire le test (section 19)**

Ajouter aux `use` : `use G2RD\WPJMGeo\AdminMenu;`. Puis :

```php
echo PHP_EOL . '19. Rattachement au menu' . PHP_EOL;
boot();
AdminMenu::reset();
$menu = new AdminMenu( '__return_false' );
$menu->attach_to_console( 'g2rd' );
$menu->maybe_add_standalone();
$top = G2RD_Test_State::$menus[0];
check( 'sans le thème G2RD : menu autonome « G2RD Geo »', 1 === count( G2RD_Test_State::$menus ) && 'top' === $top['type'] && 'g2rd-wpjm-geo' === $top['slug'] && 'G2RD Geo' === $top['title'] && 'dashicons-location-alt' === $top['icon'] && 31 === $top['position'] );
check( 'sans le thème : écran reconnu', AdminMenu::is_plugin_screen( 'toplevel_page_g2rd-wpjm-geo' ) && ! AdminMenu::is_attached() && ! AdminMenu::is_plugin_screen( 'job_listing_page_g2rd-wpjm-geo' ) );

require __DIR__ . '/fixtures/fake-admin-console.php';
boot();
AdminMenu::reset();
$menu = new AdminMenu( '__return_false' );
$menu->attach_to_console( 'g2rd' );
$menu->maybe_add_standalone();
$sub = G2RD_Test_State::$menus[0];
check( 'avec la console G2RD : sous-menu du menu « G2RD », pas de menu autonome', 1 === count( G2RD_Test_State::$menus ) && 'sub' === $sub['type'] && 'g2rd' === $sub['parent'] && 'Offres géolocalisées' === $sub['title'] );
check( 'avec la console : écran reconnu', AdminMenu::is_attached() && AdminMenu::is_plugin_screen( 'g2rd_page_g2rd-wpjm-geo' ) );
AdminMenu::reset();
```

Dans `tests/bootstrap.php`, ajouter `'AdminMenu'` à la fin de la liste des classes.

- [ ] **Step 3: Vérifier l'échec**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected: `failed to open stream` sur `AdminMenu.php`.

- [ ] **Step 4: Créer `AdminMenu.php`**

```php
<?php
/**
 * Rattachement de la page du plugin au menu d'administration.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thème G2RD avec sa console (contrat v2, action `g2rd_console_menu`) :
 * sous-menu du menu « G2RD ». Sinon : menu de premier niveau « G2RD Geo »,
 * juste sous « Offres d'emploi » (position 30 chez WP Job Manager).
 *
 * La détection repose sur l'action du thème elle-même : un thème G2RD sans
 * console ne la déclenche pas, et le menu autonome prend le relais.
 */
class AdminMenu {

	const STANDALONE_POSITION = 31;

	/**
	 * Hook de la page, renvoyé par WordPress.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Rattaché au menu « G2RD » ?
	 *
	 * @var bool
	 */
	private static $attached = false;

	/**
	 * Rendu de la page.
	 *
	 * @var callable
	 */
	private $render;

	/**
	 * Constructeur.
	 *
	 * @param callable $render Rendu de la page.
	 */
	public function __construct( $render ) {
		$this->render = $render;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'g2rd_console_menu', array( $this, 'attach_to_console' ) );
		add_action( 'admin_menu', array( $this, 'maybe_add_standalone' ), 20 );
		add_filter( 'plugin_action_links_' . plugin_basename( G2RD_WPJM_GEO_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * La console du thème G2RD (contrat v2 ou plus) est-elle disponible ?
	 *
	 * @return bool
	 */
	public static function console_available() {
		return class_exists( 'G2RD\AdminConsole' )
			&& defined( 'G2RD\AdminConsole::CONTRACT_VERSION' )
			&& (int) constant( 'G2RD\AdminConsole::CONTRACT_VERSION' ) >= 2;
	}

	/**
	 * Sous-menu du menu « G2RD » (action du thème, priorité 9 de admin_menu).
	 *
	 * @param string $parent Slug du menu parent.
	 * @return void
	 */
	public function attach_to_console( $parent ) {
		if ( self::$attached || ! self::console_available() ) {
			return;
		}
		// La console est là : jamais de menu autonome, même sans la capacité (WordPress renvoie alors false).
		self::$attached = true;
		$hook           = add_submenu_page(
			'' !== (string) $parent ? (string) $parent : \G2RD\AdminConsole::MENU_SLUG,
			__( 'Console G2RD — Offres géolocalisées', 'g2rd-wpjm-geo' ),
			__( 'Offres géolocalisées', 'g2rd-wpjm-geo' ),
			Settings::CAPABILITY,
			Settings::PAGE_SLUG,
			$this->render
		);
		self::$hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Menu autonome si le thème n'a pas fourni sa console.
	 *
	 * @return void
	 */
	public function maybe_add_standalone() {
		if ( self::$attached ) {
			return;
		}
		self::$hook_suffix = (string) add_menu_page(
			__( 'G2RD WP Job Manager Geo', 'g2rd-wpjm-geo' ),
			__( 'G2RD Geo', 'g2rd-wpjm-geo' ),
			Settings::CAPABILITY,
			Settings::PAGE_SLUG,
			$this->render,
			'dashicons-location-alt',
			self::STANDALONE_POSITION
		);
	}

	/**
	 * Hook de la page du plugin.
	 *
	 * @return string
	 */
	public static function hook_suffix() {
		return self::$hook_suffix;
	}

	/**
	 * Rattaché au menu « G2RD » ?
	 *
	 * @return bool
	 */
	public static function is_attached() {
		return self::$attached;
	}

	/**
	 * L'écran courant est-il la page du plugin ?
	 *
	 * @param string $hook_suffix Suffixe fourni par admin_enqueue_scripts.
	 * @return bool
	 */
	public static function is_plugin_screen( $hook_suffix ) {
		return '' !== self::$hook_suffix && (string) $hook_suffix === self::$hook_suffix;
	}

	/**
	 * Réinitialise l'état (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$hook_suffix = '';
		self::$attached    = false;
	}

	/**
	 * Lien « Réglages » dans la liste des extensions.
	 *
	 * @param array $links Liens existants.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( Settings::page_url() ), esc_html__( 'Réglages', 'g2rd-wpjm-geo' ) ) );
		return $links;
	}
}
```

- [ ] **Step 5: Vérifier le succès**

Run: `php g2rd-wpjm-geo/tests/run.php | sed -n '/^19\./,$p'`
Expected: section 19 en `[OK]`, `0 échoués`.

- [ ] **Step 6: Créer `AdminScreen.php`**

```php
<?php
/**
 * Gabarit de la page d'administration, à la charte de la console G2RD.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rattaché au menu « G2RD » : délègue à \G2RD\AdminConsole (feuille et polices
 * chargées par le thème). Sinon : même balisage, avec la copie de la feuille
 * de la console (assets/css/console.css) et les polices du plugin.
 */
class AdminScreen {

	const STYLE_HANDLE  = 'g2rd-wpjm-geo-console';
	const SCRIPT_HANDLE = 'g2rd-wpjm-geo-admin-settings';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Feuille de repli (mode autonome) et script de la page.
	 *
	 * @param string $hook_suffix Écran courant.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( ! AdminMenu::is_plugin_screen( $hook_suffix ) ) {
			return;
		}
		if ( AdminMenu::is_attached() ) {
			// Le thème reconnaît ses pages au préfixe g2rd_page_ ; charger sa feuille soi-même n'en dépend pas (appel idempotent).
			if ( is_callable( array( 'G2RD\AdminConsole', 'enqueueStyle' ) ) ) {
				\G2RD\AdminConsole::enqueueStyle();
			}
		} else {
			$file = G2RD_WPJM_GEO_DIR . 'assets/css/console.css';
			wp_enqueue_style( self::STYLE_HANDLE, G2RD_WPJM_GEO_URL . 'assets/css/console.css', array( 'wp-components', 'dashicons' ), file_exists( $file ) ? (string) filemtime( $file ) : G2RD_WPJM_GEO_VERSION );
			wp_add_inline_style( self::STYLE_HANDLE, self::font_face_css() );
		}
		wp_enqueue_script( self::SCRIPT_HANDLE, G2RD_WPJM_GEO_URL . 'assets/js/admin-settings.js', array(), G2RD_WPJM_GEO_VERSION, true );
	}

	/**
	 * Polices embarquées (mêmes noms de famille que le thème G2RD).
	 *
	 * @return string
	 */
	public static function font_face_css() {
		$base = G2RD_WPJM_GEO_URL . 'assets/fonts/';
		return '@font-face{font-family:"Instrument Sans";font-style:normal;font-weight:400 700;font-display:swap;src:url("' . esc_url( $base . 'instrument-sans-latin.woff2' ) . '") format("woff2");}'
			. '@font-face{font-family:"IBM Plex Mono";font-style:normal;font-weight:400;font-display:swap;src:url("' . esc_url( $base . 'ibm-plex-mono-400-latin.woff2' ) . '") format("woff2");}'
			. '@font-face{font-family:"IBM Plex Mono";font-style:normal;font-weight:500;font-display:swap;src:url("' . esc_url( $base . 'ibm-plex-mono-500-latin.woff2' ) . '") format("woff2");}';
	}

	/**
	 * Ouvre la page : bandeau, barre, titre, tuiles, puis la zone de contenu.
	 *
	 * @param array $args {
	 *     @type string $eyebrow     Sur-titre.
	 *     @type string $title       Titre.
	 *     @type string $description Description.
	 *     @type array  $tiles       Jusqu'à 3 tuiles : label, value, hint, mono.
	 *     @type array  $chips       Puces : label, tone (ok|warn).
	 * }
	 * @return void
	 */
	public static function open( array $args ) {
		if ( AdminMenu::is_attached() ) {
			\G2RD\AdminConsole::open( $args );
			return;
		}

		$eyebrow     = (string) ( isset( $args['eyebrow'] ) ? $args['eyebrow'] : '' );
		$title       = (string) ( isset( $args['title'] ) ? $args['title'] : '' );
		$description = (string) ( isset( $args['description'] ) ? $args['description'] : '' );
		$tiles       = array_slice( (array) ( isset( $args['tiles'] ) ? $args['tiles'] : array() ), 0, 3 );
		$chips       = (array) ( isset( $args['chips'] ) ? $args['chips'] : array() );

		echo '<hr class="wp-header-end">';
		echo '<div class="wrap" id="g2rd-options-root"><div class="g2rd-app g2rd-app--php">';
		echo '<div class="g2rd-band" aria-hidden="true"></div>';
		echo '<div class="g2rd-inner">';

		echo '<header class="g2rd-topbar"><div class="g2rd-topbar__brand">';
		echo '<span class="g2rd-topbar__mark" aria-hidden="true">G</span>';
		echo '<h1 class="g2rd-topbar__title">' . esc_html__( 'G2RD WP Job Manager Geo', 'g2rd-wpjm-geo' ) . '</h1>';
		echo '<span class="g2rd-chip g2rd-chip--mono">v' . esc_html( G2RD_WPJM_GEO_VERSION ) . '</span>';
		echo '</div>';
		if ( $chips ) {
			echo '<div class="g2rd-topbar__status">';
			foreach ( $chips as $chip ) {
				$tone = ( isset( $chip['tone'] ) && in_array( $chip['tone'], array( 'ok', 'warn' ), true ) ) ? ' is-' . $chip['tone'] : '';
				echo '<span class="g2rd-chip">';
				if ( $tone ) {
					echo '<span class="g2rd-dot' . esc_attr( $tone ) . '" aria-hidden="true"></span>';
				}
				echo esc_html( (string) ( isset( $chip['label'] ) ? $chip['label'] : '' ) ) . '</span>';
			}
			echo '</div>';
		}
		echo '</header>';

		echo '<div class="g2rd-page-head">';
		if ( '' !== $eyebrow ) {
			echo '<p class="g2rd-page-head__eyebrow">' . esc_html( $eyebrow ) . '</p>';
		}
		echo '<h2 class="g2rd-page-head__title">' . esc_html( $title ) . '</h2>';
		if ( '' !== $description ) {
			echo '<p class="g2rd-page-head__desc">' . esc_html( $description ) . '</p>';
		}
		echo '</div>';

		if ( $tiles ) {
			echo '<div class="g2rd-tiles">';
			foreach ( $tiles as $tile ) {
				$value = isset( $tile['value'] ) ? $tile['value'] : '';
				$mono  = ! empty( $tile['mono'] ) || is_numeric( $value );
				echo '<div class="g2rd-tile">';
				echo '<small class="g2rd-tile__label">' . esc_html( (string) ( isset( $tile['label'] ) ? $tile['label'] : '' ) ) . '</small>';
				echo '<div class="g2rd-tile__row">';
				echo '<b class="g2rd-tile__value' . ( $mono ? ' is-mono' : '' ) . '">' . esc_html( (string) $value ) . '</b>';
				if ( ! empty( $tile['hint'] ) ) {
					echo '<span class="g2rd-tile__hint">' . esc_html( (string) $tile['hint'] ) . '</span>';
				}
				echo '</div></div>';
			}
			echo '</div>';
		}

		echo '<div class="g2rd-body"><main class="g2rd-main"><div class="g2rd-tab-content">';
	}

	/**
	 * Ferme la page.
	 *
	 * @return void
	 */
	public static function close() {
		if ( AdminMenu::is_attached() ) {
			\G2RD\AdminConsole::close();
			return;
		}
		echo '</div></main></div></div></div></div>';
	}
}
```

- [ ] **Step 7: Créer `tools/build-console-css.php` et générer la feuille**

```php
<?php
/**
 * Régénère g2rd-wpjm-geo/assets/css/console.css depuis la feuille compilée de
 * la console du thème G2RD (même auteur).
 *
 * Usage : php tools/build-console-css.php ../G2RD-theme
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$theme = rtrim( (string) ( isset( $argv[1] ) ? $argv[1] : '' ), '/\\' );
$src   = $theme . '/blocks/g2rd-options-page/build/style-index.css';
$style = $theme . '/style.css';
if ( '' === $theme || ! is_readable( $src ) || ! is_readable( $style ) ) {
	fwrite( STDERR, "Feuille de la console introuvable : $src\n" );
	exit( 1 );
}

preg_match( '/^\s*Version:\s*(\S+)/mi', (string) file_get_contents( $style ), $match );
$version = isset( $match[1] ) ? $match[1] : 'inconnue';
$null    = '\\' === DIRECTORY_SEPARATOR ? 'NUL' : '/dev/null';
$rel     = 'blocks/g2rd-options-page/build/style-index.css';
$git     = 'git -C ' . escapeshellarg( $theme ) . ' ';
// Dernier commit de la feuille elle-même (pas HEAD) : l'en-tête ne change que si le CSS change.
$commit = trim( (string) shell_exec( $git . 'log -1 --format=%h -- ' . escapeshellarg( $rel ) . ' 2>' . $null ) );
if ( '' !== $commit && '' !== trim( (string) shell_exec( $git . 'status --porcelain -- ' . escapeshellarg( $rel ) . ' 2>' . $null ) ) ) {
	$commit .= ' + modifications locales';
}
// La carte des sources (.map) n'est pas copiée : sa référence provoquerait un 404.
$css = preg_replace( '~\s*/\*#\s*sourceMappingURL=[^*]*\*/\s*$~', "\n", (string) file_get_contents( $src ) );

$header = "/*\n"
	. ' * Copie de la feuille de la console G2RD — thème G2RD ' . $version . ( '' !== $commit ? ', commit ' . $commit : '' ) . ".\n"
	. " * Source : blocks/g2rd-options-page/build/style-index.css — © Sébastien Gérard, reprise par son auteur.\n"
	. " * Chargée UNIQUEMENT quand le thème G2RD ne fournit pas sa console (menu autonome).\n"
	. " * Ne pas modifier à la main : php tools/build-console-css.php <dossier du thème G2RD>.\n"
	. " */\n\n";

$target = dirname( __DIR__ ) . '/g2rd-wpjm-geo/assets/css/console.css';
if ( false === file_put_contents( $target, $header . $css ) ) {
	fwrite( STDERR, "Écriture impossible : $target\n" );
	exit( 1 );
}
echo 'console.css régénérée (thème ' . $version . ( '' !== $commit ? ' ' . $commit : '' ) . ', ' . filesize( $target ) . " octets)\n";
```

Run:

```bash
php tools/build-console-css.php ../G2RD-theme
head -6 g2rd-wpjm-geo/assets/css/console.css
grep -c "\.g2rd-app" g2rd-wpjm-geo/assets/css/console.css
```

Expected: `console.css régénérée (thème 1.40.1 …, ~58 000 octets)`, l'en-tête, puis un nombre supérieur à 100.

- [ ] **Step 8: Copier les polices**

```bash
mkdir -p g2rd-wpjm-geo/assets/fonts
cp ../g2rd-facturation/assets/fonts/instrument-sans-latin.woff2 ../g2rd-facturation/assets/fonts/ibm-plex-mono-400-latin.woff2 ../g2rd-facturation/assets/fonts/ibm-plex-mono-500-latin.woff2 ../g2rd-facturation/assets/fonts/OFL-InstrumentSans.txt ../g2rd-facturation/assets/fonts/OFL-IBMPlexMono.txt g2rd-wpjm-geo/assets/fonts/
cp g2rd-wpjm-geo/assets/index.php g2rd-wpjm-geo/assets/fonts/index.php
ls -la g2rd-wpjm-geo/assets/fonts
```

Expected: 6 fichiers (3 woff2 de 14 à 30 Ko, 2 licences, `index.php`).

- [ ] **Step 9: Lint, tests, commit**

```bash
for f in g2rd-wpjm-geo/includes/*.php tools/*.php; do php -l "$f" | grep -v "^No syntax"; done
php g2rd-wpjm-geo/tests/run.php | tail -1
git add g2rd-wpjm-geo/includes/AdminMenu.php g2rd-wpjm-geo/includes/AdminScreen.php g2rd-wpjm-geo/tests g2rd-wpjm-geo/assets/css/console.css g2rd-wpjm-geo/assets/fonts tools/build-console-css.php
git commit -m "feat: rattachement au menu G2RD ou menu autonome, gabarit et feuille de la console"
```

Expected: lint muet, `0 échoués`.

---

### Task 8: Outil « Re-géocoder les offres »

**Files:**
- Create: `g2rd-wpjm-geo/includes/RegeocodeTool.php`, `g2rd-wpjm-geo/assets/js/admin-regeocode.js`
- Modify: `g2rd-wpjm-geo/includes/Plugin.php`, `g2rd-wpjm-geo/tests/bootstrap.php`, `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Écrire le test (section 20)**

Ajouter aux `use` : `use G2RD\WPJMGeo\RegeocodeTool;`. Puis :

```php
echo PHP_EOL . '20. Re-géocodage des offres' . PHP_EOL;
$rows = array(
	array( 'id' => '300', 'location' => 'Rouen', 'source' => null, 'geolocated' => '1' ),
	array( 'id' => '301', 'location' => 'Montréal', 'source' => 'osm', 'geolocated' => '1' ),
	array( 'id' => '302', 'location' => 'IDF', 'source' => null, 'geolocated' => null ),
	array( 'id' => '303', 'location' => 'Brest', 'source' => null, 'geolocated' => '1' ),
	array( 'id' => '303', 'location' => 'Brest', 'source' => null, 'geolocated' => '1' ),
);
$sum = RegeocodeTool::summarize( $rows, 'osm' );
check( 'résumé libre : 4 offres (doublon ignoré), 2 Google, 1 libre, 1 sans coordonnées', 4 === $sum['total'] && 2 === $sum['google'] && 1 === $sum['osm'] && 1 === $sum['none'] );
check( 'résumé libre : 1 affichée, 3 à re-géocoder', 1 === $sum['shown'] && 3 === $sum['todo'] && array( 300, 302, 303 ) === $sum['todo_ids'] );
$sum = RegeocodeTool::summarize( $rows, 'google' );
check( 'résumé Google : 2 affichées, 2 à re-géocoder', 2 === $sum['shown'] && array( 301, 302 ) === $sum['todo_ids'] );

list( $s ) = geo_boot();
foreach ( $rows as $row ) {
	$id = (int) $row['id'];
	job_with_google_coords( $id );
	G2RD_Test_State::$meta[ $id ]['_job_location'] = $row['location'];
}
$sleeps = 0;
$tool   = new RegeocodeTool(
	$s,
	new PhotonGeocoder( $s ),
	function () use ( &$sleeps ) {
		$sleeps++;
	}
);
$a = $tool->handle( 'analyze', 0, $rows );
check( 'analyse : terminée sans appel réseau', true === $a['done'] && array() === $a['rows'] && 3 === $a['summary']['todo'] && 0 === count( G2RD_Test_State::$http_calls ) && ! isset( $a['summary']['todo_ids'] ) );
$sim = $tool->handle( 'simulate', 0, $rows );
$st  = array_column( $sim['rows'], 'status', 'id' );
check( 'simulation : Rouen prévu, IDF non trouvée, Brest en erreur', 'ok' === $st[300] && 'not_found' === $st[302] && 'error' === $st[303] && 'Rouen, France' === $sim['rows'][0]['result'] );
check( 'simulation : arrêt sur panne, aucune écriture', true === $sim['done'] && true === $sim['stopped'] && '1.5' === G2RD_Test_State::$meta[300]['geolocation_lat'] && ! isset( G2RD_Test_State::$meta[300][ $src ] ) );
check( 'simulation : lien de modification fourni', 'https://example.test/wp-admin/post.php?post=300&action=edit' === $sim['rows'][0]['edit'] );
G2RD_Test_State::$http_responder = function ( $method, $url ) {
	if ( false !== strpos( $url, 'q=Rouen' ) ) {
		return photon_reply( array( photon_feature( 'Rouen', 'place', 'city', 1.0999, 49.4432, array( 'country' => 'France' ) ) ) );
	}
	if ( false !== strpos( $url, 'q=Brest' ) ) {
		return photon_reply( array( photon_feature( 'Brest', 'place', 'city', -4.4861, 48.3904, array( 'country' => 'France' ) ) ) );
	}
	return photon_reply( array() );
};
$before = $sleeps;
$run    = $tool->handle( 'run', 0, $rows );
$st     = array_column( $run['rows'], 'status', 'id' );
check( 'exécution : Rouen et Brest écrits, IDF non trouvée', 'done' === $st[300] && 'done' === $st[303] && 'not_found' === $st[302] && true === $run['done'] );
check( 'exécution : pause seulement après un vrai appel (Brest), pas sur le cache (Rouen, IDF)', 1 === $sleeps - $before );
check( 'exécution : coordonnées libres et source osm', '49.4432' === G2RD_Test_State::$meta[300]['geolocation_lat'] && 'osm' === G2RD_Test_State::$meta[300][ $src ] && 'osm' === G2RD_Test_State::$meta[303][ $src ] );
check( 'exécution : offre non trouvée laissée intacte', '1.5' === G2RD_Test_State::$meta[302]['geolocation_lat'] );
check( 'exécution : curseur sur la dernière offre traitée', 303 === $run['cursor'] );

list( $s ) = geo_boot( 'google' );
foreach ( array( 310 => 'Paris', 311 => 'Nulle part' ) as $id => $loc ) {
	G2RD_Test_State::$post_types[ $id ]            = 'job_listing';
	G2RD_Test_State::$meta[ $id ]['_job_location'] = $loc;
	G2RD_Test_State::$meta[ $id ]['geolocated']    = 1;
	G2RD_Test_State::$meta[ $id ][ $src ]          = 'osm';
}
G2RD_Test_State::$wpjm_geocode['Paris'] = array( 'lat' => '48.85', 'long' => '2.35', 'city' => 'Paris', 'country_long' => 'France' );
$grows = array(
	array( 'id' => '310', 'location' => 'Paris', 'source' => 'osm', 'geolocated' => '1' ),
	array( 'id' => '311', 'location' => 'Nulle part', 'source' => 'osm', 'geolocated' => '1' ),
);
$tool = new RegeocodeTool( $s, new PhotonGeocoder( $s ), '__return_false' );
$sim  = $tool->handle( 'simulate', 0, $grows );
check( 'Google, simulation : aucune requête, à l’exécution', 'pending' === $sim['rows'][0]['status'] && 0 === count( G2RD_Test_State::$http_calls ) );
$run = $tool->handle( 'run', 0, $grows );
$st  = array_column( $run['rows'], 'status', 'id' );
check( 'Google, exécution : Paris géocodée par WPJM, source google', 'done' === $st[310] && 'google' === G2RD_Test_State::$meta[310][ $src ] && '48.85' === G2RD_Test_State::$meta[310]['geolocation_lat'] );
check( 'Google, exécution : échec de géocodage listé, offre intacte', 'not_found' === $st[311] && 'osm' === G2RD_Test_State::$meta[311][ $src ] );
foreach ( array( 312 => 'Lyon', 313 => ',' ) as $id => $loc ) {
	G2RD_Test_State::$post_types[ $id ]            = 'job_listing';
	G2RD_Test_State::$meta[ $id ]['_job_location'] = $loc;
	G2RD_Test_State::$meta[ $id ]['geolocated']    = 1;
	G2RD_Test_State::$meta[ $id ][ $src ]          = 'osm';
}
$lyon = array( array( 'id' => '312', 'location' => 'Lyon', 'source' => 'osm', 'geolocated' => '1' ) );
G2RD_Test_State::$wpjm_geocode['Lyon'] = new WP_Error( 'error', 'Geocoding error' );
$run = $tool->handle( 'run', 0, $lyon );
check( 'Google, clé refusée ou panne : erreur et arrêt, offre intacte', 'error' === $run['rows'][0]['status'] && true === $run['stopped'] && 'osm' === G2RD_Test_State::$meta[312][ $src ] );
G2RD_Test_State::$wpjm_geocode['Lyon'] = false;
$run = $tool->handle( 'run', 0, $lyon );
check( 'Google, quota mémorisé ou géocodage coupé : erreur et arrêt', 'error' === $run['rows'][0]['status'] && true === $run['stopped'] );
$run = $tool->handle( 'run', 0, array( array( 'id' => '313', 'location' => ',', 'source' => 'osm', 'geolocated' => '1' ) ) );
check( 'localisation sans rien de géocodable : non trouvée, sans arrêt', 'not_found' === $run['rows'][0]['status'] && false === $run['stopped'] );
```

Dans `tests/bootstrap.php`, ajouter `'RegeocodeTool'` à la fin de la liste des classes.

- [ ] **Step 2: Vérifier l'échec**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected: `failed to open stream` sur `RegeocodeTool.php`.

- [ ] **Step 3: Créer `RegeocodeTool.php`**

```php
<?php
/**
 * Outil « Re-géocoder les offres » avec le fournisseur actif.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jamais lancé automatiquement. Analyse (sans réseau), simulation (réseau,
 * sans écriture) puis exécution, par lots de 5 via admin-ajax. L'outil
 * n'efface jamais de coordonnées : une offre introuvable reste en l'état et
 * est listée pour correction manuelle.
 */
class RegeocodeTool {

	const AJAX_ACTION  = 'g2rd_wpjm_geo_regeocode';
	const NONCE_ACTION = 'g2rd_wpjm_geo_regeocode';
	const BATCH        = 5;
	const TIME_BUDGET  = 20; // Secondes par requête AJAX.

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Géocodeur libre.
	 *
	 * @var PhotonGeocoder
	 */
	private $geocoder;

	/**
	 * Pause entre deux appels réels à Photon.
	 *
	 * @var callable
	 */
	private $sleeper;

	/**
	 * Constructeur.
	 *
	 * @param Settings       $settings Réglages.
	 * @param PhotonGeocoder $geocoder Géocodeur.
	 * @param callable|null  $sleeper  Pause en secondes ; défaut sleep().
	 */
	public function __construct( Settings $settings, PhotonGeocoder $geocoder, $sleeper = null ) {
		$this->settings = $settings;
		$this->geocoder = $geocoder;
		$this->sleeper  = is_callable( $sleeper ) ? $sleeper : 'sleep';
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'g2rd_wpjm_geo_settings_tools', array( $this, 'render' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax' ) );
	}

	/**
	 * Offres à localisation renseignée (hors corbeille et brouillons automatiques).
	 *
	 * @return array[] Lignes id, location, source, geolocated.
	 */
	public static function rows() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- agrégat en lecture seule sur trois métadonnées, sans équivalent WP_Query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, loc.meta_value AS location, src.meta_value AS source, geo.meta_value AS geolocated
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} loc ON loc.post_id = p.ID AND loc.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} geo ON geo.post_id = p.ID AND geo.meta_key = %s
				WHERE p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' ) AND loc.meta_value <> ''
				ORDER BY p.ID ASC",
				'_job_location',
				GeocodingIntegration::META_SOURCE,
				'geolocated',
				'job_listing'
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Résumé par source.
	 *
	 * @param array  $rows     Lignes de rows().
	 * @param string $provider Fournisseur actif.
	 * @return array total, google, osm, none, shown, todo, todo_ids.
	 */
	public static function summarize( array $rows, $provider ) {
		$summary = array(
			'total'    => 0,
			'google'   => 0,
			'osm'      => 0,
			'none'     => 0,
			'shown'    => 0,
			'todo'     => 0,
			'todo_ids' => array(),
		);
		$seen    = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			if ( ! $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			++$summary['total'];

			$source = ( isset( $row['source'] ) && Settings::PROVIDER_OSM === $row['source'] ) ? Settings::PROVIDER_OSM : Settings::PROVIDER_GOOGLE;
			if ( ! isset( $row['geolocated'] ) || '1' !== (string) $row['geolocated'] ) {
				++$summary['none'];
			} else {
				++$summary[ $source ];
				if ( $source === $provider ) {
					++$summary['shown'];
					continue;
				}
			}
			++$summary['todo'];
			$summary['todo_ids'][] = $id;
		}
		return $summary;
	}

	/**
	 * Résumé pour le fournisseur actif (bandeau de la page).
	 *
	 * @param string $provider Fournisseur actif.
	 * @return array
	 */
	public static function stats( $provider ) {
		return self::summarize( self::rows(), $provider );
	}

	/**
	 * Point d'entrée AJAX.
	 *
	 * @return void
	 */
	public function ajax() {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'g2rd-wpjm-geo' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$task   = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$cursor = isset( $_POST['cursor'] ) ? absint( wp_unslash( $_POST['cursor'] ) ) : 0;
		if ( ! in_array( $task, array( 'analyze', 'simulate', 'run' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Tâche inconnue.', 'g2rd-wpjm-geo' ) ), 400 );
		}
		$result = $this->handle( $task, $cursor, self::rows() );
		if ( 'run' === $task && $result['rows'] ) {
			// Compteurs relus après les écritures du lot : sinon le dernier lot affiche un reste périmé.
			$result['summary'] = array_diff_key( self::summarize( self::rows(), $this->settings->get_provider() ), array( 'todo_ids' => true ) );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Traite un lot.
	 *
	 * @param string $task   analyze, simulate ou run.
	 * @param int    $cursor Dernier ID traité.
	 * @param array  $rows   Lignes de rows().
	 * @return array summary, rows, cursor, done, stopped.
	 */
	public function handle( $task, $cursor, array $rows ) {
		$provider = $this->settings->get_provider();
		$summary  = self::summarize( $rows, $provider );
		$public   = array_diff_key( $summary, array( 'todo_ids' => true ) );

		if ( 'analyze' === $task ) {
			return array( 'summary' => $public, 'rows' => array(), 'cursor' => 0, 'done' => true, 'stopped' => false );
		}

		$cursor  = absint( $cursor );
		$ids     = array_values(
			array_filter(
				$summary['todo_ids'],
				function ( $id ) use ( $cursor ) {
					return $id > $cursor;
				}
			)
		);
		$results = array();
		$stopped = false;
		$start   = microtime( true );
		foreach ( array_slice( $ids, 0, self::BATCH ) as $id ) {
			if ( $results && microtime( true ) - $start > self::TIME_BUDGET ) {
				// Requête AJAX courte : la suite au lot suivant.
				break;
			}
			$cursor    = $id;
			$row       = $this->process( $id, 'run' === $task, $provider );
			$results[] = $row;
			if ( 'error' === $row['status'] ) {
				// Service indisponible : inutile d'insister sur les offres suivantes.
				$stopped = true;
				break;
			}
		}

		return array(
			'summary' => $public,
			'rows'    => $results,
			'cursor'  => $cursor,
			'done'    => $stopped || count( $results ) >= count( $ids ),
			'stopped' => $stopped,
		);
	}

	/**
	 * Re-géocode (ou simule) une offre.
	 *
	 * @param int    $job_id   ID de l'offre.
	 * @param bool   $write    Écrire le résultat.
	 * @param string $provider Fournisseur actif.
	 * @return array id, location, result, status (ok|done|not_found|error|pending), edit.
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
			$result = $this->geocoder->geocode( $location );
			if ( ! $this->geocoder->last_was_cached() ) {
				call_user_func( $this->sleeper, 1 );
			}
		} elseif ( ! $write ) {
			$row['status'] = 'pending';
			$row['result'] = __( 'Géocodage par WP Job Manager (Google) à l’exécution', 'g2rd-wpjm-geo' );
			return $row;
		} else {
			$data = class_exists( 'WP_Job_Manager_Geocode' ) ? \WP_Job_Manager_Geocode::get_location_data( $location ) : false;
			// Message exact de WP Job Manager pour ZERO_RESULTS (même domaine, même traduction) ; tout autre
			// WP_Error vient d'une clé refusée, d'un quota ou du réseau.
			$zero = __( 'No results found', 'wp-job-manager' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
			if ( is_array( $data ) && $data ) {
				$result = $data;
			} elseif ( is_wp_error( $data ) && $zero === $data->get_error_message() ) {
				$result = null; // Aucun résultat chez Google : offre listée « Non trouvée ».
			} elseif ( is_wp_error( $data ) ) {
				/* translators: %s: WP Job Manager error message. */
				$result = new \WP_Error( 'g2rd_geocode_unavailable', sprintf( __( 'Géocodage Google en échec (clé refusée, quota ou réseau) : %s', 'g2rd-wpjm-geo' ), $data->get_error_message() ) );
			} else {
				$result = new \WP_Error( 'g2rd_geocode_unavailable', __( 'Géocodage Google indisponible (quota atteint ou géocodage désactivé).', 'g2rd-wpjm-geo' ) );
			}
		}

		if ( is_wp_error( $result ) ) {
			$row['status'] = 'error';
			$row['result'] = $result->get_error_message();
			return $row;
		}
		if ( null === $result ) {
			$row['status'] = 'not_found';
			return $row;
		}

		$label         = implode( ', ', array_filter( array( isset( $result['city'] ) ? $result['city'] : '', isset( $result['country_long'] ) ? $result['country_long'] : '' ) ) );
		$row['result'] = '' !== $label ? $label : (string) ( isset( $result['formatted_address'] ) ? $result['formatted_address'] : '' );
		if ( $write ) {
			GeocodingIntegration::write( $job_id, $result, $provider );
			$row['status'] = 'done';
		} else {
			$row['status'] = 'ok';
		}
		return $row;
	}

	/**
	 * Script de l'outil, sur la page du plugin uniquement.
	 *
	 * @param string $hook_suffix Écran courant.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( Settings::is_settings_screen( $hook_suffix ) ) {
			wp_enqueue_script( 'g2rd-wpjm-geo-admin-regeocode', G2RD_WPJM_GEO_URL . 'assets/js/admin-regeocode.js', array(), G2RD_WPJM_GEO_VERSION, true );
		}
	}

	/**
	 * Carte de l'outil.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			return;
		}
		$is_osm = Settings::PROVIDER_OSM === $this->settings->get_provider();
		$i18n   = array(
			'running'      => __( 'Traitement en cours…', 'g2rd-wpjm-geo' ),
			'done'         => __( 'Terminé.', 'g2rd-wpjm-geo' ),
			'simulated'    => __( 'Simulation : aucune donnée n’a été modifiée.', 'g2rd-wpjm-geo' ),
			'stopped'      => __( 'Service de géocodage indisponible : traitement interrompu. Relancez plus tard.', 'g2rd-wpjm-geo' ),
			'error'        => __( 'Une erreur est survenue. Le traitement a été interrompu ; vous pouvez le relancer.', 'g2rd-wpjm-geo' ),
			'confirm'      => __( 'Les coordonnées des offres listées vont être remplacées. Continuer ?', 'g2rd-wpjm-geo' ),
			'total'        => __( 'Offres avec une localisation', 'g2rd-wpjm-geo' ),
			'google'       => __( 'Géocodées par Google', 'g2rd-wpjm-geo' ),
			'osm'          => __( 'Géocodées par OpenStreetMap', 'g2rd-wpjm-geo' ),
			'none'         => __( 'Sans coordonnées', 'g2rd-wpjm-geo' ),
			'todo'         => __( 'À re-géocoder', 'g2rd-wpjm-geo' ),
			'job'          => __( 'Offre', 'g2rd-wpjm-geo' ),
			'location'     => __( 'Localisation', 'g2rd-wpjm-geo' ),
			'result'       => __( 'Résultat', 'g2rd-wpjm-geo' ),
			'status'       => __( 'État', 'g2rd-wpjm-geo' ),
			'statusLabels' => array(
				'ok'        => __( 'Prévu', 'g2rd-wpjm-geo' ),
				'done'      => __( 'Re-géocodée', 'g2rd-wpjm-geo' ),
				'not_found' => __( 'Non trouvée', 'g2rd-wpjm-geo' ),
				'error'     => __( 'Erreur', 'g2rd-wpjm-geo' ),
				'pending'   => __( 'À l’exécution', 'g2rd-wpjm-geo' ),
			),
		);
		?>
		<section class="g2rd-section" id="g2rd-geo-outils">
			<div class="g2rd-section__head"><h3 class="g2rd-section__title"><?php esc_html_e( 'Re-géocoder les offres', 'g2rd-wpjm-geo' ); ?></h3></div>
			<p class="g2rd-section__desc">
				<?php
				echo esc_html(
					$is_osm
						? __( 'Recalcule avec Photon (OpenStreetMap) les coordonnées des offres qui ne viennent pas de la source libre. Les offres introuvables ne sont jamais effacées : elles sont listées pour correction.', 'g2rd-wpjm-geo' )
						: __( 'Recalcule avec Google, via WP Job Manager et sa clé serveur, les coordonnées des offres géocodées par OpenStreetMap. Les offres introuvables ne sont jamais effacées.', 'g2rd-wpjm-geo' )
				);
				?>
			</p>
			<div class="g2rd-wpjm-geo-regeocode"
				data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
				data-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>">
				<div class="g2rd-row">
					<button type="button" class="button" data-g2rd-regeo="analyze"><?php esc_html_e( 'Analyser', 'g2rd-wpjm-geo' ); ?></button>
					<button type="button" class="button" data-g2rd-regeo="simulate"><?php esc_html_e( 'Simuler', 'g2rd-wpjm-geo' ); ?></button>
					<button type="button" class="button button-primary" data-g2rd-regeo="run"><?php esc_html_e( 'Re-géocoder', 'g2rd-wpjm-geo' ); ?></button>
				</div>
				<p class="g2rd-muted" role="status" aria-live="polite" data-g2rd-regeo-status></p>
				<div data-g2rd-regeo-summary></div>
				<div data-g2rd-regeo-rows></div>
			</div>
		</section>
		<?php
	}
}
```

- [ ] **Step 4: Vérifier le succès**

Run: `php g2rd-wpjm-geo/tests/run.php | sed -n '/^20\./,$p'`
Expected: section 20 en `[OK]`, `0 échoués`.

- [ ] **Step 5: Créer `assets/js/admin-regeocode.js`**

```js
/**
 * Outil « Re-géocoder les offres » : analyse, simulation, exécution par lots.
 * Tout le contenu est inséré par textContent.
 */
( function () {
	'use strict';

	var TONES = { ok: 'info', done: 'success', not_found: 'warning', error: 'danger', pending: 'neutral' };

	function init( root ) {
		var i18n = {};
		try {
			i18n = JSON.parse( root.getAttribute( 'data-i18n' ) || '{}' );
		} catch ( e ) {
			i18n = {};
		}
		var statusEl = root.querySelector( '[data-g2rd-regeo-status]' );
		var summaryEl = root.querySelector( '[data-g2rd-regeo-summary]' );
		var rowsEl = root.querySelector( '[data-g2rd-regeo-rows]' );
		var buttons = root.querySelectorAll( '[data-g2rd-regeo]' );
		var running = false;

		function setBusy( busy ) {
			running = busy;
			Array.prototype.forEach.call( buttons, function ( button ) {
				button.disabled = busy;
			} );
		}

		function renderSummary( summary ) {
			var list = document.createElement( 'ul' );
			list.className = 'g2rd-stack';
			[ 'total', 'google', 'osm', 'none', 'todo' ].forEach( function ( key ) {
				var item = document.createElement( 'li' );
				item.textContent = ( i18n[ key ] || key ) + ' : ' + ( summary[ key ] || 0 );
				list.appendChild( item );
			} );
			summaryEl.textContent = '';
			summaryEl.appendChild( list );
		}

		function tableBody() {
			var table = rowsEl.querySelector( 'table' );
			if ( table ) {
				return table.tBodies[ 0 ];
			}
			table = document.createElement( 'table' );
			table.className = 'widefat striped g2rd-table';
			var head = table.createTHead().insertRow();
			[ i18n.job, i18n.location, i18n.result, i18n.status ].forEach( function ( label ) {
				var th = document.createElement( 'th' );
				th.scope = 'col';
				th.textContent = label || '';
				head.appendChild( th );
			} );
			table.createTBody();
			rowsEl.appendChild( table );
			return table.tBodies[ 0 ];
		}

		function appendRows( rows ) {
			if ( ! rows.length ) {
				return;
			}
			var body = tableBody();
			rows.forEach( function ( row ) {
				var tr = body.insertRow();
				var idCell = tr.insertCell();
				if ( row.edit && /^https?:\/\//i.test( row.edit ) ) {
					var link = document.createElement( 'a' );
					link.href = row.edit;
					link.textContent = '#' + row.id;
					idCell.appendChild( link );
				} else {
					idCell.textContent = '#' + row.id;
				}
				tr.insertCell().textContent = row.location || '';
				tr.insertCell().textContent = row.result || '';
				var tag = document.createElement( 'span' );
				tag.className = 'g2rd-tag g2rd-tag--' + ( TONES[ row.status ] || 'neutral' );
				tag.textContent = ( i18n.statusLabels && i18n.statusLabels[ row.status ] ) || row.status || '';
				tr.insertCell().appendChild( tag );
			} );
		}

		function run( task ) {
			if ( running ) {
				return;
			}
			if ( 'run' === task && ! window.confirm( i18n.confirm ) ) {
				return;
			}
			rowsEl.textContent = '';
			statusEl.textContent = i18n.running || '';
			setBusy( true );

			function step( cursor ) {
				var body = new URLSearchParams();
				body.append( 'action', root.getAttribute( 'data-action' ) );
				body.append( 'nonce', root.getAttribute( 'data-nonce' ) );
				body.append( 'task', task );
				body.append( 'cursor', String( cursor ) );
				return window.fetch( root.getAttribute( 'data-ajax-url' ), { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) {
						// Le corps JSON porte le message du serveur, même en erreur HTTP (droits insuffisants, tâche inconnue).
						return response.json().then( function ( payload ) {
							return payload;
						}, function () {
							return null;
						} ).then( function ( payload ) {
							if ( ! response.ok || ! payload || ! payload.success ) {
								var error = new Error( 'HTTP ' + response.status );
								error.serverMessage = ( payload && payload.data && 'string' === typeof payload.data.message ) ? payload.data.message : '';
								throw error;
							}
							return payload;
						} );
					} )
					.then( function ( payload ) {
						var data = payload.data;
						renderSummary( data.summary || {} );
						appendRows( data.rows || [] );
						if ( data.done ) {
							if ( data.stopped ) {
								statusEl.textContent = i18n.stopped || '';
							} else {
								statusEl.textContent = 'simulate' === task ? i18n.done + ' ' + i18n.simulated : i18n.done;
							}
							setBusy( false );
							return null;
						}
						return step( data.cursor );
					} );
			}

			step( 0 ).catch( function ( error ) {
				statusEl.textContent = ( error && error.serverMessage ) || i18n.error || '';
				setBusy( false );
			} );
		}

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				run( button.getAttribute( 'data-g2rd-regeo' ) );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '.g2rd-wpjm-geo-regeocode' ), init );
	} );
}() );
```

- [ ] **Step 6: Brancher dans le plugin**

Dans `Plugin.php`, dans le bloc `if ( is_admin() ) {`, juste avant `( new MigrationTool( … ) )->register();`, ajouter :

```php
			( new RegeocodeTool( $this->settings, $geocoder ) )->register();
```

- [ ] **Step 7: Lint, tests, commit**

```bash
php -l g2rd-wpjm-geo/includes/RegeocodeTool.php && node --check g2rd-wpjm-geo/assets/js/admin-regeocode.js
php g2rd-wpjm-geo/tests/run.php | tail -1
git add g2rd-wpjm-geo/includes/RegeocodeTool.php g2rd-wpjm-geo/includes/Plugin.php g2rd-wpjm-geo/assets/js/admin-regeocode.js g2rd-wpjm-geo/tests
git commit -m "feat: outil de re-géocodage des offres par lots, avec simulation"
```

Expected: `No syntax errors`, `0 échoués`.

---

### Task 9: Page d'administration à la charte de la console

**Files:**
- Create: `g2rd-wpjm-geo/includes/SettingsPage.php`, `g2rd-wpjm-geo/assets/js/admin-settings.js`
- Modify: `g2rd-wpjm-geo/includes/Settings.php` (réécrit), `g2rd-wpjm-geo/includes/Logger.php`, `g2rd-wpjm-geo/includes/Plugin.php`, `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Écrire le test (section 21)**

```php
echo PHP_EOL . '21. Adresse de la page' . PHP_EOL;
check( 'page_url pointe sur admin.php', 'https://example.test/wp-admin/admin.php?page=g2rd-wpjm-geo' === Settings::page_url() );
AdminMenu::reset();
check( 'is_settings_screen suit AdminMenu (aucune page enregistrée)', false === Settings::is_settings_screen( 'job_listing_page_g2rd-wpjm-geo' ) );

// Ressources et gabarit de la page (la console simulée est chargée depuis la section 19).
boot();
AdminMenu::reset();
$menu   = new AdminMenu( '__return_false' );
$screen = new AdminScreen();
$menu->maybe_add_standalone();
$screen->enqueue( 'edit.php' );
check( 'autre écran : aucune ressource', array() === G2RD_Test_State::$enqueued );
$screen->enqueue( 'toplevel_page_g2rd-wpjm-geo' );
check( 'menu autonome : copie de la feuille, polices et script', isset( G2RD_Test_State::$enqueued['style:g2rd-wpjm-geo-console'], G2RD_Test_State::$enqueued['inline:g2rd-wpjm-geo-console'], G2RD_Test_State::$enqueued['script:g2rd-wpjm-geo-admin-settings'] ) );
AdminMenu::reset();
G2RD_Test_State::$enqueued    = array();
\G2RD\AdminConsole::$styles = 0;
$menu->attach_to_console( 'g2rd' );
$screen->enqueue( 'g2rd_page_g2rd-wpjm-geo' );
check( 'rattaché : feuille du thème chargée, pas la copie', 1 === \G2RD\AdminConsole::$styles && ! isset( G2RD_Test_State::$enqueued['style:g2rd-wpjm-geo-console'] ) && isset( G2RD_Test_State::$enqueued['script:g2rd-wpjm-geo-admin-settings'] ) );
AdminScreen::open( array( 'title' => 'Titre délégué' ) );
check( 'rattaché : gabarit délégué à la console du thème', 'Titre délégué' === end( \G2RD\AdminConsole::$opened )['title'] );
AdminMenu::reset();
```

Dans `tests/bootstrap.php` : ajouter `'AdminScreen'` à la fin de la liste des classes ; ajouter à `G2RD_Test_State` la propriété `public static $enqueued = array();` (remise à `array()` dans `reset()`) et ces fonctions simulées :

```php
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ) {
		G2RD_Test_State::$enqueued[ 'style:' . $handle ] = $src;
	}
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
		G2RD_Test_State::$enqueued[ 'script:' . $handle ] = $src;
	}
	function wp_add_inline_style( $handle, $data ) {
		G2RD_Test_State::$enqueued[ 'inline:' . $handle ] = $data;
		return true;
	}
	function esc_url( $url, $protocols = null, $_context = 'display' ) {
		return str_replace( '&', '&#038;', esc_url_raw( $url, $protocols ) );
	}
```

Si l'une de ces fonctions existe déjà, la remplacer par cette version. Dans `tests/fixtures/fake-admin-console.php`, ajouter à la classe `AdminConsole` :

```php
	public static $styles = 0;
	public static function enqueueStyle() {
		self::$styles++;
	}
```

Test de rendu (section 22, juste après la section 21) : une case absente du formulaire serait enregistrée décochée.

```php
echo PHP_EOL . '22. Rendu de la page de réglages' . PHP_EOL;
list( $s ) = boot( array( 'provider' => 'google', 'browser_api_key' => 'AIza"<k>' ) );
AdminMenu::reset();
ob_start();
( new \G2RD\WPJMGeo\SettingsPage( $s ) )->render();
$html    = ob_get_clean();
$missing = array();
foreach ( array_keys( Settings::defaults() ) as $key ) {
	if ( false === strpos( $html, 'name="' . Settings::OPTION . '[' . $key . ']"' ) ) {
		$missing[] = $key;
	}
}
check( 'chaque réglage a son champ (une case absente serait enregistrée décochée)', array() === $missing );
check( 'adresse générique : option dédiée dans le formulaire', false !== strpos( $html, 'name="' . Settings::FALLBACK_EMAIL_OPTION . '"' ) );
check( 'formulaire options.php du groupe enregistré', false !== strpos( $html, 'action="options.php"' ) && false !== strpos( $html, 'name="option_page" value="' . Settings::GROUP . '"' ) );
check( 'valeur de la clé échappée', false !== strpos( $html, 'value="AIza&quot;&lt;k&gt;"' ) && false === strpos( $html, 'AIza"<k>' ) );
check( 'mode Google : lignes OpenStreetMap masquées mais présentes', 3 === substr_count( $html, 'data-g2rd-provider="osm" style="display:none"' ) && false === strpos( $html, 'data-g2rd-provider="google" style=' ) );
check( 'remplacement Go Fetch Jobs coché par défaut', 1 === preg_match( '/is-checked"><input[^>]+name="g2rd_wpjm_geo_settings\[gofetch_replace\]"[^>]+checked/', $html ) );
preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
preg_match_all( '/aria-(?:describedby|labelledby)="([^"]+)"/', $html, $refs );
check( 'aucune référence ARIA pendante', array() === array_diff( $refs[1], $ids[1] ) );
AdminMenu::reset();
```

Pour ce test, dans `tests/bootstrap.php` :

- ajouter `'Dependencies', 'GoogleAssets', 'SettingsPage'` à la fin de la liste des classes ;
- dans la classe `G2RD_Test_Wpdb`, remplacer `public $prefix = 'wp_';` par :

```php
		public $prefix   = 'wp_';
		public $posts    = 'wp_posts';
		public $postmeta = 'wp_postmeta';
		public function prepare( $query, ...$args ) {
			return $query;
		}
		public function get_results( $query, $output = null ) {
			return array();
		}
		public function get_var( $query ) {
			return 0;
		}
```

- ajouter, après `esc_url()`, les fonctions simulées du rendu (ne pas dupliquer une fonction déjà présente) :

```php
	// --- Rendu de la page d'administration.
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
	function esc_attr__( $text ) {
		return esc_attr( $text );
	}
	function esc_html__( $text ) {
		return esc_html( $text );
	}
	function _n( $single, $plural, $number ) {
		return 1 === (int) $number ? $single : $plural;
	}
	function checked( $checked, $current = true, $echo = true ) {
		$result = (string) $checked === (string) $current ? " checked='checked'" : '';
		if ( $echo ) {
			echo $result;
		}
		return $result;
	}
	function current_user_can( $capability ) {
		return true;
	}
	function wp_die( $message = '' ) {
		throw new RuntimeException( (string) $message );
	}
	function settings_errors() {
		echo '<!-- settings_errors -->';
	}
	function settings_fields( $group ) {
		echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
	}
```

Test du script (section F de `tests/js/run.js`, juste avant la ligne `console.log('\nResultat JS : ' …`) :

```js
  console.log('\nF. Page de reglages');
  const settingsHtml = '<form class="g2rd-wpjm-geo-settings">'
    + '<label><input type="radio" name="o[provider]" value="google" checked></label><label><input type="radio" name="o[provider]" value="osm"></label>'
    + '<div data-g2rd-provider="google"><input name="o[browser_api_key]" value="k"></div>'
    + '<div data-g2rd-provider="osm" style="display:none"><input type="url" name="o[photon_url]" value="https://p.test/api/"></div>'
    + '<span class="components-form-toggle is-checked"><input class="components-form-toggle__input" type="checkbox" id="t" name="o[gofetch_replace]" value="1" checked></span>'
    + '<div class="g2rd-savebar"><button type="submit">ok</button></div></form>';
  const settings = async (before) => {
    const sw = new JSDOM(settingsHtml, { runScripts: 'outside-only' }).window;
    if (before) before(sw.document);
    sw.eval(src('js/admin-settings.js'));
    await wait(10);
    const sd = sw.document;
    return { sw, sd, bar: sd.querySelector('.g2rd-savebar'), t: sd.getElementById('t'), rows: v => [...sd.querySelectorAll('[data-g2rd-provider="' + v + '"]')].map(n => n.style.display).join() };
  };
  const fire = (s, el, type) => el.dispatchEvent(new s.sw.Event(type, { bubbles: true }));
  let s = await settings();
  check('barre masquee tant que rien ne change', s.bar.style.display === 'none');
  const osmRadio = s.sd.querySelector('input[value="osm"]');
  osmRadio.checked = true; fire(s, osmRadio, 'change');
  check('bascule OSM : lignes Google masquees, OSM affichees, barre visible', s.rows('google') === 'none' && s.rows('osm') === '' && s.bar.style.display === '');
  check('champs masques toujours soumis', new s.sw.FormData(s.sd.querySelector('form')).get('o[browser_api_key]') === 'k');
  const googleRadio = s.sd.querySelector('input[value="google"]');
  googleRadio.checked = true; fire(s, googleRadio, 'change');
  check('retour a la valeur enregistree : barre masquee', s.bar.style.display === 'none');
  s.t.checked = false; fire(s, s.t, 'change');
  check('interrupteur : classe is-checked suit la case', !s.t.parentNode.classList.contains('is-checked'));
  s = await settings(d => { d.getElementById('t').checked = false; d.querySelector('input[value="osm"]').checked = true; });
  check('etat restaure par le navigateur : interrupteur, lignes et barre synchronises', !s.t.parentNode.classList.contains('is-checked') && s.rows('google') === 'none' && s.bar.style.display === '');
  check('formulaire absent : aucune erreur', await (async () => { const ew = new JSDOM('<p></p>', { runScripts: 'outside-only' }).window; ew.eval(src('js/admin-settings.js')); await wait(10); return true; })());
```

- [ ] **Step 2: Vérifier l'échec**

Run: `php g2rd-wpjm-geo/tests/run.php | sed -n '/^21\./,$p'`
Expected: `[FAIL] page_url pointe sur admin.php` et `[FAIL] is_settings_screen …`.

- [ ] **Step 3: Réécrire `Settings.php`**

Remplacer tout le contenu par :

```php
<?php
/**
 * Réglages du plugin : stockage, lecture et assainissement (Settings API).
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options du plugin. Le rendu de la page est assuré par SettingsPage, le
 * rattachement au menu par AdminMenu.
 */
class Settings {

	const OPTION     = 'g2rd_wpjm_geo_settings';
	const GROUP      = 'g2rd_wpjm_geo';
	const PAGE_SLUG  = 'g2rd-wpjm-geo';
	const CAPABILITY = 'manage_options';

	/**
	 * Option dédiée à l'adresse générique de candidature.
	 */
	const FALLBACK_EMAIL_OPTION = 'g2rd_wpjm_geo_fallback_email';

	/**
	 * Valeur d'initialisation uniquement : la logique métier lit toujours
	 * l'option WordPress via get_fallback_email().
	 */
	const DEFAULT_FALLBACK_EMAIL = 'reconversion@pompiersparis.fr';

	const MODE_KEEP    = 'keep';
	const MODE_ALL     = 'all';
	const MODE_INVALID = 'invalid';

	const PROVIDER_GOOGLE = 'google';
	const PROVIDER_OSM    = 'osm';

	const DEFAULT_OSM_STYLE_URL = 'https://tiles.openfreemap.org/styles/liberty';
	const DEFAULT_PHOTON_URL    = 'https://photon.komoot.io/api/';

	const MAX_URL_LENGTH = 2048;

	/**
	 * Cache mémoire des réglages fusionnés avec les valeurs par défaut.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Valeurs par défaut.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'browser_api_key'       => '',
			'map_id'                => '',
			'autocomplete_enabled'  => true,
			'autocomplete_frontend' => true,
			'autocomplete_admin'    => true,
			'map_enabled'           => true,
			'map_height'            => 480,
			'clustering_enabled'    => true,
			'gofetch_replace'       => true,
			'gofetch_scope'         => self::MODE_ALL,
			'log_enabled'           => true,
			'log_retention_days'    => 90,
			'provider'              => self::PROVIDER_GOOGLE,
			'osm_style_url'         => self::DEFAULT_OSM_STYLE_URL,
			'photon_url'            => self::DEFAULT_PHOTON_URL,
		);
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		// Le cache mémoire ne doit jamais survivre à une modification des réglages dans la même requête.
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_cache' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush_cache' ) );
	}

	/**
	 * Vide le cache mémoire des réglages.
	 *
	 * @return void
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/**
	 * Tous les réglages.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return $this->cache;
	}

	/**
	 * Un réglage.
	 *
	 * @param string $key Clé.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Adresse email générique de candidature configurée.
	 *
	 * Retourne la valeur enregistrée dans les options WordPress ; tant
	 * qu'aucune valeur n'a été enregistrée, retourne la valeur d'initialisation.
	 * Si la valeur stockée n'est pas une adresse valide (base modifiée à la
	 * main…), retourne une chaîne vide : aucun remplacement n'est alors effectué.
	 *
	 * @return string
	 */
	public function get_fallback_email() {
		$email = sanitize_email( (string) get_option( self::FALLBACK_EMAIL_OPTION, self::DEFAULT_FALLBACK_EMAIL ) );
		return ( '' !== $email && is_email( $email ) ) ? $email : '';
	}

	/**
	 * Mode de remplacement effectif.
	 *
	 * @return string MODE_KEEP si le remplacement est désactivé, sinon MODE_ALL ou MODE_INVALID.
	 */
	public function get_mode() {
		if ( ! $this->get( 'gofetch_replace' ) ) {
			return self::MODE_KEEP;
		}
		return self::MODE_INVALID === $this->get( 'gofetch_scope' ) ? self::MODE_INVALID : self::MODE_ALL;
	}

	/**
	 * Fournisseur cartographique actif.
	 *
	 * @return string PROVIDER_GOOGLE ou PROVIDER_OSM.
	 */
	public function get_provider() {
		return self::PROVIDER_OSM === $this->get( 'provider' ) ? self::PROVIDER_OSM : self::PROVIDER_GOOGLE;
	}

	/**
	 * URL https avec hôte et sans identifiants, ou chaîne vide.
	 *
	 * Le schéma est comparé sans tenir compte de la casse. Une URL contenant un
	 * utilisateur ou un mot de passe est refusée : elle serait transmise aux
	 * navigateurs des visiteurs.
	 *
	 * @param mixed $raw Valeur saisie.
	 * @return string
	 */
	public static function https_url( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > self::MAX_URL_LENGTH ) {
			return '';
		}
		$url   = esc_url_raw( $raw, array( 'https' ) );
		$parts = '' === $url ? false : wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) || '' === $parts['host'] ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * URL de la page du plugin (sous-menu « G2RD » ou menu autonome).
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * La page courante est-elle la page du plugin ?
	 *
	 * @param string $hook_suffix Suffixe fourni par admin_enqueue_scripts.
	 * @return bool
	 */
	public static function is_settings_screen( $hook_suffix ) {
		return AdminMenu::is_plugin_screen( $hook_suffix );
	}

	/**
	 * Déclare les deux options (capacité et nonce vérifiés par options.php).
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::GROUP,
			self::FALLBACK_EMAIL_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_fallback_email' ),
				'default'           => self::DEFAULT_FALLBACK_EMAIL,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Assainit l'intégralité des réglages. La capacité et le nonce sont
	 * vérifiés par options.php (Settings API) avant cet appel.
	 *
	 * @param mixed $input Données brutes.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = array();

		$clean['browser_api_key'] = isset( $input['browser_api_key'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['browser_api_key'] ) : '';
		$clean['map_id']          = isset( $input['map_id'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['map_id'] ) : '';

		foreach ( array( 'autocomplete_enabled', 'autocomplete_frontend', 'autocomplete_admin', 'map_enabled', 'clustering_enabled', 'log_enabled', 'gofetch_replace' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		$height              = isset( $input['map_height'] ) ? absint( $input['map_height'] ) : $defaults['map_height'];
		$clean['map_height'] = min( 1200, max( 200, $height ) );

		$retention                   = isset( $input['log_retention_days'] ) ? absint( $input['log_retention_days'] ) : $defaults['log_retention_days'];
		$clean['log_retention_days'] = min( 365, max( 1, $retention ) );

		$scope                  = isset( $input['gofetch_scope'] ) ? sanitize_key( $input['gofetch_scope'] ) : self::MODE_ALL;
		$clean['gofetch_scope'] = self::MODE_INVALID === $scope ? self::MODE_INVALID : self::MODE_ALL;

		$clean['provider'] = ( isset( $input['provider'] ) && self::PROVIDER_OSM === $input['provider'] ) ? self::PROVIDER_OSM : self::PROVIDER_GOOGLE;

		$urls = array(
			'osm_style_url' => __( 'L’URL du style de carte doit être une adresse https:// valide, sans identifiant : elle n’a pas été enregistrée.', 'g2rd-wpjm-geo' ),
			'photon_url'    => __( 'L’URL du serveur Photon doit être une adresse https:// valide, sans identifiant : elle n’a pas été enregistrée.', 'g2rd-wpjm-geo' ),
		);
		foreach ( $urls as $key => $message ) {
			$given = isset( $input[ $key ] );
			$raw   = ( $given && is_string( $input[ $key ] ) ) ? trim( $input[ $key ] ) : null;
			$url   = self::https_url( $raw );
			if ( '' !== $url ) {
				$clean[ $key ] = $url;
			} elseif ( '' === $raw ) {
				// Champ vidé : retour à l’instance publique par défaut.
				$clean[ $key ] = $defaults[ $key ];
			} else {
				$clean[ $key ] = (string) $this->get( $key );
				if ( $given ) {
					$this->notify( 'invalid_' . $key, $message );
				}
			}
		}

		$this->cache = null;
		return $clean;
	}

	/**
	 * Valide l'adresse générique. Une adresse invalide (ou vide) n'est jamais
	 * enregistrée : la dernière adresse valide est conservée.
	 *
	 * @param mixed $value Valeur soumise.
	 * @return string
	 */
	public function sanitize_fallback_email( $value ) {
		$raw   = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
		$email = sanitize_email( $raw );

		if ( '' !== $raw && $email === $raw && is_email( $email ) ) {
			return $email;
		}

		$previous = $this->get_fallback_email();
		$this->notify(
			'invalid_fallback_email',
			sprintf(
				/* translators: %s: email address kept. */
				__( 'L’adresse email générique de candidature saisie n’est pas valide : elle n’a pas été enregistrée. L’adresse précédente (%s) est conservée. Pour suspendre les remplacements, décochez l’option « Remplacer les URL de candidature des offres Go Fetch Jobs ».', 'g2rd-wpjm-geo' ),
				'' !== $previous ? $previous : self::DEFAULT_FALLBACK_EMAIL
			)
		);
		return '' !== $previous ? $previous : self::DEFAULT_FALLBACK_EMAIL;
	}

	/**
	 * Affiche un message d'administration. add_settings_error() n'existe que
	 * dans wp-admin, alors que les callbacks d'assainissement peuvent être
	 * appelés par update_option() dans n'importe quel contexte.
	 *
	 * @param string $code    Code du message.
	 * @param string $message Message.
	 * @param string $type    Type (error, warning…).
	 * @return void
	 */
	private function notify( $code, $message, $type = 'error' ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, $message, $type );
		}
	}
}
```

- [ ] **Step 4: Vérifier le succès**

Run: `php g2rd-wpjm-geo/tests/run.php | sed -n '/^21\./,$p'`
Expected: section 21 en `[OK]`, `0 échoués`.

- [ ] **Step 5: Ajouter `Logger::count_since()`**

Dans `Logger.php`, après la méthode `latest()`, ajouter :

```php
	/**
	 * Nombre de remplacements sur les derniers jours.
	 *
	 * @param int $days Nombre de jours.
	 * @return int
	 */
	public static function count_since( $days ) {
		global $wpdb;
		$table = self::table();
		$limit = gmdate( 'Y-m-d H:i:s', time() - max( 1, absint( $days ) ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $limit ) );
	}
```

- [ ] **Step 6: Créer `SettingsPage.php`**

```php
<?php
/**
 * Page d'administration du plugin, à la charte de la console G2RD.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bandeau, sommaire, cartes de réglages (enregistrées par options.php) puis
 * outils (action g2rd_wpjm_geo_settings_tools). Classes du contrat v2 de la
 * console : g2rd-section, g2rd-setting-row, g2rd-field__input, g2rd-alert…
 */
class SettingsPage {

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Rendu de la page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits suffisants pour accéder à cette page.', 'g2rd-wpjm-geo' ) );
		}

		$provider = $this->settings->get_provider();
		$is_osm   = Settings::PROVIDER_OSM === $provider;
		$stats    = RegeocodeTool::stats( $provider );

		AdminScreen::open(
			array(
				'eyebrow'     => __( 'WP Job Manager', 'g2rd-wpjm-geo' ),
				'title'       => __( 'Offres géolocalisées', 'g2rd-wpjm-geo' ),
				'description' => __( 'Carte des offres, autocomplétion des villes et candidatures des offres importées.', 'g2rd-wpjm-geo' ),
				'chips'       => $this->chips( $is_osm, $stats ),
				'tiles'       => array(
					array(
						'label' => __( 'Fournisseur', 'g2rd-wpjm-geo' ),
						'value' => $is_osm ? 'OpenStreetMap' : 'Google Maps',
						'hint'  => $is_osm ? __( 'libre', 'g2rd-wpjm-geo' ) : __( 'clé navigateur', 'g2rd-wpjm-geo' ),
					),
					array(
						'label' => __( 'Offres géolocalisées', 'g2rd-wpjm-geo' ),
						'value' => $stats['shown'] . ' / ' . $stats['total'],
						'mono'  => true,
						'hint'  => __( 'fournisseur actif', 'g2rd-wpjm-geo' ),
					),
					array(
						'label' => __( 'Remplacements sur 30 jours', 'g2rd-wpjm-geo' ),
						'value' => $this->settings->get( 'log_enabled' ) ? (string) Logger::count_since( 30 ) : '—',
						'mono'  => true,
						'hint'  => __( 'candidatures Go Fetch Jobs', 'g2rd-wpjm-geo' ),
					),
				),
			)
		);

		// Sans argument : affiche aussi la confirmation native « Réglages enregistrés ».
		settings_errors();
		$this->toc();

		echo '<form action="options.php" method="post" class="g2rd-wpjm-geo-settings g2rd-tab-content">'; // g2rd-tab-content : espacement des cartes, comme dans le thème.
		settings_fields( Settings::GROUP );
		$this->section_cartography( $is_osm, $stats );
		$this->section_autocomplete();
		$this->section_map();
		$this->section_gofetch();
		$this->section_log();
		echo '<div class="g2rd-savebar" role="region" aria-label="' . esc_attr__( 'Enregistrement des réglages', 'g2rd-wpjm-geo' ) . '">';
		echo '<span class="g2rd-savebar__text">' . esc_html__( 'Modifications en attente', 'g2rd-wpjm-geo' ) . '</span>';
		echo '<button type="submit" class="components-button is-primary g2rd-savebar__save">' . esc_html__( 'Enregistrer les modifications', 'g2rd-wpjm-geo' ) . '</button>';
		echo '</div></form>';

		/**
		 * Outils sous les réglages (re-géocodage, offres Go Fetch Jobs, journal).
		 */
		do_action( 'g2rd_wpjm_geo_settings_tools' );

		AdminScreen::close();
	}

	/**
	 * Puces d'état du bandeau.
	 *
	 * @param bool  $is_osm Mode libre.
	 * @param array $stats  Résumé de RegeocodeTool.
	 * @return array
	 */
	private function chips( $is_osm, array $stats ) {
		$chips = array();
		if ( Dependencies::has_gofetch() ) {
			$chips[] = array( 'label' => __( 'Go Fetch Jobs actif', 'g2rd-wpjm-geo' ), 'tone' => 'ok' );
		}
		if ( ! $is_osm && ! GoogleAssets::has_key( $this->settings ) ) {
			$chips[] = array( 'label' => __( 'Clé navigateur manquante', 'g2rd-wpjm-geo' ), 'tone' => 'warn' );
		}
		if ( $stats['todo'] > 0 ) {
			$chips[] = array(
				/* translators: %d: number of jobs. */
				'label' => sprintf( __( 'Offres à re-géocoder : %d', 'g2rd-wpjm-geo' ), $stats['todo'] ),
				'tone'  => 'warn',
			);
		}
		return $chips;
	}

	/**
	 * Sommaire d'ancres.
	 *
	 * @return void
	 */
	private function toc() {
		$items = array(
			'cartographie'   => __( 'Cartographie', 'g2rd-wpjm-geo' ),
			'autocompletion' => __( 'Autocomplétion', 'g2rd-wpjm-geo' ),
			'carte'          => __( 'Carte', 'g2rd-wpjm-geo' ),
			'gofetch'        => __( 'Candidatures Go Fetch Jobs', 'g2rd-wpjm-geo' ),
			'journal'        => __( 'Journal', 'g2rd-wpjm-geo' ),
			'outils'         => __( 'Outils', 'g2rd-wpjm-geo' ),
		);
		echo '<nav class="g2rd-row g2rd-row--wrap" aria-label="' . esc_attr__( 'Sommaire', 'g2rd-wpjm-geo' ) . '">';
		foreach ( $items as $id => $label ) {
			echo '<a class="g2rd-chip g2rd-chip--muted" href="#g2rd-geo-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Carte « Cartographie ».
	 *
	 * @param bool  $is_osm Mode libre.
	 * @param array $stats  Résumé de RegeocodeTool.
	 * @return void
	 */
	private function section_cartography( $is_osm, array $stats ) {
		$this->open_section( 'cartographie', __( 'Cartographie', 'g2rd-wpjm-geo' ), __( 'Fournisseur de la carte, de l’autocomplétion des villes et du géocodage des offres : le choix s’applique aux trois.', 'g2rd-wpjm-geo' ) );
		$this->row(
			'g2rd-wpjm-geo-provider',
			__( 'Fournisseur cartographique', 'g2rd-wpjm-geo' ),
			__( 'Google Maps : clé navigateur requise, géocodage par WP Job Manager. OpenStreetMap : MapLibre, OpenFreeMap et Photon, sans clé ni compte.', 'g2rd-wpjm-geo' ),
			$this->radios(
				'provider',
				array(
					Settings::PROVIDER_GOOGLE => __( 'Google Maps', 'g2rd-wpjm-geo' ),
					Settings::PROVIDER_OSM    => __( 'OpenStreetMap (libre)', 'g2rd-wpjm-geo' ),
				),
				$this->settings->get_provider()
			),
			'',
			true
		);
		$this->row(
			'g2rd-wpjm-geo-browser_api_key',
			__( 'Google Maps Browser API Key', 'g2rd-wpjm-geo' ),
			sprintf(
				/* translators: %s: site domain pattern. */
				__( 'Clé utilisée uniquement par le navigateur (autocomplétion et carte). Restreignez-la au référent %s et aux API « Maps JavaScript API » et « Places API (New) ». La clé serveur de WP Job Manager n’est jamais envoyée au navigateur.', 'g2rd-wpjm-geo' ),
				wp_parse_url( home_url(), PHP_URL_HOST ) . '/*'
			),
			$this->input( 'browser_api_key', 'password', ' autocomplete="new-password" spellcheck="false"' ),
			Settings::PROVIDER_GOOGLE
		);
		$this->row(
			'g2rd-wpjm-geo-map_id',
			__( 'Map ID (facultatif)', 'g2rd-wpjm-geo' ),
			__( 'Active les marqueurs avancés de Google. Sans Map ID, la carte utilise les marqueurs classiques.', 'g2rd-wpjm-geo' ),
			$this->input( 'map_id', 'text', ' autocomplete="off" spellcheck="false"' ),
			Settings::PROVIDER_GOOGLE
		);
		$this->row(
			'g2rd-wpjm-geo-osm_style_url',
			__( 'Style de la carte', 'g2rd-wpjm-geo' ),
			__( 'URL https d’un style MapLibre. Styles OpenFreeMap : liberty, bright ou positron (https://tiles.openfreemap.org/styles/…). Videz le champ pour revenir au style par défaut.', 'g2rd-wpjm-geo' ),
			$this->input( 'osm_style_url', 'text', ' inputmode="url" spellcheck="false"' ), // text : un champ url invalide et masqué bloquerait l'envoi sans message.
			Settings::PROVIDER_OSM
		);
		$this->row(
			'g2rd-wpjm-geo-photon_url',
			__( 'Serveur Photon', 'g2rd-wpjm-geo' ),
			__( 'URL https de l’API de recherche Photon, utilisée pour l’autocomplétion et le géocodage des offres. Remplaçable par un serveur auto-hébergé ; videz le champ pour revenir à l’instance publique.', 'g2rd-wpjm-geo' ),
			$this->input( 'photon_url', 'text', ' inputmode="url" spellcheck="false"' ),
			Settings::PROVIDER_OSM
		);
		echo '</div>';
		$this->alert(
			'info',
			__( 'Services publics gratuits, sans garantie de disponibilité. L’attribution « © OpenStreetMap contributors » est affichée automatiquement. L’adresse IP des visiteurs de la carte est transmise à OpenFreeMap, celle des personnes qui saisissent une ville au serveur Photon : un texte suggéré est disponible dans Réglages → Confidentialité.', 'g2rd-wpjm-geo' ),
			Settings::PROVIDER_OSM
		);
		if ( $stats['todo'] > 0 ) {
			$this->alert(
				'warning',
				sprintf(
					/* translators: %d: number of jobs. */
					_n( '%d offre n’est pas géocodée par le fournisseur actif : elle n’apparaît pas sur la carte.', '%d offres ne sont pas géocodées par le fournisseur actif : elles n’apparaissent pas sur la carte.', $stats['todo'], 'g2rd-wpjm-geo' ),
					$stats['todo']
				),
				'',
				'#g2rd-geo-outils',
				__( 'Re-géocoder les offres', 'g2rd-wpjm-geo' )
			);
		}
		echo '</section>';
	}

	/**
	 * Carte « Autocomplétion ».
	 *
	 * @return void
	 */
	private function section_autocomplete() {
		$this->open_section( 'autocompletion', __( 'Autocomplétion des villes', 'g2rd-wpjm-geo' ), __( 'Le champ de localisation natif de WP Job Manager est conservé : aucune saisie en double.', 'g2rd-wpjm-geo' ) );
		$this->row( 'g2rd-wpjm-geo-autocomplete_enabled', __( 'Activer l’autocomplétion des villes', 'g2rd-wpjm-geo' ), __( 'Interrupteur général.', 'g2rd-wpjm-geo' ), $this->toggle( 'autocomplete_enabled' ) );
		$this->row( 'g2rd-wpjm-geo-autocomplete_frontend', __( 'Formulaire public', 'g2rd-wpjm-geo' ), __( 'Dépôt et modification d’une offre par un employeur.', 'g2rd-wpjm-geo' ), $this->toggle( 'autocomplete_frontend' ) );
		$this->row( 'g2rd-wpjm-geo-autocomplete_admin', __( 'Administration', 'g2rd-wpjm-geo' ), __( 'Ajout et modification d’une offre dans wp-admin.', 'g2rd-wpjm-geo' ), $this->toggle( 'autocomplete_admin' ) );
		echo '</div></section>';
	}

	/**
	 * Carte « Carte des offres ».
	 *
	 * @return void
	 */
	private function section_map() {
		$this->open_section(
			'carte',
			__( 'Carte des offres', 'g2rd-wpjm-geo' ),
			sprintf(
				/* translators: 1: shortcode, 2: block name. */
				__( 'Shortcode %1$s (attribut facultatif height="500") ou bloc « %2$s ».', 'g2rd-wpjm-geo' ),
				'[wpjm_jobs_map]',
				__( 'Carte des offres d’emploi', 'g2rd-wpjm-geo' )
			)
		);
		$this->row( 'g2rd-wpjm-geo-map_enabled', __( 'Activer la carte', 'g2rd-wpjm-geo' ), '', $this->toggle( 'map_enabled', false ) );
		$this->row( 'g2rd-wpjm-geo-map_height', __( 'Hauteur de la carte (px)', 'g2rd-wpjm-geo' ), __( 'De 200 à 1200 px. Sur petit écran, la carte est limitée à 70 % de la hauteur de la fenêtre ; la largeur est toujours fluide.', 'g2rd-wpjm-geo' ), $this->input( 'map_height', 'number', ' min="200" max="1200" step="10"' ) );
		$this->row( 'g2rd-wpjm-geo-clustering_enabled', __( 'Regrouper les offres proches', 'g2rd-wpjm-geo' ), __( 'Les offres aux coordonnées strictement identiques sont toujours réunies dans une même fenêtre.', 'g2rd-wpjm-geo' ), $this->toggle( 'clustering_enabled' ) );
		echo '</div></section>';
	}

	/**
	 * Carte « Candidatures Go Fetch Jobs ».
	 *
	 * @return void
	 */
	private function section_gofetch() {
		$this->open_section(
			'gofetch',
			__( 'Candidatures Go Fetch Jobs', 'g2rd-wpjm-geo' ),
			Dependencies::has_gofetch()
				? __( 'Go Fetch Jobs est actif. Seules les offres importées par Go Fetch Jobs sont concernées ; les offres déposées par un employeur ou créées par un administrateur ne sont jamais modifiées.', 'g2rd-wpjm-geo' )
				: __( 'Go Fetch Jobs n’est pas actif : ces réglages sont sans effet pour le moment.', 'g2rd-wpjm-geo' )
		);
		$this->row(
			'g2rd-wpjm-geo-fallback_email',
			__( 'Adresse email générique de candidature', 'g2rd-wpjm-geo' ),
			__( 'Cette adresse est utilisée lorsqu’une offre importée par Go Fetch Jobs contient une URL dans le champ de candidature.', 'g2rd-wpjm-geo' ),
			'<input class="g2rd-field__input" type="email" id="g2rd-wpjm-geo-fallback_email" name="' . esc_attr( Settings::FALLBACK_EMAIL_OPTION ) . '" value="' . esc_attr( $this->settings->get_fallback_email() ) . '" required aria-describedby="g2rd-wpjm-geo-fallback_email-desc" />'
		);
		$this->row( 'g2rd-wpjm-geo-gofetch_replace', __( 'Remplacer les URL de candidature des offres Go Fetch Jobs', 'g2rd-wpjm-geo' ), __( 'Lorsqu’elle est activée, toute URL de candidature issue d’une offre Go Fetch Jobs est remplacée par l’adresse email renseignée ci-dessus.', 'g2rd-wpjm-geo' ), $this->toggle( 'gofetch_replace' ) );
		$this->row(
			'g2rd-wpjm-geo-gofetch_scope',
			__( 'URL concernées', 'g2rd-wpjm-geo' ),
			__( 'Une adresse email valide n’est jamais modifiée et une valeur vide reste vide.', 'g2rd-wpjm-geo' ),
			$this->radios(
				'gofetch_scope',
				array(
					Settings::MODE_ALL     => __( 'Toutes les URL (recommandé) — aucune requête HTTP', 'g2rd-wpjm-geo' ),
					Settings::MODE_INVALID => __( 'Uniquement les URL invalides ou inaccessibles — vérification différée par WP-Cron', 'g2rd-wpjm-geo' ),
				),
				Settings::MODE_INVALID === $this->settings->get( 'gofetch_scope' ) ? Settings::MODE_INVALID : Settings::MODE_ALL
			),
			'',
			true
		);
		echo '</div></section>';
	}

	/**
	 * Carte « Journal ».
	 *
	 * @return void
	 */
	private function section_log() {
		$this->open_section( 'journal', __( 'Journal', 'g2rd-wpjm-geo' ), __( 'Trace de chaque remplacement : offre, date, ancienne et nouvelle valeur, raison, source.', 'g2rd-wpjm-geo' ) );
		$this->row( 'g2rd-wpjm-geo-log_enabled', __( 'Activer le journal', 'g2rd-wpjm-geo' ), '', $this->toggle( 'log_enabled', false ) );
		$this->row(
			'g2rd-wpjm-geo-log_retention_days',
			__( 'Durée de conservation (jours)', 'g2rd-wpjm-geo' ),
			sprintf(
				/* translators: %d: max number of rows. */
				__( 'Les entrées plus anciennes sont purgées chaque jour. Le journal est en outre plafonné à %d entrées.', 'g2rd-wpjm-geo' ),
				Logger::MAX_ROWS
			),
			$this->input( 'log_retention_days', 'number', ' min="1" max="365"' )
		);
		echo '</div></section>';
	}

	/**
	 * Ouvre une carte et sa liste de réglages (fermer par `</div></section>`).
	 *
	 * @param string $id    Suffixe d'ancre.
	 * @param string $title Titre.
	 * @param string $desc  Description.
	 * @return void
	 */
	private function open_section( $id, $title, $desc ) {
		echo '<section class="g2rd-section" id="g2rd-geo-' . esc_attr( $id ) . '">';
		echo '<div class="g2rd-section__head"><h3 class="g2rd-section__title">' . esc_html( $title ) . '</h3></div>';
		if ( '' !== $desc ) {
			echo '<p class="g2rd-section__desc">' . esc_html( $desc ) . '</p>';
		}
		echo '<div class="g2rd-setting-list">';
	}

	/**
	 * Attributs d'un élément propre à un fournisseur.
	 *
	 * @param string $provider Fournisseur, ou chaîne vide.
	 * @return string Attributs échappés, précédés d'une espace.
	 */
	private function provider_attrs( $provider ) {
		if ( '' === $provider ) {
			return '';
		}
		$attrs = ' data-g2rd-provider="' . esc_attr( $provider ) . '"';
		if ( $provider !== $this->settings->get_provider() ) {
			// style et non hidden : la console donne display:flex aux lignes, ce qui l'emporte sur [hidden].
			$attrs .= ' style="display:none"';
		}
		return $attrs;
	}

	/**
	 * Ligne de réglage.
	 *
	 * @param string $field_id Identifiant du champ.
	 * @param string $title    Titre.
	 * @param string $desc     Description.
	 * @param string $control  HTML du contrôle (construit et échappé par cette classe).
	 * @param string $provider Fournisseur concerné, ou chaîne vide.
	 * @param bool   $group    Groupe de boutons radio (titre non relié par for).
	 * @return void
	 */
	private function row( $field_id, $title, $desc, $control, $provider = '', $group = false ) {
		echo '<div class="g2rd-setting-row"' . $this->provider_attrs( $provider ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributs échappés par provider_attrs().
		echo '<div class="g2rd-setting-row__main">';
		if ( $group ) {
			echo '<span class="g2rd-setting-row__title" id="' . esc_attr( $field_id ) . '-title">' . esc_html( $title ) . '</span>';
		} else {
			echo '<label class="g2rd-setting-row__title" for="' . esc_attr( $field_id ) . '">' . esc_html( $title ) . '</label>';
		}
		if ( '' !== $desc ) {
			echo '<p class="g2rd-setting-row__desc" id="' . esc_attr( $field_id ) . '-desc">' . esc_html( $desc ) . '</p>';
		}
		echo '</div><div class="g2rd-setting-row__control">' . $control . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- contrôle construit et échappé par les méthodes de cette classe.
	}

	/**
	 * Encart.
	 *
	 * @param string $tone       info, warning, success ou danger.
	 * @param string $text       Texte.
	 * @param string $provider   Fournisseur concerné, ou chaîne vide.
	 * @param string $link_url   Lien facultatif.
	 * @param string $link_label Libellé du lien.
	 * @return void
	 */
	private function alert( $tone, $text, $provider = '', $link_url = '', $link_label = '' ) {
		echo '<div class="g2rd-alert g2rd-alert--' . esc_attr( $tone ) . '"' . $this->provider_attrs( $provider ) . '><p>' . esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributs échappés par provider_attrs().
		if ( '' !== $link_url ) {
			echo ' <a href="' . esc_url( $link_url ) . '">' . esc_html( $link_label ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Nom de champ de l'option principale.
	 *
	 * @param string $key Clé.
	 * @return string
	 */
	private function name( $key ) {
		return Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Interrupteur (balisage de FormToggle de WordPress).
	 *
	 * @param string $key       Clé.
	 * @param bool   $described La ligne a une description.
	 * @return string
	 */
	private function toggle( $key, $described = true ) {
		$id = 'g2rd-wpjm-geo-' . $key;
		$on = (bool) $this->settings->get( $key );
		return '<span class="components-form-toggle g2rd-toggle' . ( $on ? ' is-checked' : '' ) . '">'
			. '<input class="components-form-toggle__input" type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $key ) ) . '" value="1"' . checked( $on, true, false ) . ( $described ? ' aria-describedby="' . esc_attr( $id ) . '-desc"' : '' ) . ' />'
			. '<span class="components-form-toggle__track"></span><span class="components-form-toggle__thumb"></span></span>';
	}

	/**
	 * Champ texte, nombre, URL ou mot de passe.
	 *
	 * @param string $key   Clé.
	 * @param string $type  Type HTML.
	 * @param string $extra Attributs littéraux supplémentaires (constantes de cette classe).
	 * @return string
	 */
	private function input( $key, $type, $extra = '' ) {
		$id = 'g2rd-wpjm-geo-' . $key;
		return '<input class="g2rd-field__input" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $key ) ) . '" value="' . esc_attr( (string) $this->settings->get( $key ) ) . '" aria-describedby="' . esc_attr( $id ) . '-desc"' . $extra . ' />';
	}

	/**
	 * Groupe de boutons radio.
	 *
	 * @param string $key     Clé.
	 * @param array  $choices Valeur => libellé.
	 * @param string $current Valeur courante.
	 * @return string
	 */
	private function radios( $key, array $choices, $current ) {
		$html = '<fieldset class="g2rd-stack" aria-labelledby="' . esc_attr( 'g2rd-wpjm-geo-' . $key ) . '-title">';
		foreach ( $choices as $value => $label ) {
			$html .= '<label class="g2rd-row"><input type="radio" name="' . esc_attr( $this->name( $key ) ) . '" value="' . esc_attr( $value ) . '"' . checked( $current, $value, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		return $html . '</fieldset>';
	}
}
```

- [ ] **Step 7: Créer `assets/js/admin-settings.js`**

```js
/**
 * Page de réglages : interrupteurs, lignes propres à un fournisseur, barre d'enregistrement.
 */
( function () {
	'use strict';

	function init() {
		var form = document.querySelector( '.g2rd-wpjm-geo-settings' );
		if ( ! form ) {
			return;
		}

		// La classe is-checked du conteneur pilote l'apparence de l'interrupteur de WordPress.
		// Synchronisée dès le chargement : le navigateur peut restaurer l'état des cases (retour arrière) sans événement.
		Array.prototype.forEach.call( form.querySelectorAll( '.components-form-toggle__input' ), function ( input ) {
			var sync = function () {
				input.parentNode.classList.toggle( 'is-checked', input.checked );
			};
			sync();
			input.addEventListener( 'change', sync );
		} );

		// display et non hidden : la console donne display:flex aux lignes.
		function showProvider( value ) {
			Array.prototype.forEach.call( document.querySelectorAll( '[data-g2rd-provider]' ), function ( node ) {
				node.style.display = node.getAttribute( 'data-g2rd-provider' ) === value ? '' : 'none';
			} );
		}
		Array.prototype.forEach.call( form.querySelectorAll( 'input[type="radio"][name$="[provider]"]' ), function ( radio ) {
			if ( radio.checked ) {
				showProvider( radio.value );
			}
			radio.addEventListener( 'change', function () {
				if ( radio.checked ) {
					showProvider( radio.value );
				}
			} );
		} );

		// Barre d'enregistrement : visible sans JavaScript, sinon tant qu'un champ diffère de la valeur enregistrée.
		var bar = form.querySelector( '.g2rd-savebar' );
		if ( bar ) {
			var dirty = function () {
				return Array.prototype.some.call( form.querySelectorAll( 'input' ), function ( el ) {
					if ( 'checkbox' === el.type || 'radio' === el.type ) {
						return el.checked !== el.defaultChecked;
					}
					return 'hidden' !== el.type && el.value !== el.defaultValue;
				} );
			};
			var refresh = function () {
				bar.style.display = dirty() ? '' : 'none';
			};
			refresh();
			form.addEventListener( 'input', refresh );
			form.addEventListener( 'change', refresh );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
```

- [ ] **Step 8: Brancher dans le plugin**

Dans `Plugin.php`, remplacer le bloc :

```php
		if ( is_admin() ) {
			( new RegeocodeTool( $this->settings, $geocoder ) )->register();
```

par :

```php
		if ( is_admin() ) {
			$page = new SettingsPage( $this->settings );
			( new AdminMenu( array( $page, 'render' ) ) )->register();
			( new AdminScreen() )->register();
			( new RegeocodeTool( $this->settings, $geocoder ) )->register();
```

- [ ] **Step 9: Lint, tests, commit**

```bash
for f in g2rd-wpjm-geo/includes/*.php; do php -l "$f" | grep -v "^No syntax"; done
node --check g2rd-wpjm-geo/assets/js/admin-settings.js
php g2rd-wpjm-geo/tests/run.php | tail -1
git add g2rd-wpjm-geo/includes/Settings.php g2rd-wpjm-geo/includes/SettingsPage.php g2rd-wpjm-geo/includes/Logger.php g2rd-wpjm-geo/includes/Plugin.php g2rd-wpjm-geo/assets/js/admin-settings.js g2rd-wpjm-geo/tests/run.php g2rd-wpjm-geo/tests/bootstrap.php g2rd-wpjm-geo/tests/fixtures/fake-admin-console.php g2rd-wpjm-geo/tests/js/run.js
git commit -m "feat: page d'administration à la charte de la console G2RD"
```

Expected: lint muet, `0 échoués`.

---

### Task 10: Outil Go Fetch Jobs et journal à la charte

**Files:**
- Modify: `g2rd-wpjm-geo/includes/MigrationTool.php`, `g2rd-wpjm-geo/assets/js/admin-tools.js`
- Delete: `g2rd-wpjm-geo/assets/css/admin.css`

- [ ] **Step 1: Remplacer `MigrationTool::render()`**

Remplacer, dans `render()`, tout ce qui suit le tableau `$i18n` (de `?>` à `$this->render_log();` inclus) par :

```php
		?>
		<section class="g2rd-section" id="g2rd-geo-gofetch-outil">
			<div class="g2rd-section__head"><h3 class="g2rd-section__title"><?php esc_html_e( 'Offres Go Fetch Jobs existantes', 'g2rd-wpjm-geo' ); ?></h3></div>
			<p class="g2rd-section__desc"><?php esc_html_e( 'Aucune modification n’est lancée automatiquement. Analysez d’abord, simulez, puis corrigez. Le traitement est réalisé par lots pour éviter les dépassements de délai. Les réglages utilisés sont ceux actuellement enregistrés.', 'g2rd-wpjm-geo' ); ?></p>

			<?php if ( ! Dependencies::has_gofetch() ) : ?>
				<div class="g2rd-alert g2rd-alert--info"><p><?php esc_html_e( 'Go Fetch Jobs n’est pas actif. L’outil peut néanmoins traiter les offres importées précédemment (elles conservent leur marqueur d’import).', 'g2rd-wpjm-geo' ); ?></p></div>
			<?php endif; ?>
			<?php if ( Settings::MODE_KEEP === $mode ) : ?>
				<div class="g2rd-alert g2rd-alert--warning"><p><?php esc_html_e( 'Le remplacement est désactivé dans les réglages : l’analyse fonctionne, mais aucune offre ne sera signalée comme à corriger.', 'g2rd-wpjm-geo' ); ?></p></div>
			<?php elseif ( '' === $email ) : ?>
				<div class="g2rd-alert g2rd-alert--warning"><p><?php esc_html_e( 'Aucune adresse email générique valide n’est configurée : aucune correction ne sera effectuée.', 'g2rd-wpjm-geo' ); ?></p></div>
			<?php endif; ?>

			<div class="g2rd-wpjm-geo-tools"
				data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
				data-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>">
				<div class="g2rd-row g2rd-row--wrap">
					<button type="button" class="button" data-g2rd-run="analyze"><?php esc_html_e( 'Analyser les offres Go Fetch Jobs existantes', 'g2rd-wpjm-geo' ); ?></button>
					<label class="g2rd-row"><input type="checkbox" data-g2rd-dry-run checked /> <?php esc_html_e( 'Mode simulation (aucune donnée n’est modifiée)', 'g2rd-wpjm-geo' ); ?></label>
					<button type="button" class="button button-primary" data-g2rd-run="fix"><?php esc_html_e( 'Corriger les offres', 'g2rd-wpjm-geo' ); ?></button>
				</div>
				<p class="g2rd-wpjm-geo-tools__status g2rd-muted" role="status" aria-live="polite"></p>
				<div class="g2rd-wpjm-geo-tools__stats"></div>
				<div class="g2rd-wpjm-geo-tools__rows" style="overflow-x:auto"></div>
			</div>
		</section>
		<?php
		$this->render_log();
```

- [ ] **Step 2: Remplacer `MigrationTool::render_log()`**

Remplacer le corps de `render_log()` par :

```php
	private function render_log() {
		echo '<section class="g2rd-section" id="g2rd-geo-journal-entrees">';
		echo '<div class="g2rd-section__head"><h3 class="g2rd-section__title">' . esc_html__( 'Journal des remplacements', 'g2rd-wpjm-geo' ) . '</h3></div>';

		if ( ! $this->settings->get( 'log_enabled' ) ) {
			echo '<div class="g2rd-alert g2rd-alert--info"><p>' . esc_html__( 'Le journal est désactivé.', 'g2rd-wpjm-geo' ) . '</p></div>';
		}

		$rows = $this->logger->latest( 50 );
		if ( empty( $rows ) ) {
			echo '<div class="g2rd-empty"><p>' . esc_html__( 'Aucune entrée pour le moment.', 'g2rd-wpjm-geo' ) . '</p></div></section>';
			return;
		}

		echo '<p class="g2rd-section__desc">' . esc_html__( '50 dernières entrées.', 'g2rd-wpjm-geo' ) . '</p>';
		echo '<table class="widefat striped g2rd-table"><caption class="screen-reader-text">' . esc_html__( 'Journal des remplacements', 'g2rd-wpjm-geo' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Offre', 'g2rd-wpjm-geo' ), __( 'Date', 'g2rd-wpjm-geo' ), __( 'Ancienne valeur', 'g2rd-wpjm-geo' ), __( 'Nouvelle valeur', 'g2rd-wpjm-geo' ), __( 'Raison', 'g2rd-wpjm-geo' ), __( 'Source', 'g2rd-wpjm-geo' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$job_id = absint( $row['job_id'] );
			$link   = get_edit_post_link( $job_id );
			echo '<tr>';
			echo '<td>' . ( $link ? '<a href="' . esc_url( $link ) . '">#' . esc_html( (string) $job_id ) . '</a>' : '#' . esc_html( (string) $job_id ) ) . '</td>';
			echo '<td class="is-date">' . esc_html( get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td>';
			// overflow-wrap en ligne : une URL importée sans point de coupure ne doit pas élargir la carte (console.css est une copie intégrale du thème).
			echo '<td class="is-mono" style="overflow-wrap:anywhere">' . esc_html( $row['old_value'] ) . '</td>';
			echo '<td class="is-mono" style="overflow-wrap:anywhere">' . esc_html( $row['new_value'] ) . '</td>';
			echo '<td><span class="g2rd-tag g2rd-tag--info">' . esc_html( Logger::reason_label( $row['reason'] ) ) . '</span></td>';
			echo '<td>' . esc_html( $row['source'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></section>';
	}
```

- [ ] **Step 3: Résultats de l'outil en tableau (`admin-tools.js`)**

Remplacer les fonctions `renderStats()` et `appendRows()` par :

```js
		function renderStats( totals, task, dryRun ) {
			var keys = [ 'analyzed', 'email', 'url', 'empty', 'toFix' ];
			if ( 'fix' === task && ! dryRun ) {
				keys.push( 'fixed' );
			}
			var list = document.createElement( 'ul' );
			list.className = 'g2rd-stack';
			keys.forEach( function ( key ) {
				var item = document.createElement( 'li' );
				item.textContent = ( i18n[ key ] || key ) + ' : ' + totals[ key ];
				list.appendChild( item );
			} );
			statsEl.textContent = '';
			statsEl.appendChild( list );
		}

		function tableBody() {
			var table = rowsEl.querySelector( 'table' );
			if ( table ) {
				return table.tBodies[ 0 ];
			}
			table = document.createElement( 'table' );
			table.className = 'widefat striped g2rd-table';
			var caption = table.createCaption();
			caption.className   = 'screen-reader-text';
			caption.textContent = i18n.results || '';
			var head = table.createTHead().insertRow();
			[ i18n.job, i18n.current, i18n.next, i18n.reason ].forEach( function ( label ) {
				var th = document.createElement( 'th' );
				th.scope = 'col';
				th.textContent = ( label || '' ).replace( /\s*:\s*$/, '' );
				head.appendChild( th );
			} );
			table.createTBody();
			rowsEl.appendChild( table );
			return table.tBodies[ 0 ];
		}

		function appendRows( rows, shown ) {
			var body = null;
			rows.forEach( function ( row ) {
				if ( shown.count >= MAX_ROWS ) {
					shown.truncated = true;
					return;
				}
				shown.count++;
				body = body || tableBody();
				var tr = body.insertRow();
				tr.insertCell().textContent = '#' + row.id;
				[ row.old, row[ 'new' ] ].forEach( function ( value ) {
					var cell = tr.insertCell();
					cell.className = 'is-mono';
					cell.style.overflowWrap = 'anywhere';
					cell.textContent = value;
				} );
				tr.insertCell().textContent = row.reason;
			} );
		}
```

- [ ] **Step 4: Supprimer l'ancienne feuille**

```bash
git rm g2rd-wpjm-geo/assets/css/admin.css
grep -rn "admin.css" g2rd-wpjm-geo --include=*.php
```

Expected: `grep` ne renvoie rien.

- [ ] **Step 4 bis: Titre accessible du tableau et tests de rendu**

Dans `MigrationTool::render()`, dans le tableau `$i18n`, juste après la ligne `'reason'    => __( 'Raison :', 'g2rd-wpjm-geo' ),`, ajouter :

```php
			'results'   => __( 'Offres à corriger', 'g2rd-wpjm-geo' ),
```

Dans `tests/bootstrap.php` : ajouter `'MigrationTool'` à la fin de la liste des classes et ces fonctions simulées (sans dupliquer une fonction déjà présente) :

```php
	function wp_create_nonce( $action = -1 ) {
		return 'nonce';
	}
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
	function esc_html_e( $text, $domain = 'default' ) {
		echo esc_html( $text );
	}
	function get_date_from_gmt( $date, $format = 'Y-m-d H:i:s' ) {
		return $date;
	}
```

Dans `tests/run.php`, ajouter aux `use` : `use G2RD\WPJMGeo\MigrationTool;`, puis, juste avant `$results = $GLOBALS['g2rd_results'];` :

```php
echo PHP_EOL . '23. Outil Go Fetch Jobs et journal : rendu' . PHP_EOL;
list( $s, $normalizer ) = boot( array( 'log_enabled' => true ) );
$hostile_log = new class( $s ) extends Logger {
	public function latest( $limit = 50 ) {
		return array( array( 'id' => 1, 'job_id' => 7, 'created_at' => '2026-01-01 00:00:00', 'old_value' => 'javascript:alert(1)//"><svg onload=alert(1)>', 'new_value' => '<b>x</b>@ex.fr', 'reason' => '<i>r</i>', 'source' => '<a href="javascript:alert(2)">x</a>' ) );
	}
};
ob_start();
( new MigrationTool( $s, $normalizer, $hostile_log ) )->render();
$html = ob_get_clean();
check( 'journal : valeurs importées échappées', false === strpos( $html, '<svg' ) && false === strpos( $html, '<b>x' ) && false === strpos( $html, '<i>r' ) && false === strpos( $html, 'href="javascript' ) && false !== strpos( $html, 'javascript:alert(1)//&quot;&gt;&lt;svg' ) );
check( 'outil : points d’accroche du script présents', 1 === preg_match( '/class="g2rd-wpjm-geo-tools"[^>]*data-ajax-url="[^"]+"[^>]*data-nonce="[^"]+"[^>]*data-i18n="/s', $html ) && false !== strpos( $html, 'g2rd-wpjm-geo-tools__rows' ) && false !== strpos( $html, 'data-g2rd-run="fix"' ) && false !== strpos( $html, 'data-g2rd-dry-run' ) );
check( 'sections équilibrées', 2 === substr_count( $html, '<section' ) && 2 === substr_count( $html, '</section>' ) );
```

Dans `tests/js/run.js`, juste avant la ligne `console.log('\nResultat JS : ' …` :

```js
  console.log('\nG. Outil Go Fetch Jobs');
  {
    const tw = new JSDOM('<div class="g2rd-wpjm-geo-tools" data-ajax-url="/a" data-action="x" data-nonce="n" data-i18n=\'{"job":"Offre","current":"Valeur actuelle :","next":"Nouvelle valeur :","reason":"Raison :","done":"Terminé."}\'><button data-g2rd-run="fix"></button><input type="checkbox" data-g2rd-dry-run checked><p class="g2rd-wpjm-geo-tools__status"></p><div class="g2rd-wpjm-geo-tools__stats"></div><div class="g2rd-wpjm-geo-tools__rows"></div></div>', { runScripts: 'outside-only' }).window;
    const toolStats = { analyzed: 1, email: 0, url: 1, empty: 0, toFix: 1, fixed: 0 };
    const pages = [
      { success: true, data: { stats: toolStats, rows: [{ id: 9, old: 'javascript:alert(1)//"><img src=x onerror=alert(1)>', new: '<svg onload=alert(2)>', reason: '<script>alert(3)</script>' }], cursor: 9, done: false } },
      { success: true, data: { stats: toolStats, rows: [{ id: 10, old: 'u', new: 'e', reason: 'r' }], cursor: 10, done: false } },
      { success: true, data: { stats: toolStats, rows: [], cursor: 10, done: true } }
    ];
    let page = 0;
    tw.fetch = () => Promise.resolve({ ok: true, json: () => Promise.resolve(pages[page++]) });
    tw.eval(src('js/admin-tools.js'));
    tw.document.dispatchEvent(new tw.Event('DOMContentLoaded'));
    tw.document.querySelector('[data-g2rd-run="fix"]').click();
    await wait(20);
    const toolRows = tw.document.querySelector('.g2rd-wpjm-geo-tools__rows');
    check('outil : un seul tableau pour tous les lots', 1 === toolRows.querySelectorAll('table').length && 2 === toolRows.querySelectorAll('tbody tr').length);
    check('outil : en-tetes sans deux-points, scope="col"', 'Offre|Valeur actuelle|Nouvelle valeur|Raison' === [...toolRows.querySelectorAll('th')].map(t => t.textContent).join('|') && 4 === toolRows.querySelectorAll('th[scope="col"]').length);
    check('outil : valeurs importees inserees en texte', 0 === toolRows.querySelectorAll('img,svg,script').length && toolRows.querySelector('td.is-mono').textContent.startsWith('javascript:'));
  }
```

- [ ] **Step 5: Lint, tests, commit**

```bash
php -l g2rd-wpjm-geo/includes/MigrationTool.php && node --check g2rd-wpjm-geo/assets/js/admin-tools.js
php g2rd-wpjm-geo/tests/run.php | tail -1
git add g2rd-wpjm-geo/includes/MigrationTool.php g2rd-wpjm-geo/assets/js/admin-tools.js g2rd-wpjm-geo/tests/bootstrap.php g2rd-wpjm-geo/tests/run.php g2rd-wpjm-geo/tests/js/run.js
git commit -m "feat: outil Go Fetch Jobs et journal à la charte de la console"
```

---

### Task 11: Fonctions de carte communes (`map-common.js`)

**Files:**
- Create: `g2rd-wpjm-geo/assets/js/map-common.js`
- Modify: `g2rd-wpjm-geo/assets/js/jobs-map.js` (réécrit), `g2rd-wpjm-geo/includes/GoogleAssets.php`, `g2rd-wpjm-geo/tests/js/run.js`

- [ ] **Step 1: Adapter le harnais (test d'abord)**

Dans `tests/js/run.js`, fonction `map()`, remplacer :

```js
  w.eval(src('js/jobs-map.js'));
```

par :

```js
  w.eval(src('js/map-common.js'));
  w.eval(src('js/jobs-map.js'));
```

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -3`
Expected: `ENOENT` sur `map-common.js`.

- [ ] **Step 2: Créer `map-common.js`**

```js
/**
 * Fonctions partagées par les moteurs de carte (Google et MapLibre) :
 * lecture paginée de l'endpoint REST, regroupement des offres aux coordonnées
 * identiques, contenu des fenêtres (DOM + textContent, jamais de HTML) et
 * démarrage paresseux à l'approche de la zone visible.
 */
( function ( window, document ) {
	'use strict';

	var MAX_PAGES = 200;

	function parseJson( value ) {
		try {
			return JSON.parse( value || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function sprintf( template, number ) {
		return String( template || '%d' ).replace( '%d', String( number ) );
	}

	function safeUrl( url ) {
		return 'string' === typeof url && /^https?:\/\//i.test( url ) ? url : '';
	}

	function placeLabel( job ) {
		var parts = [];
		if ( job.city ) {
			parts.push( job.city );
		}
		if ( job.country && job.country !== job.city ) {
			parts.push( job.country );
		}
		return parts.length ? parts.join( ', ' ) : ( job.location || '' );
	}

	function addLine( parent, className, text ) {
		if ( ! text ) {
			return;
		}
		var node = document.createElement( 'div' );
		node.className = className;
		node.textContent = text;
		parent.appendChild( node );
	}

	function buildJobNode( job, i18n, compact ) {
		var wrapper = document.createElement( compact ? 'li' : 'div' );
		wrapper.className = 'g2rd-wpjm-geo-iw__job';

		var url = safeUrl( job.permalink );
		var title = document.createElement( compact && url ? 'a' : 'div' );
		title.className = 'g2rd-wpjm-geo-iw__title';
		title.textContent = job.title || '';
		if ( compact && url ) {
			title.href = url;
		}
		wrapper.appendChild( title );

		addLine( wrapper, 'g2rd-wpjm-geo-iw__company', job.company );
		if ( ! compact ) {
			addLine( wrapper, 'g2rd-wpjm-geo-iw__place', placeLabel( job ) );
		}
		addLine( wrapper, 'g2rd-wpjm-geo-iw__type', job.job_type );

		if ( ! compact && url ) {
			var link = document.createElement( 'a' );
			link.className = 'g2rd-wpjm-geo-iw__link';
			link.href = url;
			link.textContent = i18n.view || '';
			wrapper.appendChild( link );
		}
		return wrapper;
	}

	function buildInfoContent( jobs, i18n ) {
		var root = document.createElement( 'div' );
		root.className = 'g2rd-wpjm-geo-iw';

		if ( 1 === jobs.length ) {
			root.appendChild( buildJobNode( jobs[ 0 ], i18n, false ) );
			return root;
		}

		addLine( root, 'g2rd-wpjm-geo-iw__heading', sprintf( i18n.manyJobs, jobs.length ) );
		addLine( root, 'g2rd-wpjm-geo-iw__place', placeLabel( jobs[ 0 ] ) );

		var list = document.createElement( 'ul' );
		list.className = 'g2rd-wpjm-geo-iw__list';
		jobs.forEach( function ( job ) {
			list.appendChild( buildJobNode( job, i18n, true ) );
		} );
		root.appendChild( list );
		return root;
	}

	function groupByCoordinates( jobs ) {
		var groups = {};
		var order = [];
		jobs.forEach( function ( job ) {
			var lat = parseFloat( job.latitude );
			var lng = parseFloat( job.longitude );
			if ( ! isFinite( lat ) || ! isFinite( lng ) || Math.abs( lat ) > 90 || Math.abs( lng ) > 180 ) {
				return;
			}
			var key = lat.toFixed( 5 ) + ',' + lng.toFixed( 5 );
			if ( ! groups[ key ] ) {
				groups[ key ] = { position: { lat: lat, lng: lng }, jobs: [] };
				order.push( key );
			}
			groups[ key ].jobs.push( job );
		} );
		return order.map( function ( key ) {
			return groups[ key ];
		} );
	}

	function createFetcher( container ) {
		var restUrl = container.getAttribute( 'data-rest-url' ) || '';
		var perPage = parseInt( container.getAttribute( 'data-per-page' ), 10 ) || 250;
		var nonce = container.getAttribute( 'data-nonce' ) || '';

		function fetchPage( page, useNonce ) {
			var url = restUrl + ( restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'page=' + page + '&per_page=' + perPage;
			var options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
			if ( useNonce && nonce ) {
				options.headers[ 'X-WP-Nonce' ] = nonce;
			}
			return window.fetch( url, options ).then( function ( response ) {
				// Nonce expiré (page servie par un cache) : nouvelle tentative en tant que visiteur anonyme.
				if ( 403 === response.status && useNonce && nonce ) {
					nonce = '';
					return fetchPage( page, false );
				}
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				var totalPages = parseInt( response.headers.get( 'X-WP-TotalPages' ), 10 ) || 1;
				return response.json().then( function ( jobs ) {
					return { jobs: Array.isArray( jobs ) ? jobs : [], totalPages: totalPages };
				} );
			} );
		}

		function fetchAll() {
			var all = [];
			function next( page ) {
				return fetchPage( page, true ).then( function ( result ) {
					all = all.concat( result.jobs );
					if ( page < result.totalPages && page < MAX_PAGES ) {
						return next( page + 1 );
					}
					return all;
				} );
			}
			return next( 1 );
		}

		return { fetchAll: fetchAll };
	}

	function observe( initMap ) {
		function init() {
			var containers = document.querySelectorAll( '.g2rd-wpjm-geo-map:not([data-g2rd-init])' );
			if ( ! containers.length || ! window.Promise || ! window.fetch ) {
				return;
			}
			var start = function ( container ) {
				if ( container.getAttribute( 'data-g2rd-init' ) ) {
					return;
				}
				container.setAttribute( 'data-g2rd-init', '1' );
				initMap( container );
			};
			if ( 'function' === typeof window.IntersectionObserver ) {
				var observer = new IntersectionObserver( function ( entries ) {
					entries.forEach( function ( entry ) {
						if ( entry.isIntersecting ) {
							observer.unobserve( entry.target );
							start( entry.target );
						}
					} );
				}, { rootMargin: '300px' } );
				Array.prototype.forEach.call( containers, function ( container ) {
					observer.observe( container );
				} );
			} else {
				Array.prototype.forEach.call( containers, start );
			}
		}

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
	}

	window.g2rdWpjmGeoMapCommon = {
		parseJson: parseJson,
		sprintf: sprintf,
		safeUrl: safeUrl,
		placeLabel: placeLabel,
		buildInfoContent: buildInfoContent,
		groupByCoordinates: groupByCoordinates,
		createFetcher: createFetcher,
		observe: observe
	};
}( window, document ) );
```

- [ ] **Step 3: Réécrire `jobs-map.js`**

Remplacer tout le contenu par :

```js
/**
 * Carte mondiale des offres WP Job Manager — moteur Google Maps.
 *
 * - Chargée uniquement quand la carte approche de la zone visible (map-common.js).
 * - Un marqueur par coordonnées exactes (liste des offres dans la fenêtre), clustering au-dessus.
 * - Tout le contenu est inséré via textContent : aucune donnée n'est interprétée comme du HTML.
 */
( function ( window, document ) {
	'use strict';

	var C = window.g2rdWpjmGeoMapCommon;
	var loader = window.g2rdWpjmGeoLoader;
	var COLOR = '#c0392b';

	function circleSvg( count, size ) {
		var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">' +
			'<circle cx="' + ( size / 2 ) + '" cy="' + ( size / 2 ) + '" r="' + ( size / 2 - 2 ) + '" fill="' + COLOR + '" fill-opacity="0.9" stroke="#fff" stroke-width="2"/>' +
			'</svg>';
		return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent( svg );
	}

	function clusterSize( count ) {
		return count < 10 ? 36 : ( count < 100 ? 44 : 52 );
	}

	function initMap( container ) {
		var i18n = C.parseJson( container.getAttribute( 'data-i18n' ) );
		var canvas = container.querySelector( '.g2rd-wpjm-geo-map__canvas' );
		var status = container.querySelector( '.g2rd-wpjm-geo-map__status' );
		var mapId = container.getAttribute( 'data-map-id' ) || '';
		var clustering = '1' === container.getAttribute( 'data-clustering' ) && !! window.markerClusterer;

		function setStatus( message ) {
			if ( status ) {
				status.textContent = message || '';
				status.hidden = ! message;
			}
		}

		function fail() {
			container.classList.add( 'has-error' );
			setStatus( i18n.error );
		}

		if ( ! canvas || ! container.getAttribute( 'data-rest-url' ) || ! loader ) {
			fail();
			return;
		}

		document.addEventListener( 'g2rdWpjmGeoAuthFailure', fail );

		Promise.all( [
			loader.load().then( function ( maps ) {
				return Promise.all( [ maps.importLibrary( 'maps' ), maps.importLibrary( 'core' ), maps.importLibrary( 'marker' ) ] );
			} ),
			C.createFetcher( container ).fetchAll()
		] ).then( function ( results ) {
			var mapsLib = results[ 0 ][ 0 ];
			var coreLib = results[ 0 ][ 1 ];
			var markerLib = results[ 0 ][ 2 ];
			// Les marqueurs avancés (recommandés par Google) exigent un Map ID ; sinon, marqueurs classiques.
			var useAdvanced = !! ( mapId && markerLib.AdvancedMarkerElement );
			var groups = C.groupByCoordinates( results[ 1 ] );

			var mapOptions = {
				center: { lat: 20, lng: 0 },
				zoom: 2,
				minZoom: 2,
				maxZoom: 18,
				streetViewControl: false,
				mapTypeControl: false,
				fullscreenControl: true,
				gestureHandling: 'cooperative' // Sur mobile : défilement de la page à un doigt, carte à deux doigts.
			};
			if ( mapId ) {
				mapOptions.mapId = mapId;
			}

			var map = new mapsLib.Map( canvas, mapOptions );
			var infoWindow = new mapsLib.InfoWindow( { maxWidth: 320 } );
			var bounds = new coreLib.LatLngBounds();

			function createMarker( position, count, title, zIndex ) {
				var marker;
				if ( useAdvanced ) {
					var options = { position: position, title: title, zIndex: zIndex, gmpClickable: true };
					if ( count > 1 ) {
						var bubble = document.createElement( 'div' );
						bubble.className = 'g2rd-wpjm-geo-marker';
						bubble.textContent = String( count );
						options.content = bubble;
					}
					marker = new markerLib.AdvancedMarkerElement( options );
				} else {
					var legacy = { position: position, title: title, zIndex: zIndex };
					if ( count > 1 ) {
						var size = clusterSize( count ) - 8;
						legacy.icon = {
							url: circleSvg( count, size ),
							scaledSize: new coreLib.Size( size, size ),
							anchor: new coreLib.Point( size / 2, size / 2 )
						};
						legacy.label = { text: String( count ), color: '#fff', fontSize: '12px', fontWeight: '700' };
					}
					marker = new markerLib.Marker( legacy );
				}
				return marker;
			}

			var markers = groups.map( function ( group ) {
				var count = group.jobs.length;
				var title = 1 === count ? ( group.jobs[ 0 ].title || '' ) : C.sprintf( i18n.manyJobs, count );
				var marker = createMarker( group.position, count, title, count );
				marker.g2rdCount = count;
				marker.addListener( 'click', function () {
					infoWindow.setContent( C.buildInfoContent( group.jobs, i18n ) );
					infoWindow.open( { map: map, anchor: marker } );
				} );
				bounds.extend( group.position );
				return marker;
			} );

			if ( clustering && markers.length > 1 ) {
				new window.markerClusterer.MarkerClusterer( {
					map: map,
					markers: markers,
					renderer: {
						render: function ( cluster ) {
							// Le nombre affiché est celui des offres, pas celui des lieux.
							var total = cluster.markers.reduce( function ( sum, item ) {
								return sum + ( item.g2rdCount || 1 );
							}, 0 );
							return createMarker( cluster.position, Math.max( total, 2 ), C.sprintf( i18n.cluster, total ), 1000 + total );
						}
					}
				} );
			} else {
				markers.forEach( function ( marker ) {
					if ( useAdvanced ) {
						marker.map = map;
					} else {
						marker.setMap( map );
					}
				} );
			}

			if ( ! markers.length ) {
				setStatus( i18n.empty );
				container.classList.add( 'is-empty' );
			} else {
				setStatus( '' );
				if ( 1 === markers.length ) {
					map.setCenter( groups[ 0 ].position );
					map.setZoom( 10 );
				} else {
					map.fitBounds( bounds, 48 );
				}
			}
			container.classList.add( 'is-ready' );
		} ).catch( fail );
	}

	if ( C ) {
		C.observe( initMap );
	}
}( window, document ) );
```

- [ ] **Step 4: Enregistrer `map-common.js`**

Dans `GoogleAssets.php`, après `const HANDLE_STYLE        = 'g2rd-wpjm-geo-frontend';`, ajouter :

```php
	const HANDLE_COMMON       = 'g2rd-wpjm-geo-map-common';
```

Dans `register_assets()`, remplacer :

```php
		$map_deps = array( self::HANDLE_LOADER );
```

par :

```php
		wp_register_script( self::HANDLE_COMMON, $base . 'js/map-common.js', array(), $version, true );

		$map_deps = array( self::HANDLE_LOADER, self::HANDLE_COMMON );
```

- [ ] **Step 5: Vérifier la non-régression**

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | grep -E "FAIL|Resultat"`
Expected: `Resultat JS : 34 reussis, 0 echoues`.

- [ ] **Step 6: Commit**

```bash
php -l g2rd-wpjm-geo/includes/GoogleAssets.php
git add g2rd-wpjm-geo/assets/js/map-common.js g2rd-wpjm-geo/assets/js/jobs-map.js g2rd-wpjm-geo/includes/GoogleAssets.php g2rd-wpjm-geo/tests/js/run.js
git commit -m "refactor: fonctions de carte partagées (map-common.js)"
```

---

### Task 12: Carte MapLibre

**Files:**
- Create: `g2rd-wpjm-geo/assets/vendor/maplibre-gl/{maplibre-gl.js,maplibre-gl.css,LICENSE.txt,index.php}`, `g2rd-wpjm-geo/assets/js/jobs-map-osm.js`, `g2rd-wpjm-geo/includes/OsmAssets.php`
- Modify: `g2rd-wpjm-geo/includes/JobsMap.php`, `g2rd-wpjm-geo/includes/Plugin.php`, `g2rd-wpjm-geo/assets/css/frontend.css`, `g2rd-wpjm-geo/assets/js/map-common.js`, `g2rd-wpjm-geo/assets/js/jobs-map.js`, `g2rd-wpjm-geo/tests/js/run.js`

- [ ] **Step 1: Embarquer MapLibre 5.24.0**

```bash
V=g2rd-wpjm-geo/assets/vendor/maplibre-gl && mkdir -p $V
for f in dist/maplibre-gl.js dist/maplibre-gl.css LICENSE.txt; do curl -sSL -o "$V/$(basename $f)" "https://cdn.jsdelivr.net/npm/maplibre-gl@5.24.0/$f"; done
cp g2rd-wpjm-geo/assets/index.php $V/index.php
head -c 120 $V/maplibre-gl.js; echo; ls -la $V
grep -c "getClusterExpansionZoom" $V/maplibre-gl.js
```

Expected: en-tête `MapLibre GL JS … @license 3-Clause BSD … v5.24.0`, `maplibre-gl.js` ≈ 1 056 837 octets, `maplibre-gl.css` ≈ 70 Ko, `LICENSE.txt` ≈ 6 Ko, compteur > 0.

- [ ] **Step 1 bis: État partagé dans `map-common.js` et tests des fonctions communes**

Dans `assets/js/map-common.js`, juste avant `window.g2rdWpjmGeoMapCommon = {`, ajouter :

```js
	/**
	 * Messages de la carte (chargement, vide, erreur), communs aux deux moteurs.
	 * L'état vide propose le lien « Re-géocoder » quand la page le fournit (administrateurs).
	 */
	function statusController( container, i18n ) {
		var status = container.querySelector( '.g2rd-wpjm-geo-map__status' );
		function set( message, linkUrl ) {
			if ( ! status ) {
				return;
			}
			status.textContent = message || '';
			var url = message ? safeUrl( linkUrl ) : '';
			if ( url && i18n.regeocode ) {
				var link = document.createElement( 'a' );
				link.href = url;
				link.textContent = i18n.regeocode;
				status.appendChild( document.createTextNode( ' ' ) );
				status.appendChild( link );
			}
			status.hidden = ! message;
		}
		return {
			set: set,
			fail: function () {
				container.classList.add( 'has-error' );
				set( i18n.error );
			},
			empty: function () {
				container.classList.add( 'is-empty' );
				set( i18n.empty, container.getAttribute( 'data-admin-url' ) );
			}
		};
	}

	/**
	 * Liste repliable des offres de la carte, juste après elle : accès au clavier et aux
	 * lecteurs d'écran quand les points sont dessinés dans un canevas (MapLibre).
	 */
	function placesList( container, groups, i18n ) {
		var previous = container.nextElementSibling;
		if ( previous && previous.classList.contains( 'g2rd-wpjm-geo-map__places' ) ) {
			previous.parentNode.removeChild( previous );
		}
		var details = document.createElement( 'details' );
		details.className = 'g2rd-wpjm-geo-map__places';
		var summary = document.createElement( 'summary' );
		summary.textContent = i18n.places || '';
		details.appendChild( summary );
		groups.forEach( function ( group ) {
			details.appendChild( buildInfoContent( group.jobs, i18n ) );
		} );
		container.parentNode.insertBefore( details, container.nextSibling );
		return details;
	}

```

et, dans l'objet exporté, remplacer `observe: observe` par :

```js
		observe: observe,
		status: statusController,
		places: placesList
```

Dans `assets/js/jobs-map.js` (moteur Google), même état partagé : remplacer

```js
		var status = container.querySelector( '.g2rd-wpjm-geo-map__status' );
		var mapId = container.getAttribute( 'data-map-id' ) || '';
		var clustering = '1' === container.getAttribute( 'data-clustering' ) && !! window.markerClusterer;

		function setStatus( message ) {
			if ( status ) {
				status.textContent = message || '';
				status.hidden = ! message;
			}
		}

		function fail() {
			container.classList.add( 'has-error' );
			setStatus( i18n.error );
		}
```

par :

```js
		var mapId = container.getAttribute( 'data-map-id' ) || '';
		var clustering = '1' === container.getAttribute( 'data-clustering' ) && !! window.markerClusterer;
		var ui = C.status( container, i18n );
		var fail = ui.fail;
```

puis remplacer

```js
			if ( ! markers.length ) {
				setStatus( i18n.empty );
				container.classList.add( 'is-empty' );
			} else {
				setStatus( '' );
```

par :

```js
			if ( ! markers.length ) {
				ui.empty();
			} else {
				ui.set( '' );
```

Dans `tests/js/run.js`, juste avant la ligne `console.log('\nResultat JS : ' …`, ajouter la section H :

```js
  console.log('\nH. Fonctions communes (map-common.js)');
  {
    const cw = new JSDOM('<div class="g2rd-wpjm-geo-map" id="m1"></div><div class="g2rd-wpjm-geo-map" id="m2"></div>', { runScripts: 'outside-only', url: 'https://x.test/' }).window;
    let io = null;
    cw.IntersectionObserver = function (cb, opt) { io = { cb, opt, targets: [] }; this.observe = t => io.targets.push(t); this.unobserve = () => {}; };
    cw.fetch = () => Promise.reject(new Error('unused'));
    cw.eval(src('js/map-common.js'));
    const C = cw.g2rdWpjmGeoMapCommon;
    check('API exposee : un seul objet global, 10 fonctions', 'parseJson,sprintf,safeUrl,placeLabel,buildInfoContent,groupByCoordinates,createFetcher,observe,status,places' === Object.keys(C).join(','));
    check('safeUrl : http(s) absolu seulement (relatif, //hote, javascript:, espace initial, non-chaine refuses)',
      C.safeUrl('HTTPS://a.test/x') === 'HTTPS://a.test/x' && ['/job/1', '//evil.test', 'javascript:alert(1)', ' javascript:alert(1)', 'https:evil', 'data:text/html,x', null, 42].every(u => C.safeUrl(u) === ''));
    const g = C.groupByCoordinates([{ latitude: '48.856614', longitude: '2.352222' }, { latitude: 48.8566141, longitude: 2.3522224 }, { latitude: 48.85662, longitude: 2.352222 }, { latitude: 90, longitude: 180 }, { latitude: 90.0001, longitude: 0 }, { latitude: '', longitude: '' }]);
    check('regroupement a 5 decimales, ordre conserve, bornes 90/180 incluses, hors bornes et vides ignores', 3 === g.length && 2 === g[0].jobs.length && 1 === g[1].jobs.length && 90 === g[2].position.lat);
    const urls = [];
    cw.fetch = url => { urls.push(url); return Promise.resolve({ ok: true, status: 200, headers: { get: () => '500' }, json: () => Promise.resolve([{}]) }); };
    const box = cw.document.createElement('div');
    box.setAttribute('data-rest-url', 'https://x.test/?rest_route=/g2rd-wpjm-geo/v1/jobs');
    const all = await C.createFetcher(box).fetchAll();
    check('pagination plafonnee a 200 pages, "&" si l URL contient deja "?", per_page par defaut 250', 200 === urls.length && 200 === all.length && /\?rest_route=[^?]+&page=1&per_page=250$/.test(urls[0]));
    urls.length = 0;
    cw.fetch = url => { urls.push(url); return Promise.resolve({ ok: true, status: 200, headers: { get: () => null }, json: () => Promise.resolve({ code: 'x' }) }); };
    check('en-tete X-WP-TotalPages absent et reponse non tableau : une page, aucune offre', 0 === (await C.createFetcher(box).fetchAll()).length && 1 === urls.length);
    const started = [];
    C.observe(c => started.push(c.id));
    await wait(20);
    check('demarrage paresseux : 2 cartes observees (marge 300px), aucune demarree avant d etre visible', !!io && 2 === io.targets.length && '300px' === io.opt.rootMargin && 0 === started.length);
    io.cb([{ isIntersecting: true, target: io.targets[1] }, { isIntersecting: false, target: io.targets[0] }]);
    io.cb([{ isIntersecting: true, target: io.targets[1] }]);
    check('seule la carte visible demarre, une seule fois', 'm2' === started.join(','));
    const sbox = cw.document.createElement('div');
    sbox.innerHTML = '<p class="g2rd-wpjm-geo-map__status"></p>';
    sbox.setAttribute('data-admin-url', 'https://x.test/wp-admin/admin.php?page=g2rd-wpjm-geo#g2rd-geo-outils');
    C.status(sbox, { empty: 'VIDE', error: 'ERREUR', regeocode: 'Re-geocoder' }).empty();
    const sp = sbox.querySelector('p');
    check('etat vide : message et lien Re-geocoder (http(s) seulement)', sbox.classList.contains('is-empty') && 'VIDE Re-geocoder' === sp.textContent && 1 === sp.querySelectorAll('a').length && !sp.hidden);
    sbox.setAttribute('data-admin-url', 'javascript:alert(1)');
    C.status(sbox, { empty: 'VIDE', regeocode: 'Re-geocoder' }).empty();
    check('etat vide : lien javascript: refuse', 0 === sp.querySelectorAll('a').length);
  }
```

Le compteur `Resultat JS` des étapes suivantes en tient compte (+9).

- [ ] **Step 2: Écrire les tests (section I)**

Dans `tests/js/run.js`, juste avant `(async () => {`, ajouter :

```js
async function osmMap({ data, clustering = true, noWebgl = false, styleFail = false, restFail = false, preload = true, admin = '' }) {
  const i18n = JSON.stringify({ loading: 'Chargement', error: 'ERREUR', empty: 'VIDE', view: 'Voir offre', manyJobs: '%d offres ici', regeocode: 'Re-geocoder', places: 'Liste' }).replace(/"/g, '&quot;');
  const html = '<div class="g2rd-wpjm-geo-map" data-rest-url="https://x.test/wp-json/g2rd-wpjm-geo/v1/jobs" data-per-page="4" data-clustering="' + (clustering ? 1 : 0) + '" data-admin-url="' + admin + '" data-nonce="" data-i18n="' + i18n + '"><div class="g2rd-wpjm-geo-map__canvas"></div><p class="g2rd-wpjm-geo-map__status">Chargement</p></div>';
  const dom = new JSDOM(html, { runScripts: 'outside-only', url: 'https://x.test/' });
  const w = dom.window;
  const st = { map: null, mapOpts: null, sources: {}, layers: [], layout: [], popups: [], fit: null, jump: null, ease: null, controls: [], noRotate: false };
  class FakeMap {
    constructor(o) {
      st.mapOpts = o; st.map = this; this.events = {};
      this.dragRotate = { disable: () => { st.noRotate = true; } };
      this.touchZoomRotate = { disableRotation: () => {} };
      this.touchPitch = { disable: () => {} };
    }
    addControl(c) { st.controls.push(c.kind); return this; }
    on(ev, a, b) { const k = b ? ev + ':' + a : ev; (this.events[k] = this.events[k] || []).push(b || a); }
    fire(k, arg) { (this.events[k] || []).forEach(f => f(arg)); }
    getStyle() { return { layers: [
      { id: 'place-label', type: 'symbol', layout: { 'text-field': ['get', 'name'], 'text-font': ['Noto Sans Regular'] } },
      { id: 'road-ref', type: 'symbol', layout: { 'text-field': ['get', 'ref'], 'text-font': ['Noto Sans Bold'] } },
      { id: 'water', type: 'fill' }
    ] }; }
    setLayoutProperty(id, prop, value) { st.layout.push({ id, prop, value }); }
    addSource(id, s) { st.sources[id] = s; }
    addLayer(l) { st.layers.push(l); }
    getSource() { return { getClusterExpansionZoom: () => Promise.resolve(7) }; }
    easeTo(o) { st.ease = o; }
    fitBounds(b, o) { st.fit = { b, o }; }
    jumpTo(o) { st.jump = o; }
    getCanvas() { return { style: {} }; }
  }
  class Popup { constructor(o) { this.o = o; st.popups.push(this); } setLngLat(c) { this.c = c; return this; } setDOMContent(n) { this.node = n; return this; } addTo() { return this; } }
  class LngLatBounds { constructor() { this.pts = []; } extend(p) { this.pts.push(p); return this; } }
  class NavigationControl { constructor(o) { this.kind = 'nav'; this.o = o; } }
  class FullscreenControl { constructor() { this.kind = 'full'; } }
  const fake = { Map: FakeMap, Popup, LngLatBounds, NavigationControl, FullscreenControl };
  if (preload) w.maplibregl = fake;
  w.WebGL2RenderingContext = noWebgl ? undefined : function () {};
  w.HTMLCanvasElement.prototype.getContext = function () { return noWebgl ? null : {}; };
  w.g2rdWpjmGeoOsm = { styleUrl: 'https://tiles.openfreemap.org/styles/liberty', maplibreJs: 'https://x.test/vendor/maplibre-gl.js', maplibreCss: 'https://x.test/vendor/maplibre-gl.css', language: 'fr', locale: { 'Popup.Close': 'Fermer', 'Map.Title': '' } };
  w.IntersectionObserver = undefined;
  w.fetch = (url) => {
    if (restFail) return Promise.resolve({ ok: false, status: 500, headers: { get: () => null } });
    const page = +new URL(url).searchParams.get('page'), per = 4;
    return Promise.resolve({ ok: true, status: 200, headers: { get: h => h === 'X-WP-TotalPages' ? String(Math.ceil(data.length / per) || 1) : null }, json: () => Promise.resolve(data.slice((page - 1) * per, page * per)) });
  };
  w.eval(src('js/map-common.js'));
  w.eval(src('js/jobs-map-osm.js'));
  await wait(50);
  if (!preload) {
    const script = w.document.querySelector('script[src="https://x.test/vendor/maplibre-gl.js"]');
    st.injected = { script: !!script, css: !!w.document.querySelector('link[href="https://x.test/vendor/maplibre-gl.css"]') };
    if (script) { w.maplibregl = fake; script.onload(); await wait(50); }
  }
  if (st.map) { st.map.fire(styleFail ? 'error' : 'load', {}); }
  await wait(20);
  return { w, st, el: w.document.querySelector('.g2rd-wpjm-geo-map'), status: w.document.querySelector('.g2rd-wpjm-geo-map__status') };
}
```

Puis, avant la ligne du résultat final, ajouter :

```js
  console.log('\nI. Carte en mode libre (MapLibre)');
  let o = await osmMap({ data: jobs });
  check('MapLibre initialise : style OpenFreeMap, gestes cooperatifs', !!o.st.map && o.st.mapOpts.style === 'https://tiles.openfreemap.org/styles/liberty' && o.st.mapOpts.cooperativeGestures === true);
  const srcJobs = o.st.sources['g2rd-jobs'];
  check('4 points pour 6 offres valides, clustering actif, clusters comptant les offres', !!srcJobs && srcJobs.data.features.length === 4 && srcJobs.cluster === true && JSON.stringify(srcJobs.clusterProperties.jobs) === JSON.stringify(['+', ['get', 'count']]));
  check('le point de Paris porte 3 offres', srcJobs.data.features[1].properties.count === 3);
  check('libelles traduits sur les seules couches de noms', o.st.layout.length === 1 && o.st.layout[0].id === 'place-label' && JSON.stringify(o.st.layout[0].value) === JSON.stringify(['coalesce', ['get', 'name:fr'], ['get', 'name']]));
  const countLayer = o.st.layers.find(l => l.id === 'g2rd-cluster-count');
  check('police des compteurs reprise du style', !!countLayer && JSON.stringify(countLayer.layout['text-font']) === JSON.stringify(['Noto Sans Regular']));
  o.st.map.fire('click:g2rd-points', { features: [{ properties: { gid: 1, count: 3 }, geometry: { coordinates: [2.352222, 48.856614] } }] });
  const pop = o.st.popups[0];
  check('clic sur Paris : fenetre avec les 3 offres, sans HTML injecte', !!pop && pop.node.querySelectorAll('li').length === 3 && pop.node.querySelectorAll('img').length === 0);
  o.st.map.fire('click:g2rd-clusters', { features: [{ properties: { cluster_id: 9 }, geometry: { coordinates: [2, 48] } }] });
  await wait(10);
  check('clic sur un cluster : zoom d expansion', !!o.st.ease && o.st.ease.zoom === 7);
  check('cadrage automatique sur les 4 lieux', !!o.st.fit && o.st.fit.b.pts.length === 4 && o.st.fit.o.padding === 48);
  check('carte prete, message masque', o.status.hidden && o.el.classList.contains('is-ready'));
  o = await osmMap({ data: jobs, clustering: false });
  check('clustering desactive', o.st.sources['g2rd-jobs'].cluster === false);
  o = await osmMap({ data: [jobs[0]] });
  check('une offre : centrage zoom 10', !!o.st.jump && o.st.jump.zoom === 10 && !o.st.fit);
  o = await osmMap({ data: [], admin: 'https://x.test/wp-admin/admin.php?page=g2rd-wpjm-geo#g2rd-geo-outils' });
  const adminLink = o.status.querySelector('a');
  check('aucune offre : message vide et lien de re-geocodage pour l administrateur', /VIDE/.test(o.status.textContent) && !!adminLink && adminLink.href.indexOf('#g2rd-geo-outils') !== -1);
  o = await osmMap({ data: [] });
  check('aucune offre, visiteur : pas de lien', !o.status.querySelector('a'));
  o = await osmMap({ data: jobs, noWebgl: true });
  check('sans WebGL 2 : message d erreur, MapLibre non initialise', !o.st.map && o.status.textContent === 'ERREUR');
  o = await osmMap({ data: jobs, styleFail: true });
  check('style injoignable : message d erreur', o.status.textContent === 'ERREUR' && o.el.classList.contains('has-error'));
  o = await osmMap({ data: jobs, restFail: true });
  check('endpoint en erreur : message d erreur', o.status.textContent === 'ERREUR');
  o = await osmMap({ data: jobs, preload: false });
  check('MapLibre charge a la demande depuis le plugin (script et feuille)', o.st.injected.script && o.st.injected.css && !!o.st.map);
  o = await osmMap({ data: jobs });
  check('interface MapLibre traduite, valeurs vides retirees', 'Fermer' === o.st.mapOpts.locale['Popup.Close'] && !('Map.Title' in o.st.mapOpts.locale));
  check('attribution conservee (obligation OpenStreetMap)', false !== o.st.mapOpts.attributionControl);
  check('boutons de zoom et plein ecran, sans rotation', 'nav,full' === o.st.controls.join() && true === o.st.noRotate);
  const places = o.w.document.querySelector('.g2rd-wpjm-geo-map + .g2rd-wpjm-geo-map__places');
  check('liste des offres sous la carte, pour le clavier', !!places && 'Liste' === places.querySelector('summary').textContent && places.children.length > 1);
  o = await osmMap({ data: jobs, styleFail: true });
  o.st.map.fire('load', {});
  await wait(10);
  check('erreur passagere avant le chargement : etat d erreur retire', !o.el.classList.contains('has-error') && o.el.classList.contains('is-ready'));
  o = await osmMap({ data: [] });
  check('aucune offre : pas de liste vide', !o.w.document.querySelector('.g2rd-wpjm-geo-map__places'));
```

- [ ] **Step 3: Vérifier l'échec**

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -3`
Expected: `ENOENT` sur `jobs-map-osm.js`.

- [ ] **Step 4: Créer `jobs-map-osm.js`**

```js
/**
 * Carte des offres en mode libre : MapLibre GL JS + fond OpenFreeMap.
 *
 * - MapLibre (embarqué dans le plugin) n'est chargé qu'à l'approche de la zone visible.
 * - Offres aux coordonnées identiques réunies en un point ; clustering natif dont
 *   les cercles comptent les offres (clusterProperties) et non les lieux.
 * - Fenêtres construites en DOM (textContent) par map-common.js.
 */
( function ( window, document ) {
	'use strict';

	var C = window.g2rdWpjmGeoMapCommon;
	var cfg = window.g2rdWpjmGeoOsm || {};
	var COLOR = '#c0392b';
	var SOURCE = 'g2rd-jobs';
	var maplibrePromise = null;

	function loadMaplibre() {
		if ( window.maplibregl ) {
			return Promise.resolve( window.maplibregl );
		}
		if ( maplibrePromise ) {
			return maplibrePromise;
		}
		maplibrePromise = new Promise( function ( resolve, reject ) {
			if ( cfg.maplibreCss && ! document.querySelector( 'link[data-g2rd-maplibre]' ) ) {
				var link = document.createElement( 'link' );
				link.rel = 'stylesheet';
				link.href = cfg.maplibreCss;
				link.setAttribute( 'data-g2rd-maplibre', '1' );
				document.head.appendChild( link );
			}
			var script = document.createElement( 'script' );
			script.src = cfg.maplibreJs;
			script.async = true;
			script.onload = function () {
				if ( window.maplibregl ) {
					resolve( window.maplibregl );
				} else {
					reject( new Error( 'maplibre-missing' ) );
				}
			};
			script.onerror = function () {
				reject( new Error( 'maplibre-network' ) );
			};
			document.head.appendChild( script );
		} );
		// Un échec n'est pas mémorisé : une nouvelle tentative reste possible.
		maplibrePromise.catch( function () {
			maplibrePromise = null;
		} );
		return maplibrePromise;
	}

	// MapLibre 5 utilise WebGL 2 et se replie sur WebGL 1. Le contexte de test est libéré aussitôt.
	function supportsWebGL() {
		try {
			var canvas = document.createElement( 'canvas' );
			var gl = canvas.getContext( 'webgl2' ) || canvas.getContext( 'webgl' );
			var lose = ( gl && 'function' === typeof gl.getExtension ) ? gl.getExtension( 'WEBGL_lose_context' ) : null;
			if ( lose ) {
				lose.loseContext();
			}
			return !! gl;
		} catch ( e ) {
			return false;
		}
	}

	// Textes de l'interface MapLibre (gestes, boutons) : une valeur absente ou vide ferait lever « Missing UI string ».
	function uiLocale() {
		var given = cfg.locale || {};
		var locale = {};
		Object.keys( given ).forEach( function ( key ) {
			if ( 'string' === typeof given[ key ] && given[ key ] ) {
				locale[ key ] = given[ key ];
			}
		} );
		return locale;
	}

	function symbolLayers( map ) {
		var style = map.getStyle();
		return ( ( style && style.layers ) || [] ).filter( function ( layer ) {
			return 'symbol' === layer.type && layer.layout;
		} );
	}

	function textFont( map ) {
		var layers = symbolLayers( map );
		for ( var i = 0; i < layers.length; i++ ) {
			var font = layers[ i ].layout[ 'text-font' ];
			if ( Array.isArray( font ) && font.length && 'string' === typeof font[ 0 ] ) {
				return font;
			}
		}
		return [ 'Noto Sans Regular' ];
	}

	function localizeLabels( map, lang ) {
		if ( ! /^[a-z]{2}$/.test( lang || '' ) ) {
			return;
		}
		symbolLayers( map ).forEach( function ( layer ) {
			var field = layer.layout[ 'text-field' ];
			// Seules les couches de noms : les numéros de route ou de maison gardent leur champ.
			if ( ! field || JSON.stringify( field ).indexOf( 'name' ) === -1 ) {
				return;
			}
			try {
				map.setLayoutProperty( layer.id, 'text-field', [ 'coalesce', [ 'get', 'name:' + lang ], [ 'get', 'name' ] ] );
			} catch ( e ) {
				// Couche au format inattendu : libellé d'origine conservé.
			}
		} );
	}

	function initMap( container ) {
		var i18n = C.parseJson( container.getAttribute( 'data-i18n' ) );
		var canvas = container.querySelector( '.g2rd-wpjm-geo-map__canvas' );
		var clustering = '1' === container.getAttribute( 'data-clustering' );
		var ui = C.status( container, i18n );
		var fail = ui.fail;

		if ( ! canvas || ! cfg.styleUrl || ! cfg.maplibreJs || ! container.getAttribute( 'data-rest-url' ) || ! supportsWebGL() ) {
			fail();
			return;
		}

		Promise.all( [ loadMaplibre(), C.createFetcher( container ).fetchAll() ] ).then( function ( results ) {
			var ml = results[ 0 ];
			var groups = C.groupByCoordinates( results[ 1 ] );
			var jobsById = {};
			var features = groups.map( function ( group, index ) {
				jobsById[ index ] = group.jobs;
				return {
					type: 'Feature',
					properties: { gid: index, count: group.jobs.length },
					geometry: { type: 'Point', coordinates: [ group.position.lng, group.position.lat ] }
				};
			} );

			var loaded = false;
			var map = new ml.Map( {
				container: canvas,
				style: cfg.styleUrl,
				center: [ 0, 20 ],
				zoom: 1,
				cooperativeGestures: true,
				attributionControl: { compact: window.innerWidth < 782 },
				locale: uiLocale()
			} );
			// Boutons de zoom et plein écran (comme la carte Google) ; ni rotation ni inclinaison pour une carte d'offres.
			map.addControl( new ml.NavigationControl( { showCompass: false } ), 'top-right' );
			map.addControl( new ml.FullscreenControl(), 'top-right' );
			map.dragRotate.disable();
			map.touchZoomRotate.disableRotation();
			map.touchPitch.disable();

			map.on( 'error', function () {
				// Avant le premier rendu (style ou tuiles injoignables) : message d'erreur. Après, une tuile manquante n'est pas bloquante.
				if ( ! loaded ) {
					fail();
				}
			} );

			map.on( 'load', function () {
				loaded = true;
				container.classList.remove( 'has-error' ); // Une erreur passagère avant le chargement ne doit pas rester affichée.
				localizeLabels( map, cfg.language );
				var font = textFont( map );

				map.addSource( SOURCE, {
					type: 'geojson',
					data: { type: 'FeatureCollection', features: features },
					cluster: clustering,
					clusterMaxZoom: 14,
					clusterRadius: 50,
					clusterProperties: { jobs: [ '+', [ 'get', 'count' ] ] }
				} );
				map.addLayer( {
					id: 'g2rd-clusters',
					type: 'circle',
					source: SOURCE,
					filter: [ 'has', 'point_count' ],
					paint: {
						'circle-color': COLOR,
						'circle-opacity': 0.9,
						'circle-stroke-color': '#fff',
						'circle-stroke-width': 2,
						'circle-radius': [ 'step', [ 'get', 'jobs' ], 18, 10, 22, 100, 26 ]
					}
				} );
				map.addLayer( {
					id: 'g2rd-cluster-count',
					type: 'symbol',
					source: SOURCE,
					filter: [ 'has', 'point_count' ],
					layout: { 'text-field': [ 'to-string', [ 'get', 'jobs' ] ], 'text-font': font, 'text-size': 12, 'text-allow-overlap': true },
					paint: { 'text-color': '#fff' }
				} );
				map.addLayer( {
					id: 'g2rd-points',
					type: 'circle',
					source: SOURCE,
					filter: [ '!', [ 'has', 'point_count' ] ],
					paint: {
						'circle-color': COLOR,
						'circle-stroke-color': '#fff',
						'circle-stroke-width': 2,
						'circle-radius': [ 'case', [ '>', [ 'get', 'count' ], 1 ], 14, 8 ]
					}
				} );
				map.addLayer( {
					id: 'g2rd-point-count',
					type: 'symbol',
					source: SOURCE,
					filter: [ 'all', [ '!', [ 'has', 'point_count' ] ], [ '>', [ 'get', 'count' ], 1 ] ],
					layout: { 'text-field': [ 'to-string', [ 'get', 'count' ] ], 'text-font': font, 'text-size': 12, 'text-allow-overlap': true },
					paint: { 'text-color': '#fff' }
				} );

				map.on( 'click', 'g2rd-clusters', function ( event ) {
					var feature = event.features && event.features[ 0 ];
					if ( ! feature ) {
						return;
					}
					var center = feature.geometry.coordinates;
					Promise.resolve( map.getSource( SOURCE ).getClusterExpansionZoom( feature.properties.cluster_id ) ).then( function ( zoom ) {
						map.easeTo( { center: center, zoom: zoom } );
					} ).catch( function () {} );
				} );
				map.on( 'click', 'g2rd-points', function ( event ) {
					var feature = event.features && event.features[ 0 ];
					var jobs = feature && jobsById[ feature.properties.gid ];
					if ( ! jobs ) {
						return;
					}
					new ml.Popup( { maxWidth: '320px' } )
						.setLngLat( feature.geometry.coordinates )
						.setDOMContent( C.buildInfoContent( jobs, i18n ) )
						.addTo( map );
				} );
				[ 'g2rd-clusters', 'g2rd-points' ].forEach( function ( id ) {
					map.on( 'mouseenter', id, function () {
						map.getCanvas().style.cursor = 'pointer';
					} );
					map.on( 'mouseleave', id, function () {
						map.getCanvas().style.cursor = '';
					} );
				} );

				if ( ! features.length ) {
					ui.empty();
				} else {
					ui.set( '' );
					// Les points du canevas ne prennent pas le focus : liste des offres sous la carte pour le clavier et les lecteurs d'écran.
					C.places( container, groups, i18n );
					if ( 1 === features.length ) {
						map.jumpTo( { center: features[ 0 ].geometry.coordinates, zoom: 10 } );
					} else {
						var bounds = new ml.LngLatBounds();
						features.forEach( function ( feature ) {
							bounds.extend( feature.geometry.coordinates );
						} );
						map.fitBounds( bounds, { padding: 48, maxZoom: 12, duration: 0 } );
					}
				}
				container.classList.add( 'is-ready' );
			} );
		} ).catch( fail );
	}

	if ( C ) {
		C.observe( initMap );
	}
}( window, document ) );
```

- [ ] **Step 5: Vérifier le succès**

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | sed -n '/^F\./,$p'`
Expected: sections H et I en `[OK]`, `0 echoues`.

- [ ] **Step 6: Créer `OsmAssets.php`**

```php
<?php
/**
 * Enregistrement du moteur de carte libre (MapLibre + OpenFreeMap).
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Le script est seulement enregistré ; JobsMap le met en file au rendu d'une
 * carte. MapLibre lui-même est injecté par le navigateur à l'approche de la
 * zone visible, depuis le plugin (aucun CDN).
 */
class OsmAssets {

	const HANDLE_MAP       = 'g2rd-wpjm-geo-map-osm';
	const MAPLIBRE_VERSION = '5.24.0';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks (après GoogleAssets, qui enregistre map-common.js).
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 6 );
	}

	/**
	 * Enregistre le script et sa configuration.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_script( self::HANDLE_MAP, G2RD_WPJM_GEO_URL . 'assets/js/jobs-map-osm.js', array( GoogleAssets::HANDLE_COMMON ), G2RD_WPJM_GEO_VERSION, true );
		wp_add_inline_script( self::HANDLE_MAP, 'window.g2rdWpjmGeoOsm = ' . wp_json_encode( $this->config() ) . ';', 'before' );
	}

	/**
	 * Configuration transmise au navigateur.
	 *
	 * @return array
	 */
	public function config() {
		$vendor = G2RD_WPJM_GEO_URL . 'assets/vendor/maplibre-gl/';
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return array(
			'styleUrl'    => esc_url_raw( (string) $this->settings->get( 'osm_style_url' ), array( 'https' ) ),
			'maplibreJs'  => esc_url_raw( $vendor . 'maplibre-gl.js?ver=' . self::MAPLIBRE_VERSION ),
			'maplibreCss' => esc_url_raw( $vendor . 'maplibre-gl.css?ver=' . self::MAPLIBRE_VERSION ),
			'language'    => strtolower( substr( (string) preg_replace( '/[^A-Za-z]/', '', (string) $locale ), 0, 2 ) ),
			// Textes de l'interface MapLibre (en anglais par défaut).
			'locale'      => array(
				'Map.Title'                                  => __( 'Carte des offres d’emploi', 'g2rd-wpjm-geo' ),
				'Popup.Close'                                => __( 'Fermer', 'g2rd-wpjm-geo' ),
				'AttributionControl.ToggleAttribution'       => __( 'Afficher ou masquer les crédits', 'g2rd-wpjm-geo' ),
				'NavigationControl.ZoomIn'                   => __( 'Zoom avant', 'g2rd-wpjm-geo' ),
				'NavigationControl.ZoomOut'                  => __( 'Zoom arrière', 'g2rd-wpjm-geo' ),
				'FullscreenControl.Enter'                    => __( 'Plein écran', 'g2rd-wpjm-geo' ),
				'FullscreenControl.Exit'                     => __( 'Quitter le plein écran', 'g2rd-wpjm-geo' ),
				'CooperativeGesturesHandler.WindowsHelpText' => __( 'Utilisez Ctrl + molette pour zoomer sur la carte', 'g2rd-wpjm-geo' ),
				'CooperativeGesturesHandler.MacHelpText'     => __( 'Utilisez ⌘ + molette pour zoomer sur la carte', 'g2rd-wpjm-geo' ),
				'CooperativeGesturesHandler.MobileHelpText'  => __( 'Utilisez deux doigts pour déplacer la carte', 'g2rd-wpjm-geo' ),
			),
		);
	}
}
```

- [ ] **Step 7: Choisir le moteur dans `JobsMap::render()`**

Dans `JobsMap.php`, méthode `render()`, remplacer :

```php
		if ( ! GoogleAssets::has_key( $this->settings ) ) {
			return $this->admin_notice( __( 'Carte des offres : aucune clé « Google Maps Browser API Key » n’est configurée.', 'g2rd-wpjm-geo' ) );
		}
```

par :

```php
		$is_osm = Settings::PROVIDER_OSM === $this->settings->get_provider();
		if ( ! $is_osm && ! GoogleAssets::has_key( $this->settings ) ) {
			return $this->admin_notice( __( 'Carte des offres : aucune clé « Google Maps Browser API Key » n’est configurée.', 'g2rd-wpjm-geo' ) );
		}
```

Remplacer :

```php
		wp_enqueue_script( GoogleAssets::HANDLE_MAP );
```

par :

```php
		wp_enqueue_script( $is_osm ? OsmAssets::HANDLE_MAP : GoogleAssets::HANDLE_MAP );
```

Dans le tableau `$i18n`, après `'mapLabel'  => …,`, ajouter :

```php
			'regeocode' => __( 'Re-géocoder les offres', 'g2rd-wpjm-geo' ),
			'places'    => __( 'Liste des offres de la carte', 'g2rd-wpjm-geo' ),
```

Dans le tableau `$attributes`, après la ligne `'data-map-id' …`, ajouter :

```php
			// Lien vers l'outil de re-géocodage dans l'état vide, pour les seuls administrateurs.
			'data-admin-url'  => current_user_can( Settings::CAPABILITY ) ? esc_url_raw( Settings::page_url() . '#g2rd-geo-outils' ) : '',
```

- [ ] **Step 8: Styles publics**

À la fin de `assets/css/frontend.css`, ajouter :

```css
/* ---------- État vide : lien administrateur ---------- */
.g2rd-wpjm-geo-map__status a {
	color: #a93226;
	font-weight: 600;
}

/* ---------- Fenêtre MapLibre ---------- */
.g2rd-wpjm-geo-map .maplibregl-popup-content {
	padding: 12px 14px;
	border-radius: 6px;
	box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
}

/* ---------- Liste des offres sous la carte (accès au clavier) ---------- */
.g2rd-wpjm-geo-map__places {
	margin-top: 12px;
}
.g2rd-wpjm-geo-map__places > summary {
	cursor: pointer;
	font-weight: 600;
}
```

Toujours dans `frontend.css`, remplacer le sélecteur `.g2rd-wpjm-geo-map__canvas {` par `.g2rd-wpjm-geo-map > .g2rd-wpjm-geo-map__canvas {` : la feuille de MapLibre (`.maplibregl-map { position: relative }`), chargée après, l'emporterait à spécificité égale.

- [ ] **Step 9: Brancher dans le plugin**

Dans `Plugin.php`, juste après `$google->register();`, ajouter :

```php
		( new OsmAssets( $this->settings ) )->register();
```

Changement de fournisseur : les pages en cache (WP Rocket sur le site cible) garderaient l'ancien moteur, alors que l'endpoint ne renvoie déjà plus que la nouvelle source. Dans `Settings.php`, dans `register()`, après la ligne `add_action( 'add_option_' . self::OPTION, array( $this, 'flush_cache' ) );`, ajouter :

```php
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'purge_page_cache_on_provider_change' ), 10, 2 );
```

et, après la méthode `flush_cache()` :

```php

	/**
	 * Vide le cache de pages de WP Rocket quand le fournisseur change (appel de sa
	 * fonction publique, sans rien modifier de l'extension).
	 *
	 * @param mixed $old Anciens réglages.
	 * @param mixed $new Nouveaux réglages.
	 * @return void
	 */
	public static function purge_page_cache_on_provider_change( $old, $new ) {
		$before = ( is_array( $old ) && isset( $old['provider'] ) ) ? $old['provider'] : self::PROVIDER_GOOGLE;
		$after  = ( is_array( $new ) && isset( $new['provider'] ) ) ? $new['provider'] : self::PROVIDER_GOOGLE;
		if ( $before !== $after && function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}
```

Dans `tests/run.php`, juste avant `$results = $GLOBALS['g2rd_results'];` :

```php
echo PHP_EOL . '24. Changement de fournisseur : cache de pages' . PHP_EOL;
if ( ! function_exists( 'rocket_clean_domain' ) ) {
	function rocket_clean_domain() {
		$GLOBALS['g2rd_rocket_purges']++;
	}
}
$GLOBALS['g2rd_rocket_purges'] = 0;
Settings::purge_page_cache_on_provider_change( array( 'provider' => 'google' ), array( 'provider' => 'google', 'map_height' => 500 ) );
check( 'fournisseur inchangé : pas de purge', 0 === $GLOBALS['g2rd_rocket_purges'] );
Settings::purge_page_cache_on_provider_change( array( 'provider' => 'google' ), array( 'provider' => 'osm' ) );
check( 'fournisseur changé : cache de pages purgé', 1 === $GLOBALS['g2rd_rocket_purges'] );
```

- [ ] **Step 10: Lint, tests, commit**

```bash
for f in g2rd-wpjm-geo/includes/*.php; do php -l "$f" | grep -v "^No syntax"; done
node --check g2rd-wpjm-geo/assets/js/jobs-map-osm.js
php g2rd-wpjm-geo/tests/run.php | tail -1
NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1
git add g2rd-wpjm-geo/assets/vendor/maplibre-gl g2rd-wpjm-geo/assets/js/jobs-map-osm.js g2rd-wpjm-geo/assets/js/map-common.js g2rd-wpjm-geo/assets/js/jobs-map.js g2rd-wpjm-geo/includes/OsmAssets.php g2rd-wpjm-geo/includes/JobsMap.php g2rd-wpjm-geo/includes/Plugin.php g2rd-wpjm-geo/includes/Settings.php g2rd-wpjm-geo/assets/css/frontend.css g2rd-wpjm-geo/tests/js/run.js g2rd-wpjm-geo/tests/run.php
git commit -m "feat: carte libre MapLibre + OpenFreeMap, clustering natif"
```

Expected: lint muet, `0 échoués`, `0 echoues`.

---

### Task 13: Autocomplétion Photon

**Files:**
- Modify: `g2rd-wpjm-geo/assets/js/location-autocomplete.js` (réécrit), `g2rd-wpjm-geo/includes/LocationAutocomplete.php`, `g2rd-wpjm-geo/includes/GoogleAssets.php`, `g2rd-wpjm-geo/tests/js/run.js`

- [ ] **Step 1: Écrire les tests (section J)**

Dans `tests/js/run.js`, juste avant `(async () => {`, ajouter :

```js
async function photonAc({ status400 = false, fail = false, slowFirst = false } = {}) {
  const dom = new JSDOM('<form><input type="text" id="job_location" name="job_location"><input id="other"></form>', { runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window, calls = [];
  w.g2rdWpjmGeoAutocomplete = { source: 'photon', photonUrl: 'https://photon.test/api/', lang: 'fr', selectors: ['#job_location'], minLength: 2, i18n: { poweredBy: 'Recherche (c) OpenStreetMap contributors', results: 'Suggestions' } };
  w.AbortController = class { constructor() { this.signal = { aborted: false }; } abort() { this.signal.aborted = true; } };
  const feature = (name, state, country, key) => ({ properties: { name, state, country, osm_key: key || 'place' } });
  w.fetch = (url, opt) => {
    calls.push({ url, opt });
    if (fail) return Promise.reject(new Error('reseau'));
    const filtered = url.indexOf('osm_tag=') !== -1;
    if (status400 && filtered) return Promise.resolve({ ok: false, status: 400, json: () => Promise.resolve({}) });
    const features = [feature('Londres', 'Angleterre', 'Royaume-Uni'), feature('London', 'Ontario', 'Canada'), feature('<img src=x onerror=alert(1)>', 'X', 'Y')];
    if (!filtered) features.splice(1, 0, feature('London Pub', 'Angleterre', 'Royaume-Uni', 'amenity'));
    const reply = { ok: true, status: 200, json: () => Promise.resolve({ features }) };
    return slowFirst && calls.length === 1 ? new Promise(r => setTimeout(() => r(reply), 600)) : Promise.resolve(reply);
  };
  w.eval(src('js/location-autocomplete.js'));
  const d = w.document, input = d.getElementById('job_location');
  input.focus(); await wait(10);
  return { w, d, input, calls, panel: d.querySelector('.g2rd-wpjm-geo-ac') };
}
```

Puis, avant la ligne du résultat final, ajouter :

```js
  console.log('\nJ. Autocompletion en mode libre (Photon)');
  let p = await photonAc();
  p.input.value = 'Lond'; p.input.dispatchEvent(new p.w.Event('input', { bubbles: true }));
  await wait(200);
  check('attente de 300 ms avant la requete', p.calls.length === 0);
  await wait(250);
  const u = p.calls[0] && p.calls[0].url;
  check('requete Photon : texte, limite 6, langue fr, 4 filtres de lieu, sans Google', p.calls.length === 1 && u.indexOf('https://photon.test/api/?q=Lond&limit=6&lang=fr') === 0 && ['city', 'town', 'village', 'municipality'].every(v => u.indexOf('osm_tag=place%3A' + v) !== -1) && !p.w.g2rdWpjmGeoLoader);
  check('3 suggestions, attribution OpenStreetMap', p.panel.querySelectorAll('[role=option]').length === 3 && /OpenStreetMap/.test(p.panel.textContent) && !p.panel.hidden);
  check('suggestion malveillante inseree comme texte', p.panel.querySelectorAll('img').length === 0);
  key(p.w, p.input, 'ArrowDown'); key(p.w, p.input, 'Enter'); await wait(10);
  check('libelle ecrit : Nom, Region, Pays', p.input.value === 'Londres, Angleterre, Royaume-Uni' && p.panel.hidden);
  await type(p, 'Lond');
  check('meme saisie : reponse memorisee, pas de nouvelle requete', p.calls.length === 1);
  p = await photonAc({ status400: true });
  await type(p, 'Lond');
  check('HTTP 400 : nouvel essai sans filtre', p.calls.length === 2 && p.calls[1].url.indexOf('osm_tag') === -1);
  check('repli : seuls les lieux sont proposes (pub ecarte)', p.panel.querySelectorAll('[role=option]').length === 3 && !/London Pub/.test(p.panel.textContent));
  await type(p, 'Londr');
  check('repli memorise : plus de filtre ensuite', p.calls.length === 3 && p.calls[2].url.indexOf('osm_tag') === -1);
  p = await photonAc({ slowFirst: true });
  p.input.value = 'Lon'; p.input.dispatchEvent(new p.w.Event('input', { bubbles: true })); await wait(350);
  p.input.value = 'Lond'; p.input.dispatchEvent(new p.w.Event('input', { bubbles: true })); await wait(350);
  check('frappe suivante : requete precedente annulee', p.calls.length === 2 && p.calls[0].opt.signal.aborted === true);
  await wait(400);
  check('reponse obsolete ignoree, liste de la derniere saisie', p.panel.querySelectorAll('[role=option]').length === 3);
  p = await photonAc({ fail: true });
  await type(p, 'Lond');
  check('Photon injoignable : pas de liste, champ utilisable', p.panel.hidden && p.input.value === 'Lond');
  await type(p, 'Londr');
  check('apres une panne : plus aucune requete', p.calls.length === 1);
```

- [ ] **Step 2: Vérifier l'échec**

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | sed -n '/^G\./,$p'`
Expected: `[FAIL]` sur les lignes de la section J (le script actuel ignore `source: 'photon'`).

- [ ] **Step 3: Réécrire `location-autocomplete.js`**

Remplacer tout le contenu par :

```js
/**
 * Autocomplétion mondiale des villes sur le champ de localisation natif de WP Job Manager.
 *
 * Deux sources, selon le réglage « Fournisseur cartographique » :
 * - google : API « Place Autocomplete Data » (Places API New) ;
 * - photon : service Photon (OpenStreetMap), sans clé.
 * Le champ existant est conservé tel quel, aucune saisie n'est dupliquée, et la
 * saisie libre reste possible si le service est indisponible.
 */
( function ( window, document ) {
	'use strict';

	var settings = window.g2rdWpjmGeoAutocomplete || {};
	var source = 'photon' === settings.source ? 'photon' : 'google';
	var loader = window.g2rdWpjmGeoLoader;
	var i18n = settings.i18n || {};
	var MIN_LENGTH = settings.minLength || 2;
	var DEBOUNCE = 'photon' === source ? 300 : 250;
	// « (cities) » est la collection prévue par Places API (New). Le second jeu sert de repli si elle est refusée.
	var TYPE_SETS = [
		[ '(cities)' ],
		[ 'locality', 'postal_town', 'administrative_area_level_3' ]
	];
	// Photon : villes, bourgs, villages et communes du monde entier.
	var PHOTON_TAGS = [ 'place:city', 'place:town', 'place:village', 'place:municipality' ];
	var uid = 0;

	function findInput() {
		var selectors = settings.selectors || [];
		for ( var i = 0; i < selectors.length; i++ ) {
			var node = null;
			try {
				node = document.querySelector( selectors[ i ] );
			} catch ( e ) {
				node = null;
			}
			if ( node && 'INPUT' === node.tagName && ! node.hasAttribute( 'data-g2rd-wpjm-geo-ac' ) ) {
				return node;
			}
		}
		return null;
	}

	// Suggestions normalisées : { label, main, secondary }.
	function fromGoogle( suggestions ) {
		var out = [];
		( suggestions || [] ).forEach( function ( suggestion ) {
			var prediction = suggestion && suggestion.placePrediction;
			if ( ! prediction || ! prediction.text ) {
				return;
			}
			var label = String( prediction.text.text || prediction.text );
			if ( ! label ) {
				return;
			}
			out.push( {
				label: label,
				main: ( prediction.mainText && prediction.mainText.text ) || '',
				secondary: ( prediction.secondaryText && prediction.secondaryText.text ) || ''
			} );
		} );
		return out;
	}

	function fromPhoton( features, placesOnly ) {
		var out = [];
		var seen = {};
		( features || [] ).forEach( function ( feature ) {
			var props = feature && feature.properties;
			if ( ! props || 'string' !== typeof props.name || ! props.name ) {
				return;
			}
			if ( placesOnly && 'place' !== props.osm_key ) {
				return;
			}
			var parts = [ props.name ];
			[ props.state, props.country ].forEach( function ( part ) {
				if ( 'string' === typeof part && part && parts.indexOf( part ) === -1 ) {
					parts.push( part );
				}
			} );
			var label = parts.join( ', ' );
			if ( seen[ label ] ) {
				return;
			}
			seen[ label ] = true;
			out.push( { label: label, main: props.name, secondary: parts.slice( 1 ).join( ', ' ) } );
		} );
		return out;
	}

	function attach( input ) {
		var listId = 'g2rd-wpjm-geo-ac-list-' + ( ++uid );
		var places = null; // Bibliothèque Places, chargée au premier focus (Google).
		var token = null;
		var disabled = false;
		var timer = null;
		var requestId = 0;
		var items = [];
		var active = -1;
		var typeSet = 0;
		var choosing = false;
		var photonFiltered = true;
		var controller = null;
		var cache = {};

		input.setAttribute( 'data-g2rd-wpjm-geo-ac', '1' );
		input.setAttribute( 'autocomplete', 'off' );
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', listId );

		// La liste est attachée à <body> : elle n'est jamais rognée par un conteneur du thème
		// et la structure HTML du formulaire WP Job Manager reste intacte.
		var panel = document.createElement( 'div' );
		panel.className = 'g2rd-wpjm-geo-ac';
		panel.hidden = true;

		var list = document.createElement( 'ul' );
		list.className = 'g2rd-wpjm-geo-ac__list';
		list.id = listId;
		list.setAttribute( 'role', 'listbox' );
		list.setAttribute( 'aria-label', i18n.results || '' );

		var attribution = document.createElement( 'div' );
		attribution.className = 'g2rd-wpjm-geo-ac__attribution';
		attribution.textContent = i18n.poweredBy || '';

		panel.appendChild( list );
		panel.appendChild( attribution );
		document.body.appendChild( panel );

		function position() {
			var rect = input.getBoundingClientRect();
			panel.style.top = ( rect.bottom + window.pageYOffset ) + 'px';
			panel.style.left = ( rect.left + window.pageXOffset ) + 'px';
			panel.style.width = rect.width + 'px';
		}

		function close() {
			panel.hidden = true;
			items = [];
			active = -1;
			list.textContent = '';
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
		}

		function setActive( index ) {
			active = index;
			Array.prototype.forEach.call( list.children, function ( node, i ) {
				var selected = i === index;
				node.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				node.classList.toggle( 'is-active', selected );
				if ( selected ) {
					input.setAttribute( 'aria-activedescendant', node.id );
				}
			} );
			if ( index < 0 ) {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function choose( index ) {
			var item = items[ index ];
			if ( ! item ) {
				return;
			}
			// La valeur est écrite dans le champ natif : c'est elle que WP Job Manager enregistre puis géocode.
			input.value = item.label;
			// Prévient les autres scripts du formulaire, sans relancer notre propre recherche.
			choosing = true;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			choosing = false;
			token = null; // Fin de la session de facturation Google.
			close();
		}

		function render( suggestions ) {
			list.textContent = '';
			items = [];
			suggestions.forEach( function ( suggestion ) {
				var index = items.length;
				var option = document.createElement( 'li' );
				option.id = listId + '-option-' + index;
				option.className = 'g2rd-wpjm-geo-ac__option';
				option.setAttribute( 'role', 'option' );
				option.setAttribute( 'aria-selected', 'false' );

				if ( suggestion.main ) {
					var strong = document.createElement( 'span' );
					strong.className = 'g2rd-wpjm-geo-ac__main';
					strong.textContent = suggestion.main;
					option.appendChild( strong );
					if ( suggestion.secondary ) {
						var rest = document.createElement( 'span' );
						rest.className = 'g2rd-wpjm-geo-ac__secondary';
						rest.textContent = ' ' + suggestion.secondary;
						option.appendChild( rest );
					}
				} else {
					option.textContent = suggestion.label;
				}

				// mousedown (et non click) : agit avant la perte de focus du champ.
				option.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
					choose( index );
				} );
				option.addEventListener( 'mouseenter', function () {
					setActive( index );
				} );

				items.push( { label: suggestion.label } );
				list.appendChild( option );
			} );

			if ( items.length ) {
				position();
				panel.hidden = false;
				input.setAttribute( 'aria-expanded', 'true' );
				active = -1;
			} else {
				close();
			}
		}

		function ensurePlaces() {
			if ( places ) {
				return Promise.resolve( places );
			}
			return loader.load().then( function ( maps ) {
				return maps.importLibrary( 'places' );
			} ).then( function ( library ) {
				if ( ! library || ! library.AutocompleteSuggestion ) {
					throw new Error( 'places-unavailable' );
				}
				places = library;
				return places;
			} );
		}

		function fetchSuggestions( library, value ) {
			if ( ! token ) {
				token = new library.AutocompleteSessionToken();
			}
			var request = {
				input: value,
				includedPrimaryTypes: TYPE_SETS[ typeSet ], // Villes du monde entier : aucune restriction de pays.
				sessionToken: token
			};
			if ( window.g2rdWpjmGeoConfig && window.g2rdWpjmGeoConfig.language ) {
				request.language = window.g2rdWpjmGeoConfig.language;
			}
			return library.AutocompleteSuggestion.fetchAutocompleteSuggestions( request ).catch( function ( error ) {
				// Le filtre de types a pu être refusé par Google : on retente une seule fois avec le jeu
				// de types explicites, conservé ensuite pour le reste de la session.
				if ( typeSet < TYPE_SETS.length - 1 ) {
					typeSet++;
					return fetchSuggestions( library, value );
				}
				throw error;
			} );
		}

		function photonSearch( value ) {
			var cacheKey = value.toLowerCase();
			if ( cache[ cacheKey ] ) {
				return Promise.resolve( cache[ cacheKey ] );
			}
			if ( controller ) {
				controller.abort();
			}
			controller = 'function' === typeof window.AbortController ? new window.AbortController() : null;

			var params = 'q=' + encodeURIComponent( value ) + '&limit=6&lang=' + encodeURIComponent( settings.lang || 'default' );
			if ( photonFiltered ) {
				params += PHOTON_TAGS.map( function ( tag ) {
					return '&osm_tag=' + encodeURIComponent( tag );
				} ).join( '' );
			}
			var base = settings.photonUrl || '';
			var options = { credentials: 'omit' };
			if ( controller ) {
				options.signal = controller.signal;
			}

			return window.fetch( base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + params, options ).then( function ( response ) {
				if ( 400 === response.status && photonFiltered ) {
					// Filtre refusé par le serveur : repli sans filtre, mémorisé pour la page.
					photonFiltered = false;
					controller = null;
					return photonSearch( value );
				}
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json().then( function ( data ) {
					var suggestions = fromPhoton( data && data.features, ! photonFiltered );
					cache[ cacheKey ] = suggestions;
					return suggestions;
				} );
			} );
		}

		function search() {
			var value = input.value.trim();
			if ( disabled || value.length < MIN_LENGTH ) {
				close();
				return;
			}
			var current = ++requestId;
			var pending = 'photon' === source
				? photonSearch( value )
				: ensurePlaces().then( function ( library ) {
					return fetchSuggestions( library, value );
				} ).then( function ( result ) {
					return fromGoogle( result && result.suggestions );
				} );

			pending.then( function ( suggestions ) {
				// Ignore les réponses obsolètes (l'utilisateur a continué à taper).
				if ( current === requestId && document.activeElement === input ) {
					render( suggestions );
				}
			} ).catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) {
					return; // Requête remplacée par une frappe plus récente.
				}
				// Service indisponible ou clé refusée : le champ redevient un simple champ texte.
				disabled = true;
				close();
			} );
		}

		input.addEventListener( 'focus', function () {
			if ( 'google' === source && ! disabled ) {
				ensurePlaces().catch( function () {
					disabled = true;
				} );
			}
		} );

		input.addEventListener( 'input', function () {
			// Ignore l'événement « input » émis par choose().
			if ( choosing ) {
				return;
			}
			window.clearTimeout( timer );
			timer = window.setTimeout( search, DEBOUNCE );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( panel.hidden ) {
				return;
			}
			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					setActive( ( active + 1 ) % items.length );
					break;
				case 'ArrowUp':
					event.preventDefault();
					setActive( ( active - 1 + items.length ) % items.length );
					break;
				case 'Enter':
					if ( active >= 0 ) {
						event.preventDefault(); // Ne soumet pas le formulaire pendant le choix d'une ville.
						choose( active );
					}
					break;
				case 'Escape':
					event.preventDefault();
					close();
					break;
				case 'Tab':
					close();
					break;
			}
		} );

		input.addEventListener( 'blur', function () {
			window.clearTimeout( timer );
			requestId++;
			close();
		} );

		window.addEventListener( 'resize', function () {
			if ( ! panel.hidden ) {
				position();
			}
		} );
		window.addEventListener( 'scroll', function () {
			if ( ! panel.hidden ) {
				position();
			}
		}, true );

		document.addEventListener( 'g2rdWpjmGeoAuthFailure', function () {
			if ( 'google' === source ) {
				disabled = true;
				close();
			}
		} );
	}

	function init() {
		if ( ! window.Promise ) {
			return;
		}
		if ( 'google' === source && ! loader ) {
			return;
		}
		if ( 'photon' === source && ( ! window.fetch || ! settings.photonUrl ) ) {
			return;
		}
		var input = findInput();
		if ( input ) {
			attach( input );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}( window, document ) );
```

- [ ] **Step 4: Vérifier le succès (sections A à J)**

Run: `NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | grep -E "FAIL|Resultat"`
Expected: une seule ligne `Resultat JS : … 0 echoues`.

- [ ] **Step 5: Configuration côté PHP**

Dans `LocationAutocomplete.php`, remplacer, dans `prepare_field()` :

```php
		if ( $this->has_location_field && GoogleAssets::has_key( $this->settings ) ) {
```

par :

```php
		if ( $this->has_location_field && ( Settings::PROVIDER_OSM === $this->settings->get_provider() || GoogleAssets::has_key( $this->settings ) ) ) {
```

Remplacer le corps de `enqueue_autocomplete()` par :

```php
	protected function enqueue_autocomplete( $selectors ) {
		$is_osm = Settings::PROVIDER_OSM === $this->settings->get_provider();
		if ( ! $is_osm && ! GoogleAssets::has_key( $this->settings ) ) {
			return;
		}

		wp_enqueue_style( GoogleAssets::HANDLE_STYLE );
		wp_enqueue_script( GoogleAssets::HANDLE_AUTOCOMPLETE );

		// Langue du site, comme le géocodage serveur : un libellé choisi ici doit être retrouvé par PhotonGeocoder.
		$locale = get_locale();
		$config = array(
			'source'    => $is_osm ? 'photon' : 'google',
			'photonUrl' => $is_osm ? esc_url_raw( (string) $this->settings->get( 'photon_url' ), array( 'https' ) ) : '',
			'lang'      => PhotonGeocoder::language( $locale ),
			'selectors' => array_values( array_filter( array_map( 'strval', (array) $selectors ) ) ),
			'minLength' => 2,
			'i18n'      => array(
				'poweredBy' => $is_osm ? __( 'Recherche © OpenStreetMap contributors', 'g2rd-wpjm-geo' ) : __( 'Propulsé par Google', 'g2rd-wpjm-geo' ),
				'results'   => __( 'Suggestions de villes', 'g2rd-wpjm-geo' ),
			),
		);
		wp_add_inline_script(
			GoogleAssets::HANDLE_AUTOCOMPLETE,
			'window.g2rdWpjmGeoAutocomplete = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
```

Dans `GoogleAssets::register_assets()`, remplacer :

```php
		wp_register_script( self::HANDLE_AUTOCOMPLETE, $base . 'js/location-autocomplete.js', array( self::HANDLE_LOADER ), $version, true );
```

par :

```php
		// En mode libre, le chargeur Google (et la clé navigateur) n'est jamais imprimé dans la page.
		$autocomplete_deps = Settings::PROVIDER_OSM === $this->settings->get_provider() ? array() : array( self::HANDLE_LOADER );
		wp_register_script( self::HANDLE_AUTOCOMPLETE, $base . 'js/location-autocomplete.js', $autocomplete_deps, $version, true );
```

- [ ] **Step 6: Lint, tests, commit**

```bash
for f in g2rd-wpjm-geo/includes/*.php; do php -l "$f" | grep -v "^No syntax"; done
node --check g2rd-wpjm-geo/assets/js/location-autocomplete.js
NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js | tail -1
git add g2rd-wpjm-geo/assets/js/location-autocomplete.js g2rd-wpjm-geo/includes/LocationAutocomplete.php g2rd-wpjm-geo/includes/GoogleAssets.php g2rd-wpjm-geo/tests/js/run.js
git commit -m "feat: autocomplétion des villes avec Photon en mode libre"
```

---

### Task 14: Confidentialité, désinstallation, version 1.1.0

**Files:**
- Create: `g2rd-wpjm-geo/includes/PrivacyPolicy.php`
- Modify: `g2rd-wpjm-geo/includes/Plugin.php`, `g2rd-wpjm-geo/uninstall.php`, `g2rd-wpjm-geo/g2rd-wpjm-geo.php`, `g2rd-wpjm-geo/readme.txt`, `g2rd-wpjm-geo/tests/bootstrap.php`, `g2rd-wpjm-geo/tests/run.php`

- [ ] **Step 1: Écrire le test (section 25)**

Ajouter aux `use` : `use G2RD\WPJMGeo\PrivacyPolicy;`. Puis :

```php
echo PHP_EOL . '25. Texte de confidentialité' . PHP_EOL;
list( $s ) = boot( array( 'provider' => 'osm', 'photon_url' => 'https://photon.mon-serveur.fr/api/' ) );
$text = ( new PrivacyPolicy( $s ) )->text();
check( 'mode libre : hôtes réellement configurés cités', false !== strpos( $text, 'tiles.openfreemap.org' ) && false !== strpos( $text, 'photon.mon-serveur.fr' ) && false === strpos( $text, 'Google' ) );
list( $s ) = boot();
check( 'mode Google : Google cité', false !== strpos( ( new PrivacyPolicy( $s ) )->text(), 'Google' ) );
```

Dans `tests/bootstrap.php`, ajouter `'PrivacyPolicy'` à la fin de la liste des classes.

- [ ] **Step 2: Vérifier l'échec**

Run: `php g2rd-wpjm-geo/tests/run.php | tail -3`
Expected: `failed to open stream` sur `PrivacyPolicy.php`.

- [ ] **Step 3: Créer `PrivacyPolicy.php`**

```php
<?php
/**
 * Texte suggéré pour la politique de confidentialité (Réglages → Confidentialité).
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Le texte suit le fournisseur actif et cite les hôtes réellement configurés.
 */
class PrivacyPolicy {

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'add_content' ) );
	}

	/**
	 * Déclare le texte auprès de WordPress.
	 *
	 * @return void
	 */
	public function add_content() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'G2RD WP Job Manager Geo', 'g2rd-wpjm-geo' ), wp_kses_post( wpautop( esc_html( $this->text() ) ) ) );
		}
	}

	/**
	 * Texte suggéré.
	 *
	 * @return string
	 */
	public function text() {
		if ( Settings::PROVIDER_OSM === $this->settings->get_provider() ) {
			return sprintf(
				/* translators: 1: map tiles host, 2: Photon host. */
				__( "La carte des offres d'emploi affiche un fond de carte fourni par %1\$s : pour l'afficher, votre navigateur transmet votre adresse IP à ce service.\n\nLorsque vous saisissez une ville dans le formulaire de dépôt d'une offre, les lettres tapées et votre adresse IP sont transmises au service de recherche %2\$s.\n\nLa localisation des offres est convertie en coordonnées par notre serveur auprès de %2\$s ; aucune donnée personnelle des visiteurs n'est transmise à cette occasion. Données cartographiques © les contributeurs d'OpenStreetMap.", 'g2rd-wpjm-geo' ),
				(string) wp_parse_url( (string) $this->settings->get( 'osm_style_url' ), PHP_URL_HOST ),
				(string) wp_parse_url( (string) $this->settings->get( 'photon_url' ), PHP_URL_HOST )
			);
		}
		return __( "La carte des offres d'emploi et l'autocomplétion des villes utilisent Google Maps Platform (Google Ireland Limited) : votre navigateur charge des scripts de Google et lui transmet votre adresse IP ainsi que les lettres saisies dans le champ de localisation.\n\nLa localisation des offres est convertie en coordonnées par notre serveur auprès de Google ; aucune donnée personnelle des visiteurs n'est transmise à cette occasion.", 'g2rd-wpjm-geo' );
	}
}
```

- [ ] **Step 4: Vérifier le succès**

Run: `php g2rd-wpjm-geo/tests/run.php | sed -n '/^25\./,$p'`
Expected: section 25 en `[OK]`, `0 échoués`.

- [ ] **Step 5: Brancher, nettoyer, versionner**

Dans `Plugin.php`, dans le bloc `if ( is_admin() ) {`, après `( new AdminScreen() )->register();`, ajouter :

```php
			( new PrivacyPolicy( $this->settings ) )->register();
```

Dans `uninstall.php`, après `wp_clear_scheduled_hook( 'g2rd_wpjm_geo_check_urls' );`, ajouter :

```php
delete_option( 'g2rd_wpjm_geo_geocode_queue' );
delete_transient( 'g2rd_wpjm_geo_geocode_lock' );
wp_clear_scheduled_hook( 'g2rd_wpjm_geo_geocode' );
delete_post_meta_by_key( '_g2rd_wpjm_geo_source' );

// Caches des réponses Photon et des vérifications d'URL.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_g2rd_geo_ph_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_g2rd_geo_ph_' ) . '%',
		$wpdb->esc_like( '_transient_g2rd_geo_url_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_g2rd_geo_url_' ) . '%'
	)
);
```

Dans `g2rd-wpjm-geo.php`, remplacer ` * Version:           1.0.0` par ` * Version:           1.1.0` et `define( 'G2RD_WPJM_GEO_VERSION', '1.0.0' );` par `define( 'G2RD_WPJM_GEO_VERSION', '1.1.0' );`. Dans la ligne `Description:`, remplacer `(Google Places)` par `(Google Places ou OpenStreetMap)`.

Dans `readme.txt` :
- `Stable tag: 1.0.0` devient `Stable tag: 1.1.0` ;
- dans `== Description ==`, ajouter en tête de liste : `* Fournisseur au choix : Google Maps, ou OpenStreetMap libre et gratuit (MapLibre GL JS 5.24.0, fonds OpenFreeMap, recherche et géocodage Photon), sans clé ni compte.` et `* Administration à la charte de la console G2RD : sous-menu du menu « G2RD » si le thème G2RD (console v2) est actif, menu « G2RD Geo » sinon.` ;
- dans `== Installation ==`, ajouter : `5. Mode libre : Offres géolocalisées → Cartographie → OpenStreetMap, puis Outils → Re-géocoder les offres.` ;
- dans `== Options WordPress ==`, ajouter : `* Métadonnée \`_g2rd_wpjm_geo_source\` (google | osm) sur chaque offre géocodée par le plugin.` et `* \`g2rd_wpjm_geo_geocode_queue\` : file des géocodages différés.` ;
- dans `== Hooks pour développeurs ==`, ajouter : `* \`g2rd_wpjm_geo_photon_allow_private_host\` : autorise un serveur Photon auto-hébergé à une adresse privée (seul son hôte).` ;
- dans `== Tests ==`, remplacer la ligne par : `\`php tests/run.php\` ; \`npm --prefix ../tools/jsdom i jsdom@24\` puis \`NODE_PATH=../tools/jsdom/node_modules node tests/js/run.js\` ; \`tools/docker/verify.sh\` (dépôt).` ;
- dans `== Changelog ==`, ajouter au-dessus de 1.0.0 :

```text
= 1.1.0 =
* Fournisseur cartographique libre : MapLibre + OpenFreeMap + Photon.
* Géocodage libre des offres (différé pendant les imports), outil de re-géocodage.
* Administration à la charte de la console G2RD.
* Texte suggéré pour la politique de confidentialité.
```

- [ ] **Step 6: Lint, tests, commit**

```bash
for f in g2rd-wpjm-geo/includes/*.php g2rd-wpjm-geo/*.php; do php -l "$f" | grep -v "^No syntax"; done
php g2rd-wpjm-geo/tests/run.php | tail -1
git add g2rd-wpjm-geo
git commit -m "feat: texte de confidentialité, nettoyage à la désinstallation, version 1.1.0"
```

---

### Task 15: Vérification de bout en bout (Docker) et archive

**Files:**
- Create: `tools/docker/docker-compose.yml`, `tools/docker/fetch-plugins.sh`, `tools/docker/verify.sh`, `tools/build-zip.php`

- [ ] **Step 1: `tools/docker/docker-compose.yml`**

```yaml
name: g2rdgeo-test
services:
  db:
    image: mariadb:11
    environment: { MARIADB_DATABASE: wp, MARIADB_USER: wp, MARIADB_PASSWORD: wp, MARIADB_ROOT_PASSWORD: root }
    healthcheck: { test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"], interval: 3s, retries: 30 }
  wp:
    image: wordpress:6-php8.2-apache
    depends_on: { db: { condition: service_healthy } }
    ports: ["8899:80"]
    environment:
      WORDPRESS_DB_HOST: db
      WORDPRESS_DB_USER: wp
      WORDPRESS_DB_PASSWORD: wp
      WORDPRESS_DB_NAME: wp
      WORDPRESS_DEBUG: "1"
      WORDPRESS_CONFIG_EXTRA: |
        define( 'WP_DEBUG_LOG', true );
        define( 'WP_DEBUG_DISPLAY', false );
        define( 'DISABLE_WP_CRON', true );
    volumes: &mounts
      - wpdata:/var/www/html
      - "../../g2rd-wpjm-geo:/var/www/html/wp-content/plugins/g2rd-wpjm-geo:ro"
      - "./plugins/wp-job-manager:/var/www/html/wp-content/plugins/wp-job-manager:ro"
      - "./plugins/go-fetch-jobs-wp-job-manager:/var/www/html/wp-content/plugins/go-fetch-jobs-wp-job-manager:ro"
      - "${G2RD_THEME_DIR:-../../../G2RD-theme}:/var/www/html/wp-content/themes/g2rd-theme:ro"
  cli:
    image: wordpress:cli-php8.2
    user: "33:33"
    depends_on: [wp]
    environment: { WORDPRESS_DB_HOST: db, WORDPRESS_DB_USER: wp, WORDPRESS_DB_PASSWORD: wp, WORDPRESS_DB_NAME: wp }
    volumes: *mounts
    entrypoint: ["sleep", "infinity"]
volumes:
  wpdata:
```

- [ ] **Step 2: `tools/docker/fetch-plugins.sh`**

```bash
#!/usr/bin/env bash
# Télécharge les extensions tierces de test (versions analysées) dans tools/docker/plugins/.
set -euo pipefail
cd "$(dirname "$0")"
mkdir -p plugins
for spec in wp-job-manager:2.4.7 go-fetch-jobs-wp-job-manager:1.8.5.2; do
  slug=${spec%%:*}
  ver=${spec##*:}
  [ -d "plugins/$slug" ] && continue
  curl -sSL -o "plugins/$slug.zip" "https://downloads.wordpress.org/plugin/$slug.$ver.zip"
  (cd plugins && unzip -q "$slug.zip" && rm "$slug.zip")
done
ls plugins
```

- [ ] **Step 3: `tools/docker/verify.sh`**

```bash
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
```

- [ ] **Step 4: Lancer la vérification**

```bash
chmod +x tools/docker/*.sh
tools/docker/fetch-plugins.sh
tools/docker/verify.sh
```

Expected: toutes les lignes `[OK]` (ou `[SKIP]` pour la section 2 si le thème G2RD est absent), `Résultat Docker : N réussis, 0 échoués`.

- [ ] **Step 5: `tools/build-zip.php` et archive**

```php
<?php
/**
 * Construit g2rd-wpjm-geo.zip à la racine du dépôt, sans les tests.
 * Usage : php tools/build-zip.php
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root     = dirname( __DIR__ );
$src      = $root . '/g2rd-wpjm-geo';
$zip_path = $root . '/g2rd-wpjm-geo.zip';
if ( file_exists( $zip_path ) ) {
	unlink( $zip_path );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Impossible de créer l'archive.\n" );
	exit( 1 );
}

$count    = 0;
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	$relative = 'g2rd-wpjm-geo/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $src ) + 1 ) );
	if ( 0 === strpos( $relative, 'g2rd-wpjm-geo/tests/' ) ) {
		continue;
	}
	$zip->addFile( $file->getPathname(), $relative );
	++$count;
}
$zip->close();
printf( "%s : %d fichiers, %d octets\n", basename( $zip_path ), $count, filesize( $zip_path ) );
```

Run: `php tools/build-zip.php && unzip -l g2rd-wpjm-geo.zip | grep -c "tests/"`
Expected: `g2rd-wpjm-geo.zip : N fichiers, … octets`, puis `0`.

- [ ] **Step 6: Commit**

```bash
git add tools/docker/docker-compose.yml tools/docker/fetch-plugins.sh tools/docker/verify.sh tools/build-zip.php
git commit -m "test: vérification de bout en bout Docker et construction de l'archive"
```

---

## Couverture de la spécification

| Section de la spécification | Tâches |
|---|---|
| 1. Réglages, métadonnée de source, unités | 2, 5, 9 |
| 2. Carte en mode libre | 11, 12 |
| 3. Autocomplétion en mode libre | 13 |
| 4. Administration (rattachement, gabarit, contenu) | 7, 9, 10 |
| 5. Géocodage serveur, file, règle d'affichage, outil | 4, 5, 6, 8 |
| 6. Sécurité (https, hôte privé, réponses non fiables), RGPD, tests | 2, 4, 14, 15 |
| Désinstallation, version | 14 |

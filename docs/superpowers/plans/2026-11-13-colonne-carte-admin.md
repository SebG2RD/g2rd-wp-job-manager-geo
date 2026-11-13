# Colonne « Carte » de la liste des offres — plan de mise en œuvre

> **Pour les agents :** sous-skill requis : `superpowers:subagent-driven-development` (recommandé) ou `superpowers:executing-plans`, tâche par tâche. Les étapes utilisent des cases à cocher (`- [ ]`).

**Objectif :** ajouter dans `wp-admin/edit.php?post_type=job_listing` une colonne « Carte ». Elle affiche un ✓ vert si les villes de l'offre sont placées sur la carte. Sinon, elle affiche un ✗ rouge, ou une horloge rouge si l'offre attend son géocodage. L'infobulle donne la raison.

**Architecture :**
- Nouvelle classe `AdminListColumn`, chargée seulement dans l'administration. Elle passe par les hooks de colonnes de WordPress.
- Elle reprend la règle « point sur la carte » de `RestController`. La validation des coordonnées y est extraite dans `coordinates()`, et `plain()` devient publique.
- Une petite feuille de style est chargée seulement sur la liste des offres.

**Pile technique :** PHP 7.4+, WordPress (hooks `manage_edit-job_listing_columns`, `manage_job_listing_posts_custom_column`, `admin_enqueue_scripts`), dashicons, infobulles `tips` de WP Job Manager. Tests : harnais maison `g2rd-wpjm-geo/tests/run.php` et Docker `tools/docker/verify.sh`.

**Spec :** [`docs/superpowers/specs/2026-11-13-colonne-carte-admin-design.md`](../specs/2026-11-13-colonne-carte-admin-design.md)

---

## Contexte utile

- Tests PHP, depuis la racine du dépôt : `C:/PHP/php.exe g2rd-wpjm-geo/tests/run.php`. La dernière ligne doit être `Résultat : N réussis, 0 échoués`. Tout avertissement PHP compte comme un échec.
- Le harnais (`tests/bootstrap.php`) :
  - charge les classes de `includes/` depuis une liste explicite, en fin de `tests/bootstrap.php` : une nouvelle classe doit y être ajoutée ;
  - simule `get_post_meta` (lecture de `G2RD_Test_State::$meta[ $id ][ $key ]`, `''` si absente), `get_option` / `update_option`, les hooks avec priorité et nombre d'arguments, et `esc_attr` / `esc_html` (`htmlspecialchars`, `ENT_QUOTES`) ;
  - simule `wp_enqueue_style`, qui note `G2RD_Test_State::$enqueued[ 'style:' . $handle ] = $src` ;
  - définit `G2RD_WPJM_GEO_URL`, `G2RD_WPJM_GEO_DIR` et `G2RD_WPJM_GEO_VERSION` (`'test'`) ;
  - remet tout à zéro avec `boot( array( 'provider' => … ) )`, qui renvoie `array( $settings, … )`.
- Le harnais ne simule pas `get_current_screen()`. La colonne lit donc `$GLOBALS['typenow']`, que WordPress renseigne sur `edit.php` avec le type de contenu affiché.
- WP Job Manager déclare ses colonnes au filtre `manage_edit-job_listing_columns` (priorité 10) ; la clé du lieu est `job_location`. Son script d'administration, chargé sur l'écran `edit-job_listing`, transforme tout `.tips[data-tip]` en infobulle texte.
- Style du code : WordPress Coding Standards (`composer lint`), `array()` et non `[]`, conditions Yoda, chaînes en français avec le domaine `g2rd-wpjm-geo`, commentaire `translators:` avant chaque chaîne à `%s`.

## Fichiers

| Fichier | Action | Rôle |
| --- | --- | --- |
| `g2rd-wpjm-geo/includes/RestController.php` | Modifier | Extraire `coordinates()`, rendre `plain()` publique |
| `g2rd-wpjm-geo/includes/AdminListColumn.php` | Créer | État de chaque offre, colonne, rendu, feuille de style |
| `g2rd-wpjm-geo/assets/css/admin-list.css` | Créer | Largeur, centrage, taille des icônes, couleurs |
| `g2rd-wpjm-geo/includes/Plugin.php` | Modifier | Enregistrer `AdminListColumn` dans le bloc `is_admin()` |
| `g2rd-wpjm-geo/tests/run.php` | Modifier | Sections 41 (`coordinates()`) et 42 (colonne) |
| `tools/docker/verify.sh` | Modifier | Contrôles de la liste des offres en mode libre |
| `g2rd-wpjm-geo/readme.txt`, `README.md`, `docs/index.md`, `docs/architecture.md`, `g2rd-wpjm-geo/g2rd-wpjm-geo.php` | Modifier | Documentation et version 1.3.0 |

---

### Tâche 1 : `RestController::coordinates()` et `plain()` publique

**Fichiers :**
- Modifier : `g2rd-wpjm-geo/includes/RestController.php` (méthodes `prepare_job()` et `plain()`)
- Test : `g2rd-wpjm-geo/tests/run.php` (nouvelle section 41, avant `$results = $GLOBALS['g2rd_results'];`)

- [ ] **Étape 1 : écrire les tests qui échouent**

Dans `g2rd-wpjm-geo/tests/run.php`, juste avant la ligne `$results = $GLOBALS['g2rd_results'];`, insérer :

```php
echo PHP_EOL . '41. Carte : coordonnées d’une offre' . PHP_EOL;
boot();
G2RD_Test_State::$meta[810] = array( 'geolocation_lat' => '49.44321234', 'geolocation_long' => '1.0999' );
check( 'coordonnées valides : arrondies à 6 décimales', array( 49.443212, 1.0999 ) === RestController::coordinates( 810 ) );
$invalid = array(
	811 => array( '0', '0' ),
	812 => array( '91', '1' ),
	813 => array( '1', '-181' ),
	814 => array( 'abc', '1' ),
	815 => array( '', '' ),
);
$nulls = 0;
foreach ( $invalid as $id => $pair ) {
	G2RD_Test_State::$meta[ $id ] = array( 'geolocation_lat' => $pair[0], 'geolocation_long' => $pair[1] );
	$nulls                       += null === RestController::coordinates( $id ) ? 1 : 0;
}
check( 'coordonnées (0, 0), hors bornes, non numériques ou absentes : null', count( $invalid ) === $nulls );
check( 'texte brut : balises retirées, entités décodées', 'Lille & environs' === RestController::plain( 'Lille &amp; <b>environs</b>' ) && '' === RestController::plain( array( 'x' ) ) );
```

- [ ] **Étape 2 : vérifier qu'ils échouent**

Lancer : `C:/PHP/php.exe g2rd-wpjm-geo/tests/run.php 2>&1 | tail -5`
Attendu : `PHP Fatal error: Uncaught Error: Call to undefined method G2RD\WPJMGeo\RestController::coordinates()`.

- [ ] **Étape 3 : implémenter**

Dans `g2rd-wpjm-geo/includes/RestController.php`, remplacer dans `prepare_job()` :

```php
		$lat = get_post_meta( $id, 'geolocation_lat', true );
		$lng = get_post_meta( $id, 'geolocation_long', true );
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}
		$lat = round( (float) $lat, 6 );
		$lng = round( (float) $lng, 6 );
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
			return null;
		}
```

par :

```php
		$point = self::coordinates( $id );
		if ( ! $point ) {
			return null;
		}
		list( $lat, $lng ) = $point;
```

Puis ajouter, juste avant le docblock de `plain()` (« Texte brut : le client n'insère ces valeurs que via textContent. ») :

```php
	/**
	 * Point de l'offre dans WP Job Manager : coordonnées numériques, dans les bornes, hors (0, 0).
	 * Même règle pour la carte et pour la colonne « Carte » de la liste des offres.
	 *
	 * @param int $id ID de l'offre.
	 * @return array{0:float,1:float}|null Latitude et longitude arrondies à 6 décimales, ou null.
	 */
	public static function coordinates( $id ) {
		$lat = get_post_meta( $id, 'geolocation_lat', true );
		$lng = get_post_meta( $id, 'geolocation_long', true );
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}
		$lat = round( (float) $lat, 6 );
		$lng = round( (float) $lng, 6 );
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
			return null;
		}
		return array( $lat, $lng );
	}
```

Enfin, remplacer `private static function plain( $value ) {` par `public static function plain( $value ) {`.

- [ ] **Étape 4 : vérifier que tout passe**

Lancer : `C:/PHP/php.exe g2rd-wpjm-geo/tests/run.php 2>&1 | grep -E "FAIL|Fatal|Warning|Notice|Deprecated|Résultat"`
Attendu : une seule ligne, `Résultat : 387 réussis, 0 échoués`.

- [ ] **Étape 5 : commit**

```bash
git add g2rd-wpjm-geo/includes/RestController.php g2rd-wpjm-geo/tests/run.php
git commit -m "refactor(carte) : coordonnées d'une offre partagées (RestController::coordinates)"
```

---

### Tâche 2 : `AdminListColumn::state()`

**Fichiers :**
- Créer : `g2rd-wpjm-geo/includes/AdminListColumn.php`
- Modifier : `g2rd-wpjm-geo/tests/bootstrap.php` (ajouter `'AdminListColumn'` après `'AdminLocation'` dans la liste des classes chargées)
- Test : `g2rd-wpjm-geo/tests/run.php` (en-tête `use`, nouvelle section 42)

- [ ] **Étape 1 : écrire les tests qui échouent**

Dans l'en-tête de `g2rd-wpjm-geo/tests/run.php`, après `use G2RD\WPJMGeo\AdminLocation;`, ajouter :

```php
use G2RD\WPJMGeo\AdminListColumn;
```

Juste avant `$results = $GLOBALS['g2rd_results'];`, insérer :

```php
echo PHP_EOL . '42. Liste des offres : colonne « Carte »' . PHP_EOL;
list( $s ) = boot( array( 'provider' => Settings::PROVIDER_OSM ) );
G2RD_Test_State::$meta[800] = array(
	'_job_location'                     => 'Rouen ; Normandie',
	'geolocation_lat'                   => '49.4432',
	'geolocation_long'                  => '1.0999',
	'geolocation_city'                  => 'Rouen',
	GeocodingIntegration::META_SOURCE => 'osm',
	GeocodingIntegration::META_PLACES => array(
		array( 'type' => 'city', 'label' => 'Rouen', 'lat' => '49.4432', 'lng' => '1.0999' ),
		array( 'type' => 'region', 'label' => 'Normandie', 'lat' => '49.1202', 'lng' => '0.1128', 'region' => '28' ),
	),
);
G2RD_Test_State::$meta[801] = array( '_job_location' => 'Paris', 'geolocation_lat' => '48.85', 'geolocation_long' => '2.35' );
G2RD_Test_State::$meta[802] = array( '_job_location' => '  ' );
G2RD_Test_State::$meta[803] = array( '_job_location' => 'Rouen ; Caen' );
G2RD_Test_State::$meta[804] = array( '_job_location' => 'Nulle part', 'geolocation_lat' => '0', 'geolocation_long' => '0', GeocodingIntegration::META_SOURCE => 'osm' );

$st = AdminListColumn::state( 800, Settings::PROVIDER_OSM, array() );
check( 'état : placée en mode libre, villes et régions de la carte', AdminListColumn::PLACED === $st['state'] && array( 'Rouen', 'Normandie' ) === $st['labels'] );
check( 'état : coordonnées écrites par Google en mode libre → introuvable', AdminListColumn::MISSING === AdminListColumn::state( 801, Settings::PROVIDER_OSM, array() )['state'] );
check( 'état : lieu vide', AdminListColumn::EMPTY_LOCATION === AdminListColumn::state( 802, Settings::PROVIDER_OSM, array() )['state'] );
check( 'état : offre dans la file → en attente', AdminListColumn::PENDING === AdminListColumn::state( 803, Settings::PROVIDER_OSM, array( 803 => true ) )['state'] );
check( 'état : hors de la file → introuvable', AdminListColumn::MISSING === AdminListColumn::state( 803, Settings::PROVIDER_OSM, array() )['state'] );
check( 'état : coordonnées (0, 0) → pas placée', AdminListColumn::MISSING === AdminListColumn::state( 804, Settings::PROVIDER_OSM, array() )['state'] );
$st = AdminListColumn::state( 801, Settings::PROVIDER_GOOGLE, array() );
check( 'état, mode Google : coordonnées sans source → placée, libellé du lieu', AdminListColumn::PLACED === $st['state'] && array( 'Paris' ) === $st['labels'] );
check( 'état, mode Google : pas d’état « en attente »', AdminListColumn::MISSING === AdminListColumn::state( 803, Settings::PROVIDER_GOOGLE, array( 803 => true ) )['state'] );
check( 'état, mode Google : offre géocodée par Photon → introuvable', AdminListColumn::MISSING === AdminListColumn::state( 800, Settings::PROVIDER_GOOGLE, array() )['state'] );
```

- [ ] **Étape 2 : vérifier qu'ils échouent**

Lancer : `C:/PHP/php.exe g2rd-wpjm-geo/tests/run.php 2>&1 | tail -5`
Attendu : `Class "G2RD\WPJMGeo\AdminListColumn" not found` (ou un `require_once` en échec pour `includes/AdminListColumn.php`).

- [ ] **Étape 3 : implémenter**

Créer `g2rd-wpjm-geo/includes/AdminListColumn.php` :

```php
<?php
/**
 * Colonne « Carte » de la liste des offres dans wp-admin.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indique, pour chaque offre de edit.php?post_type=job_listing, si ses villes sont placées sur
 * la carte (même règle que l'API de la carte), et sinon pourquoi.
 */
class AdminListColumn {

	const COLUMN       = 'g2rd_wpjm_geo_map';
	const STYLE_HANDLE = 'g2rd-wpjm-geo-admin-list';

	const PLACED         = 'placed';
	const EMPTY_LOCATION = 'empty';
	const PENDING        = 'pending';
	const MISSING        = 'missing';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Offres de la file de géocodage (IDs en clés), lues une fois par affichage.
	 *
	 * @var array<int,true>|null
	 */
	private $queued = null;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * État d'une offre pour la colonne. Placée : coordonnées valides venant du fournisseur
	 * actif (règle de la carte). Sinon, dans l'ordre : lieu vide, en attente (file du mode
	 * libre), introuvable.
	 *
	 * @param int             $job_id   ID de l'offre.
	 * @param string          $provider Fournisseur actif.
	 * @param array<int,true> $queued   Offres de la file de géocodage (IDs en clés).
	 * @return array{state:string,labels:list<string>} Libellés des lieux placés (vide si non placée).
	 */
	public static function state( $job_id, $provider, array $queued ) {
		$is_osm = Settings::PROVIDER_OSM === $provider;
		$source = (string) get_post_meta( $job_id, GeocodingIntegration::META_SOURCE, true );
		$ours   = $is_osm ? Settings::PROVIDER_OSM === $source : ( '' === $source || Settings::PROVIDER_GOOGLE === $source );
		$point  = $ours ? RestController::coordinates( $job_id ) : null;
		if ( $point ) {
			$city   = RestController::plain( get_post_meta( $job_id, 'geolocation_city', true ) );
			$label  = '' !== $city ? $city : RestController::plain( get_post_meta( $job_id, '_job_location', true ) );
			$labels = array();
			foreach ( RestController::places( $job_id, $point[0], $point[1], $label, $is_osm ) as $place ) {
				$name = (string) $place['label'];
				if ( '' !== $name && ! in_array( $name, $labels, true ) ) {
					$labels[] = $name;
				}
			}
			return array(
				'state'  => self::PLACED,
				'labels' => $labels,
			);
		}
		if ( '' === trim( (string) get_post_meta( $job_id, '_job_location', true ) ) ) {
			$state = self::EMPTY_LOCATION;
		} elseif ( $is_osm && isset( $queued[ $job_id ] ) ) {
			$state = self::PENDING;
		} else {
			$state = self::MISSING;
		}
		return array(
			'state'  => $state,
			'labels' => array(),
		);
	}
}
```

- [ ] **Étape 4 : vérifier que tout passe**

Lancer : `C:/PHP/php.exe g2rd-wpjm-geo/tests/run.php 2>&1 | grep -E "FAIL|Fatal|Warning|Notice|Deprecated|Résultat"`
Attendu : `Résultat : 396 réussis, 0 échoués`.

- [ ] **Étape 5 : commit**

```bash
git add g2rd-wpjm-geo/includes/AdminListColumn.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat(admin) : état « sur la carte » d'une offre (AdminListColumn::state)"
```

---

### Tâche 3 : colonne, rendu, feuille de style, enregistrement

**Fichiers :**
- Modifier : `g2rd-wpjm-geo/includes/AdminListColumn.php`
- Créer : `g2rd-wpjm-geo/assets/css/admin-list.css`
- Modifier : `g2rd-wpjm-geo/includes/Plugin.php` (bloc `if ( is_admin() )` de `boot()`)
- Test : `g2rd-wpjm-geo/tests/run.php` (suite de la section 42)

- [ ] **Étape 1 : écrire les tests qui échouent**

À la suite de la section 42 (après le dernier `check` de la tâche 2), ajouter :

```php
update_option( GeocodeQueue::OPTION, array( 803 ) );
G2RD_Test_State::$meta[805] = array(
	'_job_location'                     => 'Caen',
	'geolocation_lat'                   => '49.1829',
	'geolocation_long'                  => '-0.3707',
	GeocodingIntegration::META_SOURCE => 'osm',
	GeocodingIntegration::META_PLACES => array(
		array( 'type' => 'city', 'label' => 'Caen "x" & <b>y</b>', 'lat' => '49.1829', 'lng' => '-0.3707' ),
	),
);
$column = new AdminListColumn( $s );
$column->register();

$c = apply_filters( 'manage_edit-job_listing_columns', array( 'cb' => '', 'job_position' => 'Poste', 'job_location' => 'Lieu', 'job_status' => 'Statut' ) );
check( 'colonne « Carte » insérée juste après le lieu', array( 'cb', 'job_position', 'job_location', AdminListColumn::COLUMN, 'job_status' ) === array_keys( $c ) && 'Carte' === $c[ AdminListColumn::COLUMN ] );
$c = $column->columns( array( 'cb' => '', 'lieu_perso' => 'Lieu du poste' ) );
check( 'sans colonne job_location : « Carte » ajoutée en fin', array( 'cb', 'lieu_perso', AdminListColumn::COLUMN ) === array_keys( $c ) );

$cell = function ( $column_name, $id ) {
	ob_start();
	do_action( 'manage_job_listing_posts_custom_column', $column_name, $id );
	return (string) ob_get_clean();
};
$html = $cell( AdminListColumn::COLUMN, 800 );
check( 'rendu placé : ✓ vert, infobulle et texte pour lecteur d’écran', false !== strpos( $html, 'g2rd-wpjm-geo-mapcol--placed tips' ) && false !== strpos( $html, '<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' ) && false !== strpos( $html, 'data-tip="Placée sur la carte : Rouen, Normandie"' ) && false !== strpos( $html, '<span class="screen-reader-text">Placée sur la carte : Rouen, Normandie</span>' ) );
$html = $cell( AdminListColumn::COLUMN, 803 );
check( 'rendu en attente (offre de la file) : horloge rouge', false !== strpos( $html, 'g2rd-wpjm-geo-mapcol--pending' ) && false !== strpos( $html, 'dashicons-clock' ) && false !== strpos( $html, 'En attente de géocodage' ) );
$html = $cell( AdminListColumn::COLUMN, 802 );
check( 'rendu lieu vide : ✗ rouge', false !== strpos( $html, 'g2rd-wpjm-geo-mapcol--empty' ) && false !== strpos( $html, 'dashicons-dismiss' ) && false !== strpos( $html, 'Lieu non renseigné' ) );
$html = $cell( AdminListColumn::COLUMN, 801 );
check( 'rendu introuvable : ✗ rouge et piste de correction', false !== strpos( $html, 'g2rd-wpjm-geo-mapcol--missing' ) && false !== strpos( $html, 'dashicons-dismiss' ) && false !== strpos( $html, 'Re-géocoder les offres' ) );
$html = $cell( AdminListColumn::COLUMN, 805 );
check( 'rendu : libellé échappé dans l’infobulle et dans le texte', false === strpos( $html, '<b>' ) && 2 === substr_count( $html, 'Caen &quot;x&quot; &amp; y' ) );
check( 'rendu : rien pour une autre colonne', '' === $cell( 'job_status', 800 ) );

$GLOBALS['typenow'] = 'job_listing';
do_action( 'admin_enqueue_scripts', 'edit.php' );
$style = isset( G2RD_Test_State::$enqueued[ 'style:' . AdminListColumn::STYLE_HANDLE ] ) ? G2RD_Test_State::$enqueued[ 'style:' . AdminListColumn::STYLE_HANDLE ] : '';
check( 'feuille de la colonne chargée sur la liste des offres', G2RD_WPJM_GEO_URL . 'assets/css/admin-list.css' === $style );
G2RD_Test_State::$enqueued = array();
do_action( 'admin_enqueue_scripts', 'post.php' );
$GLOBALS['typenow'] = 'post';
do_action( 'admin_enqueue_scripts', 'edit.php' );
check( 'feuille absente des autres écrans (édition d’une offre, liste des articles)', ! isset( G2RD_Test_State::$enqueued[ 'style:' . AdminListColumn::STYLE_HANDLE ] ) );
unset( $GLOBALS['typenow'] );
```

- [ ] **Étape 2 : vérifier qu'ils échouent**

Lancer : `C:/PHP/php.exe g2rd-wpjm-geo/tests/run.php 2>&1 | tail -5`
Attendu : `Call to undefined method G2RD\WPJMGeo\AdminListColumn::register()`.

- [ ] **Étape 3 : implémenter**

Dans `g2rd-wpjm-geo/includes/AdminListColumn.php`, insérer entre le constructeur et `state()` :

```php
	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Priorité 20 : après WP Job Manager (10), pour trouver sa colonne job_location.
		add_filter( 'manage_edit-job_listing_columns', array( $this, 'columns' ), 20 );
		add_action( 'manage_job_listing_posts_custom_column', array( $this, 'render' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Ajoute la colonne juste après le lieu, ou en fin de tableau si une extension a retiré job_location.
	 *
	 * @param mixed $columns Colonnes (clé => titre).
	 * @return array<string,string>
	 */
	public function columns( $columns ) {
		$columns = is_array( $columns ) ? $columns : array();
		$title   = __( 'Carte', 'g2rd-wpjm-geo' );
		if ( ! array_key_exists( 'job_location', $columns ) ) {
			$columns[ self::COLUMN ] = $title;
			return $columns;
		}
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'job_location' === $key ) {
				$out[ self::COLUMN ] = $title;
			}
		}
		return $out;
	}

	/**
	 * Cellule de la colonne : icône (forme et couleur selon l'état), infobulle, texte pour lecteur d'écran.
	 *
	 * @param string $column  Colonne affichée.
	 * @param int    $post_id ID de l'offre.
	 * @return void
	 */
	public function render( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$provider = $this->settings->get_provider();
		$status   = self::state( absint( $post_id ), $provider, Settings::PROVIDER_OSM === $provider ? $this->queued() : array() );
		list( $icon, $text ) = self::describe( $status );
		printf(
			'<span class="g2rd-wpjm-geo-mapcol g2rd-wpjm-geo-mapcol--%1$s tips" data-tip="%2$s"><span class="dashicons %3$s" aria-hidden="true"></span><span class="screen-reader-text">%4$s</span></span>',
			esc_attr( $status['state'] ),
			esc_attr( $text ),
			esc_attr( $icon ),
			esc_html( $text )
		);
	}

	/**
	 * Feuille de la colonne, sur la liste des offres seulement.
	 *
	 * @param string $hook_suffix Écran courant.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		// WordPress renseigne $typenow sur edit.php avec le type de contenu listé.
		$type = isset( $GLOBALS['typenow'] ) ? (string) $GLOBALS['typenow'] : '';
		if ( 'edit.php' !== $hook_suffix || 'job_listing' !== $type ) {
			return;
		}
		$file = G2RD_WPJM_GEO_DIR . 'assets/css/admin-list.css';
		wp_enqueue_style( self::STYLE_HANDLE, G2RD_WPJM_GEO_URL . 'assets/css/admin-list.css', array( 'dashicons' ), file_exists( $file ) ? (string) filemtime( $file ) : G2RD_WPJM_GEO_VERSION );
	}
```

Puis, après `state()` et avant l'accolade fermante de la classe :

```php
	/**
	 * Icône dashicons et texte d'un état.
	 *
	 * @param array{state:string,labels:list<string>} $status Résultat de state().
	 * @return array{0:string,1:string}
	 */
	private static function describe( array $status ) {
		switch ( $status['state'] ) {
			case self::PLACED:
				return array(
					'dashicons-yes-alt',
					$status['labels']
						/* translators: %s: comma-separated place names. */
						? sprintf( __( 'Placée sur la carte : %s', 'g2rd-wpjm-geo' ), implode( ', ', $status['labels'] ) )
						: __( 'Placée sur la carte', 'g2rd-wpjm-geo' ),
				);
			case self::EMPTY_LOCATION:
				return array( 'dashicons-dismiss', __( 'Lieu non renseigné', 'g2rd-wpjm-geo' ) );
			case self::PENDING:
				return array( 'dashicons-clock', __( 'En attente de géocodage', 'g2rd-wpjm-geo' ) );
			default:
				return array( 'dashicons-dismiss', __( 'Ville introuvable : corrigez le lieu ou utilisez « Re-géocoder les offres » dans les réglages du plugin', 'g2rd-wpjm-geo' ) );
		}
	}

	/**
	 * Offres de la file de géocodage, lues une seule fois par affichage de la liste.
	 *
	 * @return array<int,true>
	 */
	private function queued() {
		if ( null === $this->queued ) {
			$ids          = get_option( GeocodeQueue::OPTION, array() );
			$ids          = is_array( $ids ) ? array_filter( array_map( 'absint', $ids ) ) : array();
			$this->queued = $ids ? array_fill_keys( $ids, true ) : array();
		}
		return $this->queued;
	}
```

Créer `g2rd-wpjm-geo/assets/css/admin-list.css` :

```css
/* Colonne « Carte » de la liste des offres (wp-admin/edit.php?post_type=job_listing). */
.wp-list-table .column-g2rd_wpjm_geo_map {
	width: 4em;
	text-align: center;
}

.g2rd-wpjm-geo-mapcol {
	display: inline-block;
	line-height: 1;
}

.g2rd-wpjm-geo-mapcol .dashicons {
	width: 20px;
	height: 20px;
	font-size: 20px;
}

/* Couleurs de l'administration WordPress ; la forme de l'icône porte aussi l'information. */
.g2rd-wpjm-geo-mapcol--placed {
	color: #00a32a;
}

.g2rd-wpjm-geo-mapcol--empty,
.g2rd-wpjm-geo-mapcol--missing,
.g2rd-wpjm-geo-mapcol--pending {
	color: #d63638;
}

/* Écran étroit : la cellule suit le libellé de colonne affiché par WordPress. */
@media screen and (max-width: 782px) {
	.wp-list-table .column-g2rd_wpjm_geo_map {
		width: auto;
		text-align: left;
	}
}
```

Dans `g2rd-wpjm-geo/includes/Plugin.php`, bloc `if ( is_admin() ) {` de `boot()`, après `( new RegeocodeTool( $this->settings, $geocoder ) )->register();`, ajouter :

```php
			( new AdminListColumn( $this->settings ) )->register();
```

- [ ] **Étape 4 : vérifier que tout passe**

Lancer : `C:/PHP/php.exe g2rd-wpjm-geo/tests/run.php 2>&1 | grep -E "FAIL|Fatal|Warning|Notice|Deprecated|Résultat"`
Attendu : `Résultat : 406 réussis, 0 échoués`.

- [ ] **Étape 5 : contrôles qualité**

Lancer (`export PATH="/c/PHP:$PATH"` d'abord sous Git Bash) : `composer lint && composer lint:security && composer analyse`
Attendu : PHPCS sans erreur, puis `[OK] No errors` de PHPStan.

- [ ] **Étape 6 : commit**

```bash
git add g2rd-wpjm-geo/includes/AdminListColumn.php g2rd-wpjm-geo/assets/css/admin-list.css g2rd-wpjm-geo/includes/Plugin.php g2rd-wpjm-geo/tests/run.php
git commit -m "feat(admin) : colonne « Carte » dans la liste des offres"
```

---

### Tâche 4 : contrôle de bout en bout (Docker)

**Fichiers :**
- Modifier : `tools/docker/verify.sh` (fin de la section 10, juste avant `echo "11. Retour vers Google"`)

- [ ] **Étape 1 : ajouter les contrôles**

Juste avant la ligne `echo "11. Retour vers Google"`, insérer :

```bash
JOBE=$(wp post create --post_type=job_listing --post_status=publish --post_title='Poste sans lieu' --porcelain)
L=$(admin 'edit.php?post_type=job_listing')
ok 'liste des offres : colonne « Carte » et sa feuille' "$(has "$L" 'column-g2rd_wpjm_geo_map')$(has "$L" 'assets/css/admin-list.css')"
ok 'liste des offres : offre multi-lieux en vert, avec ses lieux' "$(has "$L" 'g2rd-wpjm-geo-mapcol--placed')$(has "$L" 'sur la carte : Paris, Lyon, Normandie')"
ok 'liste des offres : offre sans lieu en rouge' "$(has "$L" 'g2rd-wpjm-geo-mapcol--empty')"
```

`$JOBE` n'est relu nulle part : la première remise à zéro de `verify.sh` supprime toutes les offres au passage suivant.

- [ ] **Étape 2 : lancer la vérification complète**

Démarrer Docker Desktop si besoin (`"C:\Program Files\Docker\Docker\Docker Desktop.exe"`), puis lancer : `bash tools/docker/verify.sh 2>&1 | tail -25`
Attendu : les trois nouveaux contrôles en `[OK]`, puis `Résultat Docker : 46 réussis, 0 échoués`.

- [ ] **Étape 3 : commit**

```bash
git add tools/docker/verify.sh
git commit -m "test(docker) : colonne « Carte » de la liste des offres"
```

---

### Tâche 5 : documentation et version 1.3.0

**Fichiers :**
- Modifier : `g2rd-wpjm-geo/readme.txt`, `docs/architecture.md`, `docs/index.md`, `README.md`, `g2rd-wpjm-geo/g2rd-wpjm-geo.php`

- [ ] **Étape 1 : documentation**

Dans `g2rd-wpjm-geo/readme.txt`, section `== Description ==`, après la puce qui commence par `* Mode libre : plusieurs lieux par offre`, ajouter :

```text
* Liste des offres dans wp-admin : colonne « Carte », ✓ vert si les villes de l'offre sont placées sur la carte, ✗ rouge si le lieu est vide ou introuvable, horloge rouge si l'offre attend son géocodage ; l'infobulle donne les villes placées ou la raison.
```

Dans `docs/architecture.md`, tableau « Modules », après la ligne `| \`RegeocodeTool\`, \`MigrationTool\` | … |`, ajouter :

```markdown
| `AdminListColumn` | Colonne « Carte » de la liste des offres : même règle que l'API de la carte (`RestController::coordinates()`, source du fournisseur actif), état en attente lu dans la file de géocodage |
```

Dans `docs/index.md`, tableau « Conceptions et plans », après la ligne `| 1.2.2 | … |`, ajouter :

```markdown
| 1.3.0 | Colonne « Carte » dans la liste des offres | [spec](superpowers/specs/2026-11-13-colonne-carte-admin-design.md) | [plan](superpowers/plans/2026-11-13-colonne-carte-admin.md) |
```

Commit :

```bash
git add g2rd-wpjm-geo/readme.txt docs/architecture.md docs/index.md
git commit -m "docs : colonne « Carte » de la liste des offres"
```

- [ ] **Étape 2 : version 1.3.0**

1. Dans `g2rd-wpjm-geo/g2rd-wpjm-geo.php`, remplacer :
   - ` * Version:           1.2.2` par ` * Version:           1.3.0` ;
   - `define( 'G2RD_WPJM_GEO_VERSION', '1.2.2' );` par `define( 'G2RD_WPJM_GEO_VERSION', '1.3.0' );`.
2. Dans `g2rd-wpjm-geo/readme.txt`, remplacer `Stable tag: 1.2.2` par `Stable tag: 1.3.0`. Sous `== Changelog ==`, avant `= 1.2.2 =`, ajouter :

   ```text
   = 1.3.0 =
   * Liste des offres dans wp-admin : colonne « Carte » (✓ vert : villes placées sur la carte ; ✗ rouge : lieu vide ou ville introuvable ; horloge rouge : géocodage en attente), avec les villes ou la raison en infobulle et un texte pour les lecteurs d'écran.
   ```

3. Dans `README.md`, remplacer `Version : **1.2.2**.` par `Version : **1.3.0**.`.

Lancer : `bash tools/verify-release-version.sh 1.3.0`
Attendu : `Toutes les sources sont alignées sur 1.3.0.`

Puis lancer : `C:/PHP/php.exe g2rd-wpjm-geo/tests/run.php 2>&1 | tail -1`
Attendu : `Résultat : 406 réussis, 0 échoués`.

- [ ] **Étape 3 : commit**

```bash
git add g2rd-wpjm-geo/g2rd-wpjm-geo.php g2rd-wpjm-geo/readme.txt README.md
git commit -m "chore(version) : 1.3.0"
```

---

### Tâche 6 : vérification finale

- [ ] **Étape 1 : tout relancer**

Lancer :

```bash
C:/PHP/php.exe g2rd-wpjm-geo/tests/run.php 2>&1 | tail -1
NODE_PATH=tools/jsdom/node_modules node g2rd-wpjm-geo/tests/js/run.js 2>&1 | tail -1
export PATH="/c/PHP:$PATH"; composer lint && composer lint:security && composer analyse
```

Attendu :
- `Résultat : 406 réussis, 0 échoués` ;
- `Resultat JS : 252 reussis, 0 echoues` ;
- PHPCS et PHPStan sans erreur.

- [ ] **Étape 2 : état du dépôt**

Lancer : `git status --short && git log --oneline main..HEAD`
Attendu : arbre propre, et sur la branche les commits de la spec, du plan, des tâches 1 à 5 et de la version.

---

## Écarts constatés pendant l'exécution

- **Chargement des classes en test** : `tests/bootstrap.php` charge les classes depuis une liste explicite. `AdminListColumn` y a été ajoutée à la tâche 2.
- **État `foreign`** (ajouté après la tâche 3). La première capture de la liste a montré un cas trompeur : une offre placée par l'autre fournisseur de carte s'affichait « Ville introuvable ». Cela arrive après un changement de fournisseur, ou en mode libre pour une offre géocodée autrefois par Google. Le nouvel état garde le ✗ rouge, mais son infobulle dit « Coordonnées d'un autre fournisseur de carte ». Il est testé en mode libre et en mode Google, et la spec est à jour. Total des tests PHP : 408.

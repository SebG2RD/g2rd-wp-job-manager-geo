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
	const FOREIGN        = 'foreign';
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
		$provider            = $this->settings->get_provider();
		$status              = self::state( absint( $post_id ), $provider, Settings::PROVIDER_OSM === $provider ? $this->queued() : array() );
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

	/**
	 * État d'une offre pour la colonne. Placée : coordonnées valides venant du fournisseur
	 * actif (règle de la carte). Sinon, dans l'ordre : lieu vide, en attente (file du mode
	 * libre), coordonnées de l'autre fournisseur (absentes de cette carte), introuvable.
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
		$point  = RestController::coordinates( $job_id );
		if ( $point && $ours ) {
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
		} elseif ( $point ) {
			$state = self::FOREIGN;
		} else {
			$state = self::MISSING;
		}
		return array(
			'state'  => $state,
			'labels' => array(),
		);
	}

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
			case self::FOREIGN:
				return array( 'dashicons-dismiss', __( 'Coordonnées d’un autre fournisseur de carte : utilisez « Re-géocoder les offres » dans les réglages du plugin', 'g2rd-wpjm-geo' ) );
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
}

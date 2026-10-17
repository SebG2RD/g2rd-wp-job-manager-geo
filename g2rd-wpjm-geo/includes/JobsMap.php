<?php
/**
 * Carte des offres : shortcode [wpjm_jobs_map] et bloc « Carte des offres d'emploi ».
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Le rendu est identique pour le shortcode et le bloc (bloc dynamique). Les
 * scripts ne sont mis en file qu'au moment du rendu : aucune page sans carte
 * ne charge Google Maps. Indépendant du thème.
 */
class JobsMap {

	const SHORTCODE = 'wpjm_jobs_map';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Compteur d'instances (identifiants uniques).
	 *
	 * @var int
	 */
	private static $instances = 0;

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
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Enregistre le bloc depuis block.json.
	 *
	 * @return void
	 */
	public function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'g2rd-wpjm-geo-block-editor',
			G2RD_WPJM_GEO_URL . 'blocks/jobs-map/index.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			G2RD_WPJM_GEO_VERSION,
			true
		);
		wp_set_script_translations( 'g2rd-wpjm-geo-block-editor', 'g2rd-wpjm-geo', G2RD_WPJM_GEO_DIR . 'languages' );

		register_block_type(
			G2RD_WPJM_GEO_DIR . 'blocks/jobs-map',
			array(
				'render_callback' => array( $this, 'render_block' ),
			)
		);
	}

	/**
	 * Rendu du bloc.
	 *
	 * @param array $attributes Attributs du bloc.
	 * @return string
	 */
	public function render_block( $attributes ) {
		$height = isset( $attributes['height'] ) ? absint( $attributes['height'] ) : 0;
		$extra  = '';
		if ( function_exists( 'get_block_wrapper_attributes' ) ) {
			$extra = get_block_wrapper_attributes();
		}
		return $this->render( $height, $extra );
	}

	/**
	 * Rendu du shortcode.
	 *
	 * @param array|string $atts Attributs.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'height' => 0 ), $atts, self::SHORTCODE );
		return $this->render( absint( $atts['height'] ), '' );
	}

	/**
	 * Rendu commun.
	 *
	 * @param int    $height             Hauteur demandée (0 = réglage par défaut).
	 * @param string $wrapper_attributes Attributs du conteneur générés par Gutenberg (déjà échappés par WordPress).
	 * @return string
	 */
	private function render( $height, $wrapper_attributes ) {
		if ( ! $this->settings->get( 'map_enabled' ) ) {
			return $this->admin_notice( __( 'La carte des offres est désactivée dans les réglages de G2RD WP Job Manager Geo.', 'g2rd-wpjm-geo' ) );
		}
		$is_osm = Settings::PROVIDER_OSM === $this->settings->get_provider();
		if ( ! $is_osm && ! GoogleAssets::has_key( $this->settings ) ) {
			return $this->admin_notice( __( 'Carte des offres : aucune clé « Google Maps Browser API Key » n’est configurée.', 'g2rd-wpjm-geo' ) );
		}
		if ( function_exists( 'job_manager_user_can_browse_job_listings' ) && ! job_manager_user_can_browse_job_listings() ) {
			return '';
		}

		$height = $height ? $height : absint( $this->settings->get( 'map_height' ) );
		$height = min( 1200, max( 200, $height ) );

		wp_enqueue_style( GoogleAssets::HANDLE_STYLE );
		wp_enqueue_script( $is_osm ? OsmAssets::HANDLE_MAP : GoogleAssets::HANDLE_MAP );

		++self::$instances;

		$i18n = array(
			'loading'   => __( 'Chargement de la carte…', 'g2rd-wpjm-geo' ),
			'error'     => __( 'La carte n’a pas pu être chargée. Veuillez réessayer plus tard.', 'g2rd-wpjm-geo' ),
			'empty'     => __( 'Aucune offre géolocalisée à afficher pour le moment.', 'g2rd-wpjm-geo' ),
			'view'      => __( 'Voir l’offre', 'g2rd-wpjm-geo' ),
			/* translators: %d: number of jobs at the same location. */
			'manyJobs'  => __( '%d offres à cet endroit', 'g2rd-wpjm-geo' ),
			/* translators: %d: number of jobs in the cluster. */
			'cluster'   => __( 'Groupe de %d offres : cliquer pour zoomer', 'g2rd-wpjm-geo' ),
			'mapLabel'  => __( 'Carte des offres d’emploi', 'g2rd-wpjm-geo' ),
			'regeocode' => __( 'Re-géocoder les offres', 'g2rd-wpjm-geo' ),
			'places'    => __( 'Liste des offres de la carte', 'g2rd-wpjm-geo' ),
		);

		$attributes = array(
			'id'              => 'g2rd-wpjm-geo-map-' . self::$instances,
			'data-rest-url'   => esc_url_raw( RestController::endpoint_url() ),
			'data-per-page'   => (string) RestController::MAX_PER_PAGE,
			'data-clustering' => $this->settings->get( 'clustering_enabled' ) ? '1' : '0',
			'data-map-id'     => (string) $this->settings->get( 'map_id' ),
			// Lien vers l'outil de re-géocodage dans l'état vide, pour les seuls administrateurs.
			'data-admin-url'  => current_user_can( Settings::CAPABILITY ) ? esc_url_raw( Settings::page_url() . '#g2rd-geo-outils' ) : '',
			// Le nonce n'est fourni qu'aux utilisateurs connectés (pages non mises en cache) : il permet à l'API REST
			// de reconnaître leur session. Les visiteurs anonymes n'en ont pas besoin, même sur une page en cache.
			'data-nonce'      => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'data-i18n'       => wp_json_encode( $i18n ),
		);

		$html = '<div class="g2rd-wpjm-geo-map" style="--g2rd-wpjm-geo-map-height:' . esc_attr( $height ) . 'px"';
		foreach ( $attributes as $name => $value ) {
			$html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}
		$html .= '>';
		$html .= '<div class="g2rd-wpjm-geo-map__canvas" role="region" aria-label="' . esc_attr( $i18n['mapLabel'] ) . '"></div>';
		$html .= '<p class="g2rd-wpjm-geo-map__status" role="status" aria-live="polite">' . esc_html( $i18n['loading'] ) . '</p>';
		$html .= '</div>';

		if ( '' !== $wrapper_attributes ) {
			$html = '<div ' . $wrapper_attributes . '>' . $html . '</div>';
		}
		return $html;
	}

	/**
	 * Message visible uniquement par les administrateurs (jamais par les visiteurs).
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function admin_notice( $message ) {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			return '';
		}
		return '<p class="g2rd-wpjm-geo-map__admin-notice">' . esc_html( $message ) . ' <a href="' . esc_url( Settings::page_url() ) . '">' . esc_html__( 'Ouvrir les réglages', 'g2rd-wpjm-geo' ) . '</a></p>';
	}
}

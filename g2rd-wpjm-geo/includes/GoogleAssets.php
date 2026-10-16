<?php
/**
 * Enregistrement (sans chargement) des scripts et styles du plugin.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Les scripts sont seulement « enregistrés » ici. Ils ne sont mis en file
 * (enqueue) que par les modules qui en ont besoin, sur les pages concernées.
 * Le script Google lui-même n'est injecté par le navigateur qu'au moment utile
 * (focus dans le champ de localisation, carte proche de la zone visible).
 */
class GoogleAssets {

	const HANDLE_LOADER       = 'g2rd-wpjm-geo-google-loader';
	const HANDLE_AUTOCOMPLETE = 'g2rd-wpjm-geo-autocomplete';
	const HANDLE_CLUSTERER    = 'g2rd-wpjm-geo-markerclusterer';
	const HANDLE_MAP          = 'g2rd-wpjm-geo-map';
	const HANDLE_STYLE        = 'g2rd-wpjm-geo-frontend';

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
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'register_assets' ), 5 );
	}

	/**
	 * Enregistre les assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		$version = G2RD_WPJM_GEO_VERSION;
		$base    = G2RD_WPJM_GEO_URL . 'assets/';

		wp_register_style( self::HANDLE_STYLE, $base . 'css/frontend.css', array(), $version );

		wp_register_script( self::HANDLE_LOADER, $base . 'js/google-loader.js', array(), $version, true );
		wp_add_inline_script(
			self::HANDLE_LOADER,
			'window.g2rdWpjmGeoConfig = ' . wp_json_encode( $this->loader_config() ) . ';',
			'before'
		);

		wp_register_script( self::HANDLE_AUTOCOMPLETE, $base . 'js/location-autocomplete.js', array( self::HANDLE_LOADER ), $version, true );
		wp_register_script( self::HANDLE_CLUSTERER, $base . 'vendor/markerclusterer.min.js', array(), '2.5.3', true );

		$map_deps = array( self::HANDLE_LOADER );
		if ( $this->settings->get( 'clustering_enabled' ) ) {
			$map_deps[] = self::HANDLE_CLUSTERER;
		}
		wp_register_script( self::HANDLE_MAP, $base . 'js/jobs-map.js', $map_deps, $version, true );
	}

	/**
	 * Une clé navigateur est-elle configurée ?
	 *
	 * @param Settings $settings Réglages.
	 * @return bool
	 */
	public static function has_key( Settings $settings ) {
		return '' !== (string) $settings->get( 'browser_api_key' );
	}

	/**
	 * Configuration transmise au chargeur. Seule la clé « navigateur » du
	 * plugin est exposée ; la clé de géocodage de WP Job Manager ne l'est jamais.
	 *
	 * @return array
	 */
	private function loader_config() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return array(
			'apiKey'   => (string) $this->settings->get( 'browser_api_key' ),
			// Balise BCP-47 complète (fr-FR, pt-BR, zh-TW) : Google distingue les variantes régionales
			// et retombe seul sur la langue de base s'il ne connaît pas la variante.
			'language' => str_replace( '_', '-', preg_replace( '/[^A-Za-z_]/', '', (string) $locale ) ),
		);
	}
}

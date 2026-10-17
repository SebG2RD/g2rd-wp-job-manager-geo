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
	 * @return array<string,string|array<string,string>>
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

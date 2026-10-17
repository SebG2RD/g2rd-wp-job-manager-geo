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
			$unknown = __( 'un service tiers', 'g2rd-wpjm-geo' );
			$tiles   = (string) wp_parse_url( (string) $this->settings->get( 'osm_style_url' ), PHP_URL_HOST );
			$photon  = (string) wp_parse_url( (string) $this->settings->get( 'photon_url' ), PHP_URL_HOST );
			return sprintf(
				/* translators: 1: map tiles host, 2: Photon host. */
				__( "La carte des offres d'emploi affiche un fond de carte fourni par %1\$s (et les ressources associées à ce style) : pour l'afficher, votre navigateur transmet votre adresse IP à ce service.\n\nLorsque vous saisissez une ville dans le formulaire de dépôt d'une offre, les lettres tapées et votre adresse IP sont transmises au service de recherche %2\$s.\n\nLa localisation des offres est convertie en coordonnées par notre serveur auprès de %2\$s : seuls le texte de la localisation et l'adresse IP de notre serveur lui sont transmis. Données cartographiques © les contributeurs d'OpenStreetMap.", 'g2rd-wpjm-geo' ),
				'' !== $tiles ? $tiles : $unknown,
				'' !== $photon ? $photon : $unknown
			);
		}
		return __( "La carte des offres d'emploi et l'autocomplétion des villes utilisent Google Maps Platform (Google Ireland Limited) : votre navigateur charge des scripts de Google et lui transmet votre adresse IP ainsi que les lettres saisies dans le champ de localisation ; Google peut déposer des cookies.\n\nLa localisation des offres est convertie en coordonnées par notre serveur auprès de Google : seuls le texte de la localisation et l'adresse IP de notre serveur lui sont transmis.", 'g2rd-wpjm-geo' );
	}
}

<?php
/**
 * Vérification des dépendances (WP Job Manager requis, Go Fetch Jobs facultatif).
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Détection des extensions tierces.
 */
class Dependencies {

	/**
	 * Enregistre la notice d'administration.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'maybe_render_notice' ) );
	}

	/**
	 * WP Job Manager est-il actif et exploitable ?
	 *
	 * Ne teste que la classe principale : WP Job Manager ne charge ses
	 * fonctions (get_job_listings()…) que sur `after_setup_theme` priorité 11,
	 * c'est-à-dire après `plugins_loaded`. Les appels à ces fonctions n'ont lieu
	 * que plus tard (rendu, REST) et sont eux-mêmes protégés.
	 *
	 * @return bool
	 */
	public static function has_wpjm() {
		return class_exists( 'WP_Job_Manager', false );
	}

	/**
	 * Go Fetch Jobs est-il actif ?
	 *
	 * Go Fetch Jobs s'initialise sur `plugins_loaded` priorité 998 : cette
	 * méthode doit donc être appelée tardivement (rendu d'écran, import), pas
	 * au chargement du plugin.
	 *
	 * @return bool
	 */
	public static function has_gofetch() {
		return defined( 'GOFT_WPJM_PLUGIN_FILE' ) || function_exists( 'GoFetch_Jobs' ) || class_exists( 'GoFetch_Importer', false );
	}

	/**
	 * Affiche une notice claire si WP Job Manager est absent.
	 *
	 * @return void
	 */
	public function maybe_render_notice() {
		if ( self::has_wpjm() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'G2RD WP Job Manager Geo nécessite l’extension WP Job Manager. Activez WP Job Manager pour utiliser l’autocomplétion des villes, la carte des offres et les outils Go Fetch Jobs. En attendant, toutes les fonctionnalités du plugin sont désactivées.', 'g2rd-wpjm-geo' )
		);
	}
}

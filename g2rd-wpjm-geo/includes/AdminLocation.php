<?php
/**
 * Autocomplétion des villes dans wp-admin (ajout / modification d'une offre).
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dans wp-admin, WP Job Manager affiche le champ `_job_location` (métadonnée
 * du même nom) dans la boîte « Données de l'offre ». Le script n'est chargé
 * que sur les écrans post.php / post-new.php du type `job_listing`.
 */
class AdminLocation extends LocationAutocomplete {

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ), 20 );
		add_filter( 'job_manager_job_listing_data_fields', array( $this, 'admin_help' ), 99 );
	}

	/**
	 * Mode libre : aide de saisie de plusieurs lieux dans l'infobulle du champ (utile sans JavaScript).
	 *
	 * @param mixed $fields Champs de la boîte « Données de l'offre ».
	 * @return mixed
	 */
	public function admin_help( $fields ) {
		if ( is_array( $fields ) && isset( $fields['_job_location'] ) && is_array( $fields['_job_location'] ) && Settings::PROVIDER_OSM === $this->settings->get_provider() ) {
			$fields['_job_location']['description'] = self::with_help( isset( $fields['_job_location']['description'] ) ? $fields['_job_location']['description'] : '' );
		}
		return $fields;
	}

	/**
	 * Charge l'autocomplétion sur l'écran d'édition d'une offre uniquement.
	 *
	 * @param string $hook_suffix Écran courant.
	 * @return void
	 */
	public function enqueue_admin( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'job_listing' !== $screen->post_type ) {
			return;
		}
		/**
		 * Sélecteurs CSS du champ de localisation dans wp-admin.
		 *
		 * @param string[] $selectors Sélecteurs.
		 */
		$selectors = apply_filters( 'g2rd_wpjm_geo_admin_location_selectors', array( '#_job_location', 'input[name="_job_location"]' ) );
		$this->enqueue_autocomplete( $selectors );
	}
}

<?php
/**
 * Aide de saisie de plusieurs lieux dans les champs « Lieu » de WP Job Manager.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mode libre : le serveur découpe toujours les lieux séparés par « ; », que l'autocomplétion
 * soit active ou non. L'aide est donc ajoutée ici, indépendamment d'elle (formulaire public et
 * boîte « Données de l'offre » de wp-admin), et une seule fois.
 */
class LocationHelp {

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hooks (mode libre uniquement : en mode Google, la saisie reste celle d'une seule ville).
	 *
	 * @return void
	 */
	public function register() {
		if ( Settings::PROVIDER_OSM !== $this->settings->get_provider() ) {
			return;
		}
		add_filter( 'submit_job_form_fields', array( $this, 'public_help' ), 99 );
		add_filter( 'job_manager_job_listing_data_fields', array( $this, 'admin_help' ), 99 );
	}

	/**
	 * Formulaire public : aide dans la description du champ `job_location`.
	 *
	 * @param mixed $fields Champs du formulaire, par groupe (job, company).
	 * @return mixed
	 */
	public function public_help( $fields ) {
		if ( is_array( $fields ) && isset( $fields['job']['job_location'] ) && is_array( $fields['job']['job_location'] ) ) {
			$fields['job']['job_location']['description'] = self::with_help( isset( $fields['job']['job_location']['description'] ) ? $fields['job']['job_location']['description'] : '' );
		}
		return $fields;
	}

	/**
	 * Administration : aide dans l'infobulle du champ `_job_location`.
	 *
	 * @param mixed $fields Champs de la boîte « Données de l'offre ».
	 * @return mixed
	 */
	public function admin_help( $fields ) {
		if ( is_array( $fields ) && isset( $fields['_job_location'] ) && is_array( $fields['_job_location'] ) ) {
			$fields['_job_location']['description'] = self::with_help( isset( $fields['_job_location']['description'] ) ? $fields['_job_location']['description'] : '' );
		}
		return $fields;
	}

	/**
	 * Description complétée par l'aide ; un point sépare les deux phrases si la première n'en a pas
	 * (la description de WP Job Manager n'a pas de point final).
	 *
	 * @param mixed $description Description existante.
	 * @return string
	 */
	public static function with_help( $description ) {
		$base = trim( is_string( $description ) ? $description : '' );
		if ( '' !== $base && ! preg_match( '/[.!?…:]$/u', $base ) ) {
			$base .= '.';
		}
		return trim( $base . ' ' . __( 'Séparez plusieurs lieux par « ; ».', 'g2rd-wpjm-geo' ) );
	}
}

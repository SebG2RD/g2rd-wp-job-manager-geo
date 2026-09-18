<?php
/**
 * Autocomplétion des villes sur le formulaire public de WP Job Manager.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enrichit le champ natif `job_location` ; aucun champ n'est ajouté.
 *
 * Points d'ancrage (API publique de WPJM, indépendante du HTML des templates) :
 * - filtre `submit_job_form_fields` : confirme la présence du champ et coupe
 *   l'autocomplétion du navigateur (option `autocomplete` du champ texte WPJM) ;
 * - action `submit_job_form_start` : le formulaire (dépôt ou modification depuis
 *   le tableau de bord employeur) est réellement affiché → on charge le script.
 */
class LocationAutocomplete {

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Le champ de localisation existe-t-il dans le formulaire courant ?
	 *
	 * @var bool
	 */
	private $has_location_field = true;

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
		add_filter( 'submit_job_form_fields', array( $this, 'prepare_field' ), 99 );
		add_action( 'submit_job_form_start', array( $this, 'enqueue' ) );
	}

	/**
	 * Note la présence du champ et désactive l'autocomplétion du navigateur.
	 *
	 * @param array $fields Champs du formulaire.
	 * @return array
	 */
	public function prepare_field( $fields ) {
		$this->has_location_field = isset( $fields['job']['job_location'] );
		if ( $this->has_location_field && GoogleAssets::has_key( $this->settings ) ) {
			$fields['job']['job_location']['autocomplete'] = false;
		}
		return $fields;
	}

	/**
	 * Charge le script uniquement quand le formulaire est affiché.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! $this->has_location_field ) {
			return;
		}
		/**
		 * Sélecteurs CSS du champ de localisation public (le premier trouvé est utilisé).
		 *
		 * @param string[] $selectors Sélecteurs.
		 */
		$selectors = apply_filters( 'g2rd_wpjm_geo_frontend_location_selectors', array( '#job_location', 'input[name="job_location"]' ) );
		$this->enqueue_autocomplete( $selectors );
	}

	/**
	 * Met en file le script d'autocomplétion et sa configuration.
	 *
	 * @param string[] $selectors Sélecteurs du champ natif.
	 * @return void
	 */
	protected function enqueue_autocomplete( $selectors ) {
		if ( ! GoogleAssets::has_key( $this->settings ) ) {
			return;
		}

		wp_enqueue_style( GoogleAssets::HANDLE_STYLE );
		wp_enqueue_script( GoogleAssets::HANDLE_AUTOCOMPLETE );

		$config = array(
			'selectors' => array_values( array_filter( array_map( 'strval', (array) $selectors ) ) ),
			'minLength' => 2,
			'i18n'      => array(
				'poweredBy' => __( 'Propulsé par Google', 'g2rd-wpjm-geo' ),
				'results'   => __( 'Suggestions de villes', 'g2rd-wpjm-geo' ),
			),
		);
		wp_add_inline_script(
			GoogleAssets::HANDLE_AUTOCOMPLETE,
			'window.g2rdWpjmGeoAutocomplete = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}
}

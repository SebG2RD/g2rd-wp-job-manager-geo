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
	 * @param array<string,array<string,array<string,mixed>>> $fields Champs du formulaire, par groupe (job, company).
	 * @return array<string,array<string,array<string,mixed>>>
	 */
	public function prepare_field( $fields ) {
		$this->has_location_field = isset( $fields['job']['job_location'] );
		if ( $this->has_location_field && ( Settings::PROVIDER_OSM === $this->settings->get_provider() || GoogleAssets::has_key( $this->settings ) ) ) {
			$fields['job']['job_location']['autocomplete'] = false;
		}
		if ( $this->has_location_field && Settings::PROVIDER_OSM === $this->settings->get_provider() ) {
			$fields['job']['job_location']['description'] = self::with_help( isset( $fields['job']['job_location']['description'] ) ? $fields['job']['job_location']['description'] : '' );
		}
		return $fields;
	}

	/**
	 * Description du champ complétée par l'aide de saisie de plusieurs lieux (utile sans JavaScript).
	 *
	 * @param mixed $description Description existante.
	 * @return string
	 */
	protected static function with_help( $description ) {
		return trim( ( is_string( $description ) ? $description : '' ) . ' ' . __( 'Séparez plusieurs lieux par « ; ».', 'g2rd-wpjm-geo' ) );
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
		$is_osm = Settings::PROVIDER_OSM === $this->settings->get_provider();
		if ( ! $is_osm && ! GoogleAssets::has_key( $this->settings ) ) {
			return;
		}

		wp_enqueue_style( GoogleAssets::HANDLE_STYLE );
		wp_enqueue_script( GoogleAssets::HANDLE_AUTOCOMPLETE );

		// Langue du site, comme le géocodage serveur : un libellé choisi ici doit être retrouvé par PhotonGeocoder.
		$locale = get_locale();
		$config = array(
			'source'    => $is_osm ? 'photon' : 'google',
			'photonUrl' => $is_osm ? esc_url_raw( (string) $this->settings->get( 'photon_url' ), array( 'https' ) ) : '',
			'lang'      => PhotonGeocoder::language( $locale ),
			'selectors' => array_values( array_filter( array_map( 'strval', (array) $selectors ) ) ),
			'minLength' => 2,
			'i18n'      => array(
				'poweredBy' => $is_osm ? __( 'Recherche © OpenStreetMap contributors', 'g2rd-wpjm-geo' ) : __( 'Propulsé par Google', 'g2rd-wpjm-geo' ),
				'results'   => __( 'Suggestions de villes', 'g2rd-wpjm-geo' ),
			),
		);
		if ( $is_osm ) {
			// Plusieurs lieux (villes et régions françaises) en étiquettes, joints par « ; » dans le champ natif.
			$config['tags']            = true;
			$config['maxPlaces']       = PlaceLocator::MAX_PLACES;
			$config['regions']         = Regions::all();
			$config['i18n']['results'] = __( 'Suggestions de villes et de régions', 'g2rd-wpjm-geo' );
			$config['i18n']['region']  = __( 'Région', 'g2rd-wpjm-geo' );
			$config['tagsI18n']        = array(
				/* translators: %s: place name. */
				'remove'    => __( 'Retirer %s', 'g2rd-wpjm-geo' ),
				/* translators: %s: place name. */
				'added'     => __( 'Lieu ajouté : %s', 'g2rd-wpjm-geo' ),
				/* translators: %s: place name. */
				'removed'   => __( 'Lieu retiré : %s', 'g2rd-wpjm-geo' ),
				/* translators: %s: place name. */
				'duplicate' => __( '%s figure déjà dans la liste.', 'g2rd-wpjm-geo' ),
				/* translators: %d: maximum number of places. */
				'limit'     => sprintf( __( '%d lieux au plus.', 'g2rd-wpjm-geo' ), PlaceLocator::MAX_PLACES ),
				'required'  => __( 'Indiquez au moins un lieu.', 'g2rd-wpjm-geo' ),
			);
		}
		$script = 'window.g2rdWpjmGeoAutocomplete = ' . wp_json_encode( $config ) . ';';
		if ( wp_script_is( GoogleAssets::HANDLE_AUTOCOMPLETE, 'registered' ) ) {
			wp_add_inline_script( GoogleAssets::HANDLE_AUTOCOMPLETE, $script, 'before' );
			return;
		}
		// Thème bloc : le formulaire est rendu avant wp_head, donc avant l'enregistrement des scripts.
		add_action(
			'wp_enqueue_scripts',
			function () use ( $script ) {
				wp_add_inline_script( GoogleAssets::HANDLE_AUTOCOMPLETE, $script, 'before' );
			},
			20
		);
	}
}

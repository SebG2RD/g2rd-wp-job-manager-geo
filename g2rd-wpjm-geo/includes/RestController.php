<?php
/**
 * Endpoint REST des marqueurs : /wp-json/g2rd-wpjm-geo/v1/jobs
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retourne uniquement les données utiles à la carte, paginées et mises en cache.
 *
 * La sélection des offres est déléguée à get_job_listings() de WP Job Manager :
 * offres expirées, postes pourvus masqués, offres protégées par mot de passe,
 * langue (WPML/Polylang) et filtres tiers suivent donc la logique native.
 */
class RestController {

	const REST_NAMESPACE = 'g2rd-wpjm-geo/v1';
	const ROUTE          = '/jobs';
	const MAX_PER_PAGE   = 500;
	const CACHE_TTL      = 900; // 15 min ; WPJM invalide en plus sa « version » à chaque modification d'offre.

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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * URL publique de l'endpoint.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( self::REST_NAMESPACE . self::ROUTE );
	}

	/**
	 * Déclare la route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_jobs' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'minimum'           => 1,
						'maximum'           => 100000,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 250,
						'minimum'           => 1,
						'maximum'           => self::MAX_PER_PAGE,
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);
	}

	/**
	 * Permission : carte activée et droit natif WPJM de parcourir les offres.
	 *
	 * @return bool|\WP_Error
	 */
	public function can_read() {
		if ( ! $this->settings->get( 'map_enabled' ) ) {
			return new \WP_Error( 'g2rd_wpjm_geo_map_disabled', __( 'La carte des offres est désactivée.', 'g2rd-wpjm-geo' ), array( 'status' => 404 ) );
		}
		if ( function_exists( 'job_manager_user_can_browse_job_listings' ) && ! job_manager_user_can_browse_job_listings() ) {
			return new \WP_Error( 'g2rd_wpjm_geo_forbidden', __( 'Vous n’êtes pas autorisé à consulter les offres.', 'g2rd-wpjm-geo' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Callback de la route.
	 *
	 * @param \WP_REST_Request $request Requête.
	 * @return \WP_REST_Response
	 */
	public function get_jobs( $request ) {
		$page     = max( 1, absint( $request->get_param( 'page' ) ) );
		$per_page = min( self::MAX_PER_PAGE, max( 1, absint( $request->get_param( 'per_page' ) ) ) );

		$cache_key = $this->cache_key( $page, $per_page );
		$payload   = $cache_key ? get_transient( $cache_key ) : false;

		if ( ! is_array( $payload ) || ! isset( $payload['jobs'], $payload['total'], $payload['pages'] ) ) {
			$payload = $this->query( $page, $per_page );
			if ( $cache_key ) {
				set_transient( $cache_key, $payload, self::CACHE_TTL );
			}
		}

		$response = rest_ensure_response( $payload['jobs'] );
		$response->header( 'X-WP-Total', (int) $payload['total'] );
		$response->header( 'X-WP-TotalPages', (int) $payload['pages'] );
		return $response;
	}

	/**
	 * Interroge WP Job Manager.
	 *
	 * @param int $page     Page.
	 * @param int $per_page Taille de page.
	 * @return array{jobs:array,total:int,pages:int}
	 */
	private function query( $page, $per_page ) {
		if ( ! function_exists( 'get_job_listings' ) ) {
			return array(
				'jobs'  => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		add_filter( 'get_job_listings_query_args', array( $this, 'require_coordinates' ), 20 );
		$query = get_job_listings(
			array(
				// Statut forcé : même si le site affiche les offres expirées dans ses listes, la carte ne montre que les offres publiées.
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'offset'         => ( $page - 1 ) * $per_page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		remove_filter( 'get_job_listings_query_args', array( $this, 'require_coordinates' ), 20 );

		$ids = array_map( 'absint', (array) $query->posts );
		if ( $ids ) {
			// get_job_listings() désactive les caches de méta/termes : on les amorce en 2 requêtes au lieu de N.
			_prime_post_caches( $ids, true, true );
		}

		$jobs = array();
		foreach ( $ids as $id ) {
			$job = $this->prepare_job( $id );
			if ( $job ) {
				$jobs[] = $job;
			}
		}

		return array(
			'jobs'  => $jobs,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * Restreint la requête native aux offres géocodées par WP Job Manager.
	 *
	 * @param array $query_args Arguments WP_Query.
	 * @return array
	 */
	public function require_coordinates( $query_args ) {
		if ( ! isset( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) {
			$query_args['meta_query'] = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		$query_args['meta_query'][] = array(
			'key'     => 'geolocation_lat',
			'value'   => '',
			'compare' => '!=',
		);
		$query_args['meta_query'][] = array(
			'key'     => 'geolocation_long',
			'value'   => '',
			'compare' => '!=',
		);
		return $query_args;
	}

	/**
	 * Données publiques d'une offre.
	 *
	 * @param int $id ID de l'offre.
	 * @return array|null
	 */
	private function prepare_job( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'job_listing' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return null;
		}
		if ( function_exists( 'job_manager_user_can_view_job_listing' ) && ! job_manager_user_can_view_job_listing( $id ) ) {
			return null;
		}

		$lat = get_post_meta( $id, 'geolocation_lat', true );
		$lng = get_post_meta( $id, 'geolocation_long', true );
		if ( ! is_numeric( $lat ) || ! is_numeric( $lng ) ) {
			return null;
		}
		$lat = round( (float) $lat, 6 );
		$lng = round( (float) $lng, 6 );
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
			return null;
		}

		$types = array();
		if ( get_option( 'job_manager_enable_types' ) && function_exists( 'wpjm_get_the_job_types' ) ) {
			foreach ( (array) wpjm_get_the_job_types( $post ) as $type ) {
				if ( is_object( $type ) && isset( $type->name ) ) {
					$types[] = $this->text( $type->name );
				}
			}
		}

		$job = array(
			'id'        => (int) $id,
			'title'     => $this->text( get_the_title( $post ) ),
			'permalink' => esc_url_raw( get_permalink( $post ), array( 'http', 'https' ) ),
			'company'   => $this->text( function_exists( 'get_the_company_name' ) ? get_the_company_name( $post ) : '' ),
			'location'  => $this->text( get_post_meta( $id, '_job_location', true ) ),
			'city'      => $this->text( get_post_meta( $id, 'geolocation_city', true ) ),
			'country'   => $this->text( get_post_meta( $id, 'geolocation_country_long', true ) ),
			'latitude'  => $lat,
			'longitude' => $lng,
			'job_type'  => implode( ', ', array_filter( $types ) ),
		);

		/**
		 * Filtre les données d'une offre envoyées à la carte.
		 *
		 * @param array    $job  Données publiques.
		 * @param \WP_Post $post Offre.
		 */
		$job = apply_filters( 'g2rd_wpjm_geo_rest_job', $job, $post );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Texte brut : le client n'insère ces valeurs que via textContent.
	 *
	 * @param mixed $value Valeur.
	 * @return string
	 */
	private function text( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Clé de cache, ou chaîne vide si la réponse peut dépendre de l'utilisateur.
	 *
	 * Le suffixe reprend la « version » de transients de WP Job Manager : elle
	 * change à chaque modification d'offre et WPJM purge lui-même les transients
	 * portant l'ancien suffixe.
	 *
	 * @param int $page     Page.
	 * @param int $per_page Taille de page.
	 * @return string
	 */
	private function cache_key( $page, $per_page ) {
		if ( ! class_exists( 'WP_Job_Manager_Cache_Helper' ) ) {
			return '';
		}

		// Seule la réponse « visiteur anonyme » est partagée. Pour un utilisateur connecté, la visibilité peut
		// dépendre de son compte (capacités WPJM, auteur de l'offre, restrictions d'une extension d'adhésion) :
		// on s'appuie alors uniquement sur le cache par utilisateur de get_job_listings().
		if ( is_user_logged_in() ) {
			return '';
		}

		/** This filter is documented in wp-job-manager.php */
		$lang    = apply_filters( 'wpjm_lang', null );
		$version = \WP_Job_Manager_Cache_Helper::get_transient_version( 'get_job_listings' );

		return 'jm_g2rdgeo_' . md5( $page . '|' . $per_page . '|' . (string) $lang . '|' . G2RD_WPJM_GEO_VERSION ) . $version;
	}
}

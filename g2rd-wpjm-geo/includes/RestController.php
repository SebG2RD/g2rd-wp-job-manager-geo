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
		$response->header( 'X-WP-Total', (string) (int) $payload['total'] );
		$response->header( 'X-WP-TotalPages', (string) (int) $payload['pages'] );
		return $response;
	}

	/**
	 * Interroge WP Job Manager.
	 *
	 * @param int $page     Page.
	 * @param int $per_page Taille de page.
	 * @return array{jobs:list<array<string,mixed>>,total:int,pages:int}
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
	 * Restreint la requête native aux offres géocodées par le fournisseur actif.
	 *
	 * @param array<string,mixed> $query_args Arguments WP_Query.
	 * @return array<string,mixed>
	 */
	public function require_coordinates( $query_args ) {
		$ours = array(
			'relation' => 'AND',
			array(
				'key'     => 'geolocation_lat',
				'value'   => '',
				'compare' => '!=',
			),
			array(
				'key'     => 'geolocation_long',
				'value'   => '',
				'compare' => '!=',
			),
			self::source_meta_query( $this->settings->get_provider() ),
		);
		// Groupe à part : un relation OR imposé au premier niveau par une autre extension ne rend pas ces clauses facultatives.
		$query_args['meta_query'] = ( empty( $query_args['meta_query'] ) || ! is_array( $query_args['meta_query'] ) ) // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			? $ours
			: array(
				'relation' => 'AND',
				$query_args['meta_query'],
				$ours,
			);
		return $query_args;
	}

	/**
	 * La carte n'affiche que les offres géocodées par le fournisseur actif : Google exige
	 * que ses résultats de géocodage soient affichés sur une Google Map.
	 *
	 * @param string $provider Fournisseur actif.
	 * @return array<int|string,mixed> Clause meta_query.
	 */
	public static function source_meta_query( $provider ) {
		if ( Settings::PROVIDER_OSM === $provider ) {
			return array(
				'key'     => GeocodingIntegration::META_SOURCE,
				'value'   => Settings::PROVIDER_OSM,
				'compare' => '=',
			);
		}
		return array(
			'relation' => 'OR',
			array(
				'key'     => GeocodingIntegration::META_SOURCE,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => GeocodingIntegration::META_SOURCE,
				'value'   => Settings::PROVIDER_GOOGLE,
				'compare' => '=',
			),
		);
	}

	/**
	 * Données publiques d'une offre.
	 *
	 * @param int $id ID de l'offre.
	 * @return array<string,mixed>|null
	 */
	private function prepare_job( $id ) {
		$post = get_post( $id );
		if ( ! $post || 'job_listing' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return null;
		}
		if ( function_exists( 'job_manager_user_can_view_job_listing' ) && ! job_manager_user_can_view_job_listing( $id ) ) {
			return null;
		}

		$point = self::coordinates( $id );
		if ( ! $point ) {
			return null;
		}
		list( $lat, $lng ) = $point;

		$types = array();
		if ( get_option( 'job_manager_enable_types' ) && function_exists( 'wpjm_get_the_job_types' ) ) {
			foreach ( (array) wpjm_get_the_job_types( $post ) as $type ) {
				if ( is_object( $type ) && isset( $type->name ) ) {
					$types[] = self::plain( $type->name );
				}
			}
		}

		$location = self::plain( get_post_meta( $id, '_job_location', true ) );
		$city     = self::plain( get_post_meta( $id, 'geolocation_city', true ) );
		$job      = array(
			'id'        => (int) $id,
			'title'     => self::plain( get_the_title( $post ) ),
			'permalink' => esc_url_raw( get_permalink( $post ), array( 'http', 'https' ) ),
			'company'   => self::plain( function_exists( 'get_the_company_name' ) ? get_the_company_name( $post ) : '' ),
			'location'  => $location,
			'city'      => $city,
			'country'   => self::plain( get_post_meta( $id, 'geolocation_country_long', true ) ),
			'latitude'  => $lat,
			'longitude' => $lng,
			'job_type'  => implode( ', ', array_filter( $types ) ),
			'places'    => self::places( $id, $lat, $lng, '' !== $city ? $city : $location, Settings::PROVIDER_OSM === $this->settings->get_provider() ),
		);

		/**
		 * Filtre les données d'une offre envoyées à la carte.
		 *
		 * @param array    $job  Données publiques.
		 * @param \WP_Post $post Offre.
		 */
		$job = apply_filters( 'g2rd_wpjm_geo_rest_job', $job, $post );
		// @phpstan-ignore ternary.elseUnreachable (un filtre tiers peut renvoyer autre chose qu'un tableau.)
		return is_array( $job ) ? $job : null;
	}

	/**
	 * Point de l'offre dans WP Job Manager : coordonnées numériques, dans les bornes, hors (0, 0).
	 * Même règle pour la carte et pour la colonne « Carte » de la liste des offres.
	 *
	 * @param int $id ID de l'offre.
	 * @return array{0:float,1:float}|null Latitude et longitude arrondies à 6 décimales, ou null.
	 */
	public static function coordinates( $id ) {
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
		return array( $lat, $lng );
	}

	/**
	 * Texte brut : le client n'insère ces valeurs que via textContent.
	 *
	 * @param mixed $value Valeur.
	 * @return string
	 */
	public static function plain( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return trim( html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Lieux de l'offre pour la carte : la liste du plugin (mode libre), sinon un
	 * seul lieu construit depuis les coordonnées de WP Job Manager.
	 *
	 * @param int    $id       ID de l'offre.
	 * @param float  $lat      Latitude du premier lieu.
	 * @param float  $lng      Longitude du premier lieu.
	 * @param string $label    Libellé du lieu unique (ville, ou texte du lieu).
	 * @param bool   $use_list Lire la liste du plugin (mode libre).
	 * @return list<array<string,string|float>>
	 */
	public static function places( $id, $lat, $lng, $label, $use_list ) {
		$places = array();
		$stored = $use_list ? get_post_meta( $id, GeocodingIntegration::META_PLACES, true ) : array();
		// Liste périmée (coordonnées réécrites hors du plugin) : le point de WP Job Manager fait foi.
		if ( ! is_array( $stored ) || ! isset( $stored[0]['lat'], $stored[0]['lng'] ) || ! is_numeric( $stored[0]['lat'] ) || ! is_numeric( $stored[0]['lng'] ) || round( (float) $stored[0]['lat'], 6 ) !== round( (float) $lat, 6 ) || round( (float) $stored[0]['lng'], 6 ) !== round( (float) $lng, 6 ) ) {
			$stored = array();
		}
		foreach ( array_slice( $stored, 0, PlaceLocator::MAX_PLACES ) as $place ) {
			$clean = self::clean_place( $place );
			if ( $clean ) {
				$places[] = $clean;
			}
		}
		if ( ! $places ) {
			$places[] = array(
				'type'  => 'city',
				'label' => (string) $label,
				'lat'   => (float) $lat,
				'lng'   => (float) $lng,
			);
		}
		return $places;
	}

	/**
	 * Lieu enregistré, revérifié : coordonnées bornées, région ou département connu (nom officiel).
	 *
	 * @param mixed $place Élément de la métadonnée.
	 * @return array<string,string|float>|null
	 */
	private static function clean_place( $place ) {
		if ( ! is_array( $place ) || ! isset( $place['type'], $place['label'], $place['lat'], $place['lng'] ) || ! is_numeric( $place['lat'] ) || ! is_numeric( $place['lng'] ) ) {
			return null;
		}
		$lat = round( (float) $place['lat'], 6 );
		$lng = round( (float) $place['lng'], 6 );
		if ( ! is_finite( $lat ) || ! is_finite( $lng ) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ( 0.0 === $lat && 0.0 === $lng ) ) {
			return null;
		}
		$clean = array(
			'type'  => 'city',
			'label' => self::plain( $place['label'] ),
			'lat'   => $lat,
			'lng'   => $lng,
		);
		if ( 'region' === $place['type'] ) {
			$region = isset( $place['region'] ) && is_scalar( $place['region'] ) ? Regions::get( (string) $place['region'] ) : null;
			if ( ! $region ) {
				return null;
			}
			$clean['type']   = 'region';
			$clean['label']  = $region['name'];
			$clean['region'] = $region['code'];
			$clean['lat']    = (float) $region['lat'];
			$clean['lng']    = (float) $region['lng'];
			// Boîte du contour, tirée de la table du plugin et non des données enregistrées.
			$clean['bbox'] = $region['bbox'];
		} elseif ( 'department' === $place['type'] ) {
			$department = isset( $place['department'] ) && is_scalar( $place['department'] ) ? Departments::get( (string) $place['department'] ) : null;
			if ( ! $department ) {
				return null;
			}
			$clean['type']       = 'department';
			$clean['label']      = $department['name'];
			$clean['department'] = $department['code'];
			$clean['lat']        = (float) $department['lat'];
			$clean['lng']        = (float) $department['lng'];
			$clean['bbox']       = $department['bbox'];
		}
		return $clean;
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

		return 'jm_g2rdgeo_' . md5( $page . '|' . $per_page . '|' . (string) $lang . '|' . $this->settings->get_provider() . '|' . G2RD_WPJM_GEO_VERSION ) . $version;
	}
}

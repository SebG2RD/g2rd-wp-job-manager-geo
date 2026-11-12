<?php
/**
 * Géocodage des offres par la source libre, branché sur WP Job Manager.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mode libre : coupe le géocodage Google de WP Job Manager (filtre officiel) et
 * géocode chaque modification de `_job_location` avec Photon. WP Job Manager
 * déclenche `job_manager_job_location_edited` sur add_post_meta et
 * update_post_meta, AVANT l'écriture : la nouvelle valeur est l'argument.
 *
 * Mode Google : WP Job Manager géocode lui-même ; on oublie seulement une
 * ancienne source « osm » pour que l'offre reste visible sur la carte Google.
 */
class GeocodingIntegration {

	const META_SOURCE = '_g2rd_wpjm_geo_source';

	/**
	 * Lieux de l'offre en mode libre (villes et régions), le premier étant aussi dans les champs de WP Job Manager.
	 */
	const META_PLACES = '_g2rd_wpjm_geo_places';

	/**
	 * Drapeau posé quand Photon ne répond pas : les enregistrements suivants
	 * passent directement par la file, sans attendre le délai réseau.
	 */
	const DOWN_FLAG = 'g2rd_wpjm_geo_photon_down';
	const DOWN_TTL  = 300;

	/**
	 * Temps accordé à Photon pendant un enregistrement (secondes) : tous les lieux de
	 * l'offre sont placés tout de suite, pour qu'ils soient sur la carte dès
	 * l'enregistrement. Au-delà (Photon lent), l'offre passe par la file. Un appel
	 * déjà parti peut encore durer PhotonGeocoder::TIMEOUT : l'enregistrement reste
	 * loin du max_execution_time de PHP.
	 */
	const SAVE_SECONDS = 8;

	/**
	 * Vrai pendant write() : nos propres écritures ne retirent pas la source.
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Placement des lieux (sans pause : saisie interactive).
	 *
	 * @var PlaceLocator
	 */
	private $locator;

	/**
	 * File différée.
	 *
	 * @var GeocodeQueue
	 */
	private $queue;

	/**
	 * Constructeur.
	 *
	 * @param Settings       $settings Réglages.
	 * @param PhotonGeocoder $geocoder Géocodeur.
	 * @param GeocodeQueue   $queue    File différée.
	 * @param callable|null  $clock    Horloge en secondes (tests) ; null : microtime().
	 */
	public function __construct( Settings $settings, PhotonGeocoder $geocoder, GeocodeQueue $queue, $clock = null ) {
		$this->settings = $settings;
		$this->locator  = new PlaceLocator( $geocoder, null, $clock );
		$this->queue    = $queue;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		// Dans les deux modes : des coordonnées écrites par un tiers (WP Job Manager,
		// Go Fetch Jobs, WP All Import) ne sont plus garanties par la source.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_foreign_coords' ), 10, 3 );
			add_action( $hook, array( __CLASS__, 'on_map_meta_changed' ), 10, 3 );
		}
		// Dans les deux modes : « Paris ; Lyon » s'affiche « Paris, Lyon » (fiche, listes, colonne de l'administration).
		add_filter( 'the_job_location', array( __CLASS__, 'display_location' ), 10, 1 );
		if ( Settings::PROVIDER_OSM === $this->settings->get_provider() ) {
			add_filter( 'job_manager_geolocation_enabled', '__return_false', 99 );
			add_action( 'job_manager_job_location_edited', array( $this, 'on_location_edited' ), 20, 2 );
			return;
		}
		add_action( 'job_manager_job_location_edited', array( $this, 'forget_source' ), 30, 1 );
	}

	/**
	 * Mode Google : la position va être recalculée par WP Job Manager.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return void
	 */
	public function forget_source( $job_id ) {
		$job_id = absint( $job_id );
		if ( $job_id ) {
			delete_post_meta( $job_id, self::META_SOURCE );
			delete_post_meta( $job_id, self::META_PLACES );
		}
	}

	/**
	 * Coordonnées écrites hors de write() : la source ne garantit plus leur origine.
	 *
	 * @param int|int[] $meta_id   ID(s) de la métadonnée.
	 * @param int       $object_id ID de l'offre.
	 * @param string    $meta_key  Clé.
	 * @return void
	 */
	public static function on_foreign_coords( $meta_id, $object_id, $meta_key ) {
		$object_id = absint( $object_id );
		if ( ! self::$writing && $object_id && 'geolocation_lat' === $meta_key ) {
			delete_post_meta( $object_id, self::META_SOURCE );
			delete_post_meta( $object_id, self::META_PLACES );
		}
	}

	/**
	 * Source ou coordonnées modifiées : la liste des offres mise en cache par
	 * WP Job Manager est renouvelée une fois, en fin de requête.
	 *
	 * @param int|int[] $meta_id   ID(s) de la métadonnée.
	 * @param int       $object_id ID de l'offre.
	 * @param string    $meta_key  Clé.
	 * @return void
	 */
	public static function on_map_meta_changed( $meta_id, $object_id, $meta_key ) {
		if ( in_array( $meta_key, array( self::META_SOURCE, self::META_PLACES, 'geolocation_lat' ), true ) ) {
			// WordPress n'enregistre qu'une fois le même rappel sur le même hook : un seul renouvellement par requête.
			add_action( 'shutdown', array( __CLASS__, 'bump_listings_cache' ) );
		}
	}

	/**
	 * Renouvelle la version du cache des offres de WP Job Manager (et donc celle de la carte).
	 *
	 * @return void
	 */
	public static function bump_listings_cache() {
		if ( class_exists( 'WP_Job_Manager_Cache_Helper' ) ) {
			\WP_Job_Manager_Cache_Helper::get_transient_version( 'get_job_listings', true );
		}
	}

	/**
	 * Mode libre : géocode la nouvelle localisation.
	 *
	 * @param int   $job_id   ID de l'offre.
	 * @param mixed $location Nouvelle localisation (transmise par le hook de WP Job Manager).
	 * @return void
	 */
	public function on_location_edited( $job_id, $location ) {
		$job_id = absint( $job_id );
		if ( ! $job_id || 'job_listing' !== get_post_type( $job_id ) ) {
			return;
		}
		$location = is_string( $location ) ? trim( $location ) : '';

		if ( '' === $location ) {
			self::write( $job_id, null, Settings::PROVIDER_OSM );
			return;
		}
		if ( self::is_deferred_context() || get_transient( self::DOWN_FLAG ) ) {
			// Les anciennes coordonnées ne correspondent plus : effacées tout de suite, recalculées par la file.
			self::write( $job_id, null, Settings::PROVIDER_OSM );
			$this->queue->push( $job_id );
			return;
		}

		// Tous les lieux sont placés pendant l'enregistrement (un point par ville sur la carte dès l'enregistrement), dans la limite de SAVE_SECONDS.
		$result = $this->locator->locate( $location, PlaceLocator::UNLIMITED, self::SAVE_SECONDS );
		if ( is_wp_error( $result ) ) {
			set_transient( self::DOWN_FLAG, 1, self::DOWN_TTL );
			self::write( $job_id, null, Settings::PROVIDER_OSM );
			$this->queue->push( $job_id );
			return;
		}
		if ( $result['deferred'] ) {
			// Photon trop lent pour finir pendant l'enregistrement : les lieux sont placés par la file.
			self::write( $job_id, null, Settings::PROVIDER_OSM );
			$this->queue->push( $job_id );
			return;
		}
		self::write_places( $job_id, $result );
	}

	/**
	 * Import (Go Fetch Jobs, importeur WordPress), WP-Cron ou WP-CLI : pas d'appel réseau pendant l'enregistrement.
	 *
	 * @return bool
	 */
	public static function is_deferred_context() {
		$deferred = defined( 'GOFJ_IMPORTING' )
			|| ( defined( 'WP_IMPORTING' ) && WP_IMPORTING )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
		/**
		 * Géocodage différé (file WP-Cron) plutôt qu'immédiat.
		 *
		 * @param bool $deferred Contexte d'import, de WP-Cron ou de WP-CLI.
		 */
		return (bool) apply_filters( 'g2rd_wpjm_geo_defer_geocoding', $deferred );
	}

	/**
	 * Remplace les coordonnées d'une offre par les méthodes publiques de WP Job Manager.
	 *
	 * @param int                      $job_id  ID de l'offre.
	 * @param array<string,mixed>|null $address Adresse au format WPJM, ou null pour seulement effacer.
	 * @param string                   $source  Fournisseur ayant produit l'adresse.
	 * @return bool false si WP Job Manager est absent.
	 */
	public static function write( $job_id, $address, $source ) {
		if ( ! class_exists( 'WP_Job_Manager_Geocode' ) ) {
			return false;
		}
		self::$writing = true;
		try {
			\WP_Job_Manager_Geocode::clear_location_data( $job_id );
			delete_post_meta( $job_id, self::META_SOURCE );
			delete_post_meta( $job_id, self::META_PLACES );
			if ( is_array( $address ) && $address ) {
				\WP_Job_Manager_Geocode::save_location_data( $job_id, $address );
				update_post_meta( $job_id, self::META_SOURCE, $source );
			}
		} finally {
			self::$writing = false;
		}
		return true;
	}

	/**
	 * Écrit les lieux d'une offre : le premier dans les champs de WP Job Manager, tous dans META_PLACES.
	 *
	 * @param int                 $job_id ID de l'offre.
	 * @param array<string,mixed> $result Résultat de PlaceLocator::locate().
	 * @return bool false si WP Job Manager est absent.
	 */
	public static function write_places( $job_id, array $result ) {
		$address = ( isset( $result['address'] ) && is_array( $result['address'] ) ) ? $result['address'] : null;
		if ( ! self::write( $job_id, $address, Settings::PROVIDER_OSM ) ) {
			return false;
		}
		if ( $address && ! empty( $result['places'] ) && is_array( $result['places'] ) ) {
			update_post_meta( $job_id, self::META_PLACES, array_values( $result['places'] ) );
		}
		return true;
	}

	/**
	 * Lieu affiché. Quand le texte contient au moins deux lieux séparés par « ; », seul le
	 * nom court de chacun (la partie avant la première virgule) est gardé :
	 * « Rouen, Normandie, France ; Caen, Normandie, France » devient « Rouen, Caen », et
	 * « Paris ; Lyon » devient « Paris, Lyon ». Un lieu seul reste inchangé, comme dans WP Job
	 * Manager. Les « ; » qui terminent une entité HTML ne séparent rien. Le formulaire
	 * d'édition lit la métadonnée directement et garde les « ; » et les libellés complets.
	 *
	 * @param mixed $location Lieu (filtre the_job_location).
	 * @return mixed
	 */
	public static function display_location( $location ) {
		if ( ! is_string( $location ) || false === strpos( $location, ';' ) ) {
			return $location;
		}
		// Les séparateurs deviennent un repère sûr ; les « ; » qui terminent une entité HTML (« &amp; », « &#039; ») restent en place.
		$marked = preg_replace_callback(
			'/&(?:#[0-9]+|#x[0-9a-f]+|[a-z][a-z0-9]*);|(?:;[\s\x{00A0}\x{202F}]*)+/iu',
			function ( $found ) {
				return '&' === $found[0][0] ? $found[0] : chr( 1 );
			},
			str_replace( chr( 1 ), '', $location )
		);
		if ( null === $marked ) {
			return $location;
		}
		$places = array();
		foreach ( explode( chr( 1 ), $marked ) as $place ) {
			$place = (string) preg_replace( '/^[\s\x{00A0}\x{202F}]+|[\s\x{00A0}\x{202F}]+$/u', '', $place );
			if ( '' !== $place ) {
				$places[] = $place;
			}
		}
		if ( count( $places ) < 2 ) {
			// Un seul lieu (« ; » de bord écartés) : texte entier ; aucun lieu : chaîne vide.
			return $places ? $places[0] : '';
		}
		$names = array();
		foreach ( $places as $place ) {
			$comma = strpos( $place, ',' );
			$name  = (string) preg_replace( '/^[\s\x{00A0}\x{202F}]+|[\s\x{00A0}\x{202F}]+$/u', '', false === $comma ? $place : substr( $place, 0, $comma ) );
			if ( '' !== $name ) {
				$names[] = $name;
			}
		}
		return $names ? implode( ', ', $names ) : '';
	}
}

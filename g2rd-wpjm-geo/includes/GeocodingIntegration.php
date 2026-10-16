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
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Géocodeur.
	 *
	 * @var PhotonGeocoder
	 */
	private $geocoder;

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
	 */
	public function __construct( Settings $settings, PhotonGeocoder $geocoder, GeocodeQueue $queue ) {
		$this->settings = $settings;
		$this->geocoder = $geocoder;
		$this->queue    = $queue;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
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
		}
	}

	/**
	 * Mode libre : géocode la nouvelle localisation.
	 *
	 * @param int    $job_id   ID de l'offre.
	 * @param string $location Nouvelle localisation.
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
		if ( self::is_deferred_context() ) {
			// Les anciennes coordonnées ne correspondent plus : effacées tout de suite, recalculées par la file.
			self::write( $job_id, null, Settings::PROVIDER_OSM );
			$this->queue->push( $job_id );
			return;
		}

		$result = $this->geocoder->geocode( $location );
		if ( is_wp_error( $result ) ) {
			self::write( $job_id, null, Settings::PROVIDER_OSM );
			$this->queue->push( $job_id );
			return;
		}
		self::write( $job_id, $result, Settings::PROVIDER_OSM );
	}

	/**
	 * Import Go Fetch Jobs, WP-Cron ou WP-CLI : pas d'appel réseau pendant l'enregistrement.
	 *
	 * @return bool
	 */
	public static function is_deferred_context() {
		return defined( 'GOFJ_IMPORTING' )
			|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
			|| ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/**
	 * Remplace les coordonnées d'une offre par les méthodes publiques de WP Job Manager.
	 *
	 * @param int        $job_id  ID de l'offre.
	 * @param array|null $address Adresse au format WPJM, ou null pour seulement effacer.
	 * @param string     $source  Fournisseur ayant produit l'adresse.
	 * @return bool false si WP Job Manager est absent.
	 */
	public static function write( $job_id, $address, $source ) {
		if ( ! class_exists( 'WP_Job_Manager_Geocode' ) ) {
			return false;
		}
		\WP_Job_Manager_Geocode::clear_location_data( $job_id );
		delete_post_meta( $job_id, self::META_SOURCE );
		if ( is_array( $address ) && $address ) {
			\WP_Job_Manager_Geocode::save_location_data( $job_id, $address );
			update_post_meta( $job_id, self::META_SOURCE, $source );
		}
		return true;
	}
}

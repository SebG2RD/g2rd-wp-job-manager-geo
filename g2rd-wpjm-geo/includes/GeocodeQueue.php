<?php
/**
 * File WP-Cron des géocodages différés (imports, cron, WP-CLI).
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lot de 10 offres, une seconde de pause entre deux appels réels à Photon
 * (usage raisonnable de l'instance publique).
 */
class GeocodeQueue extends JobQueue {

	const OPTION     = 'g2rd_wpjm_geo_geocode_queue';
	const CRON_HOOK  = 'g2rd_wpjm_geo_geocode';
	const LOCK       = 'g2rd_wpjm_geo_geocode_lock';
	const BATCH_SIZE = 10;

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
	 * Fonction de pause (remplaçable dans les tests).
	 *
	 * @var callable
	 */
	private $sleeper;

	/**
	 * Constructeur.
	 *
	 * @param Settings       $settings Réglages.
	 * @param PhotonGeocoder $geocoder Géocodeur.
	 * @param callable|null  $sleeper  Pause en secondes ; défaut sleep().
	 */
	public function __construct( Settings $settings, PhotonGeocoder $geocoder, $sleeper = null ) {
		$this->settings = $settings;
		$this->geocoder = $geocoder;
		$this->sleeper  = is_callable( $sleeper ) ? $sleeper : 'sleep';
	}

	/**
	 * Géocode une offre de la file.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return bool false si Photon est indisponible.
	 */
	protected function process_item( $job_id ) {
		if ( Settings::PROVIDER_OSM !== $this->settings->get_provider() || 'job_listing' !== get_post_type( $job_id ) ) {
			return true;
		}
		$location = trim( (string) get_post_meta( $job_id, '_job_location', true ) );
		if ( '' === $location ) {
			GeocodingIntegration::write( $job_id, null, Settings::PROVIDER_OSM );
			return true;
		}

		$result = $this->geocoder->geocode( $location );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		GeocodingIntegration::write( $job_id, $result, Settings::PROVIDER_OSM );
		if ( ! $this->geocoder->last_was_cached() ) {
			call_user_func( $this->sleeper, 1 );
		}
		return true;
	}
}

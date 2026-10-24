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
 * Lot de 10 offres ; tous les lieux de chaque offre sont placés, avec une
 * seconde de pause après chaque appel réel à Photon (usage raisonnable de
 * l'instance publique).
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
	 * Placement des lieux.
	 *
	 * @var PlaceLocator
	 */
	private $locator;

	/**
	 * Constructeur.
	 *
	 * @param Settings       $settings Réglages.
	 * @param PhotonGeocoder $geocoder Géocodeur.
	 * @param callable|null  $sleeper  Pause en secondes ; défaut sleep().
	 */
	public function __construct( Settings $settings, PhotonGeocoder $geocoder, $sleeper = null ) {
		$this->settings = $settings;
		$this->locator  = new PlaceLocator( $geocoder, is_callable( $sleeper ) ? $sleeper : 'sleep' );
	}

	/**
	 * Place les lieux d'une offre de la file.
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

		$result = $this->locator->locate( $location );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		// Lieu modifié pendant le calcul : la nouvelle saisie a déjà placé l'offre ou l'a remise en file.
		wp_cache_delete( $job_id, 'post_meta' );
		if ( trim( (string) get_post_meta( $job_id, '_job_location', true ) ) !== $location ) {
			return true;
		}
		GeocodingIntegration::write_places( $job_id, $result );
		return true;
	}
}

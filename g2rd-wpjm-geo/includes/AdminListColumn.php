<?php
/**
 * Colonne « Carte » de la liste des offres dans wp-admin.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indique, pour chaque offre de edit.php?post_type=job_listing, si ses villes sont placées sur
 * la carte (même règle que l'API de la carte), et sinon pourquoi.
 */
class AdminListColumn {

	const COLUMN       = 'g2rd_wpjm_geo_map';
	const STYLE_HANDLE = 'g2rd-wpjm-geo-admin-list';

	const PLACED         = 'placed';
	const EMPTY_LOCATION = 'empty';
	const PENDING        = 'pending';
	const MISSING        = 'missing';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Offres de la file de géocodage (IDs en clés), lues une fois par affichage.
	 *
	 * @var array<int,true>|null
	 */
	private $queued = null;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * État d'une offre pour la colonne. Placée : coordonnées valides venant du fournisseur
	 * actif (règle de la carte). Sinon, dans l'ordre : lieu vide, en attente (file du mode
	 * libre), introuvable.
	 *
	 * @param int             $job_id   ID de l'offre.
	 * @param string          $provider Fournisseur actif.
	 * @param array<int,true> $queued   Offres de la file de géocodage (IDs en clés).
	 * @return array{state:string,labels:list<string>} Libellés des lieux placés (vide si non placée).
	 */
	public static function state( $job_id, $provider, array $queued ) {
		$is_osm = Settings::PROVIDER_OSM === $provider;
		$source = (string) get_post_meta( $job_id, GeocodingIntegration::META_SOURCE, true );
		$ours   = $is_osm ? Settings::PROVIDER_OSM === $source : ( '' === $source || Settings::PROVIDER_GOOGLE === $source );
		$point  = $ours ? RestController::coordinates( $job_id ) : null;
		if ( $point ) {
			$city   = RestController::plain( get_post_meta( $job_id, 'geolocation_city', true ) );
			$label  = '' !== $city ? $city : RestController::plain( get_post_meta( $job_id, '_job_location', true ) );
			$labels = array();
			foreach ( RestController::places( $job_id, $point[0], $point[1], $label, $is_osm ) as $place ) {
				$name = (string) $place['label'];
				if ( '' !== $name && ! in_array( $name, $labels, true ) ) {
					$labels[] = $name;
				}
			}
			return array(
				'state'  => self::PLACED,
				'labels' => $labels,
			);
		}
		if ( '' === trim( (string) get_post_meta( $job_id, '_job_location', true ) ) ) {
			$state = self::EMPTY_LOCATION;
		} elseif ( $is_osm && isset( $queued[ $job_id ] ) ) {
			$state = self::PENDING;
		} else {
			$state = self::MISSING;
		}
		return array(
			'state'  => $state,
			'labels' => array(),
		);
	}
}

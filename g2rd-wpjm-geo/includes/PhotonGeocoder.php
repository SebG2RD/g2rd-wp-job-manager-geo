<?php
/**
 * Géocodage serveur avec Photon (OpenStreetMap).
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transforme une localisation saisie en tableau d'adresse au format de
 * WP Job Manager (clés attendues par WP_Job_Manager_Geocode::save_location_data()).
 *
 * Seuls les lieux (place=*) et les limites administratives sont acceptés, et
 * seulement si leur nom correspond au premier segment du texte : une offre
 * reste sans coordonnées plutôt que d'être placée sur un homonyme lointain
 * (« IDF » donne Idfu, en Égypte).
 */
class PhotonGeocoder {

	const CACHE_PREFIX = 'g2rd_geo_ph_';
	const CACHE_TTL    = 2592000; // 30 jours.
	const MISS_TTL     = 86400;   // 1 jour.
	const TIMEOUT      = 5;

	/**
	 * Valeurs osm_value d'un lieu dont le nom est celui de la ville.
	 */
	const CITY_VALUES = array( 'city', 'town', 'village', 'hamlet', 'municipality', 'suburb', 'locality' );

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Le dernier résultat venait-il du cache ?
	 *
	 * @var bool
	 */
	private $last_was_cached = false;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Langue acceptée par l'instance publique de Photon (autre valeur : HTTP 400).
	 *
	 * @param string $locale Locale WordPress.
	 * @return string fr, en, de ou default.
	 */
	public static function language( $locale ) {
		$lang = strtolower( substr( (string) $locale, 0, 2 ) );
		return in_array( $lang, array( 'fr', 'en', 'de' ), true ) ? $lang : 'default';
	}

	/**
	 * Retire parenthèses, nombres isolés et séparateurs de bord.
	 *
	 * @param string $text Localisation saisie.
	 * @return string
	 */
	public static function clean( $text ) {
		$text = preg_replace( '/\([^)]*\)/u', ' ', (string) $text );
		$text = preg_replace( '/(?<![\p{L}\p{N}])\p{N}+(?![\p{L}\p{N}])/u', ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return (string) preg_replace( '/^[\s,;\/\-–—]+|[\s,;\/\-–—]+$/u', '', $text );
	}

	/**
	 * Forme de comparaison : sans accents, minuscules, ponctuation remplacée par une espace.
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = strtolower( remove_accents( (string) $text ) );
		return trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', $text ) );
	}

	/**
	 * Le dernier appel à geocode() a-t-il été servi par le cache ?
	 *
	 * @return bool
	 */
	public function last_was_cached() {
		return $this->last_was_cached;
	}

	/**
	 * Géocode une localisation.
	 *
	 * @param string $text Localisation saisie.
	 * @return array|null|\WP_Error Adresse au format WPJM, null si introuvable, WP_Error si le service est indisponible.
	 */
	public function geocode( $text ) {
		$this->last_was_cached = false;
		$cleaned               = self::clean( $text );
		if ( '' === $cleaned ) {
			return null;
		}

		$lang   = self::language( function_exists( 'determine_locale' ) ? determine_locale() : get_locale() );
		$key    = self::CACHE_PREFIX . md5( $lang . '|' . self::normalize( $cleaned ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'r', $cached ) ) {
			$this->last_was_cached = true;
			return $cached['r'];
		}

		$features = $this->request( $cleaned, $lang, true );
		if ( is_wp_error( $features ) && 'g2rd_photon_bad_request' === $features->get_error_code() ) {
			$features = $this->request( $cleaned, $lang, false );
		}
		if ( is_wp_error( $features ) ) {
			return $features;
		}

		$feature = self::pick( $features, $cleaned );
		$address = $feature ? self::to_address( $feature ) : null;
		set_transient( $key, array( 'r' => $address ), $address ? self::CACHE_TTL : self::MISS_TTL );
		return $address;
	}

	/**
	 * Premier résultat dont le nom correspond au premier segment du texte.
	 *
	 * @param array  $features Entités GeoJSON renvoyées par Photon.
	 * @param string $cleaned  Texte nettoyé.
	 * @return array|null
	 */
	public static function pick( array $features, $cleaned ) {
		$parts  = explode( ',', (string) $cleaned );
		$wanted = self::normalize( $parts[0] );
		if ( '' === $wanted ) {
			return null;
		}
		foreach ( $features as $feature ) {
			$name = ( isset( $feature['properties']['name'] ) && is_string( $feature['properties']['name'] ) ) ? $feature['properties']['name'] : '';
			if ( '' !== $name && self::normalize( $name ) === $wanted ) {
				return $feature;
			}
		}
		return null;
	}

	/**
	 * Convertit une entité Photon au format de WP Job Manager.
	 *
	 * @param array $feature Entité GeoJSON.
	 * @return array|null null si les coordonnées sont absentes ou hors bornes.
	 */
	public static function to_address( array $feature ) {
		$coords = isset( $feature['geometry']['coordinates'] ) ? $feature['geometry']['coordinates'] : null;
		if ( ! is_array( $coords ) || count( $coords ) < 2 || ! is_numeric( $coords[0] ) || ! is_numeric( $coords[1] ) ) {
			return null;
		}
		$lng = (float) $coords[0];
		$lat = (float) $coords[1];
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}

		$props = ( isset( $feature['properties'] ) && is_array( $feature['properties'] ) ) ? $feature['properties'] : array();
		$text  = function ( $key ) use ( $props ) {
			return ( isset( $props[ $key ] ) && is_scalar( $props[ $key ] ) ) ? sanitize_text_field( (string) $props[ $key ] ) : '';
		};

		$name    = $text( 'name' );
		$state   = $text( 'state' );
		$country = $text( 'country' );
		$is_city = 'place' === $text( 'osm_key' ) && in_array( $text( 'osm_value' ), self::CITY_VALUES, true );

		return array(
			'lat'               => (string) round( $lat, 7 ),
			'long'              => (string) round( $lng, 7 ),
			'formatted_address' => implode( ', ', array_values( array_unique( array_filter( array( $name, $state, $country ) ) ) ) ),
			'city'              => $is_city ? $name : $text( 'city' ),
			'state_long'        => $state,
			'postcode'          => $text( 'postcode' ),
			'country_long'      => $country,
			'country_short'     => strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', $text( 'countrycode' ) ), 0, 2 ) ),
		);
	}

	/**
	 * Appelle Photon.
	 *
	 * @param string $query    Texte nettoyé.
	 * @param string $lang     Langue Photon.
	 * @param bool   $filtered Restreindre aux lieux et limites administratives.
	 * @return array|\WP_Error Entités, ou erreur (code g2rd_photon_bad_request pour un HTTP 400 avec filtre).
	 */
	private function request( $query, $lang, $filtered ) {
		$base = (string) $this->settings->get( 'photon_url' );
		$url  = $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . http_build_query(
			array(
				'q'     => $query,
				'limit' => 5,
				'lang'  => $lang,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		if ( $filtered ) {
			$url .= '&osm_tag=place&osm_tag=' . rawurlencode( 'boundary:administrative' );
		}

		$allow    = $this->allow_private_host( $base );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				'user-agent'  => 'G2RD-WPJM-Geo/' . G2RD_WPJM_GEO_VERSION . ' (+' . home_url( '/' ) . ')',
			)
		);
		if ( $allow ) {
			remove_filter( 'http_request_host_is_external', $allow, 10 );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 400 === $code && $filtered ) {
			return new \WP_Error( 'g2rd_photon_bad_request', 'Photon : HTTP 400' );
		}
		if ( 400 === $code ) {
			// Refusé même sans filtre : propre à ce texte, traité comme introuvable
			// (sinon l'offre bloquerait la file de géocodage à chaque passage).
			return array();
		}
		if ( 200 !== $code ) {
			return new \WP_Error( 'g2rd_photon_http', 'Photon : HTTP ' . $code );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['features'] ) || ! is_array( $data['features'] ) ) {
			return new \WP_Error( 'g2rd_photon_invalid', 'Photon : réponse illisible' );
		}
		if ( $filtered ) {
			return $data['features'];
		}
		return array_values(
			array_filter(
				$data['features'],
				function ( $feature ) {
					$key = isset( $feature['properties']['osm_key'] ) ? $feature['properties']['osm_key'] : '';
					return in_array( $key, array( 'place', 'boundary' ), true );
				}
			)
		);
	}

	/**
	 * Autorise, le temps d'une requête, l'hôte d'un Photon auto-hébergé à une adresse privée.
	 *
	 * @param string $base URL Photon configurée.
	 * @return callable|null Callback à retirer après la requête.
	 */
	private function allow_private_host( $base ) {
		/**
		 * Autorise un serveur Photon auto-hébergé à une adresse privée (même serveur, réseau local).
		 * Seul l'hôte de l'URL Photon configurée est autorisé.
		 *
		 * @param bool $allow Défaut false.
		 */
		if ( ! apply_filters( 'g2rd_wpjm_geo_photon_allow_private_host', false ) ) {
			return null;
		}
		$photon_host = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
		$callback    = function ( $external, $host ) use ( $photon_host ) {
			return ( '' !== $photon_host && strtolower( (string) $host ) === $photon_host ) ? true : $external;
		};
		add_filter( 'http_request_host_is_external', $callback, 10, 2 );
		return $callback;
	}
}

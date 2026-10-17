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
 * (« IDF » donne Idfu, en Égypte). Entre plusieurs lieux du même nom, le
 * choix favorise le département ou code postal saisi (« Laval (53) »), puis
 * les autres segments du texte (« Valence, Espagne »), puis le pays du site.
 */
class PhotonGeocoder {

	const CACHE_PREFIX = 'g2rd_geo_ph_';
	const CACHE_TTL    = 2592000; // 30 jours.
	const MISS_TTL     = 86400;   // 1 jour.
	const TIMEOUT      = 5;
	const MAX_LENGTH   = 200;
	const MAX_RESPONSE = 262144;  // 256 Kio.

	const CACHE_VERSION = '2'; // À incrémenter quand le choix du lieu change (résultats gardés 30 jours).

	/**
	 * Valeurs osm_value d'un lieu dont le nom est celui de la ville.
	 */
	const CITY_VALUES = array( 'city', 'town', 'village', 'hamlet', 'municipality', 'suburb', 'locality' );

	/**
	 * Lieux rattachés à une commune : la ville est celle de Photon (properties.city) quand elle est connue.
	 */
	const SUB_CITY_VALUES = array( 'hamlet', 'suburb', 'locality' );

	/**
	 * Lieux qui reçoivent le bonus du pays du site : ni les villages ni les
	 * lieux-dits (« Moscou », « Montréal » dans l'Aude), qui l'emporteraient
	 * sinon sur la grande ville étrangère du même nom.
	 */
	const PREFERRED_VALUES = array( 'city', 'town', 'municipality', 'administrative' );

	/**
	 * Pays des codes postaux français (métropole et outre-mer).
	 */
	const FRENCH_COUNTRIES = array( 'FR', 'RE', 'GP', 'MQ', 'GF', 'YT', 'PM', 'BL', 'MF' );

	/**
	 * Localisations sans lieu précis (forme normalisée) : jamais envoyées à Photon.
	 */
	const VAGUE = array( 'remote', 'full remote', 'teletravail', 'a distance', 'hybride' );

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Le dernier appel s'est-il passé de Photon ?
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
	 * Retire parenthèses, numéros d'arrondissement, nombres isolés et séparateurs de bord.
	 *
	 * @param string $text Localisation saisie.
	 * @return string
	 */
	public static function clean( $text ) {
		$text = wp_check_invalid_utf8( (string) $text );
		$text = preg_replace( '/\([^)]*\)/u', ' ', $text );
		// « Paris 15e », « Lyon 1er », « Marseille 13e arrondissement ».
		$text = preg_replace( '/(?<![\p{L}\p{N}])\p{N}+\s*(?:er|re|ère|ème|eme|e|è)(?:\s+arrondissement)?(?![\p{L}\p{N}])/iu', ' ', $text );
		$text = preg_replace( '/(?<![\p{L}\p{N}])\p{N}+(?![\p{L}\p{N}])/u', ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return self::truncate( (string) preg_replace( '/^[\s,;\/\-–—]+|[\s,;\/\-–—]+$/u', '', $text ) );
	}

	/**
	 * Forme de comparaison : sans accents, minuscules, ponctuation remplacée par
	 * une espace, « st » et « ste » lus « saint » et « sainte ».
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	public static function normalize( $text ) {
		// Les accents combinants (texte décomposé, sans l'extension intl) sont retirés à part.
		$text = (string) preg_replace( '/\p{Mn}+/u', '', remove_accents( (string) $text ) );
		$text = trim( (string) preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $text ) ) );
		return (string) preg_replace( array( '/\bst\b/', '/\bste\b/' ), array( 'saint', 'sainte' ), $text );
	}

	/**
	 * Département ou code postal français indiqué dans le texte.
	 *
	 * « Laval (53) » → 53, « 75008 Paris » → 75, « Ajaccio (2A) » → 20,
	 * « Saint-Denis (974) » → 974.
	 *
	 * @param string $text Localisation saisie.
	 * @return string Préfixe de code postal, ou chaîne vide.
	 */
	public static function hint( $text ) {
		if ( ! preg_match( '/\(\s*(\d{2}|97\d|2[AB])\s*\)|(?<!\d)(\d{5})(?!\d)/i', (string) $text, $m ) ) {
			return '';
		}
		$code = ! empty( $m[2] ) ? $m[2] : strtoupper( $m[1] );
		if ( in_array( substr( $code, 0, 2 ), array( '2A', '2B' ), true ) ) {
			return '20';
		}
		return '97' === substr( $code, 0, 2 ) ? substr( $code, 0, 3 ) : substr( $code, 0, 2 );
	}

	/**
	 * Pays favorisé entre deux lieux du même nom : celui de la langue du site.
	 *
	 * @return string Code ISO à deux lettres, ou chaîne vide.
	 */
	public static function preferred_country() {
		$country = preg_match( '/^[a-z]{2,3}_([A-Z]{2})/', (string) get_locale(), $m ) ? $m[1] : '';
		/**
		 * Pays (code ISO à deux lettres) favorisé quand plusieurs lieux portent le nom saisi.
		 *
		 * @param string $country Défaut : région de la langue du site (FR pour fr_FR), vide sinon.
		 */
		$country = (string) apply_filters( 'g2rd_wpjm_geo_photon_preferred_country', $country );
		return strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', $country ), 0, 2 ) );
	}

	/**
	 * Le dernier appel à geocode() s'est-il passé de Photon (cache, ou texte
	 * qui ne désigne aucun lieu) ? Sert à ne marquer une pause qu'après un vrai appel.
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
		$this->last_was_cached = true;
		$cleaned               = self::clean( $text );
		$segments              = array_map( array( __CLASS__, 'normalize' ), explode( ',', $cleaned ) );
		if ( '' === $segments[0] || in_array( $segments[0], self::VAGUE, true ) ) {
			// Rien de comparable (vide, écriture non latine) ou pas de lieu précis : inutile d'appeler Photon.
			return null;
		}

		$base   = $this->base_url();
		$hint   = self::hint( $text );
		$lang   = self::language( get_locale() );
		$key    = self::CACHE_PREFIX . md5( self::CACHE_VERSION . '|' . $base . '|' . $lang . '|' . self::preferred_country() . '|' . implode( ',', $segments ) . '|' . $hint );
		$cached = get_transient( $key );
		if ( is_array( $cached ) && array_key_exists( 'r', $cached ) ) {
			return $cached['r'];
		}

		$this->last_was_cached = false;
		$features              = $this->request( $base, $cleaned, $lang, true );
		if ( is_wp_error( $features ) && 'g2rd_photon_bad_request' === $features->get_error_code() ) {
			$features = $this->request( $base, $cleaned, $lang, false );
		}
		if ( is_wp_error( $features ) ) {
			return $features;
		}

		$feature = self::pick( $features, $cleaned, $hint );
		$address = $feature ? self::to_address( $feature ) : null;
		set_transient( $key, array( 'r' => $address ), $address ? self::CACHE_TTL : self::MISS_TTL );
		return $address;
	}

	/**
	 * Meilleur résultat dont le nom correspond au premier segment du texte.
	 *
	 * À nom égal : lieu français dont le code postal commence par l'indice (+4),
	 * pays, région ou département cité dans les autres segments, article ignoré
	 * (+2), ville ou commune du pays du site (+1). À rang égal, l'ordre de
	 * Photon (pertinence) est conservé.
	 *
	 * @param array  $features Entités GeoJSON renvoyées par Photon.
	 * @param string $cleaned  Texte nettoyé.
	 * @param string $hint     Préfixe de code postal (voir hint()).
	 * @return array|null
	 */
	public static function pick( array $features, $cleaned, $hint = '' ) {
		$segments = array_map( array( __CLASS__, 'normalize' ), explode( ',', (string) $cleaned ) );
		$wanted   = array_shift( $segments );
		if ( '' === $wanted ) {
			return null;
		}
		$context   = array_map( array( __CLASS__, 'without_article' ), array_filter( $segments ) );
		$preferred = self::preferred_country();
		$best      = null;
		$best_rank = -1;
		foreach ( $features as $feature ) {
			$props = ( is_array( $feature ) && isset( $feature['properties'] ) && is_array( $feature['properties'] ) ) ? $feature['properties'] : array();
			$name  = ( isset( $props['name'] ) && is_string( $props['name'] ) ) ? $props['name'] : '';
			if ( '' === $name || self::normalize( $name ) !== $wanted || null === self::to_address( $feature ) ) {
				continue;
			}
			$code  = ( isset( $props['countrycode'] ) && is_string( $props['countrycode'] ) ) ? strtoupper( $props['countrycode'] ) : '';
			$value = ( isset( $props['osm_value'] ) && is_string( $props['osm_value'] ) ) ? $props['osm_value'] : '';
			$rank  = 0;
			if ( '' !== $hint && in_array( $code, self::FRENCH_COUNTRIES, true ) && isset( $props['postcode'] ) && is_scalar( $props['postcode'] ) && 0 === strpos( (string) $props['postcode'], $hint ) ) {
				$rank += 4;
			}
			foreach ( array( 'country', 'state', 'county' ) as $field ) {
				if ( isset( $props[ $field ] ) && is_string( $props[ $field ] ) && in_array( self::without_article( self::normalize( $props[ $field ] ) ), $context, true ) ) {
					$rank += 2;
					break;
				}
			}
			if ( isset( $props['county'] ) && is_string( $props['county'] ) && in_array( self::without_article( self::normalize( $props['county'] ) ), $context, true ) ) {
				++$rank; // Département cité : départage les homonymes d'une même région.
			}
			if ( '' !== $preferred && $code === $preferred && in_array( $value, self::PREFERRED_VALUES, true ) ) {
				++$rank;
			}
			if ( $rank > $best_rank ) {
				$best      = $feature;
				$best_rank = $rank;
			}
		}
		return $best;
	}

	/**
	 * Convertit une entité Photon au format de WP Job Manager.
	 *
	 * @param array $feature Entité GeoJSON.
	 * @return array|null null si les coordonnées sont absentes ou hors bornes.
	 */
	public static function to_address( array $feature ) {
		$coords = isset( $feature['geometry']['coordinates'] ) ? $feature['geometry']['coordinates'] : null;
		if ( ! is_array( $coords ) || ! isset( $coords[0], $coords[1] ) || ! is_numeric( $coords[0] ) || ! is_numeric( $coords[1] ) ) {
			return null;
		}
		$lng = (float) $coords[0];
		$lat = (float) $coords[1];
		if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
			return null;
		}

		$props = ( isset( $feature['properties'] ) && is_array( $feature['properties'] ) ) ? $feature['properties'] : array();
		$text  = function ( $key ) use ( $props ) {
			return ( isset( $props[ $key ] ) && is_scalar( $props[ $key ] ) ) ? self::truncate( sanitize_text_field( (string) $props[ $key ] ) ) : '';
		};

		$name      = $text( 'name' );
		$state     = $text( 'state' );
		$country   = $text( 'country' );
		$osm_value = $text( 'osm_value' );
		$city      = $text( 'city' );
		if ( 'place' === $text( 'osm_key' ) && in_array( $osm_value, self::CITY_VALUES, true ) && ( '' === $city || ! in_array( $osm_value, self::SUB_CITY_VALUES, true ) ) ) {
			$city = $name;
		}

		return array(
			'lat'               => self::coordinate( $lat ),
			'long'              => self::coordinate( $lng ),
			'formatted_address' => implode( ', ', array_values( array_unique( array_filter( array( $name, $state, $country ) ) ) ) ),
			'city'              => $city,
			'state_long'        => $state,
			'postcode'          => $text( 'postcode' ),
			'country_long'      => $country,
			'country_short'     => strtoupper( substr( (string) preg_replace( '/[^A-Za-z]/', '', $text( 'countrycode' ) ), 0, 2 ) ),
		);
	}

	/**
	 * Forme normalisée sans article en tête (« la reunion » → « reunion »).
	 *
	 * @param string $text Texte normalisé.
	 * @return string
	 */
	public static function without_article( $text ) {
		return (string) preg_replace( '/^(?:le|la|les|l) /', '', (string) $text );
	}

	/**
	 * Lieu (place=*) ou limite administrative.
	 *
	 * @param mixed $feature Entité GeoJSON.
	 * @return bool
	 */
	public static function is_place( $feature ) {
		$props = ( is_array( $feature ) && isset( $feature['properties'] ) && is_array( $feature['properties'] ) ) ? $feature['properties'] : array();
		$key   = isset( $props['osm_key'] ) ? $props['osm_key'] : '';
		$value = isset( $props['osm_value'] ) ? $props['osm_value'] : '';
		return 'place' === $key || ( 'boundary' === $key && 'administrative' === $value );
	}

	/**
	 * Coordonnée en notation décimale (jamais « 1.0E-5 »), 7 décimales au plus.
	 *
	 * @param float $value Coordonnée.
	 * @return string
	 */
	private static function coordinate( $value ) {
		$text = rtrim( rtrim( sprintf( '%.7F', $value ), '0' ), '.' );
		return '-0' === $text ? '0' : $text;
	}

	/**
	 * Tronque un texte à MAX_LENGTH caractères.
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	private static function truncate( $text ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_LENGTH ) : substr( $text, 0, self::MAX_LENGTH );
	}

	/**
	 * URL Photon configurée, sans fragment (sinon les paramètres partiraient dans le fragment).
	 *
	 * @return string
	 */
	private function base_url() {
		$base = (string) $this->settings->get( 'photon_url' );
		$hash = strpos( $base, '#' );
		return false === $hash ? $base : substr( $base, 0, $hash );
	}

	/**
	 * Appelle Photon.
	 *
	 * @param string $base     URL Photon.
	 * @param string $query    Texte nettoyé.
	 * @param string $lang     Langue Photon.
	 * @param bool   $filtered Restreindre aux lieux et limites administratives.
	 * @return array|\WP_Error Entités, ou erreur (code g2rd_photon_bad_request pour un HTTP 400 avec filtre).
	 */
	private function request( $base, $query, $lang, $filtered ) {
		$url = $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . http_build_query(
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

		$allow = $this->allow_private_host( $base );
		try {
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => self::TIMEOUT,
					'redirection'         => 2,
					'sslverify'           => true,
					'limit_response_size' => self::MAX_RESPONSE,
					'user-agent'          => 'G2RD-WPJM-Geo/' . G2RD_WPJM_GEO_VERSION . ' (+' . home_url( '/' ) . ')',
				)
			);
		} finally {
			if ( $allow ) {
				remove_filter( 'http_request_host_is_external', $allow, 10 );
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 400 === $code && $filtered ) {
			return new \WP_Error( 'g2rd_photon_bad_request', __( 'Photon a refusé la requête (HTTP 400).', 'g2rd-wpjm-geo' ) );
		}
		if ( 400 === $code ) {
			$body = strtolower( (string) wp_remote_retrieve_body( $response ) );
			if ( false !== strpos( $body, 'language' ) || false !== strpos( $body, 'parameter' ) ) {
				// Langue ou paramètre refusé : la configuration du serveur est en cause, pas le texte.
				return new \WP_Error( 'g2rd_photon_config', __( 'Le serveur Photon refuse la langue ou les paramètres de la requête (HTTP 400) : vérifiez sa configuration.', 'g2rd-wpjm-geo' ) );
			}
			// Refusé même sans filtre : propre à ce texte, traité comme introuvable
			// (sinon l'offre bloquerait la file de géocodage à chaque passage).
			return array();
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return new \WP_Error( 'g2rd_photon_http', sprintf( __( 'Photon est indisponible (HTTP %d).', 'g2rd-wpjm-geo' ), $code ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['features'] ) || ! is_array( $data['features'] ) ) {
			return new \WP_Error( 'g2rd_photon_invalid', __( 'Photon a renvoyé une réponse illisible.', 'g2rd-wpjm-geo' ) );
		}
		// Filtré aussi côté serveur : une instance qui ignorerait osm_tag renverrait des commerces.
		return array_values( array_filter( $data['features'], array( __CLASS__, 'is_place' ) ) );
	}

	/**
	 * Autorise, le temps d'une requête, l'hôte d'un Photon auto-hébergé à une adresse privée.
	 *
	 * Photon écoute par défaut en HTTP sur le port 2322 : il doit être servi en
	 * https sur le port 443 (proxy inverse), seul port accepté avec 80 et 8080
	 * par wp_safe_remote_get().
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

<?php
/**
 * Vérification d'accessibilité d'une URL de candidature.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * N'est utilisé que si l'administrateur choisit « uniquement les URL invalides
 * ou inaccessibles », et jamais pendant un import (voir UrlCheckQueue).
 *
 * Protection SSRF : seuls http/https sont acceptés, la cible est validée par
 * wp_http_validate_url() (IP privées, ports non standards…) et les requêtes
 * passent par wp_safe_remote_*() qui revalide chaque redirection.
 */
class UrlChecker {

	const TIMEOUT      = 5;
	const REDIRECTIONS = 3;
	const CACHE_TTL    = 43200; // 12 h.

	/**
	 * Vérifie une URL.
	 *
	 * @param string $url URL à vérifier.
	 * @return array{problem:bool,reason:string}
	 */
	public function check( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';

		// Un « : » suivi d'un numéro de port (« careers.acme.com:8443/… ») n'est pas un schéma.
		if ( preg_match( '#^([a-z][a-z0-9+.\-]*):(?!\d+(?:[/?\#]|$))#i', $url, $m ) ) {
			if ( ! in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ) {
				return $this->result( true, 'bad_scheme' );
			}
		} else {
			// WP Job Manager préfixe lui aussi les URL sans schéma par http://.
			$url = 'http://' . $url;
		}

		$url  = $this->to_ascii_host( $url );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return $this->result( true, 'invalid_syntax' );
		}

		$cache_key = 'g2rd_geo_url_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['problem'], $cached['reason'] ) ) {
			return $cached;
		}

		$result = $this->check_remote( $url, $host );
		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	/**
	 * Partie réseau de la vérification.
	 *
	 * @param string $url  URL http(s) syntaxiquement valide.
	 * @param string $host Nom d'hôte.
	 * @return array{problem:bool,reason:string}
	 */
	private function check_remote( $url, $host ) {
		// wp_http_validate_url() résout l'hôte (IPv4) et rejette les destinations interdites.
		// Il ne distingue pas « DNS inexistant » de « cible interdite » : on lève l'ambiguïté ici.
		if ( ! wp_http_validate_url( $url ) ) {
			$is_ip = (bool) filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP );
			if ( ! $is_ip && ! $this->host_has_record( $host, 'A' ) ) {
				// Hôte joignable uniquement en IPv6 : WordPress ne sait pas le valider, on ne tranche pas.
				return $this->host_has_record( $host, 'AAAA' ) ? $this->result( false, 'inconclusive' ) : $this->result( true, 'dns_error' );
			}
			return $this->result( true, 'unsafe_target' );
		}

		$args = array(
			'timeout'             => self::TIMEOUT,
			'redirection'         => self::REDIRECTIONS,
			'user-agent'          => 'Mozilla/5.0 (compatible; G2RD-WPJM-Geo/' . G2RD_WPJM_GEO_VERSION . '; +' . home_url( '/' ) . ')',
			'sslverify'           => true,
			'limit_response_size' => 2048,
		);

		$response = wp_safe_remote_head( $url, $args );
		$verdict  = $this->interpret( $response );

		// HEAD non supporté, ou 404/410 à confirmer : certains serveurs répondent
		// mal à HEAD alors que la page existe. Vérification GET légère (2 Ko max).
		if ( 'retry_get' === $verdict ) {
			$args['headers'] = array( 'Range' => 'bytes=0-2047' );
			$verdict         = $this->interpret( wp_safe_remote_get( $url, $args ), true );
		}

		return is_array( $verdict ) ? $verdict : $this->result( false, 'inconclusive' );
	}

	/**
	 * Interprète une réponse HTTP.
	 *
	 * @param array|\WP_Error $response Réponse.
	 * @param bool            $final    Vrai pour la requête GET de confirmation.
	 * @return array|string Résultat, ou 'retry_get'.
	 */
	private function interpret( $response, $final = false ) {
		if ( is_wp_error( $response ) ) {
			$message = strtolower( $response->get_error_message() );
			if ( false !== strpos( $message, 'resolve host' ) || false !== strpos( $message, 'could not resolve' ) || false !== strpos( $message, 'name or service not known' ) ) {
				return $this->result( true, 'dns_error' );
			}
			if ( false !== strpos( $message, 'ssl' ) || false !== strpos( $message, 'certificate' ) ) {
				return $this->result( true, 'ssl_error' );
			}
			if ( false !== strpos( $message, 'redirect' ) ) {
				return $this->result( true, 'redirect_error' );
			}
			if ( false !== strpos( $message, 'valid url' ) ) {
				// wp_safe_remote_* a refusé la cible (souvent une redirection vers une destination interdite).
				return $this->result( true, 'unsafe_target' );
			}
			// Timeout, connexion refusée, HTTP sortant bloqué par l'hébergeur (WP_HTTP_BLOCK_EXTERNAL)… :
			// non concluant, on ne touche à rien.
			return $this->result( false, 'inconclusive' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( ! $final && ( in_array( $code, array( 400, 404, 405, 410, 501 ), true ) || ( $code >= 300 && $code < 400 ) ) ) {
			return 'retry_get';
		}
		if ( 404 === $code || 410 === $code ) {
			return $this->result( true, 'http_' . $code );
		}
		// 3xx restant après suivi : redirection sans destination exploitable.
		if ( $code >= 300 && $code < 400 && 304 !== $code ) {
			return $this->result( true, 'redirect_error' );
		}
		// 401, 403, 429, 5xx… : la page peut exister (protection anti-robot, surcharge).
		return $this->result( false, 'http_' . $code );
	}

	/**
	 * Convertit un nom d'hôte internationalisé (« münchen-jobs.de ») en punycode,
	 * seule forme acceptée par FILTER_VALIDATE_URL et par la résolution DNS.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function to_ascii_host( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( empty( $host ) || ! function_exists( 'idn_to_ascii' ) || ! preg_match( '/[^[:ascii:]]/', $host ) ) {
			return $url;
		}
		$ascii = defined( 'INTL_IDNA_VARIANT_UTS46' ) ? idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) : idn_to_ascii( $host );
		if ( ! $ascii ) {
			return $url;
		}
		$pos = strpos( $url, $host );
		return false === $pos ? $url : substr_replace( $url, $ascii, $pos, strlen( $host ) );
	}

	/**
	 * L'hôte possède-t-il un enregistrement DNS du type demandé ?
	 *
	 * @param string $host Nom d'hôte.
	 * @param string $type A ou AAAA.
	 * @return bool
	 */
	private function host_has_record( $host, $type ) {
		if ( 'A' === $type ) {
			return gethostbyname( $host ) !== $host;
		}
		return function_exists( 'checkdnsrr' ) && checkdnsrr( $host, $type );
	}

	/**
	 * Construit un résultat.
	 *
	 * @param bool   $problem URL problématique ?
	 * @param string $reason  Code de raison.
	 * @return array{problem:bool,reason:string}
	 */
	private function result( $problem, $reason ) {
		return array(
			'problem' => (bool) $problem,
			'reason'  => (string) $reason,
		);
	}
}

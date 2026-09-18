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

		if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $url, $m ) ) {
			if ( ! in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ) {
				return $this->result( true, 'bad_scheme' );
			}
		} else {
			// WP Job Manager préfixe lui aussi les URL sans schéma par http://.
			$url = 'http://' . $url;
		}

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
		$is_ip = (bool) filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP );
		if ( ! $is_ip && gethostbyname( $host ) === $host ) {
			return $this->result( true, 'dns_error' );
		}
		if ( ! wp_http_validate_url( $url ) ) {
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
			if ( 'http_request_not_executed' === $response->get_error_code() || false !== strpos( $message, 'valid url' ) ) {
				// wp_safe_remote_* a refusé la cible (souvent une redirection vers une destination interdite).
				return $this->result( true, 'unsafe_target' );
			}
			// Timeout, connexion refusée… : non concluant, on ne touche à rien.
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

<?php
/**
 * Mises à jour proposées depuis les releases GitHub.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * L'en-tête « Update URI » du plugin fait appeler par WordPress le filtre
 * update_plugins_github.com : il reçoit ici la dernière release du dépôt, et
 * WordPress compare lui-même les versions. Les autres plugins G2RD utilisent le
 * même filtre : chacun ne répond que pour son propre fichier.
 */
class Updater {

	const REPO      = 'SebG2RD/g2rd-wp-job-manager-geo';
	const SLUG      = 'g2rd-wpjm-geo';
	const API       = 'https://api.github.com/repos/SebG2RD/g2rd-wp-job-manager-geo/releases/latest';
	const TRANSIENT = 'g2rd_wpjm_geo_github_release';

	/**
	 * 6 heures : sans jeton, l'API GitHub accepte 60 requêtes par heure et par adresse IP.
	 */
	const CACHE_TTL = 21600;

	/**
	 * 15 minutes après un échec (réseau, quota) avant de réessayer.
	 */
	const FAIL_TTL = 900;

	/**
	 * Branche les filtres.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'info' ), 10, 3 );
		add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'clear_cache' ) );
	}

	/**
	 * Mise à jour de ce plugin d'après la dernière release.
	 *
	 * @param array<string,mixed>|false $update      Réponse d'une autre source, ou false.
	 * @param array<string,mixed>       $plugin_data En-têtes du plugin.
	 * @param string                    $plugin_file Fichier du plugin, relatif au dossier des plugins.
	 * @return array<string,mixed>|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( G2RD_WPJM_GEO_FILE ) !== $plugin_file || ! empty( $update ) ) {
			return $update;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'slug'    => self::SLUG,
			'version' => $release['version'],
			'url'     => $release['url'],
			'package' => $release['package'],
		);
	}

	/**
	 * Fiche « Voir les détails » de l'écran Extensions.
	 *
	 * @param false|object|array<string,mixed> $result Réponse en cours.
	 * @param string                           $action Action de plugins_api().
	 * @param object                           $args   Arguments, dont le slug.
	 * @return false|object|array<string,mixed>
	 */
	public function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}
		// Notes en Markdown, affichées en texte échappé.
		$notes = '' !== $release['body'] ? $release['body'] : $release['url'];
		return (object) array(
			'name'          => 'G2RD WP Job Manager Geo',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'G2RD',
			'homepage'      => $release['url'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => '<pre style="white-space:pre-wrap;font-family:inherit">' . esc_html( $notes ) . '</pre>',
			),
		);
	}

	/**
	 * Vide le cache, en même temps que celui des mises à jour de WordPress.
	 *
	 * @return void
	 */
	public static function clear_cache() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Dernière release publiée (ni brouillon, ni préversion), mise en cache.
	 *
	 * @return array{version:string,package:string,url:string,body:string}|null
	 */
	public static function latest_release() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return isset( $cached['version'], $cached['package'], $cached['url'], $cached['body'] ) ? $cached : null;
		}
		$response = wp_safe_remote_get(
			self::API,
			array(
				'timeout' => 10,
				// Un User-Agent est exigé par GitHub ; celui de WordPress contiendrait l'adresse du site.
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'g2rd-wpjm-geo/' . G2RD_WPJM_GEO_VERSION,
				),
			)
		);
		$data    = ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) ? null : json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$release = is_array( $data ) ? self::normalize( $data ) : null;
		set_transient( self::TRANSIENT, $release ? $release : array(), $release ? self::CACHE_TTL : self::FAIL_TTL );
		return $release;
	}

	/**
	 * Release de l'API GitHub ramenée à ce qui sert, ou null. Le paquet ne peut être que
	 * l'archive g2rd-wpjm-geo-X.Y.Z.zip publiée dans les releases de ce dépôt.
	 *
	 * @param array<string,mixed> $data Réponse de l'API.
	 * @return array{version:string,package:string,url:string,body:string}|null
	 */
	public static function normalize( array $data ) {
		$version = ( isset( $data['tag_name'] ) && is_string( $data['tag_name'] ) ) ? ltrim( $data['tag_name'], 'vV' ) : '';
		if ( ! preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}
		$package = 'https://github.com/' . self::REPO . '/releases/download/v' . $version . '/' . self::SLUG . '-' . $version . '.zip';
		$assets  = ( isset( $data['assets'] ) && is_array( $data['assets'] ) ) ? $data['assets'] : array();
		foreach ( $assets as $asset ) {
			if ( is_array( $asset ) && isset( $asset['browser_download_url'] ) && $package === $asset['browser_download_url'] ) {
				return array(
					'version' => $version,
					'package' => $package,
					'url'     => 'https://github.com/' . self::REPO . '/releases/tag/v' . $version,
					'body'    => ( isset( $data['body'] ) && is_string( $data['body'] ) ) ? $data['body'] : '',
				);
			}
		}
		return null;
	}
}

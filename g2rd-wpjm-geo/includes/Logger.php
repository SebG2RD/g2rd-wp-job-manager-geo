<?php
/**
 * Journal minimal des remplacements de candidature.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table dédiée, bornée en durée (rétention) et en volume (MAX_ROWS).
 *
 * Données conservées : ID de l'offre, date, ancienne valeur (toujours une URL,
 * puisqu'une adresse email n'est jamais remplacée), nouvelle valeur (adresse
 * générique du site), raison, source. Aucune donnée de candidat.
 */
class Logger {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'g2rd_wpjm_geo_db_version';
	const CRON_HOOK         = 'g2rd_wpjm_geo_purge_log';
	const MAX_ROWS          = 5000;

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Nom de la table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'g2rd_wpjm_geo_log';
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'purge' ) );
		add_action( 'init', array( $this, 'schedule_purge' ) );
	}

	/**
	 * Planifie la purge quotidienne.
	 *
	 * @return void
	 */
	public function schedule_purge() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Crée ou met à jour la table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
			old_value text NOT NULL,
			new_value varchar(255) NOT NULL DEFAULT '',
			reason varchar(64) NOT NULL DEFAULT '',
			source varchar(191) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY job_id (job_id),
			KEY created_at (created_at)
			) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * Installe la table si le plugin a été copié sans passer par l'activation
	 * (mise à jour par FTP, mu-plugin…).
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Ajoute une entrée.
	 *
	 * @param int    $job_id    ID de l'offre.
	 * @param string $old_value Ancienne valeur.
	 * @param string $new_value Nouvelle valeur.
	 * @param string $reason    Code de raison.
	 * @param string $source    Source détectée.
	 * @return void
	 */
	public function log( $job_id, $old_value, $new_value, $reason, $source ) {
		if ( ! $this->settings->get( 'log_enabled' ) ) {
			return;
		}
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- table propre au plugin.
		$wpdb->insert(
			self::table(),
			array(
				'job_id'     => absint( $job_id ),
				'created_at' => current_time( 'mysql', true ),
				'old_value'  => self::redact_url( (string) $old_value ),
				'new_value'  => substr( sanitize_text_field( (string) $new_value ), 0, 255 ),
				'reason'     => substr( sanitize_key( $reason ), 0, 64 ),
				'source'     => substr( sanitize_text_field( (string) $source ), 0, 191 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Retire de l'URL journalisée les identifiants et les paramètres pouvant
	 * contenir des jetons (clé d'API de flux, identifiants de suivi…).
	 *
	 * @param string $url URL d'origine.
	 * @return string
	 */
	public static function redact_url( $url ) {
		// Pas de sanitize_text_field() : il supprimerait les octets encodés (%C3%A9, %20) et
		// l'URL journalisée ne correspondrait plus à la valeur réellement remplacée.
		$url   = substr( trim( wp_strip_all_tags( (string) $url ) ), 0, 2000 );
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return $url;
		}
		$clean = ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '' ) . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$clean .= ':' . (int) $parts['port'];
		}
		$clean .= isset( $parts['path'] ) ? $parts['path'] : '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			foreach ( $query as $key => $value ) {
				if ( preg_match( '/(key|token|secret|pass|auth|sig|session)/i', (string) $key ) ) {
					$query[ $key ] = '***';
				}
			}
			$clean .= '?' . http_build_query( $query );
		}
		return $clean;
	}

	/**
	 * Purge par ancienneté puis par volume.
	 *
	 * @return void
	 */
	public function purge() {
		global $wpdb;
		$table = self::table();
		$days  = max( 1, absint( $this->settings->get( 'log_retention_days' ) ) );
		$limit = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne, non influençable.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $limit ) );

		$threshold = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", self::MAX_ROWS ) );
		if ( $threshold ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", (int) $threshold ) );
		}
		// phpcs:enable
	}

	/**
	 * Dernières entrées.
	 *
	 * @param int $limit Nombre maximum.
	 * @return array
	 */
	public function latest( $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, job_id, created_at, old_value, new_value, reason, source FROM {$table} ORDER BY id DESC LIMIT %d", min( 200, max( 1, absint( $limit ) ) ) ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Libellé lisible d'un code de raison.
	 *
	 * @param string $reason Code.
	 * @return string
	 */
	public static function reason_label( $reason ) {
		$labels = array(
			'mode_all'       => __( 'Mode « toutes les URL »', 'g2rd-wpjm-geo' ),
			'invalid_syntax' => __( 'URL syntaxiquement invalide', 'g2rd-wpjm-geo' ),
			'bad_scheme'     => __( 'Schéma non autorisé', 'g2rd-wpjm-geo' ),
			'unsafe_target'  => __( 'Destination interdite (IP privée, port non autorisé…)', 'g2rd-wpjm-geo' ),
			'http_404'       => __( 'Page introuvable (404)', 'g2rd-wpjm-geo' ),
			'http_410'       => __( 'Page supprimée (410)', 'g2rd-wpjm-geo' ),
			'dns_error'      => __( 'Erreur DNS', 'g2rd-wpjm-geo' ),
			'ssl_error'      => __( 'Échec SSL', 'g2rd-wpjm-geo' ),
			'redirect_error' => __( 'Redirection cassée', 'g2rd-wpjm-geo' ),
		);
		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
	}
}

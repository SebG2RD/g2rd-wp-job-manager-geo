<?php
/**
 * Désinstallation : supprime uniquement les données propres au plugin.
 * Aucune offre ni métadonnée de WP Job Manager / Go Fetch Jobs n'est touchée.
 *
 * @package G2RD\WPJMGeo
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'g2rd_wpjm_geo_settings' );
delete_option( 'g2rd_wpjm_geo_fallback_email' );
delete_option( 'g2rd_wpjm_geo_db_version' );
delete_option( 'g2rd_wpjm_geo_url_queue' );
delete_transient( 'g2rd_wpjm_geo_url_queue_lock' );

wp_clear_scheduled_hook( 'g2rd_wpjm_geo_purge_log' );
wp_clear_scheduled_hook( 'g2rd_wpjm_geo_check_urls' );
delete_option( 'g2rd_wpjm_geo_geocode_queue' );
delete_transient( 'g2rd_wpjm_geo_geocode_lock' );
delete_transient( 'g2rd_wpjm_geo_photon_down' );
wp_clear_scheduled_hook( 'g2rd_wpjm_geo_geocode' );
delete_post_meta_by_key( '_g2rd_wpjm_geo_source' );

// Caches des réponses Photon, des vérifications d'URL et de la réponse REST de la carte.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_g2rd_geo_ph_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_g2rd_geo_ph_' ) . '%',
		$wpdb->esc_like( '_transient_g2rd_geo_url_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_g2rd_geo_url_' ) . '%',
		$wpdb->esc_like( '_transient_jm_g2rdgeo_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_jm_g2rdgeo_' ) . '%'
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne, non influençable.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}g2rd_wpjm_geo_log" );

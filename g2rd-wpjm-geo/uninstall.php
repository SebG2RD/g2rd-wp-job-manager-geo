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

// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nom de table interne, non influençable.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}g2rd_wpjm_geo_log" );

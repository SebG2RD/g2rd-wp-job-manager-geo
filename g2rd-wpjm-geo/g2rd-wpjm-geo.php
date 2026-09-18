<?php
/**
 * Plugin Name:       G2RD WP Job Manager Geo
 * Plugin URI:        https://g2rd.fr
 * Description:       Complète WP Job Manager : autocomplétion mondiale des villes (Google Places) sur le champ de localisation natif, carte interactive des offres, et normalisation des candidatures des offres importées par Go Fetch Jobs.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            G2RD
 * Author URI:        https://g2rd.fr
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       g2rd-wpjm-geo
 * Domain Path:       /languages
 *
 * @package G2RD\WPJMGeo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'G2RD_WPJM_GEO_VERSION', '1.0.0' );
define( 'G2RD_WPJM_GEO_FILE', __FILE__ );
define( 'G2RD_WPJM_GEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'G2RD_WPJM_GEO_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloader PSR-4 minimal : G2RD\WPJMGeo\Foo => includes/Foo.php.
 */
spl_autoload_register(
	static function ( $class ) {
		$prefix = 'G2RD\\WPJMGeo\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = G2RD_WPJM_GEO_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'G2RD\\WPJMGeo\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'G2RD\\WPJMGeo\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'G2RD\\WPJMGeo\\Plugin', 'instance' ) );

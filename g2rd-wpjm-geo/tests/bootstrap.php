<?php
/**
 * Environnement de test autonome : simule le strict nécessaire de WordPress
 * (options, métadonnées, hooks, HTTP) pour exécuter la logique métier du plugin
 * sans installation WordPress. Usage : php tests/run.php
 *
 * @package G2RD\WPJMGeo
 */

// phpcs:disable -- fichier de test, hors conventions de production.

namespace {

	if ( 'cli' !== PHP_SAPI ) {
		exit;
	}

	define( 'ABSPATH', __DIR__ . '/' );
	define( 'G2RD_WPJM_GEO_VERSION', 'test' );
	define( 'G2RD_WPJM_GEO_FILE', dirname( __DIR__ ) . '/g2rd-wpjm-geo.php' );
	define( 'G2RD_WPJM_GEO_DIR', dirname( __DIR__ ) . '/' );
	define( 'G2RD_WPJM_GEO_URL', 'https://example.test/wp-content/plugins/g2rd-wpjm-geo/' );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	final class G2RD_Test_State {
		public static $options    = array();
		public static $meta       = array();
		public static $post_types = array();
		public static $hooks      = array();
		public static $transients = array();
		public static $errors     = array();
		public static $http       = array(); // url => array( 'HEAD' => response, 'GET' => response ).
		public static $http_calls = array();
		public static $dns        = array(); // host => ip|false.
		public static $scheduled  = array();
		public static $log_rows   = array();

		public static function reset() {
			self::$options    = array();
			self::$meta       = array();
			self::$post_types = array();
			self::$hooks      = array();
			self::$transients = array();
			self::$errors     = array();
			self::$http       = array();
			self::$http_calls = array();
			self::$dns        = array();
			self::$scheduled  = array();
			self::$log_rows   = array();
		}
	}

	class WP_Error {
		private $code;
		private $message;
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}

	class G2RD_Test_Wpdb {
		public $prefix = 'wp_';
		public function insert( $table, $data ) {
			G2RD_Test_State::$log_rows[] = $data;
			return 1;
		}
	}
	$GLOBALS['wpdb'] = new G2RD_Test_Wpdb();

	// --- Options.
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, G2RD_Test_State::$options ) ? G2RD_Test_State::$options[ $name ] : $default;
	}
	function update_option( $name, $value ) {
		G2RD_Test_State::$options[ $name ] = $value;
		return true;
	}
	function add_option( $name, $value ) {
		if ( array_key_exists( $name, G2RD_Test_State::$options ) ) {
			return false;
		}
		G2RD_Test_State::$options[ $name ] = $value;
		return true;
	}

	// --- Métadonnées et contenus.
	function get_post_meta( $id, $key, $single = false ) {
		return isset( G2RD_Test_State::$meta[ $id ][ $key ] ) ? G2RD_Test_State::$meta[ $id ][ $key ] : '';
	}
	function update_post_meta( $id, $key, $value ) {
		if ( isset( G2RD_Test_State::$meta[ $id ][ $key ] ) && G2RD_Test_State::$meta[ $id ][ $key ] === $value ) {
			return false;
		}
		G2RD_Test_State::$meta[ $id ][ $key ] = $value;
		return true;
	}
	function get_post_type( $id ) {
		return isset( G2RD_Test_State::$post_types[ $id ] ) ? G2RD_Test_State::$post_types[ $id ] : false;
	}

	// --- Hooks (implémentation minimale mais réelle : priorité et nombre d'arguments).
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		G2RD_Test_State::$hooks[ $hook ][ $priority ][] = array( $callback, $args );
		return true;
	}
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		return add_filter( $hook, $callback, $priority, $args );
	}
	function apply_filters( $hook, $value, ...$rest ) {
		if ( empty( G2RD_Test_State::$hooks[ $hook ] ) ) {
			return $value;
		}
		ksort( G2RD_Test_State::$hooks[ $hook ] );
		foreach ( G2RD_Test_State::$hooks[ $hook ] as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$value = call_user_func_array( $entry[0], array_slice( array_merge( array( $value ), $rest ), 0, $entry[1] ) );
			}
		}
		return $value;
	}
	function do_action( $hook, ...$params ) {
		if ( empty( G2RD_Test_State::$hooks[ $hook ] ) ) {
			return;
		}
		ksort( G2RD_Test_State::$hooks[ $hook ] );
		foreach ( G2RD_Test_State::$hooks[ $hook ] as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				call_user_func_array( $entry[0], array_slice( $params, 0, $entry[1] ) );
			}
		}
	}

	// --- Assainissement (comportement aligné sur WordPress pour les cas testés).
	function is_email( $email ) {
		if ( strlen( $email ) < 6 || false === strpos( $email, '@', 1 ) ) {
			return false;
		}
		list( $local, $domain ) = explode( '@', $email, 2 );
		if ( ! preg_match( '/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]+$/', $local ) ) {
			return false;
		}
		if ( preg_match( '/\.{2,}/', $domain ) || trim( $domain, " \t\n\r\0\x0B." ) !== $domain ) {
			return false;
		}
		$subs = explode( '.', $domain );
		if ( count( $subs ) < 2 ) {
			return false;
		}
		foreach ( $subs as $sub ) {
			if ( trim( $sub, " \t\n\r\0\x0B-" ) !== $sub || ! preg_match( '/^[a-z0-9-]+$/i', $sub ) ) {
				return false;
			}
		}
		return $email;
	}
	function sanitize_email( $email ) {
		$email = trim( (string) $email );
		if ( strlen( $email ) < 6 || false === strpos( $email, '@', 1 ) ) {
			return '';
		}
		list( $local, $domain ) = explode( '@', $email, 2 );
		$local  = preg_replace( '/[^a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~\.-]/', '', $local );
		$domain = preg_replace( '/[^a-z0-9\-\.]/i', '', $domain );
		if ( '' === $local || '' === $domain || false === strpos( $domain, '.' ) ) {
			return '';
		}
		return $local . '@' . $domain;
	}
	function sanitize_text_field( $value ) {
		return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
	}
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
	function absint( $value ) {
		return abs( (int) $value );
	}
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
	function wp_parse_args( $args, $defaults = array() ) {
		return array_merge( $defaults, (array) $args );
	}
	function __( $text ) {
		return $text;
	}
	function current_time( $type, $gmt = 0 ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
	function home_url( $path = '' ) {
		return 'https://example.test' . $path;
	}
	function add_settings_error( $setting, $code, $message, $type = 'error' ) {
		G2RD_Test_State::$errors[] = array( 'code' => $code, 'type' => $type );
	}

	// --- Transients et cron.
	function get_transient( $key ) {
		return isset( G2RD_Test_State::$transients[ $key ] ) ? G2RD_Test_State::$transients[ $key ] : false;
	}
	function set_transient( $key, $value, $ttl = 0 ) {
		G2RD_Test_State::$transients[ $key ] = $value;
		return true;
	}
	function delete_transient( $key ) {
		unset( G2RD_Test_State::$transients[ $key ] );
		return true;
	}
	function wp_next_scheduled( $hook ) {
		return isset( G2RD_Test_State::$scheduled[ $hook ] ) ? G2RD_Test_State::$scheduled[ $hook ] : false;
	}
	function wp_schedule_single_event( $time, $hook ) {
		G2RD_Test_State::$scheduled[ $hook ] = $time;
		return true;
	}

	// --- HTTP simulé.
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
	function wp_http_validate_url( $url ) {
		$host = parse_url( $url, PHP_URL_HOST );
		if ( preg_match( '/^(127\.|10\.|192\.168\.|169\.254\.)/', (string) $host ) || 'localhost' === $host ) {
			return false;
		}
		return $url;
	}
	function g2rd_test_http( $method, $url ) {
		G2RD_Test_State::$http_calls[] = $method . ' ' . $url;
		if ( isset( G2RD_Test_State::$http[ $url ][ $method ] ) ) {
			return G2RD_Test_State::$http[ $url ][ $method ];
		}
		return array( 'response' => array( 'code' => 200 ) );
	}
	function wp_safe_remote_head( $url, $args = array() ) {
		return g2rd_test_http( 'HEAD', $url );
	}
	function wp_safe_remote_get( $url, $args = array() ) {
		return g2rd_test_http( 'GET', $url );
	}
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? $response['response']['code'] : '';
	}
}

namespace G2RD\WPJMGeo {
	// Remplace la résolution DNS réelle : le code appelle gethostbyname() sans préfixe depuis ce namespace.
	function gethostbyname( $host ) {
		if ( array_key_exists( $host, \G2RD_Test_State::$dns ) ) {
			return false === \G2RD_Test_State::$dns[ $host ] ? $host : \G2RD_Test_State::$dns[ $host ];
		}
		return '93.184.216.34';
	}
}

namespace {
	foreach ( array( 'Settings', 'Logger', 'UrlChecker', 'ApplicationNormalizer', 'UrlCheckQueue', 'GoFetchIntegration' ) as $class ) {
		require_once dirname( __DIR__ ) . '/includes/' . $class . '.php';
	}
}

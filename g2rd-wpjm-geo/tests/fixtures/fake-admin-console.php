<?php
/**
 * Console du thème G2RD simulée (contrat v2) pour les tests.
 *
 * @package G2RD\WPJMGeo
 */

// phpcs:disable -- fichier de test.

namespace G2RD;

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

class AdminConsole {
	const CONTRACT_VERSION = 2;
	public static $opened  = array();
	public static function open( array $args ) {
		self::$opened[] = $args;
	}
	public static function close() {
	}
}

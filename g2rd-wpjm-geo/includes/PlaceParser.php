<?php
/**
 * Découpage du champ « Lieu » en morceaux.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sépare le texte sur les séparateurs forts (« ; », « | », « / », retour à la
 * ligne, « et » entouré d'espaces). La virgule ne sépare rien ici : elle est
 * traitée par PlaceLocator (règle de la virgule).
 */
class PlaceParser {

	const SEPARATORS = '/[;|\/\r\n]|\s+et\s+/iu';

	/**
	 * Garde-fou : un texte importé démesuré ne déclenche pas des centaines de recherches.
	 */
	const MAX_PIECES = 30;

	/**
	 * Morceaux dans l'ordre du texte, sans vides, mentions vagues ni doublons.
	 *
	 * @param string $text Texte du champ « Lieu ».
	 * @return list<string>
	 */
	public static function split( $text ) {
		$parts  = preg_split( self::SEPARATORS, wp_check_invalid_utf8( (string) $text ) );
		$pieces = array();
		$seen   = array();
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			$piece = trim( (string) preg_replace( '/^[\s,.:\-–—]+|[\s,.:\-–—]+$/u', '', (string) $part ) );
			if ( '' === $piece ) {
				continue;
			}
			$key = PhotonGeocoder::normalize( $piece );
			if ( in_array( $key, PhotonGeocoder::VAGUE, true ) ) {
				continue;
			}
			if ( '' === $key ) {
				// Écriture non latine : comparée telle quelle.
				$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $piece, 'UTF-8' ) : strtolower( $piece );
			}
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$pieces[]     = $piece;
			if ( count( $pieces ) >= self::MAX_PIECES ) {
				break;
			}
		}
		return $pieces;
	}
}

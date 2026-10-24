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
	 * Départements et collectivités dont le nom contient « -et- » : écrits avec des espaces
	 * (« Seine et Marne »), ils seraient coupés en deux par le séparateur « et ».
	 */
	const HYPHENATED = array(
		'Eure-et-Loir',
		'Ille-et-Vilaine',
		'Indre-et-Loire',
		'Loir-et-Cher',
		'Lot-et-Garonne',
		'Maine-et-Loire',
		'Meurthe-et-Moselle',
		'Saône-et-Loire',
		'Seine-et-Marne',
		'Tarn-et-Garonne',
		'Saint-Pierre-et-Miquelon',
		'Wallis-et-Futuna',
	);

	/**
	 * Garde-fou : un texte importé démesuré ne déclenche pas des centaines de recherches.
	 */
	const MAX_PIECES = 30;

	/**
	 * Longueur lue (caractères) : bornée avant toute expression régulière, dont le coût
	 * peut devenir quadratique sans le compilateur JIT de PCRE.
	 */
	const MAX_LENGTH = 2000;

	/**
	 * Morceaux dans l'ordre du texte, sans vides, mentions vagues ni doublons.
	 *
	 * @param string $text Texte du champ « Lieu ».
	 * @return list<string>
	 */
	public static function split( $text ) {
		$text = wp_check_invalid_utf8( (string) $text );
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_LENGTH, 'UTF-8' ) : substr( $text, 0, self::MAX_LENGTH );
		// Texte importé : un saut de ligne HTML sépare comme un retour à la ligne ; balises retirées, entités décodées.
		$text   = (string) preg_replace( '/<br\s*\/?>/i', "\n", $text );
		$text   = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text   = self::protect_hyphenated( $text );
		$parts  = preg_split( self::SEPARATORS, $text );
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

	/**
	 * Ramène à leur forme à tirets les noms de HYPHENATED écrits avec des espaces ou des tirets
	 * (casse et accent de « Saône » ignorés), tant qu'aucune lettre ne les touche.
	 *
	 * @param string $text Texte déjà nettoyé.
	 * @return string
	 */
	private static function protect_hyphenated( $text ) {
		foreach ( self::HYPHENATED as $name ) {
			$pattern = str_replace( array( '-', 'ô' ), array( '[\s\x{00A0}\x{202F}\-]+', '[oô]' ), $name );
			$result  = preg_replace( '/(?<!\p{L})' . $pattern . '(?!\p{L})/iu', $name, $text );
			if ( null !== $result ) {
				$text = $result;
			}
		}
		return $text;
	}
}

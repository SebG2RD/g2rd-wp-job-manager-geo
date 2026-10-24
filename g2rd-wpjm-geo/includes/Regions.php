<?php
/**
 * Régions françaises reconnues dans le champ « Lieu ».
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Les 18 régions (codes INSEE), leurs variantes et anciens noms, et un centre
 * fixe : centre de gravité du plus grand polygone du contour, à l'intérieur de
 * celui-ci (tools/regions/extract-regions.js). Aucun appel réseau.
 */
class Regions {

	/**
	 * Code INSEE => nom officiel, latitude et longitude du centre.
	 */
	const DATA = array(
		'01' => array( 'Guadeloupe', '16.1596', '-61.6805' ),
		'02' => array( 'Martinique', '14.6562', '-61.0217' ),
		'03' => array( 'Guyane', '3.9150', '-53.2403' ),
		'04' => array( 'La Réunion', '-21.1334', '55.5322' ),
		'06' => array( 'Mayotte', '-12.8202', '45.1424' ),
		'11' => array( 'Île-de-France', '48.7091', '2.5042' ),
		'24' => array( 'Centre-Val de Loire', '47.4851', '1.6843' ),
		'27' => array( 'Bourgogne-Franche-Comté', '47.2342', '4.8062' ),
		'28' => array( 'Normandie', '49.1202', '0.1128' ),
		'32' => array( 'Hauts-de-France', '49.9691', '2.7709' ),
		'44' => array( 'Grand Est', '48.6883', '5.6124' ),
		'52' => array( 'Pays de la Loire', '47.4801', '-0.8126' ),
		'53' => array( 'Bretagne', '48.1794', '-2.8331' ),
		'75' => array( 'Nouvelle-Aquitaine', '45.2037', '0.2128' ),
		'76' => array( 'Occitanie', '43.7026', '2.1453' ),
		'84' => array( 'Auvergne-Rhône-Alpes', '45.5127', '4.5361' ),
		'93' => array( "Provence-Alpes-Côte d'Azur", '43.9588', '6.0645' ),
		'94' => array( 'Corse', '42.1523', '9.1074' ),
	);

	/**
	 * Variantes, sigles et anciennes régions => code de la région actuelle.
	 */
	const ALIASES = array(
		'IDF'                  => '11',
		'Région parisienne'    => '11',
		'PACA'                 => '93',
		'Région Sud'           => '93',
		'AURA'                 => '84',
		'HDF'                  => '32',
		'BFC'                  => '27',
		'Région Centre'        => '24',
		'Réunion'              => '04',
		'Guyane française'     => '03',
		'Alsace'               => '44',
		'Lorraine'             => '44',
		'Champagne-Ardenne'    => '44',
		'Aquitaine'            => '75',
		'Limousin'             => '75',
		'Poitou-Charentes'     => '75',
		'Auvergne'             => '84',
		'Rhône-Alpes'          => '84',
		'Bourgogne'            => '27',
		'Franche-Comté'        => '27',
		'Midi-Pyrénées'        => '76',
		'Languedoc-Roussillon' => '76',
		'Nord-Pas-de-Calais'   => '32',
		'Picardie'             => '32',
		'Haute-Normandie'      => '28',
		'Basse-Normandie'      => '28',
	);

	/**
	 * Forme de comparaison => code, construit au premier appel.
	 *
	 * @var array<string,string>|null
	 */
	private static $index = null;

	/**
	 * Codes INSEE des 18 régions.
	 *
	 * @return list<string>
	 */
	public static function codes() {
		return array_map( 'strval', array_keys( self::DATA ) );
	}

	/**
	 * Région d'après son code.
	 *
	 * @param string $code Code INSEE.
	 * @return array{code:string,name:string,lat:string,lng:string}|null
	 */
	public static function get( $code ) {
		$code = (string) $code;
		if ( ! isset( self::DATA[ $code ] ) ) {
			return null;
		}
		return array(
			'code' => $code,
			'name' => self::DATA[ $code ][0],
			'lat'  => self::DATA[ $code ][1],
			'lng'  => self::DATA[ $code ][2],
		);
	}

	/**
	 * Région désignée par un texte : nom, variante, sigle ou ancien nom. Accents,
	 * casse, ponctuation, article et préfixe « Région » sont ignorés.
	 *
	 * @param string $text Texte.
	 * @return array{code:string,name:string,lat:string,lng:string}|null
	 */
	public static function find( $text ) {
		$key = self::key( $text );
		if ( '' === $key ) {
			return null;
		}
		$index = self::index();
		if ( ! isset( $index[ $key ] ) && 0 === strpos( $key, 'region ' ) ) {
			$key = self::key( substr( $key, 7 ) );
		}
		return isset( $index[ $key ] ) ? self::get( $index[ $key ] ) : null;
	}

	/**
	 * Liste transmise à l'autocomplétion : code, nom officiel et autres noms reconnus.
	 *
	 * @return list<array{code:string,name:string,aliases:list<string>}>
	 */
	public static function all() {
		$aliases = array();
		foreach ( self::ALIASES as $alias => $code ) {
			$aliases[ $code ][] = (string) $alias;
		}
		$out = array();
		foreach ( self::DATA as $code => $row ) {
			$code  = (string) $code;
			$out[] = array(
				'code'    => $code,
				'name'    => $row[0],
				'aliases' => isset( $aliases[ $code ] ) ? $aliases[ $code ] : array(),
			);
		}
		return $out;
	}

	/**
	 * Forme de comparaison (celle du géocodeur, sans article).
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	private static function key( $text ) {
		return PhotonGeocoder::without_article( PhotonGeocoder::normalize( (string) $text ) );
	}

	/**
	 * Index des noms reconnus.
	 *
	 * @return array<string,string>
	 */
	private static function index() {
		if ( null === self::$index ) {
			self::$index = array();
			foreach ( self::DATA as $code => $row ) {
				self::$index[ self::key( $row[0] ) ] = (string) $code;
			}
			foreach ( self::ALIASES as $alias => $code ) {
				self::$index[ self::key( (string) $alias ) ] = $code;
			}
		}
		return self::$index;
	}
}

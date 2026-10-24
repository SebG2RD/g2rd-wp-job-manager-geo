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
	 * Code INSEE => nom officiel, latitude et longitude du centre, boîte englobante
	 * du contour [ouest, sud, est, nord] arrondie vers l'extérieur à 4 décimales.
	 */
	const DATA = array(
		'01' => array( 'Guadeloupe', '16.1596', '-61.6805', array( '-61.8060', '15.8400', '-61.0020', '16.5101' ) ),
		'02' => array( 'Martinique', '14.6562', '-61.0217', array( '-61.2270', '14.3980', '-60.8240', '14.8770' ) ),
		'03' => array( 'Guyane', '3.9150', '-53.2403', array( '-54.6020', '2.1130', '-51.6260', '5.7490' ) ),
		'04' => array( 'La Réunion', '-21.1334', '55.5322', array( '55.2170', '-21.3890', '55.8370', '-20.8720' ) ),
		'06' => array( 'Mayotte', '-12.8202', '45.1424', array( '45.0420', '-13.0000', '45.2960', '-12.6730' ) ),
		'11' => array( 'Île-de-France', '48.7091', '2.5042', array( '1.4470', '48.1200', '3.5560', '49.2320' ) ),
		'24' => array( 'Centre-Val de Loire', '47.4851', '1.6843', array( '0.0540', '46.3470', '3.1280', '48.9410' ) ),
		'27' => array( 'Bourgogne-Franche-Comté', '47.2342', '4.8062', array( '2.8450', '46.1569', '7.1420', '48.3990' ) ),
		'28' => array( 'Normandie', '49.1202', '0.1128', array( '-1.9460', '48.1820', '1.8020', '50.0690' ) ),
		'32' => array( 'Hauts-de-France', '49.9691', '2.7709', array( '1.3810', '48.8370', '4.2560', '51.0890' ) ),
		'44' => array( 'Grand Est', '48.6883', '5.6124', array( '3.3840', '47.4250', '8.2231', '50.1620' ) ),
		'52' => array( 'Pays de la Loire', '47.4801', '-0.8126', array( '-2.5590', '46.2780', '0.9170', '48.5680' ) ),
		'53' => array( 'Bretagne', '48.1794', '-2.8331', array( '-5.1410', '47.2879', '-1.0209', '48.8670' ) ),
		'75' => array( 'Nouvelle-Aquitaine', '45.2037', '0.2128', array( '-1.7920', '42.7790', '2.6071', '47.1760' ) ),
		'76' => array( 'Occitanie', '43.7026', '2.1453', array( '-0.3270', '42.3340', '4.8460', '45.0440' ) ),
		'84' => array( 'Auvergne-Rhône-Alpes', '45.5127', '4.5361', array( '2.0630', '44.1160', '7.1850', '46.8040' ) ),
		'93' => array( "Provence-Alpes-Côte d'Azur", '43.9588', '6.0645', array( '4.2300', '42.9910', '7.7160', '45.1270' ) ),
		'94' => array( 'Corse', '42.1523', '9.1074', array( '8.5510', '41.3660', '9.5590', '43.0110' ) ),
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
		// Noms anglais et allemands : ceux que Photon renvoie selon la langue du site.
		'Normandy'            => '28',
		'Upper Normandy'      => '28',
		'Lower Normandy'      => '28',
		'Brittany'            => '53',
		'Corsica'             => '94',
		'Korsika'             => '94',
		'Occitania'           => '76',
		'Okzitanien'          => '76',
		'New Aquitaine'       => '75',
		'Neu-Aquitanien'      => '75',
		'French Guiana'       => '03',
		'Französisch-Guayana' => '03',
		'Burgundy'            => '27',
		'Picardy'             => '32',
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
	 * @return array{code:string,name:string,lat:string,lng:string,bbox:list<float>}|null
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
			'bbox' => array_map( 'floatval', self::DATA[ $code ][3] ),
		);
	}

	/**
	 * Région désignée par un texte : nom, variante, sigle ou ancien nom. Accents,
	 * casse, ponctuation, article et préfixe « Région » sont ignorés.
	 *
	 * @param string $text Texte.
	 * @return array{code:string,name:string,lat:string,lng:string,bbox:list<float>}|null
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

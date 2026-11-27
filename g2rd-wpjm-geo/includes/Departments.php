<?php
/**
 * Départements français reconnus dans le champ « Lieu ».
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Les 95 départements de métropole (codes INSEE), sauf Paris : la ville et le
 * département couvrent le même territoire, Paris reste une ville. Les départements
 * d'outre-mer sont des régions (Regions). Centre et boîte calculés comme pour les
 * régions (tools/regions/extract-regions.js). Aucun appel réseau.
 */
class Departments {

	/**
	 * Code INSEE => nom officiel, latitude et longitude du centre, boîte englobante
	 * du contour [ouest, sud, est, nord] arrondie vers l'extérieur à 4 décimales,
	 * code de la région.
	 */
	const DATA = array(
		'01' => array( 'Ain', '46.0993', '5.3482', array( '4.7310', '45.6130', '6.1700', '46.5180' ), '84' ),
		'02' => array( 'Aisne', '49.5602', '3.5580', array( '2.9640', '48.8370', '4.2560', '50.0690' ), '32' ),
		'03' => array( 'Allier', '46.3938', '3.1885', array( '2.2810', '45.9310', '4.0050', '46.8040' ), '84' ),
		'04' => array( 'Alpes-de-Haute-Provence', '44.1065', '6.2454', array( '5.4990', '43.6680', '6.9620', '44.6570' ), '93' ),
		'05' => array( 'Hautes-Alpes', '44.6635', '6.2628', array( '5.4180', '44.1860', '7.0770', '45.1270' ), '93' ),
		'06' => array( 'Alpes-Maritimes', '43.9392', '7.1166', array( '6.6360', '43.4799', '7.7160', '44.3610' ), '93' ),
		'07' => array( 'Ardèche', '44.7526', '4.4260', array( '3.8630', '44.2640', '4.8860', '45.3660' ), '84' ),
		'08' => array( 'Ardennes', '49.6154', '4.6409', array( '4.0320', '49.2269', '5.3940', '50.1620' ), '44' ),
		'09' => array( 'Ariège', '42.9219', '1.5044', array( '0.8350', '42.5740', '2.1760', '43.3100' ), '76' ),
		'10' => array( 'Aube', '48.3048', '4.1612', array( '3.3840', '47.9240', '4.8620', '48.7170' ), '44' ),
		'11' => array( 'Aude', '43.1030', '2.4141', array( '1.6880', '42.6490', '3.2401', '43.4600' ), '76' ),
		'12' => array( 'Aveyron', '44.2815', '2.6785', array( '1.8480', '43.6930', '3.4510', '44.9410' ), '76' ),
		'13' => array( 'Bouches-du-Rhône', '43.5462', '5.0857', array( '4.2300', '43.1599', '5.8130', '43.9240' ), '93' ),
		'14' => array( 'Calvados', '49.0989', '-0.3618', array( '-1.1570', '48.7520', '0.4460', '49.4300' ), '28' ),
		'15' => array( 'Cantal', '45.0507', '2.6693', array( '2.0630', '44.6160', '3.3710', '45.4790' ), '84' ),
		'16' => array( 'Charente', '45.7183', '0.2021', array( '-0.4630', '45.1920', '0.9430', '46.1390' ), '75' ),
		'17' => array( 'Charente-Maritime', '45.7653', '-0.6541', array( '-1.5610', '45.0900', '0.0060', '46.3710' ), '75' ),
		'18' => array( 'Cher', '47.0662', '2.4913', array( '1.7740', '46.4200', '3.0790', '47.6290' ), '24' ),
		'19' => array( 'Corrèze', '45.3575', '1.8783', array( '1.2260', '44.9200', '2.5290', '45.7640' ), '75' ),
		'21' => array( "Côte-d'Or", '47.4263', '4.7730', array( '4.0650', '46.9010', '5.5180', '48.0310' ), '27' ),
		'22' => array( "Côtes-d'Armor", '48.4389', '-2.8663', array( '-3.6640', '48.0330', '-1.9090', '48.8670' ), '53' ),
		'23' => array( 'Creuse', '46.0909', '2.0184', array( '1.3750', '45.6640', '2.6071', '46.4550' ), '75' ),
		'24' => array( 'Dordogne', '45.1051', '0.7396', array( '-0.0400', '44.5720', '1.4480', '45.7151' ), '75' ),
		'25' => array( 'Doubs', '47.1660', '6.3631', array( '5.6990', '46.5540', '7.0530', '47.5800' ), '27' ),
		'26' => array( 'Drôme', '44.6803', '5.1632', array( '4.6500', '44.1160', '5.8270', '45.3420' ), '84' ),
		'27' => array( 'Eure', '49.1141', '0.9968', array( '0.2970', '48.6670', '1.8020', '49.4840' ), '28' ),
		'28' => array( 'Eure-et-Loir', '48.3881', '1.3700', array( '0.7600', '47.9540', '1.9940', '48.9410' ), '24' ),
		'29' => array( 'Finistère', '48.2599', '-4.0530', array( '-5.1410', '47.7670', '-3.3870', '48.7530' ), '53' ),
		'2A' => array( 'Corse-du-Sud', '41.8640', '8.9890', array( '8.5510', '41.3660', '9.4070', '42.3820' ), '94' ),
		'2B' => array( 'Haute-Corse', '42.3937', '9.2064', array( '8.5730', '41.8320', '9.5590', '43.0110' ), '94' ),
		'30' => array( 'Gard', '43.9935', '4.1792', array( '3.2630', '43.4610', '4.8460', '44.4600' ), '76' ),
		'31' => array( 'Haute-Garonne', '43.3597', '1.1755', array( '0.4420', '42.6890', '2.0480', '43.9180' ), '76' ),
		'32' => array( 'Gers', '43.6930', '0.4532', array( '-0.2820', '43.3110', '1.2020', '44.0781' ), '76' ),
		'33' => array( 'Gironde', '44.8387', '-0.5831', array( '-1.2610', '44.1960', '0.3150', '45.5750' ), '75' ),
		'34' => array( 'Hérault', '43.5792', '3.3676', array( '2.5400', '43.2130', '4.1940', '43.9670' ), '76' ),
		'35' => array( 'Ille-et-Vilaine', '48.1508', '-1.6335', array( '-2.2880', '47.6320', '-1.0209', '48.7020' ), '53' ),
		'36' => array( 'Indre', '46.7786', '1.5761', array( '0.8670', '46.3470', '2.2000', '47.2770' ), '24' ),
		'37' => array( 'Indre-et-Loire', '47.2583', '0.6911', array( '0.0540', '46.7370', '1.3630', '47.6990' ), '24' ),
		'38' => array( 'Isère', '45.2643', '5.5745', array( '4.7450', '44.6960', '6.3590', '45.8800' ), '84' ),
		'39' => array( 'Jura', '46.7298', '5.6971', array( '5.2520', '46.2610', '6.2030', '47.3060' ), '27' ),
		'40' => array( 'Landes', '43.9659', '-0.7839', array( '-1.5210', '43.4930', '0.1370', '44.5320' ), '75' ),
		'41' => array( 'Loir-et-Cher', '47.6170', '1.4274', array( '0.5800', '47.1860', '2.2481', '48.1330' ), '24' ),
		'42' => array( 'Loire', '45.7279', '4.1647', array( '3.6940', '45.2310', '4.7600', '46.2761' ), '84' ),
		'43' => array( 'Haute-Loire', '45.1278', '3.8077', array( '3.0980', '44.7440', '4.4830', '45.4280' ), '84' ),
		'44' => array( 'Loire-Atlantique', '47.3632', '-1.6786', array( '-2.5590', '46.8600', '-0.9470', '47.8320' ), '52' ),
		'45' => array( 'Loiret', '47.9120', '2.3437', array( '1.5120', '47.4829', '3.1280', '48.3450' ), '24' ),
		'46' => array( 'Lot', '44.6244', '1.6049', array( '0.9820', '44.2030', '2.2081', '45.0440' ), '76' ),
		'47' => array( 'Lot-et-Garonne', '44.3679', '0.4607', array( '-0.1410', '43.9739', '1.0750', '44.7620' ), '75' ),
		'48' => array( 'Lozère', '44.5177', '3.5000', array( '2.9820', '44.1140', '3.9980', '44.9760' ), '76' ),
		'49' => array( 'Maine-et-Loire', '47.3901', '-0.5595', array( '-1.3541', '46.9760', '0.2340', '47.8100' ), '52' ),
		'50' => array( 'Manche', '49.0812', '-1.3288', array( '-1.9460', '48.4570', '-0.7370', '49.7260' ), '28' ),
		'51' => array( 'Marne', '48.9502', '4.2386', array( '3.3990', '48.5150', '5.0390', '49.4080' ), '44' ),
		'52' => array( 'Haute-Marne', '48.1106', '5.2258', array( '4.6270', '47.5770', '5.8910', '48.6890' ), '44' ),
		'53' => array( 'Mayenne', '48.1471', '-0.6568', array( '-1.2380', '47.7329', '-0.0540', '48.5680' ), '52' ),
		'54' => array( 'Meurthe-et-Moselle', '48.7872', '6.1625', array( '5.4260', '48.3500', '7.1230', '49.5630' ), '44' ),
		'55' => array( 'Meuse', '48.9908', '5.3817', array( '4.8890', '48.4110', '5.8540', '49.6170' ), '44' ),
		'56' => array( 'Morbihan', '47.8548', '-2.8031', array( '-3.7330', '47.2879', '-2.0360', '48.2100' ), '53' ),
		'57' => array( 'Moselle', '49.0360', '6.6616', array( '5.8930', '48.5280', '7.6350', '49.5080' ), '44' ),
		'58' => array( 'Nièvre', '47.1162', '3.5046', array( '2.8450', '46.6520', '4.2290', '47.5841' ), '27' ),
		'59' => array( 'Nord', '50.4495', '3.2124', array( '2.0890', '49.9709', '4.2280', '51.0890' ), '32' ),
		'60' => array( 'Oise', '49.4101', '2.4230', array( '1.6890', '49.0610', '3.1660', '49.7580' ), '32' ),
		'61' => array( 'Orne', '48.6233', '0.1277', array( '-0.8600', '48.1820', '0.9760', '48.9730' ), '28' ),
		'62' => array( 'Pas-de-Calais', '50.4924', '2.2896', array( '1.5550', '50.0240', '3.1870', '51.0040' ), '32' ),
		'63' => array( 'Puy-de-Dôme', '45.7262', '3.1397', array( '2.3880', '45.2870', '3.9850', '46.2570' ), '84' ),
		'64' => array( 'Pyrénées-Atlantiques', '43.2566', '-0.7575', array( '-1.7920', '42.7790', '0.0260', '43.5970' ), '75' ),
		'65' => array( 'Hautes-Pyrénées', '43.0525', '0.1662', array( '-0.3270', '42.6730', '0.6420', '43.6080' ), '76' ),
		'66' => array( 'Pyrénées-Orientales', '42.5995', '2.5207', array( '1.7250', '42.3340', '3.1740', '42.9190' ), '76' ),
		'67' => array( 'Bas-Rhin', '48.6709', '7.5517', array( '6.9450', '48.1200', '8.2231', '49.0781' ), '44' ),
		'68' => array( 'Haut-Rhin', '47.8606', '7.2740', array( '6.8460', '47.4250', '7.6210', '48.3100' ), '44' ),
		'69' => array( 'Rhône', '45.8716', '4.6409', array( '4.2440', '45.4540', '5.1590', '46.3030' ), '84' ),
		'70' => array( 'Haute-Saône', '47.6407', '6.0867', array( '5.3720', '47.2550', '6.8240', '48.0240' ), '27' ),
		'71' => array( 'Saône-et-Loire', '46.6453', '4.5416', array( '3.6290', '46.1569', '5.4630', '47.1550' ), '27' ),
		'72' => array( 'Sarthe', '47.9951', '0.2232', array( '-0.4480', '47.5680', '0.9170', '48.4830' ), '52' ),
		'73' => array( 'Savoie', '45.4779', '6.4422', array( '5.6240', '45.0520', '7.1850', '45.9380' ), '84' ),
		'74' => array( 'Haute-Savoie', '46.0350', '6.4292', array( '5.8060', '45.6840', '7.0440', '46.4080' ), '84' ),
		'76' => array( 'Seine-Maritime', '49.6544', '1.0286', array( '0.0670', '49.2520', '1.7890', '50.0690' ), '28' ),
		'77' => array( 'Seine-et-Marne', '48.6278', '2.9338', array( '2.3920', '48.1200', '3.5560', '49.1180' ), '11' ),
		'78' => array( 'Yvelines', '48.8153', '1.8418', array( '1.4470', '48.4400', '2.2270', '49.0841' ), '11' ),
		'79' => array( 'Deux-Sèvres', '46.5564', '-0.3186', array( '-0.9040', '45.9700', '0.2160', '47.1080' ), '75' ),
		'80' => array( 'Somme', '49.9575', '2.2769', array( '1.3810', '49.5720', '3.2030', '50.3620' ), '32' ),
		'81' => array( 'Tarn', '43.7856', '2.1665', array( '1.5350', '43.3820', '2.9350', '44.2010' ), '76' ),
		'82' => array( 'Tarn-et-Garonne', '44.0864', '1.2830', array( '0.7420', '43.7710', '1.9900', '44.3940' ), '76' ),
		'83' => array( 'Var', '43.4439', '6.2452', array( '5.6689', '42.9910', '6.9330', '43.8060' ), '93' ),
		'84' => array( 'Vaucluse', '43.9942', '5.1853', array( '4.6490', '43.6590', '5.7570', '44.4230' ), '93' ),
		'85' => array( 'Vendée', '46.6737', '-1.2877', array( '-2.3990', '46.2780', '-0.5380', '47.0830' ), '52' ),
		'86' => array( 'Vienne', '46.5648', '0.4585', array( '-0.1020', '46.0490', '1.2130', '47.1760' ), '75' ),
		'87' => array( 'Haute-Vienne', '45.8934', '1.2346', array( '0.6290', '45.4440', '1.9020', '46.4020' ), '75' ),
		'88' => array( 'Vosges', '48.1958', '6.3815', array( '5.3940', '47.8130', '7.1980', '48.5140' ), '44' ),
		'89' => array( 'Yonne', '47.8402', '3.5633', array( '2.8490', '47.3110', '4.3400', '48.3990' ), '27' ),
		'90' => array( 'Territoire de Belfort', '47.6334', '6.9276', array( '6.7580', '47.4330', '7.1420', '47.8230' ), '27' ),
		'91' => array( 'Essonne', '48.5221', '2.2432', array( '1.9220', '48.2870', '2.5840', '48.7761' ), '11' ),
		'92' => array( 'Hauts-de-Seine', '48.8437', '2.2415', array( '2.1460', '48.7290', '2.3371', '48.9510' ), '11' ),
		'93' => array( 'Seine-Saint-Denis', '48.9180', '2.4792', array( '2.2879', '48.8070', '2.6031', '49.0120' ), '11' ),
		'94' => array( 'Val-de-Marne', '48.7766', '2.4690', array( '2.3160', '48.6890', '2.6151', '48.8610' ), '11' ),
		'95' => array( "Val-d'Oise", '49.0820', '2.1302', array( '1.6090', '48.9090', '2.5951', '49.2320' ), '11' ),
	);

	/**
	 * Forme de comparaison => code, construit au premier appel.
	 *
	 * @var array<string,string>|null
	 */
	private static $index = null;

	/**
	 * Codes INSEE des 95 départements.
	 *
	 * @return list<string>
	 */
	public static function codes() {
		return array_map( 'strval', array_keys( self::DATA ) );
	}

	/**
	 * Département d'après son code.
	 *
	 * @param string $code Code INSEE.
	 * @return array{code:string,name:string,lat:string,lng:string,bbox:list<float>,region:string}|null
	 */
	public static function get( $code ) {
		$code = (string) $code;
		if ( ! isset( self::DATA[ $code ] ) ) {
			return null;
		}
		return array(
			'code'   => $code,
			'name'   => self::DATA[ $code ][0],
			'lat'    => self::DATA[ $code ][1],
			'lng'    => self::DATA[ $code ][2],
			'bbox'   => array_map( 'floatval', self::DATA[ $code ][3] ),
			'region' => self::DATA[ $code ][4],
		);
	}

	/**
	 * Département désigné par un texte : son nom officiel. Accents, casse, ponctuation,
	 * article et préfixe « Département (de, du, de la, des, d') » sont ignorés.
	 *
	 * @param string $text Texte.
	 * @return array{code:string,name:string,lat:string,lng:string,bbox:list<float>,region:string}|null
	 */
	public static function find( $text ) {
		$key   = PhotonGeocoder::without_article( (string) preg_replace( '/^departement (?:de la |de l |du |des |de |d )?/', '', self::key( $text ) ) );
		$index = self::index();
		return ( '' !== $key && isset( $index[ $key ] ) ) ? self::get( $index[ $key ] ) : null;
	}

	/**
	 * Liste transmise à l'autocomplétion : code et nom officiel.
	 *
	 * @return list<array{code:string,name:string}>
	 */
	public static function all() {
		$out = array();
		foreach ( self::DATA as $code => $row ) {
			$out[] = array(
				'code' => (string) $code,
				'name' => $row[0],
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
		}
		return self::$index;
	}
}

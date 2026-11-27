<?php
/**
 * Lieux d'une offre : texte du champ « Lieu » → villes, départements et régions placés.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Découpe le texte (PlaceParser), reconnaît les régions et les départements
 * français (Regions, Departments, sans réseau) et géocode les villes avec Photon.
 *
 * Règle de la virgule : les segments qui suivent le premier le précisent s'ils
 * égalent le pays, la région ou le département du lieu trouvé ; sinon chacun
 * devient un lieu à part. Si le texte entier ne donne rien (« Rouen, Caen,
 * Normandie » : Photon ne renvoie que 5 résultats), chaque segment est cherché
 * seul, et une région déjà couverte par une ville du même morceau est retirée.
 */
class PlaceLocator {

	const MAX_PLACES = 10;
	const UNLIMITED  = -1;

	/**
	 * Réponse interne : budget d'appels épuisé.
	 */
	const DEFER = 'defer';

	/**
	 * Appels réels à Photon par locate() au plus : un texte démesuré (virgules à la
	 * chaîne) ne bloque ni la file d'attente ni l'instance publique de Photon.
	 */
	const MAX_LOOKUPS = 20;

	/**
	 * Géocodeur.
	 *
	 * @var PhotonGeocoder
	 */
	private $geocoder;

	/**
	 * Pause après chaque appel réel à Photon (files et outils), ou null.
	 *
	 * @var callable|null
	 */
	private $sleeper;

	/**
	 * Textes absents du cache encore autorisés pendant locate() (UNLIMITED : sans limite).
	 *
	 * @var int
	 */
	private $budget = self::UNLIMITED;

	/**
	 * Appels réels à Photon déjà faits pendant locate().
	 *
	 * @var int
	 */
	private $lookups = 0;

	/**
	 * Horloge en secondes (remplaçable dans les tests), ou null : microtime().
	 *
	 * @var callable|null
	 */
	private $clock;

	/**
	 * Instant au-delà duquel locate() n'envoie plus de texte à Photon, ou null : sans échéance.
	 *
	 * @var float|null
	 */
	private $deadline = null;

	/**
	 * Constructeur.
	 *
	 * @param PhotonGeocoder $geocoder Géocodeur.
	 * @param callable|null  $sleeper  Pause en secondes après chaque appel réel ; null : aucune.
	 * @param callable|null  $clock    Horloge en secondes ; null : microtime().
	 */
	public function __construct( PhotonGeocoder $geocoder, $sleeper = null, $clock = null ) {
		$this->geocoder = $geocoder;
		$this->sleeper  = is_callable( $sleeper ) ? $sleeper : null;
		$this->clock    = is_callable( $clock ) ? $clock : null;
	}

	/**
	 * Place les lieux d'un texte.
	 *
	 * @param string    $text    Texte du champ « Lieu ».
	 * @param int       $budget  Textes absents du cache que l'on peut envoyer à Photon (UNLIMITED : sans limite).
	 * @param int|float $seconds Délai après lequel plus aucun texte absent du cache n'est envoyé à Photon (0 : sans délai).
	 *                           Un appel déjà parti peut encore durer PhotonGeocoder::TIMEOUT.
	 * @return array{places:list<array<string,string>>,address:array<string,string>|null,missing:list<string>,deferred:bool}|\WP_Error
	 */
	public function locate( $text, $budget = self::UNLIMITED, $seconds = 0 ) {
		$this->budget   = (int) $budget;
		$this->lookups  = 0;
		$this->deadline = $seconds > 0 ? $this->now() + (float) $seconds : null;
		$found         = array();
		$seen          = array();
		$missing       = array();
		$queue         = PlaceParser::split( $text );
		while ( $queue && count( $found ) < self::MAX_PLACES ) {
			$result = $this->piece( (string) array_shift( $queue ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( ! is_array( $result ) ) {
				return array(
					'places'   => array(),
					'address'  => null,
					'missing'  => array(),
					'deferred' => true,
				);
			}
			foreach ( $result['found'] as $entry ) {
				$place = $entry['place'];
				if ( 'region' === $place['type'] ) {
					$key = 'r:' . $place['region'];
				} elseif ( 'department' === $place['type'] ) {
					$key = 'd:' . $place['department'];
				} else {
					$key = 'c:' . $place['lat'] . ',' . $place['lng'];
				}
				if ( ! isset( $seen[ $key ] ) && count( $found ) < self::MAX_PLACES ) {
					$seen[ $key ] = true;
					$found[]      = $entry;
				}
			}
			$missing = array_merge( $missing, $result['missing'] );
			// Les segments devenus des lieux à part passent juste après le lieu dont ils viennent.
			$queue = array_merge( $result['more'], $queue );
		}
		return array(
			'places'   => array_values( array_column( $found, 'place' ) ),
			'address'  => $found ? $found[0]['address'] : null,
			'missing'  => array_values( array_unique( $missing ) ),
			'deferred' => false,
		);
	}

	/**
	 * Place un morceau (texte sans séparateur fort).
	 *
	 * @param string $piece Morceau.
	 * @return array{found:list<array{place:array<string,string>,address:array<string,string>}>,missing:list<string>,more:list<string>}|\WP_Error|string DEFER si le budget est épuisé.
	 */
	private function piece( $piece ) {
		$segments = array_slice(
			array_values(
				array_filter(
					array_map( 'trim', explode( ',', $piece ) ),
					function ( $segment ) {
						return '' !== $segment;
					}
				)
			),
			0,
			self::MAX_PLACES
		);
		if ( ! $segments ) {
			return self::result( array(), array(), array() );
		}
		$first = $segments[0];
		$rest  = array_slice( $segments, 1 );

		$hinted = self::hinted( $first, $rest );
		$region = $hinted ? null : Regions::find( $first );
		if ( $region ) {
			$more   = array();
			$within = false;
			foreach ( $rest as $segment ) {
				if ( '' !== self::segment_key( $segment ) ) {
					$more[] = $segment;
					$named  = Departments::find( $segment );
					$within = $within || ( $named && $named['region'] === $region['code'] );
				}
			}
			// « Normandie, Calvados » : le département précise la région, qui n'est pas gardée.
			return self::result( $within ? array() : array( self::region_entry( $region ) ), array(), $more );
		}

		$department = $hinted ? null : Departments::find( $first );
		if ( $department && self::frames( $department, $rest ) ) {
			return self::result( array( self::department_entry( $department ) ), array(), array() );
		}

		$detailed = $this->geocode( $piece );
		if ( is_wp_error( $detailed ) || self::DEFER === $detailed ) {
			return $detailed;
		}
		// Département suivi d'autres lieux (« Vienne, Isère ») : une ville seulement si Photon trouve une commune, pas la limite du département.
		if ( is_array( $detailed ) && ( ! $department || '' !== $detailed['address']['city'] ) ) {
			return self::result( array( self::city_entry( $detailed ) ), array(), self::unused( $detailed, $rest ) );
		}
		if ( ! $rest ) {
			return self::result( array(), array( $piece ), array() );
		}
		return $this->each_segment( $segments );
	}

	/**
	 * Seconde passe : chaque segment est cherché seul. Un segment qui précise une
	 * ville trouvée dans le même morceau (son pays, sa région, son département) n'est
	 * pas un lieu, et une région déjà couverte par une de ces villes est retirée.
	 *
	 * @param list<string> $segments Segments du morceau.
	 * @return array{found:list<array{place:array<string,string>,address:array<string,string>}>,missing:list<string>,more:list<string>}|\WP_Error|string
	 */
	private function each_segment( array $segments ) {
		$items   = array();
		$lost    = array();
		$covered = array();
		foreach ( $segments as $segment ) {
			$key = self::segment_key( $segment );
			if ( '' === $key || self::in_context( $key, $items, -1 ) ) {
				continue; // Ni un lieu, ni autre chose qu'une précision d'une ville déjà trouvée.
			}
			$region = '' === PhotonGeocoder::hint( $segment ) ? Regions::find( $segment ) : null;
			if ( $region ) {
				$items[] = array(
					'key'     => $key,
					'entry'   => self::region_entry( $region ),
					'context' => array(),
				);
				continue;
			}
			$department = '' === PhotonGeocoder::hint( $segment ) ? Departments::find( $segment ) : null;
			if ( $department ) {
				$covered[ $department['region'] ] = true;
				$items[]                          = array(
					'key'     => $key,
					'entry'   => self::department_entry( $department ),
					'context' => array(),
				);
				continue;
			}
			$detailed = $this->geocode( $segment );
			if ( is_wp_error( $detailed ) || self::DEFER === $detailed ) {
				return $detailed;
			}
			if ( ! is_array( $detailed ) ) {
				$lost[ $key ] = $segment;
				continue;
			}
			$own = Regions::find( $detailed['address']['state_long'] );
			if ( $own ) {
				$covered[ $own['code'] ] = true;
			}
			$items[] = array(
				'key'     => $key,
				'entry'   => self::city_entry( $detailed ),
				'context' => self::context( $detailed ),
			);
		}
		$found = array();
		foreach ( $items as $index => $item ) {
			$place      = $item['entry']['place'];
			$is_covered = 'region' === $place['type'] && isset( $covered[ $place['region'] ] );
			if ( ! $is_covered && ! self::in_context( $item['key'], $items, $index ) ) {
				$found[] = $item['entry'];
			}
		}
		$missing = array();
		foreach ( $lost as $key => $segment ) {
			if ( ! self::in_context( (string) $key, $items, -1 ) ) {
				$missing[] = $segment;
			}
		}
		return self::result( $found, $missing, array() );
	}

	/**
	 * Segments qui ne précisent pas le lieu trouvé (ni son pays, ni sa région, ni son département).
	 *
	 * @param array{address:array<string,string>,county:string,name:string} $detailed Résultat de geocode_detailed().
	 * @param list<string>                                                   $rest     Segments suivant le premier.
	 * @return list<string>
	 */
	private static function unused( array $detailed, array $rest ) {
		$context = self::context( $detailed );
		$own     = Regions::find( $detailed['address']['state_long'] );
		$more    = array();
		foreach ( $rest as $segment ) {
			$key = self::segment_key( $segment );
			if ( '' === $key || in_array( $key, $context, true ) ) {
				continue;
			}
			$named = Regions::find( $segment );
			if ( $own && $named && $own['code'] === $named['code'] ) {
				continue;
			}
			$more[] = $segment;
		}
		return $more;
	}

	/**
	 * Formes comparées du pays, de la région et du département d'un lieu trouvé.
	 *
	 * @param array{address:array<string,string>,county:string,name:string} $detailed Résultat de geocode_detailed().
	 * @return list<string>
	 */
	private static function context( array $detailed ) {
		$context = array();
		foreach ( array( $detailed['address']['country_long'], $detailed['address']['state_long'], $detailed['county'] ) as $value ) {
			if ( '' !== (string) $value ) {
				$context[] = self::key( $value );
			}
		}
		return $context;
	}

	/**
	 * Le segment précise-t-il une ville trouvée du morceau (autre que lui-même) ?
	 *
	 * @param string                                                                                                    $key   Forme comparée du segment.
	 * @param list<array{key:string,entry:array{place:array<string,string>,address:array<string,string>},context:list<string>}> $items Lieux du morceau.
	 * @param int                                                                                                       $skip  Index du segment lui-même, -1 si aucun.
	 * @return bool
	 */
	private static function in_context( $key, array $items, $skip ) {
		foreach ( $items as $index => $item ) {
			if ( $index !== $skip && in_array( $key, $item['context'], true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Les segments qui suivent un département ne font-ils que le situer (sa région,
	 * le pays, lui-même) ? « Calvados, Normandie » oui, « Vienne, Isère » non.
	 *
	 * @param array{code:string,region:string} $department Département.
	 * @param list<string>                     $rest       Segments suivant le premier.
	 * @return bool
	 */
	private static function frames( array $department, array $rest ) {
		foreach ( $rest as $segment ) {
			if ( '' === self::segment_key( $segment ) ) {
				continue;
			}
			$region = Regions::find( $segment );
			$same   = Departments::find( $segment );
			if ( ! ( $region && $region['code'] === $department['region'] ) && ! ( $same && $same['code'] === $department['code'] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Le morceau porte-t-il un indice de département ou de code postal pour son premier
	 * segment (« Bretagne (90) », « Bretagne, 90300 ») ? Un indice placé dans un autre
	 * lieu (« Bretagne, Rennes (35) ») ne compte pas.
	 *
	 * @param string       $first Premier segment.
	 * @param list<string> $rest  Segments suivants.
	 * @return bool
	 */
	private static function hinted( $first, array $rest ) {
		if ( '' !== PhotonGeocoder::hint( $first ) ) {
			return true;
		}
		foreach ( $rest as $segment ) {
			if ( '' === self::segment_key( $segment ) && '' !== PhotonGeocoder::hint( $segment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Forme comparée d'un segment, ou chaîne vide s'il ne désigne aucun lieu :
	 * indice seul (« 76000 », « (76) »), mention vague, pays du site (« France »).
	 *
	 * @param string $segment Segment.
	 * @return string
	 */
	private static function segment_key( $segment ) {
		$key = self::key( PhotonGeocoder::clean( $segment ) );
		return ( in_array( $key, PhotonGeocoder::VAGUE, true ) || 'france' === $key ) ? '' : $key;
	}

	/**
	 * Géocode un texte en respectant le budget et l'échéance, puis marque une pause après un appel réel.
	 *
	 * @param string $text Texte.
	 * @return array{address:array<string,string>,county:string,name:string}|null|\WP_Error|string DEFER si le budget est épuisé ou le délai écoulé.
	 */
	private function geocode( $text ) {
		if ( ! $this->geocoder->is_cached( $text ) ) {
			if ( 0 === $this->budget || ( null !== $this->deadline && $this->now() >= $this->deadline ) ) {
				return self::DEFER;
			}
			if ( $this->lookups >= self::MAX_LOOKUPS ) {
				return null; // Plafond atteint : le texte est compté introuvable.
			}
			if ( $this->budget > 0 ) {
				--$this->budget;
			}
			++$this->lookups;
		}
		$result = $this->geocoder->geocode_detailed( $text );
		if ( $this->sleeper && ! $this->geocoder->last_was_cached() ) {
			call_user_func( $this->sleeper, 1 );
		}
		return $result;
	}

	/**
	 * Horloge en secondes.
	 *
	 * @return float
	 */
	private function now() {
		return $this->clock ? (float) call_user_func( $this->clock ) : microtime( true );
	}

	/**
	 * Lieu « ville ».
	 *
	 * @param array{address:array<string,string>,county:string,name:string} $detailed Résultat de geocode_detailed().
	 * @return array{place:array<string,string>,address:array<string,string>}
	 */
	private static function city_entry( array $detailed ) {
		$address = $detailed['address'];
		return array(
			'place'   => array(
				'type'  => 'city',
				'label' => '' !== $detailed['name'] ? $detailed['name'] : (string) $address['city'],
				'lat'   => (string) $address['lat'],
				'lng'   => (string) $address['long'],
			),
			'address' => $address,
		);
	}

	/**
	 * Lieu « région » : centre fixe, adresse régionale pour les champs de WP Job Manager.
	 *
	 * @param array{code:string,name:string,lat:string,lng:string} $region Région.
	 * @return array{place:array<string,string>,address:array<string,string>}
	 */
	private static function region_entry( array $region ) {
		return array(
			'place'   => array(
				'type'   => 'region',
				'label'  => $region['name'],
				'lat'    => $region['lat'],
				'lng'    => $region['lng'],
				'region' => $region['code'],
			),
			'address' => array(
				'lat'               => $region['lat'],
				'long'              => $region['lng'],
				'formatted_address' => $region['name'] . ', France',
				'city'              => '',
				'state_long'        => $region['name'],
				'postcode'          => '',
				'country_long'      => 'France',
				'country_short'     => 'FR',
			),
		);
	}

	/**
	 * Lieu « département » : centre fixe, adresse avec la région pour les champs de WP Job Manager.
	 *
	 * @param array{code:string,name:string,lat:string,lng:string,region:string} $department Département.
	 * @return array{place:array<string,string>,address:array<string,string>}
	 */
	private static function department_entry( array $department ) {
		$region = Regions::get( $department['region'] );
		return array(
			'place'   => array(
				'type'       => 'department',
				'label'      => $department['name'],
				'lat'        => $department['lat'],
				'lng'        => $department['lng'],
				'department' => $department['code'],
			),
			'address' => array(
				'lat'               => $department['lat'],
				'long'              => $department['lng'],
				'formatted_address' => $department['name'] . ', France',
				'city'              => '',
				'state_long'        => $region ? $region['name'] : '',
				'postcode'          => '',
				'country_long'      => 'France',
				'country_short'     => 'FR',
			),
		);
	}

	/**
	 * Résultat d'un morceau.
	 *
	 * @param list<array{place:array<string,string>,address:array<string,string>}> $found   Lieux placés.
	 * @param list<string>                                                         $missing Textes introuvables.
	 * @param list<string>                                                         $more    Segments à traiter comme des morceaux à part.
	 * @return array{found:list<array{place:array<string,string>,address:array<string,string>}>,missing:list<string>,more:list<string>}
	 */
	private static function result( array $found, array $missing, array $more ) {
		return array(
			'found'   => $found,
			'missing' => $missing,
			'more'    => $more,
		);
	}

	/**
	 * Forme de comparaison (sans accents, casse, ponctuation ni article).
	 *
	 * @param string $text Texte.
	 * @return string
	 */
	private static function key( $text ) {
		return PhotonGeocoder::without_article( PhotonGeocoder::normalize( (string) $text ) );
	}
}

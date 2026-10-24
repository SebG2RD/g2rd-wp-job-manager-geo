<?php
/**
 * Outil « Re-géocoder les offres » avec le fournisseur actif.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jamais lancé automatiquement. Analyse (sans réseau), simulation (réseau,
 * sans écriture) puis exécution, par lots de 5 via admin-ajax. L'outil
 * n'efface jamais de coordonnées : une offre introuvable reste en l'état et
 * est listée pour correction manuelle.
 */
class RegeocodeTool {

	const AJAX_ACTION  = 'g2rd_wpjm_geo_regeocode';
	const NONCE_ACTION = 'g2rd_wpjm_geo_regeocode';
	const BATCH        = 5;
	const TIME_BUDGET  = 20; // Secondes par requête AJAX.
	const KEY_OFFSET   = 1000000000; // Clé de curseur : offres publiées (rang 0) avant les autres (rang 1).

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Placement des lieux (mode libre).
	 *
	 * @var PlaceLocator
	 */
	private $locator;

	/**
	 * Pause entre deux appels réels à Photon.
	 *
	 * @var callable
	 */
	private $sleeper;

	/**
	 * Constructeur.
	 *
	 * @param Settings       $settings Réglages.
	 * @param PhotonGeocoder $geocoder Géocodeur.
	 * @param callable|null  $sleeper  Pause en secondes ; défaut sleep().
	 */
	public function __construct( Settings $settings, PhotonGeocoder $geocoder, $sleeper = null ) {
		$this->settings = $settings;
		$this->sleeper  = is_callable( $sleeper ) ? $sleeper : 'sleep';
		$this->locator  = new PlaceLocator( $geocoder, $this->sleeper );
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'g2rd_wpjm_geo_settings_tools', array( $this, 'render' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax' ) );
	}

	/**
	 * Offres à localisation renseignée (hors corbeille et brouillons automatiques).
	 *
	 * @return array<int,array<string,string|null>> Lignes id, status, location, source, geolocated, places.
	 */
	public static function rows() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- agrégat en lecture seule sur quatre métadonnées, sans équivalent WP_Query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, p.post_status AS status, loc.meta_value AS location, src.meta_value AS source, geo.meta_value AS geolocated, pl.meta_id AS places
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} loc ON loc.post_id = p.ID AND loc.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} geo ON geo.post_id = p.ID AND geo.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} pl ON pl.post_id = p.ID AND pl.meta_key = %s
				WHERE p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' ) AND loc.meta_value <> ''
				ORDER BY p.ID ASC",
				'_job_location',
				GeocodingIntegration::META_SOURCE,
				'geolocated',
				GeocodingIntegration::META_PLACES,
				'job_listing'
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Résumé par source.
	 *
	 * @param array<int,array<string,string|null>> $rows     Lignes de rows().
	 * @param string                               $provider Fournisseur actif.
	 * @return array{total:int,google:int,osm:int,none:int,shown:int,update:int,todo:int,todo_ids:list<int>,todo_keys:list<int>}
	 */
	public static function summarize( array $rows, $provider ) {
		$summary = array(
			'total'     => 0,
			'google'    => 0,
			'osm'       => 0,
			'none'      => 0,
			'shown'     => 0,
			'update'    => 0,
			'todo'      => 0,
			'todo_ids'  => array(),
			'todo_keys' => array(),
		);
		$seen    = array();
		$todo    = array();
		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) ? absint( $row['id'] ) : 0;
			if ( ! $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			++$summary['total'];

			$source = ( isset( $row['source'] ) && Settings::PROVIDER_OSM === $row['source'] ) ? Settings::PROVIDER_OSM : Settings::PROVIDER_GOOGLE;
			if ( ! isset( $row['geolocated'] ) || '1' !== (string) $row['geolocated'] ) {
				++$summary['none'];
			} else {
				++$summary[ $source ];
				if ( $source === $provider ) {
					++$summary['shown'];
					if ( Settings::PROVIDER_OSM !== $provider || ! empty( $row['places'] ) ) {
						continue;
					}
					// Offre libre antérieure à la 1.2.0 (un seul point) : reprise pour placer tous ses lieux.
					++$summary['update'];
				}
			}
			++$summary['todo'];
			$todo[ self::order_key( $id, isset( $row['status'] ) ? $row['status'] : null ) ] = $id;
		}
		// Offres publiées d'abord : ce sont celles que la carte affiche.
		ksort( $todo );
		$summary['todo_ids']  = array_values( $todo );
		$summary['todo_keys'] = array_keys( $todo );
		return $summary;
	}

	/**
	 * Clé de parcours : rang (0 publiée, 1 autre statut) × KEY_OFFSET + ID.
	 *
	 * @param int         $id     ID de l'offre.
	 * @param string|null $status Statut WordPress (absent : publiée).
	 * @return int
	 */
	public static function order_key( $id, $status ) {
		$rank = ( null === $status || '' === (string) $status || 'publish' === $status ) ? 0 : 1;
		return $rank * self::KEY_OFFSET + absint( $id );
	}

	/**
	 * Résumé pour le fournisseur actif (bandeau de la page).
	 *
	 * @param string $provider Fournisseur actif.
	 * @return array{total:int,google:int,osm:int,none:int,shown:int,update:int,todo:int,todo_ids:list<int>,todo_keys:list<int>}
	 */
	public static function stats( $provider ) {
		return self::summarize( self::rows(), $provider );
	}

	/**
	 * Point d'entrée AJAX.
	 *
	 * @return void
	 */
	public function ajax() {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'g2rd-wpjm-geo' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$task   = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$cursor = isset( $_POST['cursor'] ) ? absint( wp_unslash( $_POST['cursor'] ) ) : 0;
		if ( ! in_array( $task, array( 'analyze', 'simulate', 'run' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Tâche inconnue.', 'g2rd-wpjm-geo' ) ), 400 );
		}
		$result = $this->handle( $task, $cursor, self::rows() );
		if ( 'run' === $task && $result['rows'] ) {
			// Compteurs relus après les écritures du lot : sinon le dernier lot affiche un reste périmé.
			$result['summary'] = array_diff_key(
				self::summarize( self::rows(), $this->settings->get_provider() ),
				array(
					'todo_ids' => true,
					'todo_keys' => true,
				)
			);
		}
		wp_send_json_success( $result );
	}

	/**
	 * Traite un lot.
	 *
	 * @param string                               $task   analyze, simulate ou run.
	 * @param int                                  $cursor Dernier ID traité.
	 * @param array<int,array<string,string|null>> $rows   Lignes de rows().
	 * @return array<string,mixed> summary, rows, cursor, done, stopped.
	 */
	public function handle( $task, $cursor, array $rows ) {
		$provider = $this->settings->get_provider();
		$summary  = self::summarize( $rows, $provider );
		$public   = array_diff_key(
			$summary,
			array(
				'todo_ids' => true,
				'todo_keys' => true,
			)
		);

		if ( 'analyze' === $task ) {
			return array(
				'summary' => $public,
				'rows' => array(),
				'cursor' => 0,
				'done' => true,
				'stopped' => false,
			);
		}

		$cursor  = absint( $cursor );
		$keys    = array_values(
			array_filter(
				$summary['todo_keys'],
				function ( $key ) use ( $cursor ) {
					return $key > $cursor;
				}
			)
		);
		$results = array();
		$stopped = false;
		$start   = $this->now();
		foreach ( array_slice( $keys, 0, self::BATCH ) as $key ) {
			$elapsed = $this->now() - $start;
			if ( $results && $elapsed > self::TIME_BUDGET ) {
				// Requête AJAX courte : la suite au lot suivant.
				break;
			}
			// Appels à Photon bornés par le temps restant (environ 2 s chacun, pause comprise).
			$budget = max( 1, (int) floor( ( self::TIME_BUDGET - $elapsed ) / 2 ) );
			$row    = $this->process( $key % self::KEY_OFFSET, 'run' === $task, $provider, $budget );
			if ( null === $row ) {
				// Offre trop longue pour ce lot : reprise au lot suivant, avec le cache déjà rempli.
				break;
			}
			$cursor    = $key;
			$results[] = $row;
			if ( 'error' === $row['status'] ) {
				// Service indisponible : inutile d'insister sur les offres suivantes.
				$stopped = true;
				break;
			}
		}

		return array(
			'summary' => $public,
			'rows'    => $results,
			'cursor'  => $cursor,
			'done'    => $stopped || count( $results ) >= count( $keys ),
			'stopped' => $stopped,
		);
	}

	/**
	 * Horloge en secondes (remplaçable dans les tests).
	 *
	 * @return float
	 */
	protected function now() {
		return microtime( true );
	}

	/**
	 * Re-géocode (ou simule) une offre.
	 *
	 * @param int    $job_id   ID de l'offre.
	 * @param bool   $write    Écrire le résultat.
	 * @param string $provider Fournisseur actif.
	 * @param int    $budget   Textes absents du cache que l'on peut envoyer à Photon.
	 * @return array{id:int,location:string,result:string,status:string,edit:string}|null Statut : ok|done|not_found|error|pending ; null : offre reportée au lot suivant (temps épuisé).
	 */
	private function process( $job_id, $write, $provider, $budget = PlaceLocator::UNLIMITED ) {
		$location = trim( (string) get_post_meta( $job_id, '_job_location', true ) );
		$row      = array(
			'id'       => $job_id,
			'location' => $location,
			'result'   => '',
			'status'   => '',
			'edit'     => (string) get_edit_post_link( $job_id, 'raw' ),
		);
		if ( '' === trim( str_replace( array( ',', '?', '&', '=', '#' ), '', $location ) ) ) {
			// Rien de géocodable : listée « Non trouvée », sans arrêter le traitement.
			$row['status'] = 'not_found';
			return $row;
		}

		if ( Settings::PROVIDER_OSM === $provider ) {
			$located = $this->locator->locate( $location, $budget );
			if ( is_wp_error( $located ) ) {
				$row['status'] = 'error';
				$row['result'] = $located->get_error_message();
				return $row;
			}
			if ( $located['deferred'] ) {
				return null;
			}
			if ( ! $located['places'] ) {
				$row['status'] = 'not_found';
				if ( $located['missing'] ) {
					/* translators: %s: comma-separated locations that were not found. */
					$row['result'] = sprintf( __( 'introuvable : %s', 'g2rd-wpjm-geo' ), implode( ', ', $located['missing'] ) );
				}
				return $row;
			}
			$row['result'] = self::describe( $located );
			if ( $write ) {
				GeocodingIntegration::write_places( $job_id, $located );
				$row['status'] = 'done';
			} else {
				$row['status'] = 'ok';
			}
			return $row;
		}

		if ( ! $write ) {
			$row['status'] = 'pending';
			$row['result'] = __( 'Géocodage par WP Job Manager (Google) à l’exécution', 'g2rd-wpjm-geo' );
			return $row;
		}
		$data = class_exists( 'WP_Job_Manager_Geocode' ) ? \WP_Job_Manager_Geocode::get_location_data( $location ) : false;
		// Message exact de WP Job Manager pour ZERO_RESULTS (même domaine, même traduction) ; tout autre
		// WP_Error vient d'une clé refusée, d'un quota ou du réseau.
		$zero = __( 'No results found', 'wp-job-manager' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch
		if ( is_wp_error( $data ) && $zero !== $data->get_error_message() ) {
			$row['status'] = 'error';
			/* translators: %s: WP Job Manager error message. */
			$row['result'] = sprintf( __( 'Géocodage Google en échec (clé refusée, quota ou réseau) : %s', 'g2rd-wpjm-geo' ), $data->get_error_message() );
			return $row;
		}
		if ( is_wp_error( $data ) ) {
			$row['status'] = 'not_found'; // Aucun résultat chez Google : offre listée « Non trouvée ».
			return $row;
		}
		if ( ! is_array( $data ) || ! $data ) {
			$row['status'] = 'error';
			$row['result'] = __( 'Géocodage Google indisponible (quota atteint ou géocodage désactivé).', 'g2rd-wpjm-geo' );
			return $row;
		}
		$label         = implode( ', ', array_filter( array( isset( $data['city'] ) ? $data['city'] : '', isset( $data['country_long'] ) ? $data['country_long'] : '' ) ) );
		$row['result'] = '' !== $label ? $label : (string) ( isset( $data['formatted_address'] ) ? $data['formatted_address'] : '' );
		GeocodingIntegration::write( $job_id, $data, $provider );
		$row['status'] = 'done';
		return $row;
	}

	/**
	 * Résultat lisible : « Rouen, France » pour une ville seule, sinon la liste des
	 * lieux ; puis les lieux introuvables.
	 *
	 * @param array<string,mixed> $located Résultat de PlaceLocator::locate().
	 * @return string
	 */
	private static function describe( array $located ) {
		$address = (array) $located['address'];
		$places  = (array) $located['places'];
		if ( 1 === count( $places ) && 'city' === $places[0]['type'] ) {
			$text = implode( ', ', array_filter( array( (string) $address['city'], (string) $address['country_long'] ) ) );
			$text = '' !== $text ? $text : (string) $address['formatted_address'];
		} else {
			$text = implode( ', ', array_column( $places, 'label' ) );
		}
		if ( ! empty( $located['missing'] ) ) {
			/* translators: %s: comma-separated locations that were not found. */
			$text .= ' — ' . sprintf( __( 'introuvable : %s', 'g2rd-wpjm-geo' ), implode( ', ', (array) $located['missing'] ) );
		}
		return $text;
	}

	/**
	 * Script de l'outil, sur la page du plugin uniquement.
	 *
	 * @param string $hook_suffix Écran courant.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( Settings::is_settings_screen( $hook_suffix ) ) {
			wp_enqueue_script( 'g2rd-wpjm-geo-admin-regeocode', G2RD_WPJM_GEO_URL . 'assets/js/admin-regeocode.js', array(), G2RD_WPJM_GEO_VERSION, true );
		}
	}

	/**
	 * Carte de l'outil.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			return;
		}
		$is_osm = Settings::PROVIDER_OSM === $this->settings->get_provider();
		$i18n   = array(
			'running'      => __( 'Traitement en cours…', 'g2rd-wpjm-geo' ),
			'done'         => __( 'Terminé.', 'g2rd-wpjm-geo' ),
			'simulated'    => __( 'Simulation : aucune donnée n’a été modifiée.', 'g2rd-wpjm-geo' ),
			'stopped'      => __( 'Service de géocodage indisponible : traitement interrompu. Relancez plus tard.', 'g2rd-wpjm-geo' ),
			'error'        => __( 'Une erreur est survenue. Le traitement a été interrompu ; vous pouvez le relancer.', 'g2rd-wpjm-geo' ),
			'confirm'      => __( 'Les coordonnées des offres listées vont être remplacées. Continuer ?', 'g2rd-wpjm-geo' ),
			'total'        => __( 'Offres avec une localisation', 'g2rd-wpjm-geo' ),
			'google'       => __( 'Géocodées par Google', 'g2rd-wpjm-geo' ),
			'osm'          => __( 'Géocodées par OpenStreetMap', 'g2rd-wpjm-geo' ),
			'update'       => __( 'Libres, à mettre à jour (plusieurs lieux)', 'g2rd-wpjm-geo' ),
			'none'         => __( 'Sans coordonnées', 'g2rd-wpjm-geo' ),
			'todo'         => __( 'À re-géocoder', 'g2rd-wpjm-geo' ),
			'job'          => __( 'Offre', 'g2rd-wpjm-geo' ),
			'location'     => __( 'Localisation', 'g2rd-wpjm-geo' ),
			'result'       => __( 'Résultat', 'g2rd-wpjm-geo' ),
			'status'       => __( 'État', 'g2rd-wpjm-geo' ),
			'statusLabels' => array(
				'ok'        => __( 'Prévu', 'g2rd-wpjm-geo' ),
				'done'      => __( 'Re-géocodée', 'g2rd-wpjm-geo' ),
				'not_found' => __( 'Non trouvée', 'g2rd-wpjm-geo' ),
				'error'     => __( 'Erreur', 'g2rd-wpjm-geo' ),
				'pending'   => __( 'À l’exécution', 'g2rd-wpjm-geo' ),
			),
		);
		?>
		<section class="g2rd-section" id="g2rd-geo-outils">
			<div class="g2rd-section__head"><h3 class="g2rd-section__title"><?php esc_html_e( 'Re-géocoder les offres', 'g2rd-wpjm-geo' ); ?></h3></div>
			<p class="g2rd-section__desc">
				<?php
				echo esc_html(
					$is_osm
						? __( 'Recalcule avec Photon (OpenStreetMap) les coordonnées des offres qui ne viennent pas de la source libre, et place tous les lieux (villes et régions) des offres antérieures à la version 1.2.0. Les offres introuvables ne sont jamais effacées : elles sont listées pour correction.', 'g2rd-wpjm-geo' )
						: __( 'Recalcule avec Google, via WP Job Manager et sa clé serveur, les coordonnées des offres géocodées par OpenStreetMap. Les offres introuvables ne sont jamais effacées.', 'g2rd-wpjm-geo' )
				);
				?>
			</p>
			<div class="g2rd-wpjm-geo-regeocode"
				data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
				data-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>">
				<div class="g2rd-row">
					<button type="button" class="button" data-g2rd-regeo="analyze"><?php esc_html_e( 'Analyser', 'g2rd-wpjm-geo' ); ?></button>
					<button type="button" class="button" data-g2rd-regeo="simulate"><?php esc_html_e( 'Simuler', 'g2rd-wpjm-geo' ); ?></button>
					<button type="button" class="button button-primary" data-g2rd-regeo="run"><?php esc_html_e( 'Re-géocoder', 'g2rd-wpjm-geo' ); ?></button>
				</div>
				<p class="g2rd-muted" role="status" aria-live="polite" data-g2rd-regeo-status></p>
				<div data-g2rd-regeo-summary></div>
				<div data-g2rd-regeo-rows></div>
			</div>
		</section>
		<?php
	}
}

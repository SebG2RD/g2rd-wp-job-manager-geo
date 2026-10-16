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

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Géocodeur libre.
	 *
	 * @var PhotonGeocoder
	 */
	private $geocoder;

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
		$this->geocoder = $geocoder;
		$this->sleeper  = is_callable( $sleeper ) ? $sleeper : 'sleep';
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
	 * @return array[] Lignes id, location, source, geolocated.
	 */
	public static function rows() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- agrégat en lecture seule sur trois métadonnées, sans équivalent WP_Query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS id, loc.meta_value AS location, src.meta_value AS source, geo.meta_value AS geolocated
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} loc ON loc.post_id = p.ID AND loc.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} src ON src.post_id = p.ID AND src.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} geo ON geo.post_id = p.ID AND geo.meta_key = %s
				WHERE p.post_type = %s AND p.post_status NOT IN ( 'trash', 'auto-draft' ) AND loc.meta_value <> ''
				ORDER BY p.ID ASC",
				'_job_location',
				GeocodingIntegration::META_SOURCE,
				'geolocated',
				'job_listing'
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Résumé par source.
	 *
	 * @param array  $rows     Lignes de rows().
	 * @param string $provider Fournisseur actif.
	 * @return array total, google, osm, none, shown, todo, todo_ids.
	 */
	public static function summarize( array $rows, $provider ) {
		$summary = array(
			'total'    => 0,
			'google'   => 0,
			'osm'      => 0,
			'none'     => 0,
			'shown'    => 0,
			'todo'     => 0,
			'todo_ids' => array(),
		);
		$seen    = array();
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
					continue;
				}
			}
			++$summary['todo'];
			$summary['todo_ids'][] = $id;
		}
		return $summary;
	}

	/**
	 * Résumé pour le fournisseur actif (bandeau de la page).
	 *
	 * @param string $provider Fournisseur actif.
	 * @return array
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
		wp_send_json_success( $this->handle( $task, $cursor, self::rows() ) );
	}

	/**
	 * Traite un lot.
	 *
	 * @param string $task   analyze, simulate ou run.
	 * @param int    $cursor Dernier ID traité.
	 * @param array  $rows   Lignes de rows().
	 * @return array summary, rows, cursor, done, stopped.
	 */
	public function handle( $task, $cursor, array $rows ) {
		$provider = $this->settings->get_provider();
		$summary  = self::summarize( $rows, $provider );
		$public   = array_diff_key( $summary, array( 'todo_ids' => true ) );

		if ( 'analyze' === $task ) {
			return array( 'summary' => $public, 'rows' => array(), 'cursor' => 0, 'done' => true, 'stopped' => false );
		}

		$cursor  = absint( $cursor );
		$ids     = array_values(
			array_filter(
				$summary['todo_ids'],
				function ( $id ) use ( $cursor ) {
					return $id > $cursor;
				}
			)
		);
		$results = array();
		$stopped = false;
		foreach ( array_slice( $ids, 0, self::BATCH ) as $id ) {
			$cursor    = $id;
			$row       = $this->process( $id, 'run' === $task, $provider );
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
			'done'    => $stopped || count( $ids ) <= self::BATCH,
			'stopped' => $stopped,
		);
	}

	/**
	 * Re-géocode (ou simule) une offre.
	 *
	 * @param int    $job_id   ID de l'offre.
	 * @param bool   $write    Écrire le résultat.
	 * @param string $provider Fournisseur actif.
	 * @return array id, location, result, status (ok|done|not_found|error|pending), edit.
	 */
	private function process( $job_id, $write, $provider ) {
		$location = trim( (string) get_post_meta( $job_id, '_job_location', true ) );
		$row      = array(
			'id'       => $job_id,
			'location' => $location,
			'result'   => '',
			'status'   => '',
			'edit'     => (string) get_edit_post_link( $job_id, 'raw' ),
		);

		if ( Settings::PROVIDER_OSM === $provider ) {
			$result = $this->geocoder->geocode( $location );
			if ( ! $this->geocoder->last_was_cached() ) {
				call_user_func( $this->sleeper, 1 );
			}
		} elseif ( ! $write ) {
			$row['status'] = 'pending';
			$row['result'] = __( 'Géocodage par WP Job Manager (Google) à l’exécution', 'g2rd-wpjm-geo' );
			return $row;
		} else {
			$data = class_exists( 'WP_Job_Manager_Geocode' ) ? \WP_Job_Manager_Geocode::get_location_data( $location ) : false;
			if ( is_array( $data ) && $data ) {
				$result = $data;
			} elseif ( is_wp_error( $data ) ) {
				$result = null; // Pas de résultat chez Google : offre listée « Non trouvée ».
			} else {
				$result = new \WP_Error( 'g2rd_geocode_unavailable', __( 'Géocodage Google indisponible (clé absente ou quota atteint).', 'g2rd-wpjm-geo' ) );
			}
		}

		if ( is_wp_error( $result ) ) {
			$row['status'] = 'error';
			$row['result'] = $result->get_error_message();
			return $row;
		}
		if ( null === $result ) {
			$row['status'] = 'not_found';
			return $row;
		}

		$label         = implode( ', ', array_filter( array( isset( $result['city'] ) ? $result['city'] : '', isset( $result['country_long'] ) ? $result['country_long'] : '' ) ) );
		$row['result'] = '' !== $label ? $label : (string) ( isset( $result['formatted_address'] ) ? $result['formatted_address'] : '' );
		if ( $write ) {
			GeocodingIntegration::write( $job_id, $result, $provider );
			$row['status'] = 'done';
		} else {
			$row['status'] = 'ok';
		}
		return $row;
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
						? __( 'Recalcule avec Photon (OpenStreetMap) les coordonnées des offres qui ne viennent pas de la source libre. Les offres introuvables ne sont jamais effacées : elles sont listées pour correction.', 'g2rd-wpjm-geo' )
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

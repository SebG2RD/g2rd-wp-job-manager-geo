<?php
/**
 * Outil d'analyse et de réparation des offres Go Fetch Jobs existantes.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rien n'est exécuté à l'activation : l'administrateur déclenche l'analyse,
 * la simulation ou la correction, traitées par lots via admin-ajax.
 */
class MigrationTool {

	const AJAX_ACTION   = 'g2rd_wpjm_geo_migrate';
	const NONCE_ACTION  = 'g2rd_wpjm_geo_migrate';
	const BATCH_DEFAULT = 50;
	// 2 offres x (HEAD 5 s + GET 5 s) = 20 s au pire : reste sous les 30 s habituels de max_execution_time.
	const BATCH_HTTP    = 2;

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Normaliseur.
	 *
	 * @var ApplicationNormalizer
	 */
	private $normalizer;

	/**
	 * Journal.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructeur.
	 *
	 * @param Settings              $settings   Réglages.
	 * @param ApplicationNormalizer $normalizer Normaliseur.
	 * @param Logger                $logger     Journal.
	 */
	public function __construct( Settings $settings, ApplicationNormalizer $normalizer, Logger $logger ) {
		$this->settings   = $settings;
		$this->normalizer = $normalizer;
		$this->logger     = $logger;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'g2rd_wpjm_geo_settings_tools', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax' ) );
	}

	/**
	 * Script de l'outil, uniquement sur la page de réglages du plugin.
	 *
	 * @param string $hook_suffix Écran courant.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( Settings::is_settings_screen( $hook_suffix ) ) {
			wp_enqueue_script( 'g2rd-wpjm-geo-admin-tools', G2RD_WPJM_GEO_URL . 'assets/js/admin-tools.js', array(), G2RD_WPJM_GEO_VERSION, true );
		}
	}

	/**
	 * Rendu de l'outil et du journal.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			return;
		}

		$mode  = $this->settings->get_mode();
		$email = $this->settings->get_fallback_email();
		$i18n  = array(
			'running'   => __( 'Traitement en cours…', 'g2rd-wpjm-geo' ),
			'done'      => __( 'Terminé.', 'g2rd-wpjm-geo' ),
			'error'     => __( 'Une erreur est survenue. Le traitement a été interrompu ; vous pouvez le relancer.', 'g2rd-wpjm-geo' ),
			'confirm'   => __( 'Les offres Go Fetch Jobs concernées vont être réellement modifiées. Continuer ?', 'g2rd-wpjm-geo' ),
			'analyzed'  => __( 'Offres importées analysées', 'g2rd-wpjm-geo' ),
			'email'     => __( 'Contenant une adresse email', 'g2rd-wpjm-geo' ),
			'url'       => __( 'Contenant une URL', 'g2rd-wpjm-geo' ),
			'empty'     => __( 'Champ vide ou autre valeur', 'g2rd-wpjm-geo' ),
			'toFix'     => __( 'Nécessitant une correction', 'g2rd-wpjm-geo' ),
			'fixed'     => __( 'Corrigées', 'g2rd-wpjm-geo' ),
			'job'       => __( 'Offre', 'g2rd-wpjm-geo' ),
			'current'   => __( 'Valeur actuelle :', 'g2rd-wpjm-geo' ),
			'next'      => __( 'Nouvelle valeur :', 'g2rd-wpjm-geo' ),
			'reason'    => __( 'Raison :', 'g2rd-wpjm-geo' ),
			'results'   => __( 'Offres à corriger', 'g2rd-wpjm-geo' ),
			'simulated' => __( 'Simulation : aucune donnée n’a été modifiée.', 'g2rd-wpjm-geo' ),
			'truncated' => __( 'Seules les 500 premières offres sont détaillées.', 'g2rd-wpjm-geo' ),
		);
		?>
		<section class="g2rd-section" id="g2rd-geo-gofetch-outil">
			<div class="g2rd-section__head"><h3 class="g2rd-section__title"><?php esc_html_e( 'Offres Go Fetch Jobs existantes', 'g2rd-wpjm-geo' ); ?></h3></div>
			<p class="g2rd-section__desc"><?php esc_html_e( 'Aucune modification n’est lancée automatiquement. Analysez d’abord, simulez, puis corrigez. Le traitement est réalisé par lots pour éviter les dépassements de délai. Les réglages utilisés sont ceux actuellement enregistrés.', 'g2rd-wpjm-geo' ); ?></p>

			<?php if ( ! Dependencies::has_gofetch() ) : ?>
				<div class="g2rd-alert g2rd-alert--info"><p><?php esc_html_e( 'Go Fetch Jobs n’est pas actif. L’outil peut néanmoins traiter les offres importées précédemment (elles conservent leur marqueur d’import).', 'g2rd-wpjm-geo' ); ?></p></div>
			<?php endif; ?>
			<?php if ( Settings::MODE_KEEP === $mode ) : ?>
				<div class="g2rd-alert g2rd-alert--warning"><p><?php esc_html_e( 'Le remplacement est désactivé dans les réglages : l’analyse fonctionne, mais aucune offre ne sera signalée comme à corriger.', 'g2rd-wpjm-geo' ); ?></p></div>
			<?php elseif ( '' === $email ) : ?>
				<div class="g2rd-alert g2rd-alert--warning"><p><?php esc_html_e( 'Aucune adresse email générique valide n’est configurée : aucune correction ne sera effectuée.', 'g2rd-wpjm-geo' ); ?></p></div>
			<?php endif; ?>

			<div class="g2rd-wpjm-geo-tools"
				data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				data-action="<?php echo esc_attr( self::AJAX_ACTION ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
				data-i18n="<?php echo esc_attr( wp_json_encode( $i18n ) ); ?>">
				<div class="g2rd-row g2rd-row--wrap">
					<button type="button" class="button" data-g2rd-run="analyze"><?php esc_html_e( 'Analyser les offres Go Fetch Jobs existantes', 'g2rd-wpjm-geo' ); ?></button>
					<label class="g2rd-row"><input type="checkbox" data-g2rd-dry-run checked /> <?php esc_html_e( 'Mode simulation (aucune donnée n’est modifiée)', 'g2rd-wpjm-geo' ); ?></label>
					<button type="button" class="button button-primary" data-g2rd-run="fix"><?php esc_html_e( 'Corriger les offres', 'g2rd-wpjm-geo' ); ?></button>
				</div>
				<p class="g2rd-wpjm-geo-tools__status g2rd-muted" role="status" aria-live="polite"></p>
				<div class="g2rd-wpjm-geo-tools__stats"></div>
				<div class="g2rd-wpjm-geo-tools__rows" style="overflow-x:auto"></div>
			</div>
		</section>
		<?php
		$this->render_log();
	}

	/**
	 * Affiche les dernières entrées du journal.
	 *
	 * @return void
	 */
	private function render_log() {
		echo '<section class="g2rd-section" id="g2rd-geo-journal-entrees">';
		echo '<div class="g2rd-section__head"><h3 class="g2rd-section__title">' . esc_html__( 'Journal des remplacements', 'g2rd-wpjm-geo' ) . '</h3></div>';

		if ( ! $this->settings->get( 'log_enabled' ) ) {
			echo '<div class="g2rd-alert g2rd-alert--info"><p>' . esc_html__( 'Le journal est désactivé.', 'g2rd-wpjm-geo' ) . '</p></div>';
		}

		$rows = $this->logger->latest( 50 );
		if ( empty( $rows ) ) {
			echo '<div class="g2rd-empty"><p>' . esc_html__( 'Aucune entrée pour le moment.', 'g2rd-wpjm-geo' ) . '</p></div></section>';
			return;
		}

		echo '<p class="g2rd-section__desc">' . esc_html__( '50 dernières entrées.', 'g2rd-wpjm-geo' ) . '</p>';
		echo '<table class="widefat striped g2rd-table"><caption class="screen-reader-text">' . esc_html__( 'Journal des remplacements', 'g2rd-wpjm-geo' ) . '</caption><thead><tr>';
		foreach ( array( __( 'Offre', 'g2rd-wpjm-geo' ), __( 'Date', 'g2rd-wpjm-geo' ), __( 'Ancienne valeur', 'g2rd-wpjm-geo' ), __( 'Nouvelle valeur', 'g2rd-wpjm-geo' ), __( 'Raison', 'g2rd-wpjm-geo' ), __( 'Source', 'g2rd-wpjm-geo' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$job_id = absint( $row['job_id'] );
			$link   = get_edit_post_link( $job_id );
			echo '<tr>';
			echo '<td>' . ( $link ? '<a href="' . esc_url( $link ) . '">#' . esc_html( (string) $job_id ) . '</a>' : '#' . esc_html( (string) $job_id ) ) . '</td>';
			echo '<td class="is-date">' . esc_html( get_date_from_gmt( $row['created_at'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td>';
			// overflow-wrap en ligne : une URL importée sans point de coupure ne doit pas élargir la carte (console.css est une copie intégrale du thème).
			echo '<td class="is-mono" style="overflow-wrap:anywhere">' . esc_html( $row['old_value'] ) . '</td>';
			echo '<td class="is-mono" style="overflow-wrap:anywhere">' . esc_html( $row['new_value'] ) . '</td>';
			echo '<td><span class="g2rd-tag g2rd-tag--info">' . esc_html( Logger::reason_label( $row['reason'] ) ) . '</span></td>';
			echo '<td>' . esc_html( $row['source'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></section>';
	}

	/**
	 * Traite un lot.
	 *
	 * @return void
	 */
	public function ajax() {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Droits insuffisants.', 'g2rd-wpjm-geo' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$task    = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$cursor  = isset( $_POST['cursor'] ) ? absint( wp_unslash( $_POST['cursor'] ) ) : 0;
		$dry_run = ! isset( $_POST['dry_run'] ) || '0' !== sanitize_text_field( wp_unslash( $_POST['dry_run'] ) );

		if ( ! in_array( $task, array( 'analyze', 'fix' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Tâche inconnue.', 'g2rd-wpjm-geo' ) ), 400 );
		}
		// L'analyse n'écrit jamais, quel que soit le paramètre reçu.
		if ( 'analyze' === $task ) {
			$dry_run = true;
		}

		$needs_http = Settings::MODE_INVALID === $this->settings->get_mode();
		$ids        = $this->get_batch( $cursor, $needs_http ? self::BATCH_HTTP : self::BATCH_DEFAULT );

		$stats = array(
			'analyzed' => 0,
			'email'    => 0,
			'url'      => 0,
			'empty'    => 0,
			'toFix'    => 0,
			'fixed'    => 0,
		);
		$rows  = array();

		foreach ( $ids as $job_id ) {
			$cursor = $job_id;
			++$stats['analyzed'];

			$decision = $this->normalizer->handle_with_check( $job_id, $dry_run );

			// Le type est recalculé ici pour les compteurs, même lorsque le remplacement est désactivé.
			$type = $this->normalizer->classify( get_post_meta( $job_id, ApplicationNormalizer::META_APPLICATION, true ) );
			if ( ApplicationNormalizer::ACTION_REPLACE === $decision['action'] ) {
				$type = ApplicationNormalizer::TYPE_URL;
			}
			if ( ApplicationNormalizer::TYPE_EMAIL === $type ) {
				++$stats['email'];
			} elseif ( ApplicationNormalizer::TYPE_URL === $type ) {
				++$stats['url'];
			} else {
				++$stats['empty'];
			}

			if ( ApplicationNormalizer::ACTION_REPLACE === $decision['action'] ) {
				++$stats['toFix'];
				if ( ! $dry_run ) {
					++$stats['fixed'];
				}
				$rows[] = array(
					'id'     => $job_id,
					'old'    => $decision['old'],
					'new'    => $decision['new'],
					'reason' => Logger::reason_label( $decision['reason'] ),
				);
			}
		}

		wp_send_json_success(
			array(
				'stats'  => $stats,
				'rows'   => $rows,
				'cursor' => $cursor,
				'done'   => empty( $ids ),
				'dryRun' => $dry_run,
			)
		);
	}

	/**
	 * Lot suivant d'offres Go Fetch Jobs (tous statuts sauf corbeille et brouillons automatiques).
	 *
	 * @param int $after_id Dernier ID traité.
	 * @param int $limit    Taille du lot.
	 * @return int[]
	 */
	private function get_batch( $after_id, $limit ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- lecture paginée par curseur, sans équivalent performant via WP_Query.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type = %s
				AND p.post_status NOT IN ( 'trash', 'auto-draft' )
				AND m.meta_value NOT IN ( '', '0' )
				AND p.ID > %d
				ORDER BY p.ID ASC
				LIMIT %d",
				ApplicationNormalizer::META_GOFETCH,
				ApplicationNormalizer::POST_TYPE,
				absint( $after_id ),
				absint( $limit )
			)
		);

		return array_map( 'absint', (array) $ids );
	}
}

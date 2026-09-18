<?php
/**
 * File d'attente WP-Cron pour la vérification différée des URL.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * En mode « uniquement les URL invalides ou inaccessibles », l'import se
 * contente d'empiler l'ID de l'offre : les requêtes HTTP sont effectuées plus
 * tard, par petits lots, pour ne jamais ralentir Go Fetch Jobs.
 */
class UrlCheckQueue {

	const OPTION     = 'g2rd_wpjm_geo_url_queue';
	const CRON_HOOK  = 'g2rd_wpjm_geo_check_urls';
	const LOCK       = 'g2rd_wpjm_geo_url_queue_lock';
	const BATCH_SIZE = 10;
	const MAX_QUEUE  = 5000;

	/**
	 * Normaliseur.
	 *
	 * @var ApplicationNormalizer
	 */
	private $normalizer;

	/**
	 * IDs empilés pendant la requête courante, écrits une seule fois à la fin.
	 *
	 * @var int[]
	 */
	private $pending = array();

	/**
	 * Constructeur.
	 *
	 * @param ApplicationNormalizer $normalizer Normaliseur.
	 */
	public function __construct( ApplicationNormalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::CRON_HOOK, array( $this, 'process' ) );
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Empile une offre à vérifier.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return void
	 */
	public function push( $job_id ) {
		$job_id = absint( $job_id );
		if ( $job_id ) {
			$this->pending[ $job_id ] = $job_id;
		}
	}

	/**
	 * Écrit la file en une seule fois (un import peut empiler des centaines d'offres).
	 *
	 * @return void
	 */
	public function flush() {
		if ( empty( $this->pending ) ) {
			return;
		}
		$queue         = array_slice( array_values( array_unique( array_merge( $this->get_queue(), array_values( $this->pending ) ) ) ), 0, self::MAX_QUEUE );
		$this->pending = array();
		update_option( self::OPTION, $queue, false );
		$this->schedule( MINUTE_IN_SECONDS );
	}

	/**
	 * Traite un lot.
	 *
	 * @return void
	 */
	public function process() {
		if ( get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$queue = $this->get_queue();
		$batch = array_splice( $queue, 0, self::BATCH_SIZE );
		// La file est réécrite avant le traitement : une erreur fatale ne rejoue pas indéfiniment le même lot.
		update_option( self::OPTION, $queue, false );

		foreach ( $batch as $job_id ) {
			$this->normalizer->handle_with_check( $job_id );
		}

		delete_transient( self::LOCK );

		if ( ! empty( $queue ) ) {
			$this->schedule( MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Planifie le prochain lot s'il ne l'est pas déjà.
	 *
	 * @param int $delay Délai en secondes.
	 * @return void
	 */
	private function schedule( $delay ) {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, self::CRON_HOOK );
		}
	}

	/**
	 * File courante.
	 *
	 * @return int[]
	 */
	private function get_queue() {
		$queue = get_option( self::OPTION, array() );
		return is_array( $queue ) ? array_values( array_filter( array_map( 'absint', $queue ) ) ) : array();
	}
}

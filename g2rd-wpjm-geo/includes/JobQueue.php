<?php
/**
 * File d'attente WP-Cron générique, traitée par petits lots.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Les sous-classes définissent les constantes OPTION, CRON_HOOK, LOCK et
 * BATCH_SIZE, ainsi que process_item().
 *
 * Les IDs empilés pendant une requête sont écrits une seule fois, en fin de
 * requête. Chaque offre est retirée de la file (relecture de l'option) avant
 * son traitement : un dépassement de délai ne rejoue pas indéfiniment la même
 * offre, et les IDs ajoutés entre-temps par un import ne sont jamais écrasés.
 */
abstract class JobQueue {

	const MAX_QUEUE   = 5000;
	const RETRY_DELAY = 600;

	/**
	 * IDs empilés pendant la requête courante, écrits une seule fois à la fin.
	 *
	 * @var int[]
	 */
	protected $pending = array();

	/**
	 * Traite une offre.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return bool false si le service est indisponible : l'offre est remise en file et le lot s'arrête.
	 */
	abstract protected function process_item( $job_id );

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( static::CRON_HOOK, array( $this, 'process' ) );
		add_action( 'shutdown', array( $this, 'flush' ) );
	}

	/**
	 * Empile une offre.
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
		update_option( static::OPTION, $queue, false );
		$this->schedule( MINUTE_IN_SECONDS );
	}

	/**
	 * Traite un lot.
	 *
	 * @return void
	 */
	public function process() {
		if ( get_transient( static::LOCK ) ) {
			return;
		}
		set_transient( static::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$retry = false;
		foreach ( array_slice( $this->get_queue(), 0, static::BATCH_SIZE ) as $job_id ) {
			$this->remove( $job_id );
			if ( false === $this->process_item( $job_id ) ) {
				$this->requeue( $job_id );
				$retry = true;
				break;
			}
		}

		delete_transient( static::LOCK );

		if ( $retry ) {
			$this->schedule( self::RETRY_DELAY );
		} elseif ( ! empty( $this->get_queue() ) ) {
			$this->schedule( MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Retire une offre en relisant l'option.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return void
	 */
	protected function remove( $job_id ) {
		update_option( static::OPTION, array_values( array_diff( $this->get_queue(), array( absint( $job_id ) ) ) ), false );
	}

	/**
	 * Remet une offre en fin de file.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return void
	 */
	protected function requeue( $job_id ) {
		$queue   = $this->get_queue();
		$queue[] = absint( $job_id );
		update_option( static::OPTION, array_slice( array_values( array_unique( $queue ) ), 0, self::MAX_QUEUE ), false );
	}

	/**
	 * Planifie le prochain lot s'il ne l'est pas déjà.
	 *
	 * @param int $delay Délai en secondes.
	 * @return void
	 */
	protected function schedule( $delay ) {
		if ( ! wp_next_scheduled( static::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $delay, static::CRON_HOOK );
		}
	}

	/**
	 * File courante.
	 *
	 * @return int[]
	 */
	protected function get_queue() {
		$queue = get_option( static::OPTION, array() );
		return is_array( $queue ) ? array_values( array_filter( array_map( 'absint', $queue ) ) ) : array();
	}
}

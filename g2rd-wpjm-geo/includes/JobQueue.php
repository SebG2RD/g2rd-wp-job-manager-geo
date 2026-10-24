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
 * requête. Chaque offre est retirée de la file (relecture de l'option en base)
 * avant son traitement : un dépassement de délai ne rejoue pas indéfiniment la
 * même offre, et les IDs ajoutés entre-temps par un import ne sont pas écrasés.
 * Un passage de secours est planifié dès la prise du verrou : si le lot est
 * interrompu (erreur fatale, délai dépassé), la file repart d'elle-même.
 */
abstract class JobQueue {

	const MAX_QUEUE   = 5000;
	const RETRY_DELAY = 600;
	const LOCK_TTL    = 300;

	/**
	 * Durée maximale d'un lot (secondes) : au-delà, les offres restantes attendent le
	 * passage suivant, une minute plus tard. Une offre peut demander plusieurs appels
	 * à un service externe.
	 */
	const TIME_BUDGET = 25;

	/**
	 * IDs empilés pendant la requête courante, écrits une seule fois à la fin.
	 *
	 * @var int[]
	 */
	protected $pending = array();

	/**
	 * Traite une offre, déjà retirée de la file au moment de l'appel.
	 *
	 * Seul un false strict déclenche un nouvel essai : l'offre est remise en fin
	 * de file, le lot s'arrête et le passage suivant attend RETRY_DELAY. Toute
	 * autre valeur signifie « traitée ou abandonnée définitivement ». La méthode
	 * ne doit pas lever d'exception ; si elle le fait, l'offre est perdue, mais
	 * le verrou est libéré et la file relancée.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return bool false si le service est indisponible.
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
		$queue         = array_slice( array_values( array_unique( array_merge( $this->get_queue(), array_values( $this->pending ) ) ) ), 0, static::MAX_QUEUE );
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
		set_transient( static::LOCK, 1, static::LOCK_TTL );
		$this->reschedule( static::LOCK_TTL + MINUTE_IN_SECONDS );

		$retry = false;
		$start = $this->now();
		$done  = 0;
		try {
			foreach ( array_slice( $this->get_queue(), 0, static::BATCH_SIZE ) as $job_id ) {
				if ( $done && $this->now() - $start > static::TIME_BUDGET ) {
					break; // Lot assez long : la suite au passage suivant.
				}
				$this->remove( $job_id );
				++$done;
				if ( false === $this->process_item( $job_id ) ) {
					$this->requeue( $job_id );
					$retry = true;
					break;
				}
			}
		} finally {
			delete_transient( static::LOCK );
			if ( $retry ) {
				$this->reschedule( static::RETRY_DELAY );
			} elseif ( ! empty( $this->get_queue() ) ) {
				$this->reschedule( MINUTE_IN_SECONDS );
			}
			// File vide : le passage de secours, sans effet, est laissé en place.
		}
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
		update_option( static::OPTION, array_slice( array_values( array_unique( $queue ) ), 0, static::MAX_QUEUE ), false );
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
	 * Remplace le prochain passage planifié par un passage dans $delay secondes.
	 *
	 * @param int $delay Délai en secondes.
	 * @return void
	 */
	protected function reschedule( $delay ) {
		wp_clear_scheduled_hook( static::CRON_HOOK );
		wp_schedule_single_event( time() + $delay, static::CRON_HOOK );
	}

	/**
	 * File courante.
	 *
	 * @return int[]
	 */
	protected function get_queue() {
		// Option non autochargée : sans cette purge, get_option() renverrait la
		// copie mise en cache plus tôt dans la requête et ignorerait les ajouts
		// d'un import concurrent.
		wp_cache_delete( static::OPTION, 'options' );
		$queue = get_option( static::OPTION, array() );
		return is_array( $queue ) ? array_values( array_filter( array_map( 'absint', $queue ) ) ) : array();
	}
}

<?php
/**
 * Branchement sur les hooks d'import de Go Fetch Jobs.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hooks constatés dans Go Fetch Jobs 1.8.x (includes/class-gofetch-importer.php) :
 *
 * - `goft_wpjm_after_insert_job` ( $post_id, $item, $params, $meta ) : exécuté
 *   pour chaque offre, après wp_insert_post() ET après l'écriture des
 *   métadonnées (dont `_application`). Le même chemin de code sert à l'insertion
 *   et à la mise à jour ($params['operation'] === 'update' ajoute simplement
 *   l'ID au tableau passé à wp_insert_post()). Il est donc déclenché pour les
 *   imports manuels, planifiés (WP-Cron) et les réimports.
 * - `goft_wpjm_after_insert_jobs_commit` ( $post_ids ) : exécuté une fois en fin
 *   de lot. Sert ici de filet de sécurité si une autre extension réécrivait
 *   `_application` après notre passage.
 *
 * Aucun hook global `save_post` n'est utilisé : les offres créées par un
 * employeur ou un administrateur ne passent jamais par ce code.
 *
 * Les callbacks sont enregistrés même si Go Fetch Jobs est absent (il se
 * charge sur `plugins_loaded` priorité 998, après ce plugin) : ils ne sont
 * alors simplement jamais appelés.
 */
class GoFetchIntegration {

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
	 * File de vérification différée.
	 *
	 * @var UrlCheckQueue
	 */
	private $queue;

	/**
	 * Constructeur.
	 *
	 * @param Settings              $settings   Réglages.
	 * @param ApplicationNormalizer $normalizer Normaliseur.
	 * @param UrlCheckQueue         $queue      File différée.
	 */
	public function __construct( Settings $settings, ApplicationNormalizer $normalizer, UrlCheckQueue $queue ) {
		$this->settings   = $settings;
		$this->normalizer = $normalizer;
		$this->queue      = $queue;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'goft_wpjm_after_insert_job', array( $this, 'after_insert_job' ), 99, 1 );
		add_action( 'goft_wpjm_after_insert_jobs_commit', array( $this, 'after_commit' ), 99, 1 );
	}

	/**
	 * Après l'insertion ou la mise à jour d'une offre par Go Fetch Jobs.
	 *
	 * @param mixed $post_id ID de l'offre (donnée tierce : validée avant usage).
	 * @return void
	 */
	public function after_insert_job( $post_id ) {
		if ( ! is_numeric( $post_id ) ) {
			return;
		}
		$this->handle( (int) $post_id );
	}

	/**
	 * Fin de lot : repasse sur les offres du lot. L'opération est idempotente
	 * (une offre déjà corrigée contient une adresse email et est ignorée).
	 *
	 * @param mixed $post_ids IDs des offres du lot.
	 * @return void
	 */
	public function after_commit( $post_ids ) {
		if ( ! is_array( $post_ids ) ) {
			return;
		}
		foreach ( $post_ids as $post_id ) {
			if ( is_numeric( $post_id ) ) {
				$this->handle( (int) $post_id );
			}
		}
	}

	/**
	 * Traite une offre.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return void
	 */
	private function handle( $job_id ) {
		if ( $job_id <= 0 || Settings::MODE_KEEP === $this->settings->get_mode() ) {
			return;
		}
		if ( ApplicationNormalizer::ACTION_CHECK === $this->normalizer->handle_import( $job_id ) ) {
			$this->queue->push( $job_id );
		}
	}
}

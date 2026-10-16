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
class UrlCheckQueue extends JobQueue {

	const OPTION    = 'g2rd_wpjm_geo_url_queue';
	const CRON_HOOK = 'g2rd_wpjm_geo_check_urls';
	const LOCK      = 'g2rd_wpjm_geo_url_queue_lock';
	// 2 offres x (HEAD 5 s + GET 5 s) = 20 s au pire par exécution du cron, sous les 30 s habituels de PHP.
	const BATCH_SIZE = 2;

	/**
	 * Normaliseur.
	 *
	 * @var ApplicationNormalizer
	 */
	private $normalizer;

	/**
	 * Constructeur.
	 *
	 * @param ApplicationNormalizer $normalizer Normaliseur.
	 */
	public function __construct( ApplicationNormalizer $normalizer ) {
		$this->normalizer = $normalizer;
	}

	/**
	 * Vérifie l'URL d'une offre. Une URL injoignable est un résultat, pas une panne : jamais de remise en file.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return bool
	 */
	protected function process_item( $job_id ) {
		$this->normalizer->handle_with_check( $job_id );
		return true;
	}
}

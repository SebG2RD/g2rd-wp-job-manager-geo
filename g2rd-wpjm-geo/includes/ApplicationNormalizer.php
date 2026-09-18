<?php
/**
 * Normalisation du champ « Application email/URL » des offres Go Fetch Jobs.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remplace, pour les seules offres importées par Go Fetch Jobs, une URL de
 * candidature par l'adresse email générique configurée dans les réglages.
 *
 * Constats tirés du code installé :
 * - WP Job Manager 2.4.x lit la candidature dans la métadonnée `_application`
 *   (get_the_job_application_method()).
 * - Go Fetch Jobs 1.8.x marque chaque offre importée avec la métadonnée
 *   `_goft_wpjm_is_external` = 1 (c'est le test de GoFetch_Helper::is_goft_job())
 *   et décrit la source dans `_goft_source_data`.
 *
 * Cette classe n'écoute aucun hook : elle est appelée par GoFetchIntegration
 * (import), UrlCheckQueue (vérification différée) et MigrationTool (réparation).
 * Elle n'écrit que la métadonnée `_application` via update_post_meta(), sans
 * jamais appeler wp_update_post() : aucun save_post n'est déclenché, donc
 * aucune boucle de sauvegarde n'est possible.
 */
class ApplicationNormalizer {

	const META_APPLICATION = '_application';
	const META_GOFETCH     = '_goft_wpjm_is_external';
	const META_SOURCE      = '_goft_source_data';
	const POST_TYPE        = 'job_listing';

	const TYPE_EMPTY = 'empty';
	const TYPE_EMAIL = 'email';
	const TYPE_URL   = 'url';
	const TYPE_OTHER = 'other';

	const ACTION_SKIP    = 'skip';
	const ACTION_REPLACE = 'replace';
	const ACTION_CHECK   = 'check';

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Journal.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Vérificateur d'URL (mode « invalides » uniquement).
	 *
	 * @var UrlChecker
	 */
	private $checker;

	/**
	 * Offres en cours d'écriture (garde de réentrance).
	 *
	 * @var array<int,bool>
	 */
	private $processing = array();

	/**
	 * Constructeur.
	 *
	 * @param Settings   $settings Réglages.
	 * @param Logger     $logger   Journal.
	 * @param UrlChecker $checker  Vérificateur d'URL.
	 */
	public function __construct( Settings $settings, Logger $logger, UrlChecker $checker ) {
		$this->settings = $settings;
		$this->logger   = $logger;
		$this->checker  = $checker;
	}

	/**
	 * Adresse email générique configurée (toujours lue dans les options).
	 *
	 * @return string Chaîne vide si aucune adresse valide n'est disponible.
	 */
	public function get_fallback_email() {
		return $this->settings->get_fallback_email();
	}

	/**
	 * L'offre provient-elle de Go Fetch Jobs ?
	 *
	 * @param int $job_id ID de l'offre.
	 * @return bool
	 */
	public function is_gofetch_job( $job_id ) {
		$job_id = absint( $job_id );
		if ( ! $job_id || self::POST_TYPE !== get_post_type( $job_id ) ) {
			return false;
		}
		return (bool) get_post_meta( $job_id, self::META_GOFETCH, true );
	}

	/**
	 * Source détectée, pour le journal. Seuls le nom du fournisseur ou le nom
	 * d'hôte sont conservés : l'URL complète du flux peut contenir une clé d'API.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return string
	 */
	public function detect_source( $job_id ) {
		$source = get_post_meta( absint( $job_id ), self::META_SOURCE, true );
		$label  = '';
		if ( is_array( $source ) ) {
			if ( ! empty( $source['name'] ) && is_string( $source['name'] ) ) {
				$label = $source['name'];
			} else {
				foreach ( array( 'website', 'feed_url' ) as $key ) {
					if ( ! empty( $source[ $key ] ) && is_string( $source[ $key ] ) ) {
						$host = wp_parse_url( $source[ $key ], PHP_URL_HOST );
						if ( $host ) {
							$label = $host;
							break;
						}
					}
				}
			}
		}
		$label = sanitize_text_field( $label );
		return 'Go Fetch Jobs' . ( '' !== $label ? ' — ' . $label : '' );
	}

	/**
	 * Qualifie une valeur du champ de candidature.
	 *
	 * @param mixed $value Valeur brute.
	 * @return string Une des constantes TYPE_*.
	 */
	public function classify( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return self::TYPE_EMPTY;
		}
		if ( false !== strpos( $value, '@' ) && is_email( $value ) ) {
			return self::TYPE_EMAIL;
		}
		// URL avec schéma (http, https, mais aussi ftp:, javascript:… que WPJM afficherait comme lien).
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $value ) ) {
			return self::TYPE_URL;
		}
		// URL sans schéma (« www.site.com/job/1 ») : WPJM la préfixe par http:// à l'affichage.
		if ( preg_match( '#^[a-z0-9\-]+(\.[a-z0-9\-]+)+(:\d+)?([/?\#]|$)#i', $value ) ) {
			return self::TYPE_URL;
		}
		return self::TYPE_OTHER;
	}

	/**
	 * Décide, sans rien modifier, de ce qu'il faut faire d'une offre.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return array{action:string,reason:string,type:string,old:string,new:string}
	 */
	public function evaluate( $job_id ) {
		$job_id = absint( $job_id );
		$result = array(
			'action' => self::ACTION_SKIP,
			'reason' => '',
			'type'   => self::TYPE_EMPTY,
			'old'    => '',
			'new'    => '',
		);

		$mode = $this->settings->get_mode();
		if ( Settings::MODE_KEEP === $mode ) {
			$result['reason'] = 'disabled';
			return $result;
		}

		$email = $this->get_fallback_email();
		if ( '' === $email ) {
			$result['reason'] = 'no_fallback_email';
			return $result;
		}

		if ( ! $this->is_gofetch_job( $job_id ) ) {
			$result['reason'] = 'not_gofetch';
			return $result;
		}

		$old            = get_post_meta( $job_id, $this->meta_key(), true );
		$old            = is_string( $old ) ? trim( $old ) : '';
		$result['old']  = $old;
		$result['type'] = $this->classify( $old );

		if ( self::TYPE_URL !== $result['type'] ) {
			$result['reason'] = 'not_url';
			return $result;
		}

		$result['new'] = $email;
		if ( Settings::MODE_ALL === $mode ) {
			$result['action'] = self::ACTION_REPLACE;
			$result['reason'] = 'mode_all';
		} else {
			$result['action'] = self::ACTION_CHECK;
			$result['reason'] = 'needs_check';
		}
		return $result;
	}

	/**
	 * Traite une offre juste après son import ou sa mise à jour par Go Fetch Jobs.
	 * N'effectue jamais de requête HTTP.
	 *
	 * @param int $job_id ID de l'offre.
	 * @return string ACTION_REPLACE, ACTION_CHECK (à vérifier en différé) ou ACTION_SKIP.
	 */
	public function handle_import( $job_id ) {
		$decision = $this->evaluate( $job_id );
		if ( self::ACTION_REPLACE === $decision['action'] ) {
			return $this->replace( $job_id, $decision['old'], $decision['new'], $decision['reason'] ) ? self::ACTION_REPLACE : self::ACTION_SKIP;
		}
		return $decision['action'];
	}

	/**
	 * Vérifie l'URL d'une offre (requête HTTP) puis la remplace si elle est
	 * problématique. Utilisé en différé (WP-Cron) et par l'outil de réparation.
	 *
	 * @param int  $job_id  ID de l'offre.
	 * @param bool $dry_run Simulation : aucune écriture.
	 * @return array Décision enrichie (`action` vaut ACTION_REPLACE ou ACTION_SKIP).
	 */
	public function handle_with_check( $job_id, $dry_run = false ) {
		$decision = $this->evaluate( $job_id );

		if ( self::ACTION_CHECK === $decision['action'] ) {
			$check = $this->checker->check( $decision['old'] );
			if ( $check['problem'] ) {
				$decision['action'] = self::ACTION_REPLACE;
				$decision['reason'] = $check['reason'];
			} else {
				$decision['action'] = self::ACTION_SKIP;
				$decision['reason'] = 'url_ok';
				$decision['new']    = '';
			}
		}

		if ( self::ACTION_REPLACE === $decision['action'] && ! $dry_run ) {
			if ( ! $this->replace( $job_id, $decision['old'], $decision['new'], $decision['reason'] ) ) {
				$decision['action'] = self::ACTION_SKIP;
				$decision['reason'] = 'write_failed';
			}
		}
		return $decision;
	}

	/**
	 * Écrit la nouvelle valeur et journalise.
	 *
	 * @param int    $job_id ID de l'offre.
	 * @param string $old    Ancienne valeur.
	 * @param string $new    Nouvelle valeur (adresse email valide).
	 * @param string $reason Code de raison.
	 * @return bool
	 */
	private function replace( $job_id, $old, $new, $reason ) {
		$job_id = absint( $job_id );
		if ( ! $job_id || ! is_email( $new ) || $old === $new || ! empty( $this->processing[ $job_id ] ) ) {
			return false;
		}

		$this->processing[ $job_id ] = true;
		$updated                     = update_post_meta( $job_id, $this->meta_key(), $new );
		unset( $this->processing[ $job_id ] );

		if ( false === $updated ) {
			return false;
		}

		$this->logger->log( $job_id, $old, $new, $reason, $this->detect_source( $job_id ) );

		/**
		 * Après le remplacement d'une URL de candidature par l'adresse générique.
		 *
		 * @param int    $job_id ID de l'offre.
		 * @param string $old    Ancienne valeur.
		 * @param string $new    Nouvelle valeur.
		 * @param string $reason Raison.
		 */
		do_action( 'g2rd_wpjm_geo_application_replaced', $job_id, $old, $new, $reason );
		return true;
	}

	/**
	 * Clé de métadonnée de la candidature.
	 *
	 * @return string
	 */
	private function meta_key() {
		/**
		 * Permet d'adapter la clé si une future version de WP Job Manager la modifiait.
		 *
		 * @param string $meta_key Clé par défaut : `_application`.
		 */
		$key = apply_filters( 'g2rd_wpjm_geo_application_meta_key', self::META_APPLICATION );
		return is_string( $key ) && '' !== $key ? $key : self::META_APPLICATION;
	}
}

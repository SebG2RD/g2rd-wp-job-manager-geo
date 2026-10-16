<?php
/**
 * Point d'entrée : instancie les modules selon les dépendances disponibles.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classe principale du plugin.
 */
final class Plugin {

	/**
	 * Instance unique.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Retourne (et démarre) l'instance unique.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Constructeur privé (singleton).
	 */
	private function __construct() {}

	/**
	 * Démarre les modules.
	 *
	 * @return void
	 */
	private function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		$dependencies = new Dependencies();
		$dependencies->register();

		// Sans WP Job Manager, aucune fonctionnalité dépendante n'est chargée. Le shortcode reste
		// déclaré (vide) pour ne pas afficher « [wpjm_jobs_map] » en clair sur les pages existantes.
		if ( ! Dependencies::has_wpjm() ) {
			add_shortcode( JobsMap::SHORTCODE, '__return_empty_string' );
			return;
		}

		$this->settings = new Settings();
		$this->settings->register();

		Logger::maybe_upgrade();
		$logger = new Logger( $this->settings );
		$logger->register();

		$google = new GoogleAssets( $this->settings );
		$google->register();
		( new OsmAssets( $this->settings ) )->register();

		if ( $this->settings->get( 'autocomplete_enabled' ) ) {
			if ( $this->settings->get( 'autocomplete_frontend' ) ) {
				( new LocationAutocomplete( $this->settings ) )->register();
			}
			if ( $this->settings->get( 'autocomplete_admin' ) && is_admin() ) {
				( new AdminLocation( $this->settings ) )->register();
			}
		}

		// Le shortcode et le bloc restent enregistrés même si la carte est
		// désactivée, afin de ne jamais afficher « [wpjm_jobs_map] » en clair.
		( new JobsMap( $this->settings ) )->register();
		( new RestController( $this->settings ) )->register();

		// Go Fetch Jobs est facultatif : sans lui, ses hooks ne sont simplement jamais déclenchés.
		$normalizer = new ApplicationNormalizer( $this->settings, $logger, new UrlChecker() );
		$queue      = new UrlCheckQueue( $normalizer );
		$queue->register();
		( new GoFetchIntegration( $this->settings, $normalizer, $queue ) )->register();

		// Géocodage libre : actif seulement si le fournisseur OpenStreetMap est choisi.
		$geocoder  = new PhotonGeocoder( $this->settings );
		$geo_queue = new GeocodeQueue( $this->settings, $geocoder );
		$geo_queue->register();
		( new GeocodingIntegration( $this->settings, $geocoder, $geo_queue ) )->register();

		if ( is_admin() ) {
			$page = new SettingsPage( $this->settings );
			( new AdminMenu( array( $page, 'render' ) ) )->register();
			( new AdminScreen() )->register();
			( new RegeocodeTool( $this->settings, $geocoder ) )->register();
			( new MigrationTool( $this->settings, $normalizer, $logger ) )->register();
		}
	}

	/**
	 * Charge les traductions.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'g2rd-wpjm-geo', false, dirname( plugin_basename( G2RD_WPJM_GEO_FILE ) ) . '/languages' );
	}

	/**
	 * Activation : crée la table du journal et initialise l'adresse générique
	 * si elle n'existe pas encore. Aucune offre n'est modifiée.
	 *
	 * @return void
	 */
	public static function activate() {
		Logger::install();
		// add_option() ne fait rien si l'option existe déjà : une adresse personnalisée survit à une réactivation.
		add_option( Settings::FALLBACK_EMAIL_OPTION, Settings::DEFAULT_FALLBACK_EMAIL, '', false );
	}

	/**
	 * Désactivation : retire les tâches planifiées du plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( Logger::CRON_HOOK );
		wp_clear_scheduled_hook( UrlCheckQueue::CRON_HOOK );
		wp_clear_scheduled_hook( GeocodeQueue::CRON_HOOK );
	}
}

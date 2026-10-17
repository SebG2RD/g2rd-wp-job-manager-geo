<?php
/**
 * Réglages du plugin : stockage, lecture et assainissement (Settings API).
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Options du plugin. Le rendu de la page est assuré par SettingsPage, le
 * rattachement au menu par AdminMenu.
 */
class Settings {

	const OPTION     = 'g2rd_wpjm_geo_settings';
	const GROUP      = 'g2rd_wpjm_geo';
	const PAGE_SLUG  = 'g2rd-wpjm-geo';
	const CAPABILITY = 'manage_options';

	/**
	 * Option dédiée à l'adresse générique de candidature.
	 */
	const FALLBACK_EMAIL_OPTION = 'g2rd_wpjm_geo_fallback_email';

	/**
	 * Valeur d'initialisation uniquement : la logique métier lit toujours
	 * l'option WordPress via get_fallback_email().
	 */
	const DEFAULT_FALLBACK_EMAIL = 'reconversion@pompiersparis.fr';

	const MODE_KEEP    = 'keep';
	const MODE_ALL     = 'all';
	const MODE_INVALID = 'invalid';

	const PROVIDER_GOOGLE = 'google';
	const PROVIDER_OSM    = 'osm';

	const DEFAULT_OSM_STYLE_URL = 'https://tiles.openfreemap.org/styles/liberty';
	const DEFAULT_PHOTON_URL    = 'https://photon.komoot.io/api/';

	const MAX_URL_LENGTH = 2048;

	/**
	 * Cache mémoire des réglages fusionnés avec les valeurs par défaut.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Valeurs par défaut.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'browser_api_key'       => '',
			'map_id'                => '',
			'autocomplete_enabled'  => true,
			'autocomplete_frontend' => true,
			'autocomplete_admin'    => true,
			'map_enabled'           => true,
			'map_height'            => 480,
			'clustering_enabled'    => true,
			'gofetch_replace'       => true,
			'gofetch_scope'         => self::MODE_ALL,
			'log_enabled'           => true,
			'log_retention_days'    => 90,
			'provider'              => self::PROVIDER_GOOGLE,
			'osm_style_url'         => self::DEFAULT_OSM_STYLE_URL,
			'photon_url'            => self::DEFAULT_PHOTON_URL,
		);
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		// Le cache mémoire ne doit jamais survivre à une modification des réglages dans la même requête.
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_cache' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush_cache' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'purge_page_cache_on_provider_change' ), 10, 2 );
	}

	/**
	 * Vide le cache mémoire des réglages.
	 *
	 * @return void
	 */
	public function flush_cache() {
		$this->cache = null;
	}

	/**
	 * Vide le cache de pages de WP Rocket quand le fournisseur change (appel de sa
	 * fonction publique, sans rien modifier de l'extension).
	 *
	 * @param mixed $old Anciens réglages.
	 * @param mixed $new Nouveaux réglages.
	 * @return void
	 */
	public static function purge_page_cache_on_provider_change( $old, $new ) {
		$before = ( is_array( $old ) && isset( $old['provider'] ) ) ? $old['provider'] : self::PROVIDER_GOOGLE;
		$after  = ( is_array( $new ) && isset( $new['provider'] ) ) ? $new['provider'] : self::PROVIDER_GOOGLE;
		if ( $before !== $after && function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	/**
	 * Tous les réglages.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return $this->cache;
	}

	/**
	 * Un réglage.
	 *
	 * @param string $key Clé.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Adresse email générique de candidature configurée.
	 *
	 * Retourne la valeur enregistrée dans les options WordPress ; tant
	 * qu'aucune valeur n'a été enregistrée, retourne la valeur d'initialisation.
	 * Si la valeur stockée n'est pas une adresse valide (base modifiée à la
	 * main…), retourne une chaîne vide : aucun remplacement n'est alors effectué.
	 *
	 * @return string
	 */
	public function get_fallback_email() {
		$email = sanitize_email( (string) get_option( self::FALLBACK_EMAIL_OPTION, self::DEFAULT_FALLBACK_EMAIL ) );
		return ( '' !== $email && is_email( $email ) ) ? $email : '';
	}

	/**
	 * Mode de remplacement effectif.
	 *
	 * @return string MODE_KEEP si le remplacement est désactivé, sinon MODE_ALL ou MODE_INVALID.
	 */
	public function get_mode() {
		if ( ! $this->get( 'gofetch_replace' ) ) {
			return self::MODE_KEEP;
		}
		return self::MODE_INVALID === $this->get( 'gofetch_scope' ) ? self::MODE_INVALID : self::MODE_ALL;
	}

	/**
	 * Fournisseur cartographique actif.
	 *
	 * @return string PROVIDER_GOOGLE ou PROVIDER_OSM.
	 */
	public function get_provider() {
		return self::PROVIDER_OSM === $this->get( 'provider' ) ? self::PROVIDER_OSM : self::PROVIDER_GOOGLE;
	}

	/**
	 * URL https avec hôte et sans identifiants, ou chaîne vide.
	 *
	 * Le schéma est comparé sans tenir compte de la casse. Une URL contenant un
	 * utilisateur ou un mot de passe est refusée : elle serait transmise aux
	 * navigateurs des visiteurs.
	 *
	 * @param mixed $raw Valeur saisie.
	 * @return string
	 */
	public static function https_url( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > self::MAX_URL_LENGTH ) {
			return '';
		}
		$url   = esc_url_raw( $raw, array( 'https' ) );
		$parts = '' === $url ? false : wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) || '' === $parts['host'] ) {
			return '';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		return $url;
	}

	/**
	 * URL de la page du plugin (sous-menu « G2RD » ou menu autonome).
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * La page courante est-elle la page du plugin ?
	 *
	 * @param string $hook_suffix Suffixe fourni par admin_enqueue_scripts.
	 * @return bool
	 */
	public static function is_settings_screen( $hook_suffix ) {
		return AdminMenu::is_plugin_screen( $hook_suffix );
	}

	/**
	 * Déclare les deux options (capacité et nonce vérifiés par options.php).
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::GROUP,
			self::FALLBACK_EMAIL_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_fallback_email' ),
				'default'           => self::DEFAULT_FALLBACK_EMAIL,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Assainit l'intégralité des réglages. La capacité et le nonce sont
	 * vérifiés par options.php (Settings API) avant cet appel.
	 *
	 * @param mixed $input Données brutes.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = array();

		$clean['browser_api_key'] = isset( $input['browser_api_key'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['browser_api_key'] ) : '';
		$clean['map_id']          = isset( $input['map_id'] ) ? preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['map_id'] ) : '';

		foreach ( array( 'autocomplete_enabled', 'autocomplete_frontend', 'autocomplete_admin', 'map_enabled', 'clustering_enabled', 'log_enabled', 'gofetch_replace' ) as $flag ) {
			$clean[ $flag ] = ! empty( $input[ $flag ] );
		}

		$height              = isset( $input['map_height'] ) ? absint( $input['map_height'] ) : $defaults['map_height'];
		$clean['map_height'] = min( 1200, max( 200, $height ) );

		$retention                   = isset( $input['log_retention_days'] ) ? absint( $input['log_retention_days'] ) : $defaults['log_retention_days'];
		$clean['log_retention_days'] = min( 365, max( 1, $retention ) );

		$scope                  = isset( $input['gofetch_scope'] ) ? sanitize_key( $input['gofetch_scope'] ) : self::MODE_ALL;
		$clean['gofetch_scope'] = self::MODE_INVALID === $scope ? self::MODE_INVALID : self::MODE_ALL;

		$clean['provider'] = ( isset( $input['provider'] ) && self::PROVIDER_OSM === $input['provider'] ) ? self::PROVIDER_OSM : self::PROVIDER_GOOGLE;

		$urls = array(
			'osm_style_url' => __( 'L’URL du style de carte doit être une adresse https:// valide, sans identifiant : elle n’a pas été enregistrée.', 'g2rd-wpjm-geo' ),
			'photon_url'    => __( 'L’URL du serveur Photon doit être une adresse https:// valide, sans identifiant : elle n’a pas été enregistrée.', 'g2rd-wpjm-geo' ),
		);
		foreach ( $urls as $key => $message ) {
			$given = isset( $input[ $key ] );
			$raw   = ( $given && is_string( $input[ $key ] ) ) ? trim( $input[ $key ] ) : null;
			$url   = self::https_url( $raw );
			if ( '' !== $url ) {
				$clean[ $key ] = $url;
			} elseif ( '' === $raw ) {
				// Champ vidé : retour à l’instance publique par défaut.
				$clean[ $key ] = $defaults[ $key ];
			} else {
				$clean[ $key ] = (string) $this->get( $key );
				if ( $given ) {
					$this->notify( 'invalid_' . $key, $message );
				}
			}
		}

		$this->cache = null;
		return $clean;
	}

	/**
	 * Valide l'adresse générique. Une adresse invalide (ou vide) n'est jamais
	 * enregistrée : la dernière adresse valide est conservée.
	 *
	 * @param mixed $value Valeur soumise.
	 * @return string
	 */
	public function sanitize_fallback_email( $value ) {
		$raw   = is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
		$email = sanitize_email( $raw );

		if ( '' !== $raw && $email === $raw && is_email( $email ) ) {
			return $email;
		}

		$previous = $this->get_fallback_email();
		$this->notify(
			'invalid_fallback_email',
			sprintf(
				/* translators: %s: email address kept. */
				__( 'L’adresse email générique de candidature saisie n’est pas valide : elle n’a pas été enregistrée. L’adresse précédente (%s) est conservée. Pour suspendre les remplacements, décochez l’option « Remplacer les URL de candidature des offres Go Fetch Jobs ».', 'g2rd-wpjm-geo' ),
				'' !== $previous ? $previous : self::DEFAULT_FALLBACK_EMAIL
			)
		);
		return '' !== $previous ? $previous : self::DEFAULT_FALLBACK_EMAIL;
	}

	/**
	 * Affiche un message d'administration. add_settings_error() n'existe que
	 * dans wp-admin, alors que les callbacks d'assainissement peuvent être
	 * appelés par update_option() dans n'importe quel contexte.
	 *
	 * @param string $code    Code du message.
	 * @param string $message Message.
	 * @param string $type    Type (error, warning…).
	 * @return void
	 */
	private function notify( $code, $message, $type = 'error' ) {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::OPTION, $code, $message, $type );
		}
	}
}

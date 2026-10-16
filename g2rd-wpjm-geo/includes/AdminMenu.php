<?php
/**
 * Rattachement de la page du plugin au menu d'administration.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thème G2RD avec sa console (contrat v2, action `g2rd_console_menu`) :
 * sous-menu du menu « G2RD ». Sinon : menu de premier niveau « G2RD Geo »,
 * juste sous « Offres d'emploi » (position 30 chez WP Job Manager).
 *
 * La détection repose sur l'action du thème elle-même : un thème G2RD sans
 * console ne la déclenche pas, et le menu autonome prend le relais.
 */
class AdminMenu {

	const STANDALONE_POSITION = 31;

	/**
	 * Hook de la page, renvoyé par WordPress.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Rattaché au menu « G2RD » ?
	 *
	 * @var bool
	 */
	private static $attached = false;

	/**
	 * Rendu de la page.
	 *
	 * @var callable
	 */
	private $render;

	/**
	 * Constructeur.
	 *
	 * @param callable $render Rendu de la page.
	 */
	public function __construct( $render ) {
		$this->render = $render;
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'g2rd_console_menu', array( $this, 'attach_to_console' ) );
		add_action( 'admin_menu', array( $this, 'maybe_add_standalone' ), 20 );
		add_filter( 'plugin_action_links_' . plugin_basename( G2RD_WPJM_GEO_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * La console du thème G2RD (contrat v2 ou plus) est-elle disponible ?
	 *
	 * @return bool
	 */
	public static function console_available() {
		return class_exists( 'G2RD\AdminConsole' )
			&& defined( 'G2RD\AdminConsole::CONTRACT_VERSION' )
			&& (int) constant( 'G2RD\AdminConsole::CONTRACT_VERSION' ) >= 2;
	}

	/**
	 * Sous-menu du menu « G2RD » (action du thème, priorité 9 de admin_menu).
	 *
	 * @param string $parent Slug du menu parent.
	 * @return void
	 */
	public function attach_to_console( $parent ) {
		if ( self::$attached || ! self::console_available() ) {
			return;
		}
		// La console est là : jamais de menu autonome, même sans la capacité (WordPress renvoie alors false).
		self::$attached = true;
		$hook           = add_submenu_page(
			'' !== (string) $parent ? (string) $parent : \G2RD\AdminConsole::MENU_SLUG,
			__( 'Console G2RD — Offres géolocalisées', 'g2rd-wpjm-geo' ),
			__( 'Offres géolocalisées', 'g2rd-wpjm-geo' ),
			Settings::CAPABILITY,
			Settings::PAGE_SLUG,
			$this->render
		);
		self::$hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Menu autonome si le thème n'a pas fourni sa console.
	 *
	 * @return void
	 */
	public function maybe_add_standalone() {
		if ( self::$attached ) {
			return;
		}
		self::$hook_suffix = (string) add_menu_page(
			__( 'G2RD WP Job Manager Geo', 'g2rd-wpjm-geo' ),
			__( 'G2RD Geo', 'g2rd-wpjm-geo' ),
			Settings::CAPABILITY,
			Settings::PAGE_SLUG,
			$this->render,
			'dashicons-location-alt',
			self::STANDALONE_POSITION
		);
	}

	/**
	 * Hook de la page du plugin.
	 *
	 * @return string
	 */
	public static function hook_suffix() {
		return self::$hook_suffix;
	}

	/**
	 * Rattaché au menu « G2RD » ?
	 *
	 * @return bool
	 */
	public static function is_attached() {
		return self::$attached;
	}

	/**
	 * L'écran courant est-il la page du plugin ?
	 *
	 * @param string $hook_suffix Suffixe fourni par admin_enqueue_scripts.
	 * @return bool
	 */
	public static function is_plugin_screen( $hook_suffix ) {
		return '' !== self::$hook_suffix && (string) $hook_suffix === self::$hook_suffix;
	}

	/**
	 * Réinitialise l'état (tests).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$hook_suffix = '';
		self::$attached    = false;
	}

	/**
	 * Lien « Réglages » dans la liste des extensions.
	 *
	 * @param array $links Liens existants.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( Settings::page_url() ), esc_html__( 'Réglages', 'g2rd-wpjm-geo' ) ) );
		return $links;
	}
}

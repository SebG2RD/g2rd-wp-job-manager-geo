<?php
/**
 * Gabarit de la page d'administration, à la charte de la console G2RD.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rattaché au menu « G2RD » : délègue à \G2RD\AdminConsole (feuille et polices
 * chargées par le thème). Sinon : même balisage, avec la copie de la feuille
 * de la console (assets/css/console.css) et les polices du plugin.
 */
class AdminScreen {

	const STYLE_HANDLE  = 'g2rd-wpjm-geo-console';
	const SCRIPT_HANDLE = 'g2rd-wpjm-geo-admin-settings';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Feuille de repli (mode autonome) et script de la page.
	 *
	 * @param string $hook_suffix Écran courant.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( ! AdminMenu::is_plugin_screen( $hook_suffix ) ) {
			return;
		}
		if ( AdminMenu::is_attached() ) {
			// Le thème reconnaît ses pages au préfixe g2rd_page_ ; charger sa feuille soi-même n'en dépend pas (appel idempotent).
			if ( is_callable( array( 'G2RD\AdminConsole', 'enqueueStyle' ) ) ) {
				\G2RD\AdminConsole::enqueueStyle();
			}
		} else {
			$file = G2RD_WPJM_GEO_DIR . 'assets/css/console.css';
			wp_enqueue_style( self::STYLE_HANDLE, G2RD_WPJM_GEO_URL . 'assets/css/console.css', array( 'wp-components', 'dashicons' ), file_exists( $file ) ? (string) filemtime( $file ) : G2RD_WPJM_GEO_VERSION );
			wp_add_inline_style( self::STYLE_HANDLE, self::font_face_css() );
		}
		wp_enqueue_script( self::SCRIPT_HANDLE, G2RD_WPJM_GEO_URL . 'assets/js/admin-settings.js', array(), G2RD_WPJM_GEO_VERSION, true );
	}

	/**
	 * Polices embarquées (mêmes noms de famille que le thème G2RD).
	 *
	 * @return string
	 */
	public static function font_face_css() {
		$base = G2RD_WPJM_GEO_URL . 'assets/fonts/';
		return '@font-face{font-family:"Instrument Sans";font-style:normal;font-weight:400 700;font-display:swap;src:url("' . esc_url( $base . 'instrument-sans-latin.woff2' ) . '") format("woff2");}'
			. '@font-face{font-family:"IBM Plex Mono";font-style:normal;font-weight:400;font-display:swap;src:url("' . esc_url( $base . 'ibm-plex-mono-400-latin.woff2' ) . '") format("woff2");}'
			. '@font-face{font-family:"IBM Plex Mono";font-style:normal;font-weight:500;font-display:swap;src:url("' . esc_url( $base . 'ibm-plex-mono-500-latin.woff2' ) . '") format("woff2");}';
	}

	/**
	 * Ouvre la page : bandeau, barre, titre, tuiles, puis la zone de contenu.
	 *
	 * @param array<string,mixed> $args {
	 *     @type string $eyebrow     Sur-titre.
	 *     @type string $title       Titre.
	 *     @type string $description Description.
	 *     @type array  $tiles       Jusqu'à 3 tuiles : label, value, hint, mono.
	 *     @type array  $chips       Puces : label, tone (ok|warn).
	 * }
	 * @return void
	 */
	public static function open( array $args ) {
		if ( AdminMenu::is_attached() ) {
			\G2RD\AdminConsole::open( $args );
			return;
		}

		$eyebrow     = (string) ( isset( $args['eyebrow'] ) ? $args['eyebrow'] : '' );
		$title       = (string) ( isset( $args['title'] ) ? $args['title'] : '' );
		$description = (string) ( isset( $args['description'] ) ? $args['description'] : '' );
		$tiles       = array_slice( (array) ( isset( $args['tiles'] ) ? $args['tiles'] : array() ), 0, 3 );
		$chips       = (array) ( isset( $args['chips'] ) ? $args['chips'] : array() );

		echo '<hr class="wp-header-end">';
		echo '<div class="wrap" id="g2rd-options-root"><div class="g2rd-app g2rd-app--php">';
		echo '<div class="g2rd-band" aria-hidden="true"></div>';
		echo '<div class="g2rd-inner">';

		echo '<header class="g2rd-topbar"><div class="g2rd-topbar__brand">';
		echo '<span class="g2rd-topbar__mark" aria-hidden="true">G</span>';
		echo '<h1 class="g2rd-topbar__title">' . esc_html__( 'G2RD WP Job Manager Geo', 'g2rd-wpjm-geo' ) . '</h1>';
		echo '<span class="g2rd-chip g2rd-chip--mono">v' . esc_html( G2RD_WPJM_GEO_VERSION ) . '</span>';
		echo '</div>';
		if ( $chips ) {
			echo '<div class="g2rd-topbar__status">';
			foreach ( $chips as $chip ) {
				$tone = ( isset( $chip['tone'] ) && in_array( $chip['tone'], array( 'ok', 'warn' ), true ) ) ? ' is-' . $chip['tone'] : '';
				echo '<span class="g2rd-chip">';
				if ( $tone ) {
					echo '<span class="g2rd-dot' . esc_attr( $tone ) . '" aria-hidden="true"></span>';
				}
				echo esc_html( (string) ( isset( $chip['label'] ) ? $chip['label'] : '' ) ) . '</span>';
			}
			echo '</div>';
		}
		echo '</header>';

		echo '<div class="g2rd-page-head">';
		if ( '' !== $eyebrow ) {
			echo '<p class="g2rd-page-head__eyebrow">' . esc_html( $eyebrow ) . '</p>';
		}
		echo '<h2 class="g2rd-page-head__title">' . esc_html( $title ) . '</h2>';
		if ( '' !== $description ) {
			echo '<p class="g2rd-page-head__desc">' . esc_html( $description ) . '</p>';
		}
		echo '</div>';

		if ( $tiles ) {
			echo '<div class="g2rd-tiles">';
			foreach ( $tiles as $tile ) {
				$value = isset( $tile['value'] ) ? $tile['value'] : '';
				$mono  = ! empty( $tile['mono'] ) || is_numeric( $value );
				echo '<div class="g2rd-tile">';
				echo '<small class="g2rd-tile__label">' . esc_html( (string) ( isset( $tile['label'] ) ? $tile['label'] : '' ) ) . '</small>';
				echo '<div class="g2rd-tile__row">';
				echo '<b class="g2rd-tile__value' . ( $mono ? ' is-mono' : '' ) . '">' . esc_html( (string) $value ) . '</b>';
				if ( ! empty( $tile['hint'] ) ) {
					echo '<span class="g2rd-tile__hint">' . esc_html( (string) $tile['hint'] ) . '</span>';
				}
				echo '</div></div>';
			}
			echo '</div>';
		}

		echo '<div class="g2rd-body"><main class="g2rd-main"><div class="g2rd-tab-content">';
	}

	/**
	 * Ferme la page.
	 *
	 * @return void
	 */
	public static function close() {
		if ( AdminMenu::is_attached() ) {
			\G2RD\AdminConsole::close();
			return;
		}
		echo '</div></main></div></div></div></div>';
	}
}

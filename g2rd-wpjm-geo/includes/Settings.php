<?php
/**
 * Réglages du plugin (Settings API).
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page de réglages, stockage et lecture des options.
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
		);
	}

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( G2RD_WPJM_GEO_FILE ), array( $this, 'action_links' ) );
		// Le cache mémoire ne doit jamais survivre à une modification des réglages dans la même requête.
		add_action( 'update_option_' . self::OPTION, array( $this, 'flush_cache' ) );
		add_action( 'add_option_' . self::OPTION, array( $this, 'flush_cache' ) );
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
	 * URL de la page de réglages.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'edit.php?post_type=job_listing&page=' . self::PAGE_SLUG );
	}

	/**
	 * Lien « Réglages » dans la liste des extensions.
	 *
	 * @param array $links Liens existants.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Réglages', 'g2rd-wpjm-geo' ) ) );
		return $links;
	}

	/**
	 * Sous-menu dans « Offres d'emploi ».
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'edit.php?post_type=job_listing',
			__( 'G2RD Geo — Réglages', 'g2rd-wpjm-geo' ),
			__( 'G2RD Geo', 'g2rd-wpjm-geo' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * La page courante est-elle la page de réglages du plugin ?
	 *
	 * @param string $hook_suffix Suffixe fourni par admin_enqueue_scripts.
	 * @return bool
	 */
	public static function is_settings_screen( $hook_suffix ) {
		return 'job_listing_page_' . self::PAGE_SLUG === $hook_suffix;
	}

	/**
	 * Feuille de style de l'administration, uniquement sur la page du plugin.
	 *
	 * @param string $hook_suffix Écran courant.
	 * @return void
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( self::is_settings_screen( $hook_suffix ) ) {
			wp_enqueue_style( 'g2rd-wpjm-geo-admin', G2RD_WPJM_GEO_URL . 'assets/css/admin.css', array(), G2RD_WPJM_GEO_VERSION );
		}
	}

	/**
	 * Déclare l'option, les sections et les champs.
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

		add_settings_section( 'g2rd_google', __( 'Google Maps', 'g2rd-wpjm-geo' ), array( $this, 'render_google_intro' ), self::PAGE_SLUG );
		$this->add_field( 'browser_api_key', __( 'Google Maps Browser API Key', 'g2rd-wpjm-geo' ), 'g2rd_google', 'render_api_key' );
		$this->add_field( 'map_id', __( 'Map ID (facultatif)', 'g2rd-wpjm-geo' ), 'g2rd_google', 'render_map_id' );

		add_settings_section( 'g2rd_autocomplete', __( 'Autocomplétion des villes', 'g2rd-wpjm-geo' ), '__return_false', self::PAGE_SLUG );
		$this->add_checkbox( 'autocomplete_enabled', __( 'Activer l’autocomplétion des villes', 'g2rd-wpjm-geo' ), 'g2rd_autocomplete', __( 'Interrupteur général. Le champ de localisation natif de WP Job Manager est conservé.', 'g2rd-wpjm-geo' ) );
		$this->add_checkbox( 'autocomplete_frontend', __( 'Activer l’autocomplétion frontend', 'g2rd-wpjm-geo' ), 'g2rd_autocomplete', __( 'Formulaire public de dépôt et de modification d’offre.', 'g2rd-wpjm-geo' ) );
		$this->add_checkbox( 'autocomplete_admin', __( 'Activer l’autocomplétion admin', 'g2rd-wpjm-geo' ), 'g2rd_autocomplete', __( 'Écrans d’ajout et de modification d’une offre dans wp-admin.', 'g2rd-wpjm-geo' ) );

		add_settings_section( 'g2rd_map', __( 'Carte des offres', 'g2rd-wpjm-geo' ), array( $this, 'render_map_intro' ), self::PAGE_SLUG );
		$this->add_checkbox( 'map_enabled', __( 'Activer la carte', 'g2rd-wpjm-geo' ), 'g2rd_map', '' );
		$this->add_field( 'map_height', __( 'Hauteur de la carte', 'g2rd-wpjm-geo' ), 'g2rd_map', 'render_map_height' );
		$this->add_checkbox( 'clustering_enabled', __( 'Activer le clustering', 'g2rd-wpjm-geo' ), 'g2rd_map', __( 'Regroupe les marqueurs proches. Les offres aux coordonnées strictement identiques sont toujours réunies dans une même fenêtre.', 'g2rd-wpjm-geo' ) );

		add_settings_section( 'g2rd_gofetch', __( 'Go Fetch Jobs — candidatures importées', 'g2rd-wpjm-geo' ), array( $this, 'render_gofetch_intro' ), self::PAGE_SLUG );
		$this->add_field( 'fallback_email', __( 'Adresse email générique de candidature', 'g2rd-wpjm-geo' ), 'g2rd_gofetch', 'render_fallback_email' );
		$this->add_checkbox( 'gofetch_replace', __( 'Remplacer les URL de candidature des offres Go Fetch Jobs', 'g2rd-wpjm-geo' ), 'g2rd_gofetch', __( 'Lorsqu’elle est activée, toute URL de candidature issue d’une offre Go Fetch Jobs est remplacée par l’adresse email renseignée ci-dessus.', 'g2rd-wpjm-geo' ) );
		$this->add_field( 'gofetch_scope', __( 'URL concernées', 'g2rd-wpjm-geo' ), 'g2rd_gofetch', 'render_scope' );

		add_settings_section( 'g2rd_log', __( 'Journal', 'g2rd-wpjm-geo' ), '__return_false', self::PAGE_SLUG );
		$this->add_checkbox( 'log_enabled', __( 'Activer le journal', 'g2rd-wpjm-geo' ), 'g2rd_log', __( 'Conserve une trace de chaque remplacement (ID, date, ancienne et nouvelle valeur, raison, source).', 'g2rd-wpjm-geo' ) );
		$this->add_field( 'log_retention_days', __( 'Durée de conservation du journal', 'g2rd-wpjm-geo' ), 'g2rd_log', 'render_retention' );
	}

	/**
	 * Raccourci add_settings_field.
	 *
	 * @param string $key      Clé du réglage.
	 * @param string $label    Libellé.
	 * @param string $section  Section.
	 * @param string $callback Méthode de rendu.
	 * @return void
	 */
	private function add_field( $key, $label, $section, $callback ) {
		add_settings_field( $key, $label, array( $this, $callback ), self::PAGE_SLUG, $section, array( 'label_for' => 'g2rd-wpjm-geo-' . $key ) );
	}

	/**
	 * Ajoute une case à cocher.
	 *
	 * @param string $key         Clé.
	 * @param string $label       Libellé.
	 * @param string $section     Section.
	 * @param string $description Aide.
	 * @return void
	 */
	private function add_checkbox( $key, $label, $section, $description ) {
		add_settings_field(
			$key,
			$label,
			array( $this, 'render_checkbox' ),
			self::PAGE_SLUG,
			$section,
			array(
				'label_for'   => 'g2rd-wpjm-geo-' . $key,
				'key'         => $key,
				'description' => $description,
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

	/**
	 * Rendu de la page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits suffisants pour accéder à cette page.', 'g2rd-wpjm-geo' ) );
		}
		?>
		<div class="wrap g2rd-wpjm-geo-admin">
			<h1><?php esc_html_e( 'G2RD WP Job Manager Geo', 'g2rd-wpjm-geo' ); ?></h1>
			<?php settings_errors( self::OPTION ); ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
			<?php
			/**
			 * Zone d'outils sous le formulaire de réglages (outil de réparation, journal).
			 */
			do_action( 'g2rd_wpjm_geo_settings_tools' );
			?>
		</div>
		<?php
	}

	/**
	 * Introduction de la section Google.
	 *
	 * @return void
	 */
	public function render_google_intro() {
		echo '<p>' . esc_html__( 'Cette clé est utilisée uniquement par le navigateur (autocomplétion Places et affichage de la carte). Elle est distincte de la clé de WP Job Manager (Offres d’emploi → Réglages → Général → Google Maps API Key), qui continue de servir au géocodage côté serveur et n’est jamais envoyée au navigateur par ce plugin.', 'g2rd-wpjm-geo' ) . '</p>';
		echo '<div class="g2rd-wpjm-geo-callout"><p><strong>' . esc_html__( 'Restrictions à appliquer à la clé navigateur dans Google Cloud Console :', 'g2rd-wpjm-geo' ) . '</strong></p><ul>';
		echo '<li>' . sprintf(
			/* translators: %s: site domain pattern. */
			esc_html__( 'Restriction d’application « Sites web » (référents HTTP) : %s', 'g2rd-wpjm-geo' ),
			'<code>' . esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) . '/*' ) . '</code>'
		) . '</li>';
		echo '<li>' . esc_html__( 'Restriction d’API : uniquement « Maps JavaScript API » et « Places API (New) ».', 'g2rd-wpjm-geo' ) . '</li>';
		echo '<li>' . esc_html__( 'N’utilisez pas ici la clé serveur de WP Job Manager (Geocoding API) : une clé visible dans le navigateur doit toujours être restreinte par domaine.', 'g2rd-wpjm-geo' ) . '</li>';
		echo '</ul></div>';
	}

	/**
	 * Introduction de la section carte.
	 *
	 * @return void
	 */
	public function render_map_intro() {
		echo '<p>' . wp_kses(
			sprintf(
				/* translators: 1: shortcode, 2: block name. */
				__( 'Affichez la carte avec le shortcode %1$s (attribut facultatif <code>height="500"</code>) ou le bloc « %2$s ». Les coordonnées utilisées sont celles déjà calculées par WP Job Manager.', 'g2rd-wpjm-geo' ),
				'<code>[wpjm_jobs_map]</code>',
				esc_html__( 'Carte des offres d’emploi', 'g2rd-wpjm-geo' )
			),
			array( 'code' => array() )
		) . '</p>';
	}

	/**
	 * Introduction de la section Go Fetch Jobs.
	 *
	 * @return void
	 */
	public function render_gofetch_intro() {
		if ( Dependencies::has_gofetch() ) {
			echo '<p>' . esc_html__( 'Go Fetch Jobs est actif. Seules les offres importées par Go Fetch Jobs sont concernées ; les offres déposées par un employeur ou créées par un administrateur ne sont jamais modifiées.', 'g2rd-wpjm-geo' ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Go Fetch Jobs n’est pas actif : ces réglages sont sans effet pour le moment. L’autocomplétion et la carte fonctionnent normalement.', 'g2rd-wpjm-geo' ) . '</p>';
		}
	}

	/**
	 * Champ clé API. La valeur est affichée dans un champ de type mot de passe.
	 *
	 * @return void
	 */
	public function render_api_key() {
		printf(
			'<input type="password" class="regular-text" id="g2rd-wpjm-geo-browser_api_key" name="%1$s[browser_api_key]" value="%2$s" autocomplete="off" spellcheck="false" />',
			esc_attr( self::OPTION ),
			esc_attr( (string) $this->get( 'browser_api_key' ) )
		);
		echo '<p class="description">' . esc_html__( 'Sans clé, l’autocomplétion et la carte sont désactivées ; le champ de localisation reste un champ texte classique.', 'g2rd-wpjm-geo' ) . '</p>';
	}

	/**
	 * Champ Map ID.
	 *
	 * @return void
	 */
	public function render_map_id() {
		printf(
			'<input type="text" class="regular-text" id="g2rd-wpjm-geo-map_id" name="%1$s[map_id]" value="%2$s" autocomplete="off" spellcheck="false" />',
			esc_attr( self::OPTION ),
			esc_attr( (string) $this->get( 'map_id' ) )
		);
		echo '<p class="description">' . esc_html__( 'Recommandé : créez un Map ID (Google Cloud Console → Google Maps Platform → Gestion des cartes) pour utiliser les marqueurs avancés actuels de Google. Sans Map ID, la carte utilise les marqueurs classiques.', 'g2rd-wpjm-geo' ) . '</p>';
	}

	/**
	 * Case à cocher générique.
	 *
	 * @param array $args Arguments du champ.
	 * @return void
	 */
	public function render_checkbox( $args ) {
		$key = $args['key'];
		printf(
			'<input type="checkbox" id="g2rd-wpjm-geo-%1$s" name="%2$s[%1$s]" value="1" %3$s />',
			esc_attr( $key ),
			esc_attr( self::OPTION ),
			checked( (bool) $this->get( $key ), true, false )
		);
		if ( ! empty( $args['description'] ) ) {
			echo ' <span class="description">' . esc_html( $args['description'] ) . '</span>';
		}
	}

	/**
	 * Champ hauteur.
	 *
	 * @return void
	 */
	public function render_map_height() {
		printf(
			'<input type="number" class="small-text" id="g2rd-wpjm-geo-map_height" name="%1$s[map_height]" value="%2$d" min="200" max="1200" step="10" /> px',
			esc_attr( self::OPTION ),
			absint( $this->get( 'map_height' ) )
		);
		echo '<p class="description">' . esc_html__( 'Hauteur par défaut (200 à 1200 px). Sur petit écran, la carte est automatiquement limitée à 70 % de la hauteur de la fenêtre. La largeur est toujours fluide.', 'g2rd-wpjm-geo' ) . '</p>';
	}

	/**
	 * Champ adresse email générique de candidature.
	 *
	 * @return void
	 */
	public function render_fallback_email() {
		printf(
			'<input type="email" class="regular-text" id="g2rd-wpjm-geo-fallback_email" name="%1$s" value="%2$s" required />',
			esc_attr( self::FALLBACK_EMAIL_OPTION ),
			esc_attr( $this->get_fallback_email() )
		);
		echo '<p class="description">' . esc_html__( 'Cette adresse est utilisée lorsqu’une offre importée par Go Fetch Jobs contient une URL dans le champ de candidature.', 'g2rd-wpjm-geo' ) . '</p>';
	}

	/**
	 * Champ portée du remplacement.
	 *
	 * @return void
	 */
	public function render_scope() {
		$scopes = array(
			self::MODE_ALL     => __( 'Toutes les URL (recommandé) — aucune requête HTTP : la présence d’une URL suffit.', 'g2rd-wpjm-geo' ),
			self::MODE_INVALID => __( 'Uniquement les URL invalides ou inaccessibles — vérification différée par WP-Cron, jamais pendant l’import.', 'g2rd-wpjm-geo' ),
		);
		$current = self::MODE_INVALID === $this->get( 'gofetch_scope' ) ? self::MODE_INVALID : self::MODE_ALL;
		echo '<fieldset id="g2rd-wpjm-geo-gofetch_scope">';
		foreach ( $scopes as $value => $label ) {
			printf(
				'<label><input type="radio" name="%1$s[gofetch_scope]" value="%2$s" %3$s /> %4$s</label><br />',
				esc_attr( self::OPTION ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__( 'Une candidature déjà renseignée avec une adresse email valide n’est jamais modifiée, et une valeur vide reste vide.', 'g2rd-wpjm-geo' ) . '</p>';
	}

	/**
	 * Champ rétention.
	 *
	 * @return void
	 */
	public function render_retention() {
		printf(
			'<input type="number" class="small-text" id="g2rd-wpjm-geo-log_retention_days" name="%1$s[log_retention_days]" value="%2$d" min="1" max="365" /> %3$s',
			esc_attr( self::OPTION ),
			absint( $this->get( 'log_retention_days' ) ),
			esc_html__( 'jours', 'g2rd-wpjm-geo' )
		);
		echo '<p class="description">' . sprintf(
			/* translators: %d: max number of rows. */
			esc_html__( 'Les entrées plus anciennes sont purgées chaque jour. Le journal est en outre plafonné à %d entrées.', 'g2rd-wpjm-geo' ),
			absint( Logger::MAX_ROWS )
		) . '</p>';
	}
}

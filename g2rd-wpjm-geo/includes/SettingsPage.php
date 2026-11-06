<?php
/**
 * Page d'administration du plugin, à la charte de la console G2RD.
 *
 * @package G2RD\WPJMGeo
 */

namespace G2RD\WPJMGeo;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bandeau, sommaire, cartes de réglages (enregistrées par options.php) puis
 * outils (action g2rd_wpjm_geo_settings_tools). Classes du contrat v2 de la
 * console : g2rd-section, g2rd-setting-row, g2rd-field__input, g2rd-alert…
 */
class SettingsPage {

	/**
	 * Réglages.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructeur.
	 *
	 * @param Settings $settings Réglages.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Rendu de la page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'Vous n’avez pas les droits suffisants pour accéder à cette page.', 'g2rd-wpjm-geo' ) );
		}

		$provider = $this->settings->get_provider();
		$is_osm   = Settings::PROVIDER_OSM === $provider;
		$stats    = RegeocodeTool::stats( $provider );

		AdminScreen::open(
			array(
				'eyebrow'     => __( 'WP Job Manager', 'g2rd-wpjm-geo' ),
				'title'       => __( 'Offres géolocalisées', 'g2rd-wpjm-geo' ),
				'description' => __( 'Carte des offres, autocomplétion des villes et candidatures des offres importées.', 'g2rd-wpjm-geo' ),
				'chips'       => $this->chips( $is_osm, $stats ),
				'tiles'       => array(
					array(
						'label' => __( 'Fournisseur', 'g2rd-wpjm-geo' ),
						'value' => $is_osm ? 'OpenStreetMap' : 'Google Maps',
						'hint'  => $is_osm ? __( 'libre', 'g2rd-wpjm-geo' ) : __( 'clé navigateur', 'g2rd-wpjm-geo' ),
					),
					array(
						'label' => __( 'Offres géolocalisées', 'g2rd-wpjm-geo' ),
						'value' => $stats['shown'] . ' / ' . $stats['total'],
						'mono'  => true,
						'hint'  => __( 'fournisseur actif', 'g2rd-wpjm-geo' ),
					),
					array(
						'label' => __( 'Remplacements sur 30 jours', 'g2rd-wpjm-geo' ),
						'value' => $this->settings->get( 'log_enabled' ) ? (string) Logger::count_since( 30 ) : '—',
						'mono'  => true,
						'hint'  => __( 'candidatures Go Fetch Jobs', 'g2rd-wpjm-geo' ),
					),
				),
			)
		);

		// Sans argument : affiche aussi la confirmation native « Réglages enregistrés ».
		settings_errors();
		$this->toc();

		echo '<form action="options.php" method="post" class="g2rd-wpjm-geo-settings g2rd-tab-content">'; // g2rd-tab-content : espacement des cartes, comme dans le thème.
		settings_fields( Settings::GROUP );
		$this->section_cartography( $is_osm, $stats );
		$this->section_autocomplete();
		$this->section_map();
		$this->section_gofetch();
		$this->section_log();
		echo '<div class="g2rd-savebar" role="region" aria-label="' . esc_attr__( 'Enregistrement des réglages', 'g2rd-wpjm-geo' ) . '">';
		echo '<span class="g2rd-savebar__text">' . esc_html__( 'Modifications en attente', 'g2rd-wpjm-geo' ) . '</span>';
		echo '<button type="submit" class="components-button is-primary g2rd-savebar__save">' . esc_html__( 'Enregistrer les modifications', 'g2rd-wpjm-geo' ) . '</button>';
		echo '</div></form>';

		/**
		 * Outils sous les réglages (re-géocodage, offres Go Fetch Jobs, journal).
		 */
		do_action( 'g2rd_wpjm_geo_settings_tools' );

		AdminScreen::close();
	}

	/**
	 * Puces d'état du bandeau.
	 *
	 * @param bool                $is_osm Mode libre.
	 * @param array<string,mixed> $stats  Résumé de RegeocodeTool.
	 * @return array<int,array<string,string>>
	 */
	private function chips( $is_osm, array $stats ) {
		$chips = array();
		if ( Dependencies::has_gofetch() ) {
			$chips[] = array(
				'label' => __( 'Go Fetch Jobs actif', 'g2rd-wpjm-geo' ),
				'tone' => 'ok',
			);
		}
		if ( ! $is_osm && ! GoogleAssets::has_key( $this->settings ) ) {
			$chips[] = array(
				'label' => __( 'Clé navigateur manquante', 'g2rd-wpjm-geo' ),
				'tone' => 'warn',
			);
		}
		if ( $stats['todo'] > 0 ) {
			$chips[] = array(
				/* translators: %d: number of jobs. */
				'label' => sprintf( __( 'Offres à re-géocoder : %d', 'g2rd-wpjm-geo' ), $stats['todo'] ),
				'tone'  => 'warn',
			);
		}
		return $chips;
	}

	/**
	 * Sommaire d'ancres.
	 *
	 * @return void
	 */
	private function toc() {
		$items = array(
			'cartographie'   => __( 'Cartographie', 'g2rd-wpjm-geo' ),
			'autocompletion' => __( 'Autocomplétion', 'g2rd-wpjm-geo' ),
			'carte'          => __( 'Carte', 'g2rd-wpjm-geo' ),
			'gofetch'        => __( 'Candidatures Go Fetch Jobs', 'g2rd-wpjm-geo' ),
			'journal'        => __( 'Journal', 'g2rd-wpjm-geo' ),
			'outils'         => __( 'Outils', 'g2rd-wpjm-geo' ),
		);
		echo '<nav class="g2rd-row g2rd-row--wrap g2rd-geo-toc" aria-label="' . esc_attr__( 'Sommaire', 'g2rd-wpjm-geo' ) . '">';
		foreach ( $items as $id => $label ) {
			echo '<a class="g2rd-geo-toc__link" href="#g2rd-geo-' . esc_attr( $id ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Carte « Cartographie ».
	 *
	 * @param bool                $is_osm Mode libre.
	 * @param array<string,mixed> $stats  Résumé de RegeocodeTool.
	 * @return void
	 */
	private function section_cartography( $is_osm, array $stats ) {
		$this->open_section( 'cartographie', __( 'Cartographie', 'g2rd-wpjm-geo' ), __( 'Fournisseur de la carte, de l’autocomplétion des villes et du géocodage des offres : le choix s’applique aux trois.', 'g2rd-wpjm-geo' ) );
		$this->row(
			'g2rd-wpjm-geo-provider',
			__( 'Fournisseur cartographique', 'g2rd-wpjm-geo' ),
			__( 'Google Maps : clé navigateur requise, géocodage par WP Job Manager. OpenStreetMap : MapLibre, OpenFreeMap et Photon, sans clé ni compte.', 'g2rd-wpjm-geo' ),
			$this->radios(
				'provider',
				array(
					Settings::PROVIDER_GOOGLE => __( 'Google Maps', 'g2rd-wpjm-geo' ),
					Settings::PROVIDER_OSM    => __( 'OpenStreetMap (libre)', 'g2rd-wpjm-geo' ),
				),
				$this->settings->get_provider()
			),
			'',
			true
		);
		$this->row(
			'g2rd-wpjm-geo-browser_api_key',
			__( 'Google Maps Browser API Key', 'g2rd-wpjm-geo' ),
			sprintf(
				/* translators: %s: site domain pattern. */
				__( 'Clé utilisée uniquement par le navigateur (autocomplétion et carte). Restreignez-la au référent %s et aux API « Maps JavaScript API » et « Places API (New) ». La clé serveur de WP Job Manager n’est jamais envoyée au navigateur.', 'g2rd-wpjm-geo' ),
				wp_parse_url( home_url(), PHP_URL_HOST ) . '/*'
			),
			$this->input( 'browser_api_key', 'password', ' autocomplete="new-password" spellcheck="false"' ),
			Settings::PROVIDER_GOOGLE
		);
		$this->row(
			'g2rd-wpjm-geo-map_id',
			__( 'Map ID (facultatif)', 'g2rd-wpjm-geo' ),
			__( 'Active les marqueurs avancés de Google. Sans Map ID, la carte utilise les marqueurs classiques.', 'g2rd-wpjm-geo' ),
			$this->input( 'map_id', 'text', ' autocomplete="off" spellcheck="false"' ),
			Settings::PROVIDER_GOOGLE
		);
		$this->row(
			'g2rd-wpjm-geo-osm_style_url',
			__( 'Style de la carte', 'g2rd-wpjm-geo' ),
			__( 'URL https d’un style MapLibre. Styles OpenFreeMap : liberty, bright ou positron (https://tiles.openfreemap.org/styles/…). Videz le champ pour revenir au style par défaut.', 'g2rd-wpjm-geo' ),
			$this->input( 'osm_style_url', 'text', ' inputmode="url" spellcheck="false"' ), // text : un champ url invalide et masqué bloquerait l'envoi sans message.
			Settings::PROVIDER_OSM
		);
		$this->row(
			'g2rd-wpjm-geo-photon_url',
			__( 'Serveur Photon', 'g2rd-wpjm-geo' ),
			__( 'URL https de l’API de recherche Photon, utilisée pour l’autocomplétion et le géocodage des offres. Remplaçable par un serveur auto-hébergé ; videz le champ pour revenir à l’instance publique. URL publique, transmise aux navigateurs : n’y mettez aucune clé privée.', 'g2rd-wpjm-geo' ),
			$this->input( 'photon_url', 'text', ' inputmode="url" spellcheck="false"' ),
			Settings::PROVIDER_OSM
		);
		echo '</div>';
		$this->alert(
			'info',
			__( 'Services publics gratuits, sans garantie de disponibilité. L’attribution « © OpenStreetMap contributors » est affichée automatiquement. L’adresse IP des visiteurs de la carte est transmise à OpenFreeMap, celle des personnes qui saisissent une ville au serveur Photon : un texte suggéré est disponible dans Réglages → Confidentialité.', 'g2rd-wpjm-geo' ),
			Settings::PROVIDER_OSM
		);
		$absent = $stats['todo'] - $stats['update'];
		if ( $absent > 0 ) {
			$this->alert(
				'warning',
				sprintf(
					/* translators: %d: number of jobs. */
					_n( '%d offre n’est pas géocodée par le fournisseur actif : elle n’apparaît pas sur la carte.', '%d offres ne sont pas géocodées par le fournisseur actif : elles n’apparaissent pas sur la carte.', $absent, 'g2rd-wpjm-geo' ),
					$absent
				),
				'',
				'#g2rd-geo-outils',
				__( 'Re-géocoder les offres', 'g2rd-wpjm-geo' )
			);
		}
		if ( $stats['update'] > 0 ) {
			$this->alert(
				'info',
				sprintf(
					/* translators: %d: number of jobs. */
					_n( '%d offre libre n’affiche qu’un lieu sur la carte : re-géocodez-la pour placer tous ses lieux (villes et régions).', '%d offres libres n’affichent qu’un lieu sur la carte : re-géocodez-les pour placer tous leurs lieux (villes et régions).', $stats['update'], 'g2rd-wpjm-geo' ),
					$stats['update']
				),
				'',
				'#g2rd-geo-outils',
				__( 'Re-géocoder les offres', 'g2rd-wpjm-geo' )
			);
		}
		echo '</section>';
	}

	/**
	 * Carte « Autocomplétion ».
	 *
	 * @return void
	 */
	private function section_autocomplete() {
		$this->open_section( 'autocompletion', __( 'Autocomplétion des villes', 'g2rd-wpjm-geo' ), __( 'Le champ de localisation natif de WP Job Manager est conservé : aucune saisie en double.', 'g2rd-wpjm-geo' ) );
		$this->row( 'g2rd-wpjm-geo-autocomplete_enabled', __( 'Activer l’autocomplétion des villes', 'g2rd-wpjm-geo' ), __( 'Interrupteur général.', 'g2rd-wpjm-geo' ), $this->toggle( 'autocomplete_enabled' ) );
		$this->row( 'g2rd-wpjm-geo-autocomplete_frontend', __( 'Formulaire public', 'g2rd-wpjm-geo' ), __( 'Dépôt et modification d’une offre par un employeur.', 'g2rd-wpjm-geo' ), $this->toggle( 'autocomplete_frontend' ) );
		$this->row( 'g2rd-wpjm-geo-autocomplete_admin', __( 'Administration', 'g2rd-wpjm-geo' ), __( 'Ajout et modification d’une offre dans wp-admin.', 'g2rd-wpjm-geo' ), $this->toggle( 'autocomplete_admin' ) );
		echo '</div></section>';
	}

	/**
	 * Carte « Carte des offres ».
	 *
	 * @return void
	 */
	private function section_map() {
		$this->open_section(
			'carte',
			__( 'Carte des offres', 'g2rd-wpjm-geo' ),
			sprintf(
				/* translators: 1: shortcode, 2: block name. */
				__( 'Shortcode %1$s (attribut facultatif height="500") ou bloc « %2$s ».', 'g2rd-wpjm-geo' ),
				'[wpjm_jobs_map]',
				__( 'Carte des offres d’emploi', 'g2rd-wpjm-geo' )
			)
		);
		$this->row( 'g2rd-wpjm-geo-map_enabled', __( 'Activer la carte', 'g2rd-wpjm-geo' ), '', $this->toggle( 'map_enabled', false ) );
		$this->row( 'g2rd-wpjm-geo-map_height', __( 'Hauteur de la carte (px)', 'g2rd-wpjm-geo' ), __( 'De 200 à 1200 px. Sur petit écran, la carte est limitée à 70 % de la hauteur de la fenêtre ; la largeur est toujours fluide.', 'g2rd-wpjm-geo' ), $this->input( 'map_height', 'number', ' min="200" max="1200" step="10"' ) );
		$this->row( 'g2rd-wpjm-geo-clustering_enabled', __( 'Regrouper les offres proches', 'g2rd-wpjm-geo' ), __( 'Les offres aux coordonnées strictement identiques sont toujours réunies dans une même fenêtre.', 'g2rd-wpjm-geo' ), $this->toggle( 'clustering_enabled' ) );
		echo '</div></section>';
	}

	/**
	 * Carte « Candidatures Go Fetch Jobs ».
	 *
	 * @return void
	 */
	private function section_gofetch() {
		$this->open_section(
			'gofetch',
			__( 'Candidatures Go Fetch Jobs', 'g2rd-wpjm-geo' ),
			Dependencies::has_gofetch()
				? __( 'Go Fetch Jobs est actif. Seules les offres importées par Go Fetch Jobs sont concernées ; les offres déposées par un employeur ou créées par un administrateur ne sont jamais modifiées.', 'g2rd-wpjm-geo' )
				: __( 'Go Fetch Jobs n’est pas actif : ces réglages sont sans effet pour le moment.', 'g2rd-wpjm-geo' )
		);
		$this->row(
			'g2rd-wpjm-geo-fallback_email',
			__( 'Adresse email générique de candidature', 'g2rd-wpjm-geo' ),
			__( 'Cette adresse est utilisée lorsqu’une offre importée par Go Fetch Jobs contient une URL dans le champ de candidature.', 'g2rd-wpjm-geo' ),
			'<input class="g2rd-field__input" type="email" id="g2rd-wpjm-geo-fallback_email" name="' . esc_attr( Settings::FALLBACK_EMAIL_OPTION ) . '" value="' . esc_attr( $this->settings->get_fallback_email() ) . '" required aria-describedby="g2rd-wpjm-geo-fallback_email-desc" />'
		);
		$this->row( 'g2rd-wpjm-geo-gofetch_replace', __( 'Remplacer les URL de candidature des offres Go Fetch Jobs', 'g2rd-wpjm-geo' ), __( 'Lorsqu’elle est activée, toute URL de candidature issue d’une offre Go Fetch Jobs est remplacée par l’adresse email renseignée ci-dessus.', 'g2rd-wpjm-geo' ), $this->toggle( 'gofetch_replace' ) );
		$this->row(
			'g2rd-wpjm-geo-gofetch_scope',
			__( 'URL concernées', 'g2rd-wpjm-geo' ),
			__( 'Une adresse email valide n’est jamais modifiée et une valeur vide reste vide.', 'g2rd-wpjm-geo' ),
			$this->radios(
				'gofetch_scope',
				array(
					Settings::MODE_ALL     => __( 'Toutes les URL (recommandé) — aucune requête HTTP', 'g2rd-wpjm-geo' ),
					Settings::MODE_INVALID => __( 'Uniquement les URL invalides ou inaccessibles — vérification différée par WP-Cron', 'g2rd-wpjm-geo' ),
				),
				Settings::MODE_INVALID === $this->settings->get( 'gofetch_scope' ) ? Settings::MODE_INVALID : Settings::MODE_ALL
			),
			'',
			true
		);
		echo '</div></section>';
	}

	/**
	 * Carte « Journal ».
	 *
	 * @return void
	 */
	private function section_log() {
		$this->open_section( 'journal', __( 'Journal', 'g2rd-wpjm-geo' ), __( 'Trace de chaque remplacement : offre, date, ancienne et nouvelle valeur, raison, source.', 'g2rd-wpjm-geo' ) );
		$this->row( 'g2rd-wpjm-geo-log_enabled', __( 'Activer le journal', 'g2rd-wpjm-geo' ), '', $this->toggle( 'log_enabled', false ) );
		$this->row(
			'g2rd-wpjm-geo-log_retention_days',
			__( 'Durée de conservation (jours)', 'g2rd-wpjm-geo' ),
			sprintf(
				/* translators: %d: max number of rows. */
				__( 'Les entrées plus anciennes sont purgées chaque jour. Le journal est en outre plafonné à %d entrées.', 'g2rd-wpjm-geo' ),
				Logger::MAX_ROWS
			),
			$this->input( 'log_retention_days', 'number', ' min="1" max="365"' )
		);
		echo '</div></section>';
	}

	/**
	 * Ouvre une carte et sa liste de réglages (fermer par `</div></section>`).
	 *
	 * @param string $id    Suffixe d'ancre.
	 * @param string $title Titre.
	 * @param string $desc  Description.
	 * @return void
	 */
	private function open_section( $id, $title, $desc ) {
		echo '<section class="g2rd-section" id="g2rd-geo-' . esc_attr( $id ) . '">';
		echo '<div class="g2rd-section__head"><h3 class="g2rd-section__title">' . esc_html( $title ) . '</h3></div>';
		if ( '' !== $desc ) {
			echo '<p class="g2rd-section__desc">' . esc_html( $desc ) . '</p>';
		}
		echo '<div class="g2rd-setting-list">';
	}

	/**
	 * Attributs d'un élément propre à un fournisseur.
	 *
	 * @param string $provider Fournisseur, ou chaîne vide.
	 * @return string Attributs échappés, précédés d'une espace.
	 */
	private function provider_attrs( $provider ) {
		if ( '' === $provider ) {
			return '';
		}
		$attrs = ' data-g2rd-provider="' . esc_attr( $provider ) . '"';
		if ( $provider !== $this->settings->get_provider() ) {
			// style et non hidden : la console donne display:flex aux lignes, ce qui l'emporte sur [hidden].
			$attrs .= ' style="display:none"';
		}
		return $attrs;
	}

	/**
	 * Ligne de réglage.
	 *
	 * @param string $field_id Identifiant du champ.
	 * @param string $title    Titre.
	 * @param string $desc     Description.
	 * @param string $control  HTML du contrôle (construit et échappé par cette classe).
	 * @param string $provider Fournisseur concerné, ou chaîne vide.
	 * @param bool   $group    Groupe de boutons radio (titre non relié par for).
	 * @return void
	 */
	private function row( $field_id, $title, $desc, $control, $provider = '', $group = false ) {
		echo '<div class="g2rd-setting-row"' . $this->provider_attrs( $provider ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributs échappés par provider_attrs().
		echo '<div class="g2rd-setting-row__main">';
		if ( $group ) {
			echo '<span class="g2rd-setting-row__title" id="' . esc_attr( $field_id ) . '-title">' . esc_html( $title ) . '</span>';
		} else {
			echo '<label class="g2rd-setting-row__title" for="' . esc_attr( $field_id ) . '">' . esc_html( $title ) . '</label>';
		}
		if ( '' !== $desc ) {
			echo '<p class="g2rd-setting-row__desc" id="' . esc_attr( $field_id ) . '-desc">' . esc_html( $desc ) . '</p>';
		}
		echo '</div><div class="g2rd-setting-row__control">' . $control . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- contrôle construit et échappé par les méthodes de cette classe.
	}

	/**
	 * Encart.
	 *
	 * @param string $tone       info, warning, success ou danger.
	 * @param string $text       Texte.
	 * @param string $provider   Fournisseur concerné, ou chaîne vide.
	 * @param string $link_url   Lien facultatif.
	 * @param string $link_label Libellé du lien.
	 * @return void
	 */
	private function alert( $tone, $text, $provider = '', $link_url = '', $link_label = '' ) {
		echo '<div class="g2rd-alert g2rd-alert--' . esc_attr( $tone ) . '"' . $this->provider_attrs( $provider ) . '><p>' . esc_html( $text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributs échappés par provider_attrs().
		if ( '' !== $link_url ) {
			echo ' <a href="' . esc_url( $link_url ) . '">' . esc_html( $link_label ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Nom de champ de l'option principale.
	 *
	 * @param string $key Clé.
	 * @return string
	 */
	private function name( $key ) {
		return Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Interrupteur (balisage de FormToggle de WordPress).
	 *
	 * @param string $key       Clé.
	 * @param bool   $described La ligne a une description.
	 * @return string
	 */
	private function toggle( $key, $described = true ) {
		$id = 'g2rd-wpjm-geo-' . $key;
		$on = (bool) $this->settings->get( $key );
		return '<span class="components-form-toggle g2rd-toggle' . ( $on ? ' is-checked' : '' ) . '">'
			. '<input class="components-form-toggle__input" type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $key ) ) . '" value="1"' . checked( $on, true, false ) . ( $described ? ' aria-describedby="' . esc_attr( $id ) . '-desc"' : '' ) . ' />'
			. '<span class="components-form-toggle__track"></span><span class="components-form-toggle__thumb"></span></span>';
	}

	/**
	 * Champ texte, nombre, URL ou mot de passe.
	 *
	 * @param string $key   Clé.
	 * @param string $type  Type HTML.
	 * @param string $extra Attributs littéraux supplémentaires (constantes de cette classe).
	 * @return string
	 */
	private function input( $key, $type, $extra = '' ) {
		$id = 'g2rd-wpjm-geo-' . $key;
		return '<input class="g2rd-field__input" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( $this->name( $key ) ) . '" value="' . esc_attr( (string) $this->settings->get( $key ) ) . '" aria-describedby="' . esc_attr( $id ) . '-desc"' . $extra . ' />';
	}

	/**
	 * Groupe de boutons radio.
	 *
	 * @param string               $key     Clé.
	 * @param array<string,string> $choices Valeur => libellé.
	 * @param string               $current Valeur courante.
	 * @return string
	 */
	private function radios( $key, array $choices, $current ) {
		$html = '<fieldset class="g2rd-stack" aria-labelledby="' . esc_attr( 'g2rd-wpjm-geo-' . $key ) . '-title">';
		foreach ( $choices as $value => $label ) {
			$html .= '<label class="g2rd-row"><input type="radio" name="' . esc_attr( $this->name( $key ) ) . '" value="' . esc_attr( $value ) . '"' . checked( $current, $value, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		return $html . '</fieldset>';
	}
}

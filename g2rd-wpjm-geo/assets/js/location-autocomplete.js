/**
 * Autocomplétion mondiale des villes sur le champ de localisation natif de WP Job Manager.
 *
 * Deux sources, selon le réglage « Fournisseur cartographique » :
 * - google : API « Place Autocomplete Data » (Places API New) ;
 * - photon : service Photon (OpenStreetMap), sans clé.
 * Le champ existant est conservé tel quel, aucune saisie n'est dupliquée, et la
 * saisie libre reste possible si le service est indisponible.
 */
( function ( window, document ) {
	'use strict';

	var settings = window.g2rdWpjmGeoAutocomplete || {};
	var source = 'photon' === settings.source ? 'photon' : 'google';
	var loader = window.g2rdWpjmGeoLoader;
	var i18n = settings.i18n || {};
	var MIN_LENGTH = settings.minLength || 2;
	var DEBOUNCE = 'photon' === source ? 300 : 250;
	// « (cities) » est la collection prévue par Places API (New). Le second jeu sert de repli si elle est refusée.
	var TYPE_SETS = [
		[ '(cities)' ],
		[ 'locality', 'postal_town', 'administrative_area_level_3' ]
	];
	// Photon : villes, bourgs, villages et communes du monde entier.
	var PHOTON_TAGS = [ 'place:city', 'place:town', 'place:village', 'place:municipality' ];
	// Mode étiquettes (configuration du plugin en mode libre) : plusieurs lieux, régions proposées sans réseau.
	var T = 'photon' === source && settings.tags ? window.g2rdWpjmGeoTags : null;
	var regionIndex = T ? T.regionIndex( settings.regions ) : [];
	var MAX_SUGGESTIONS = 6;
	var uid = 0;

	function findInput() {
		var selectors = settings.selectors || [];
		for ( var i = 0; i < selectors.length; i++ ) {
			var node = null;
			try {
				node = document.querySelector( selectors[ i ] );
			} catch ( e ) {
				node = null;
			}
			if ( node && 'INPUT' === node.tagName && ! node.hasAttribute( 'data-g2rd-wpjm-geo-ac' ) && ! node.hasAttribute( 'data-g2rd-wpjm-geo-tags' ) ) {
				return node;
			}
		}
		return null;
	}

	// Suggestions normalisées : { label, main, secondary }.
	function fromGoogle( suggestions ) {
		var out = [];
		( suggestions || [] ).forEach( function ( suggestion ) {
			var prediction = suggestion && suggestion.placePrediction;
			if ( ! prediction || ! prediction.text ) {
				return;
			}
			var label = String( prediction.text.text || prediction.text );
			if ( ! label ) {
				return;
			}
			out.push( {
				label: label,
				main: ( prediction.mainText && prediction.mainText.text ) || '',
				secondary: ( prediction.secondaryText && prediction.secondaryText.text ) || ''
			} );
		} );
		return out;
	}

	// Commune dont le nom est aussi celui d'une region (« Bretagne », Indre) : le libelle recoit un indice que le
	// serveur reconnait (PhotonGeocoder::hint), sinon il lirait la region. Departement (2 chiffres, 3 pour 97),
	// ou code postal complet pour la Corse (20) ; sans code postal, le nom reste seul.
	function homonymName( props ) {
		var found = /(?:^|\D)(\d{5})(?!\d)/.exec( String( props.postcode || '' ) );
		var code = found ? found[ 1 ] : '';
		if ( ! T || ! code || ! T.findRegion( props.name, regionIndex ) ) {
			return props.name;
		}
		if ( '20' === code.slice( 0, 2 ) ) {
			return props.name + ' ' + code;
		}
		return props.name + ' (' + code.slice( 0, '97' === code.slice( 0, 2 ) ? 3 : 2 ) + ')';
	}

	function fromPhoton( features, placesOnly ) {
		var places = [];
		var count = {};
		( features || [] ).forEach( function ( feature ) {
			var props = feature && feature.properties;
			if ( ! props || 'string' !== typeof props.name || ! props.name ) {
				return;
			}
			if ( placesOnly && 'place' !== props.osm_key ) {
				return;
			}
			var key = [ props.name, props.state, props.country ].join( '|' );
			count[ key ] = ( count[ key ] || 0 ) + 1;
			places.push( { props: props, key: key } );
		} );
		var out = [];
		var seen = {};
		places.forEach( function ( place ) {
			var props = place.props;
			var parts = [ props.name ];
			// Homonymes dans une même région : le département les distingue, à l'écran comme pour le géocodage serveur.
			var extra = count[ place.key ] > 1 ? [ props.county ] : [];
			extra.concat( [ props.state, props.country ] ).forEach( function ( part ) {
				if ( 'string' === typeof part && part && parts.indexOf( part ) === -1 ) {
					parts.push( part );
				}
			} );
			parts[ 0 ] = homonymName( props );
			var label = parts.join( ', ' );
			if ( seen[ label ] ) {
				return;
			}
			seen[ label ] = true;
			out.push( { label: label, main: props.name, secondary: parts.slice( 1 ).join( ', ' ) } );
		} );
		return out;
	}

	function fromRegions( value ) {
		return T.searchRegions( value, regionIndex, MAX_SUGGESTIONS ).map( function ( hit ) {
			var kind = i18n.region || '';
			return {
				label: hit.region.name,
				main: hit.region.name,
				secondary: hit.matched !== hit.region.name ? kind + ' (' + hit.matched + ')' : kind,
				region: hit.region.code,
				start: hit.start
			};
		} );
	}

	// Mode étiquettes : régions dont le nom commence par la saisie, puis villes Photon, puis régions trouvées par un mot.
	function merge( local, cities ) {
		var first = local.filter( function ( item ) {
			return item.start;
		} );
		var later = local.filter( function ( item ) {
			return ! item.start;
		} );
		return first.concat( cities, later ).slice( 0, MAX_SUGGESTIONS );
	}

	function attach( input, field ) {
		var listId = 'g2rd-wpjm-geo-ac-list-' + ( ++uid );
		var places = null; // Bibliothèque Places, chargée au premier focus (Google).
		var token = null;
		var disabled = false;
		var timer = null;
		var requestId = 0;
		var items = [];
		var active = -1;
		var typeSet = 0;
		var choosing = false;
		var byKeyboard = false; // L'option active vient des flèches (et non du simple survol de la souris).
		var photonFiltered = true;
		var controller = null;
		var cache = {};

		input.setAttribute( 'data-g2rd-wpjm-geo-ac', '1' );
		input.setAttribute( 'autocomplete', 'off' );
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', listId );

		// La liste est attachée à <body> : elle n'est jamais rognée par un conteneur du thème
		// et la structure HTML du formulaire WP Job Manager reste intacte.
		var panel = document.createElement( 'div' );
		panel.className = 'g2rd-wpjm-geo-ac';
		panel.hidden = true;

		var list = document.createElement( 'ul' );
		list.className = 'g2rd-wpjm-geo-ac__list';
		list.id = listId;
		list.setAttribute( 'role', 'listbox' );
		list.setAttribute( 'aria-label', i18n.results || '' );

		var attribution = document.createElement( 'div' );
		attribution.className = 'g2rd-wpjm-geo-ac__attribution';
		attribution.textContent = i18n.poweredBy || '';

		panel.appendChild( list );
		panel.appendChild( attribution );
		document.body.appendChild( panel );

		function position() {
			var rect = input.getBoundingClientRect();
			// Étiquettes : le panneau suit la largeur de toute la zone, pas celle de la saisie restante.
			var box = field ? field.root.getBoundingClientRect() : rect;
			panel.style.top = ( rect.bottom + window.pageYOffset ) + 'px';
			panel.style.left = ( box.left + window.pageXOffset ) + 'px';
			panel.style.width = box.width + 'px';
		}

		function close() {
			panel.hidden = true;
			items = [];
			active = -1;
			byKeyboard = false;
			list.textContent = '';
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
		}

		function setActive( index ) {
			active = index;
			Array.prototype.forEach.call( list.children, function ( node, i ) {
				var selected = i === index;
				node.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				node.classList.toggle( 'is-active', selected );
				if ( selected ) {
					input.setAttribute( 'aria-activedescendant', node.id );
				}
			} );
			if ( index < 0 ) {
				input.removeAttribute( 'aria-activedescendant' );
			}
		}

		function choose( index ) {
			var item = items[ index ];
			if ( ! item ) {
				return;
			}
			if ( field ) {
				// Mode étiquettes : la saisie est vidée d'abord (son texte serait sinon enregistré avec l'étiquette).
				var typed = input.value;
				input.value = '';
				// Ni le serveur ni la réouverture de l'offre ne doivent découper ce libellé (« Biel/Bienne »).
				var label = item.label.replace( /\s*[\/|;]\s*/g, '-' );
				if ( ! field.add( label, item.region || '' ) && field.values().length >= ( settings.maxPlaces || 10 ) && ! field.has( label, item.region || '' ) ) {
					input.value = typed; // Refusé au plafond : le texte tapé reste dans la saisie (un doublon est abandonné).
				}
				close();
				return;
			}
			// La valeur est écrite dans le champ natif : c'est elle que WP Job Manager enregistre puis géocode.
			input.value = item.label;
			// Prévient les autres scripts du formulaire, sans relancer notre propre recherche.
			choosing = true;
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			choosing = false;
			token = null; // Fin de la session de facturation Google.
			close();
		}

		function render( suggestions ) {
			// Option atteinte aux flèches : elle est réactivée si la nouvelle liste la contient encore.
			var keep = field && byKeyboard && active >= 0 && items[ active ] ? items[ active ] : null;
			list.textContent = '';
			items = [];
			byKeyboard = false;
			// Nouvelle liste : aucune option active (sinon le lecteur d'écran annoncerait une option qu'Entrée ne choisit pas).
			active = -1;
			input.removeAttribute( 'aria-activedescendant' );
			suggestions.forEach( function ( suggestion ) {
				var index = items.length;
				var option = document.createElement( 'li' );
				option.id = listId + '-option-' + index;
				option.className = 'g2rd-wpjm-geo-ac__option';
				option.setAttribute( 'role', 'option' );
				option.setAttribute( 'aria-selected', 'false' );

				if ( field ) {
					var icon = document.createElement( 'span' );
					icon.className = 'g2rd-wpjm-geo-ac__icon';
					icon.setAttribute( 'aria-hidden', 'true' );
					icon.textContent = suggestion.region ? T.icons.region : T.icons.city;
					option.appendChild( icon );
				}
				if ( suggestion.main ) {
					var strong = document.createElement( 'span' );
					strong.className = 'g2rd-wpjm-geo-ac__main';
					strong.textContent = suggestion.main;
					option.appendChild( strong );
					if ( suggestion.secondary ) {
						var rest = document.createElement( 'span' );
						rest.className = 'g2rd-wpjm-geo-ac__secondary';
						rest.textContent = ' ' + suggestion.secondary;
						option.appendChild( rest );
					}
				} else {
					option.textContent = suggestion.label;
				}

				// mousedown (et non click) : agit avant la perte de focus du champ.
				option.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
					choose( index );
				} );
				option.addEventListener( 'mouseenter', function () {
					byKeyboard = false;
					setActive( index );
				} );

				items.push( { label: suggestion.label, region: suggestion.region || '' } );
				list.appendChild( option );
			} );

			if ( items.length ) {
				position();
				panel.hidden = false;
				input.setAttribute( 'aria-expanded', 'true' );
				active = -1;
				items.forEach( function ( item, i ) {
					if ( keep && item.label === keep.label && item.region === keep.region ) {
						byKeyboard = true;
						setActive( i );
					}
				} );
			} else {
				close();
			}
		}

		function ensurePlaces() {
			if ( places ) {
				return Promise.resolve( places );
			}
			return loader.load().then( function ( maps ) {
				return maps.importLibrary( 'places' );
			} ).then( function ( library ) {
				if ( ! library || ! library.AutocompleteSuggestion ) {
					throw new Error( 'places-unavailable' );
				}
				places = library;
				return places;
			} );
		}

		function fetchSuggestions( library, value ) {
			if ( ! token ) {
				token = new library.AutocompleteSessionToken();
			}
			var request = {
				input: value,
				includedPrimaryTypes: TYPE_SETS[ typeSet ], // Villes du monde entier : aucune restriction de pays.
				sessionToken: token
			};
			if ( window.g2rdWpjmGeoConfig && window.g2rdWpjmGeoConfig.language ) {
				request.language = window.g2rdWpjmGeoConfig.language;
			}
			return library.AutocompleteSuggestion.fetchAutocompleteSuggestions( request ).catch( function ( error ) {
				// Le filtre de types a pu être refusé par Google : on retente une seule fois avec le jeu
				// de types explicites, conservé ensuite pour le reste de la session.
				if ( typeSet < TYPE_SETS.length - 1 ) {
					typeSet++;
					return fetchSuggestions( library, value );
				}
				throw error;
			} );
		}

		function photonSearch( value ) {
			var cacheKey = value.toLowerCase();
			if ( cache[ cacheKey ] ) {
				return Promise.resolve( cache[ cacheKey ] );
			}
			if ( controller ) {
				controller.abort();
			}
			controller = 'function' === typeof window.AbortController ? new window.AbortController() : null;

			var params = 'q=' + encodeURIComponent( value ) + '&limit=6&lang=' + encodeURIComponent( settings.lang || 'default' );
			if ( photonFiltered ) {
				params += PHOTON_TAGS.map( function ( tag ) {
					return '&osm_tag=' + encodeURIComponent( tag );
				} ).join( '' );
			}
			var base = ( settings.photonUrl || '' ).split( '#' )[ 0 ]; // Fragment retiré, comme côté serveur.
			var options = { credentials: 'omit' };
			if ( controller ) {
				options.signal = controller.signal;
			}

			return window.fetch( base + ( base.indexOf( '?' ) === -1 ? '?' : '&' ) + params, options ).then( function ( response ) {
				if ( 400 === response.status && photonFiltered ) {
					// Filtre refusé par le serveur : repli sans filtre, mémorisé pour la page.
					photonFiltered = false;
					controller = null;
					return photonSearch( value );
				}
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json().then( function ( data ) {
					var suggestions = fromPhoton( data && data.features, ! photonFiltered );
					cache[ cacheKey ] = suggestions;
					return suggestions;
				} );
			} );
		}

		function search() {
			var value = input.value.trim();
			// Régions françaises (mode étiquettes) : liste locale, sans appel réseau.
			var local = field ? fromRegions( value ) : [];
			if ( value.length < MIN_LENGTH || ( disabled && ! local.length ) ) {
				close();
				return;
			}
			// Les régions dont le nom commence par la saisie n'attendent pas Photon ; les autres viennent après les villes.
			var early = local.filter( function ( item ) {
				return item.start;
			} );
			if ( early.length && document.activeElement === input ) {
				render( early );
			}
			var current = ++requestId;
			var pending;
			if ( 'photon' === source ) {
				pending = disabled ? Promise.resolve( [] ) : photonSearch( value );
			} else {
				pending = ensurePlaces().then( function ( library ) {
					return fetchSuggestions( library, value );
				} ).then( function ( result ) {
					return fromGoogle( result && result.suggestions );
				} );
			}

			// Réponse encore utile : ni nouvelle frappe, ni perte du focus, ni texte changé (étiquettes : Entrée ou choix ont pu vider la saisie).
			function fresh() {
				return current === requestId && document.activeElement === input && ( ! field || input.value.trim() === value );
			}

			pending.then( function ( suggestions ) {
				// Ignore les réponses obsolètes (l'utilisateur a continué à taper).
				if ( fresh() ) {
					render( field ? merge( local, suggestions ) : suggestions );
				}
			} ).catch( function ( error ) {
				if ( error && 'AbortError' === error.name ) {
					return; // Requête remplacée par une frappe plus récente.
				}
				// Service indisponible ou clé refusée : le champ redevient un simple champ texte (les régions restent proposées).
				disabled = true;
				if ( local.length && fresh() ) {
					render( local );
				} else {
					close();
				}
			} );
		}

		input.addEventListener( 'focus', function () {
			if ( 'google' === source && ! disabled ) {
				ensurePlaces().catch( function () {
					disabled = true;
				} );
			}
		} );

		input.addEventListener( 'input', function () {
			// Ignore l'événement « input » émis par choose().
			if ( choosing ) {
				return;
			}
			window.clearTimeout( timer );
			if ( field && input.value.trim().length < MIN_LENGTH ) {
				close();
			} else if ( field ) {
				// Le texte change : l'option surlignée ne correspond plus à ce qui est tapé.
				byKeyboard = false;
				setActive( -1 );
			}
			timer = window.setTimeout( search, DEBOUNCE );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( panel.hidden ) {
				if ( field && 'Enter' === event.key && input.value.trim() ) {
					event.preventDefault(); // Entrée valide le texte en cours sans envoyer le formulaire.
					field.commit();
				}
				return;
			}
			switch ( event.key ) {
				case 'ArrowDown':
					byKeyboard = true;
					event.preventDefault();
					setActive( ( active + 1 ) % items.length );
					break;
				case 'ArrowUp':
					byKeyboard = true;
					event.preventDefault();
					setActive( ( active - 1 + items.length ) % items.length );
					break;
				case 'Enter':
					if ( active >= 0 ) {
						event.preventDefault(); // Ne soumet pas le formulaire pendant le choix d'une ville.
						choose( active );
					} else if ( field && input.value.trim() ) {
						event.preventDefault();
						close();
						field.commit();
					}
					break;
				case 'Escape':
					event.preventDefault();
					close();
					break;
				case 'Tab':
					if ( field && active >= 0 && byKeyboard ) {
						choose( active ); // Sans preventDefault : le focus continue vers le champ suivant.
					}
					close();
					break;
			}
		} );

		input.addEventListener( 'blur', function () {
			window.clearTimeout( timer );
			requestId++;
			close();
			// Un texte laissé dans la saisie devient une étiquette, sauf si la fenêtre ou l'onglet a seulement perdu le focus.
			if ( field && ( 'function' !== typeof document.hasFocus || document.hasFocus() ) ) {
				field.commit();
			}
		} );

		if ( field ) {
			// Un clic dans le panneau hors d'une option (attribution, barre de défilement) ne fait pas perdre le focus.
			panel.addEventListener( 'mousedown', function ( event ) {
				event.preventDefault();
			} );
		}

		window.addEventListener( 'resize', function () {
			if ( ! panel.hidden ) {
				position();
			}
		} );
		window.addEventListener( 'scroll', function () {
			if ( ! panel.hidden ) {
				position();
			}
		}, true );

		document.addEventListener( 'g2rdWpjmGeoAuthFailure', function () {
			if ( 'google' === source ) {
				disabled = true;
				close();
			}
		} );
	}

	function init() {
		if ( ! window.Promise ) {
			return;
		}
		if ( 'google' === source && ! loader ) {
			return;
		}
		if ( 'photon' === source && ( ! window.fetch || ! settings.photonUrl ) ) {
			return;
		}
		var input = findInput();
		if ( ! input ) {
			return;
		}
		if ( T ) {
			var field = T.create( input, { regions: settings.regions, max: settings.maxPlaces || 10, i18n: settings.tagsI18n || {} } );
			attach( field.input, field );
			return;
		}
		attach( input, null );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}( window, document ) );

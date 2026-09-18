/**
 * Autocomplétion mondiale des villes sur le champ de localisation natif de WP Job Manager.
 *
 * Utilise l'API « Place Autocomplete Data » (Places API New) : le champ existant
 * est conservé tel quel, aucune saisie n'est dupliquée, et la saisie libre reste
 * possible si Google est indisponible.
 */
( function ( window, document ) {
	'use strict';

	var settings = window.g2rdWpjmGeoAutocomplete || {};
	var loader = window.g2rdWpjmGeoLoader;
	var i18n = settings.i18n || {};
	var MIN_LENGTH = settings.minLength || 2;
	var DEBOUNCE = 250;
	// « (cities) » est la collection prévue par Places API (New). Le second jeu sert de repli si elle est refusée.
	var TYPE_SETS = [
		[ '(cities)' ],
		[ 'locality', 'postal_town', 'administrative_area_level_3' ]
	];
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
			if ( node && 'INPUT' === node.tagName && ! node.hasAttribute( 'data-g2rd-wpjm-geo-ac' ) ) {
				return node;
			}
		}
		return null;
	}

	function attach( input ) {
		var listId = 'g2rd-wpjm-geo-ac-list-' + ( ++uid );
		var places = null; // Bibliothèque Places, chargée au premier focus.
		var token = null;
		var disabled = false;
		var timer = null;
		var requestId = 0;
		var items = [];
		var active = -1;
		var typeSet = 0;
		var choosing = false;

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
		attribution.textContent = i18n.poweredBy || 'Powered by Google';

		panel.appendChild( list );
		panel.appendChild( attribution );
		document.body.appendChild( panel );

		function position() {
			var rect = input.getBoundingClientRect();
			panel.style.top = ( rect.bottom + window.pageYOffset ) + 'px';
			panel.style.left = ( rect.left + window.pageXOffset ) + 'px';
			panel.style.width = rect.width + 'px';
		}

		function close() {
			panel.hidden = true;
			items = [];
			active = -1;
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
			list.textContent = '';
			items = [];
			suggestions.forEach( function ( suggestion ) {
				var prediction = suggestion && suggestion.placePrediction;
				if ( ! prediction || ! prediction.text ) {
					return;
				}
				var label = String( prediction.text.text || prediction.text );
				if ( ! label ) {
					return;
				}
				var index = items.length;
				var option = document.createElement( 'li' );
				option.id = listId + '-option-' + index;
				option.className = 'g2rd-wpjm-geo-ac__option';
				option.setAttribute( 'role', 'option' );
				option.setAttribute( 'aria-selected', 'false' );

				var main = prediction.mainText && prediction.mainText.text;
				var secondary = prediction.secondaryText && prediction.secondaryText.text;
				if ( main ) {
					var strong = document.createElement( 'span' );
					strong.className = 'g2rd-wpjm-geo-ac__main';
					strong.textContent = main;
					option.appendChild( strong );
					if ( secondary ) {
						var rest = document.createElement( 'span' );
						rest.className = 'g2rd-wpjm-geo-ac__secondary';
						rest.textContent = ' ' + secondary;
						option.appendChild( rest );
					}
				} else {
					option.textContent = label;
				}

				// mousedown (et non click) : agit avant la perte de focus du champ.
				option.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
					choose( index );
				} );
				option.addEventListener( 'mouseenter', function () {
					setActive( index );
				} );

				items.push( { label: label } );
				list.appendChild( option );
			} );

			if ( items.length ) {
				position();
				panel.hidden = false;
				input.setAttribute( 'aria-expanded', 'true' );
				active = -1;
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

		function search() {
			var value = input.value.trim();
			if ( disabled || value.length < MIN_LENGTH ) {
				close();
				return;
			}
			var current = ++requestId;

			ensurePlaces().then( function ( library ) {
				return fetchSuggestions( library, value );
			} ).then( function ( result ) {
				// Ignore les réponses obsolètes (l'utilisateur a continué à taper).
				if ( current === requestId && document.activeElement === input ) {
					render( ( result && result.suggestions ) || [] );
				}
			} ).catch( function () {
				// Google indisponible ou clé refusée : le champ redevient un simple champ texte.
				disabled = true;
				close();
			} );
		}

		input.addEventListener( 'focus', function () {
			if ( ! disabled ) {
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
			timer = window.setTimeout( search, DEBOUNCE );
		} );

		input.addEventListener( 'keydown', function ( event ) {
			if ( panel.hidden ) {
				return;
			}
			switch ( event.key ) {
				case 'ArrowDown':
					event.preventDefault();
					setActive( ( active + 1 ) % items.length );
					break;
				case 'ArrowUp':
					event.preventDefault();
					setActive( ( active - 1 + items.length ) % items.length );
					break;
				case 'Enter':
					if ( active >= 0 ) {
						event.preventDefault(); // Ne soumet pas le formulaire pendant le choix d'une ville.
						choose( active );
					}
					break;
				case 'Escape':
					event.preventDefault();
					close();
					break;
				case 'Tab':
					close();
					break;
			}
		} );

		input.addEventListener( 'blur', function () {
			window.clearTimeout( timer );
			requestId++;
			close();
		} );

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
			disabled = true;
			close();
		} );
	}

	function init() {
		if ( ! loader || ! window.Promise ) {
			return;
		}
		var input = findInput();
		if ( input ) {
			attach( input );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}( window, document ) );

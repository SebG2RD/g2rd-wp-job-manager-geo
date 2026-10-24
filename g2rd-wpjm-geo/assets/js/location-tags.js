/**
 * Champ « Lieu » à étiquettes (mode OpenStreetMap) : plusieurs villes et régions
 * dans le champ natif de WP Job Manager, joints par « ; ». Le champ natif reste
 * celui qui est enregistré : il est masqué et mis à jour à chaque ajout ou retrait.
 * Fonctions de texte partagées avec l'autocomplétion : découpage (comme
 * PlaceParser::split() côté serveur) et recherche des régions (comme Regions::find()).
 */
( function ( window, document ) {
	'use strict';

	var SEPARATORS = /[;|\/\r\n]|\s+et\s+/i;
	var LIVE = /[;|\/\r\n]/;
	var EDGES = /^[\s,.:\-\u2013\u2014]+|[\s,.:\-\u2013\u2014]+$/g;
	var VAGUE = [ 'remote', 'full remote', 'teletravail', 'a distance', 'hybride' ];
	var MAX_PIECES = 30;
	var ICON_CITY = '\uD83D\uDCCD';
	var ICON_REGION = '\uD83D\uDDFA\uFE0F';

	// Forme de comparaison de PhotonGeocoder::normalize() : sans accents, minuscules, ponctuation en espace.
	function normalize( text ) {
		var value = String( text || '' );
		if ( value.normalize ) {
			value = value.normalize( 'NFD' ).replace( /[\u0300-\u036F]/g, '' );
		}
		value = value.toLowerCase().replace( /[^a-z0-9]+/g, ' ' ).trim();
		return value.replace( /\bst\b/g, 'saint' ).replace( /\bste\b/g, 'sainte' );
	}

	function key( text ) {
		return normalize( text ).replace( /^(?:le|la|les|l) /, '' );
	}

	// Même découpage que le serveur : « <br> » sépare comme un retour à la ligne, 30 morceaux au plus.
	function split( text ) {
		var seen = {};
		var out = [];
		String( text || '' ).replace( /<br\s*\/?>/gi, '\n' ).split( SEPARATORS ).forEach( function ( part ) {
			var piece = String( part || '' ).replace( EDGES, '' );
			var id = normalize( piece ) || piece.toLowerCase();
			if ( out.length >= MAX_PIECES || ! piece || VAGUE.indexOf( normalize( piece ) ) !== -1 || seen[ id ] ) {
				return;
			}
			seen[ id ] = true;
			out.push( piece );
		} );
		return out;
	}

	function regionIndex( regions ) {
		var entries = [];
		( Array.isArray( regions ) ? regions : [] ).forEach( function ( region ) {
			if ( ! region || 'string' !== typeof region.code || 'string' !== typeof region.name ) {
				return;
			}
			[ region.name ].concat( Array.isArray( region.aliases ) ? region.aliases : [] ).forEach( function ( name ) {
				if ( 'string' === typeof name && name ) {
					entries.push( { key: key( name ), name: name, region: region } );
				}
			} );
		} );
		return entries;
	}

	function findRegion( text, index ) {
		var wanted = key( text );
		var candidates = [ wanted, wanted.replace( /^region /, '' ) ];
		var found = null;
		if ( ! wanted ) {
			return null;
		}
		candidates.some( function ( candidate ) {
			index.some( function ( entry ) {
				if ( entry.key === candidate ) {
					found = entry.region;
					return true;
				}
				return false;
			} );
			return !! found;
		} );
		return found;
	}

	// Régions dont un nom commence par la saisie, puis dont un mot commence par elle (3 lettres au moins).
	// La saisie est comparée telle quelle (« Région Sud ») et sans le préfixe « Région » (« Région Bretagne »).
	function searchRegions( query, index, max ) {
		var full = key( query );
		var stripped = full.replace( /^region /, '' );
		var wanted = stripped && stripped !== full ? [ full, stripped ] : [ full ];
		var out = [];
		var seen = {};
		if ( ! full ) {
			return out;
		}
		[ 0, 1 ].forEach( function ( pass ) {
			index.forEach( function ( entry ) {
				if ( out.length >= max || seen[ entry.region.code ] ) {
					return;
				}
				var hit = wanted.some( function ( text ) {
					return 0 === pass ? 0 === entry.key.indexOf( text ) : text.length >= 3 && ( ' ' + entry.key ).indexOf( ' ' + text ) > 0;
				} );
				if ( hit ) {
					seen[ entry.region.code ] = true;
					out.push( { region: entry.region, matched: entry.name } );
				}
			} );
		} );
		return out;
	}

	function format( template, value ) {
		return String( template || '%s' ).replace( '%s', value );
	}

	/**
	 * Remplace à l'écran le champ natif par une zone à étiquettes.
	 *
	 * @return {{root: Element, input: HTMLInputElement, add: Function, commit: Function, values: Function}}
	 */
	function create( native, options ) {
		var opts = options || {};
		var i18n = opts.i18n || {};
		var max = opts.max || 10;
		var index = regionIndex( opts.regions );
		var required = !! native.required;
		var tags = [];

		var root = document.createElement( 'div' );
		root.className = 'g2rd-wpjm-geo-tags';
		var list = document.createElement( 'ul' );
		list.className = 'g2rd-wpjm-geo-tags__list';
		list.hidden = true;
		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'g2rd-wpjm-geo-tags__input';
		input.id = ( native.id || 'g2rd-wpjm-geo-location' ) + '-g2rd-tags';
		input.setAttribute( 'autocomplete', 'off' );
		if ( native.placeholder ) {
			input.placeholder = native.placeholder;
		}
		var notice = document.createElement( 'p' );
		notice.className = 'g2rd-wpjm-geo-tags__notice';
		notice.hidden = true;
		var live = document.createElement( 'p' );
		live.className = 'g2rd-wpjm-geo-tags__live';
		live.setAttribute( 'aria-live', 'polite' );
		root.appendChild( list );
		root.appendChild( input );
		root.appendChild( notice );
		root.appendChild( live );

		native.parentNode.insertBefore( root, native.nextSibling );
		native.setAttribute( 'data-g2rd-wpjm-geo-tags', '1' );
		// Attribut et style : un thème qui impose display sur les champs texte ne le réaffiche pas. La valeur part toujours avec le formulaire.
		native.hidden = true;
		native.style.display = 'none';
		native.required = false;
		var label = null;
		try {
			label = native.id ? document.querySelector( 'label[for="' + native.id + '"]' ) : null;
		} catch ( e ) {
			label = null;
		}
		if ( label ) {
			label.htmlFor = input.id;
		}

		function announce( message ) {
			live.textContent = message || '';
		}

		function validate() {
			if ( required ) {
				input.setCustomValidity( tags.length ? '' : ( i18n.required || ' ' ) );
			}
		}

		function sync() {
			native.value = tags.map( function ( tag ) {
				return tag.text;
			} ).join( ' ; ' );
			native.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			native.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			validate();
		}

		function removeAt( position ) {
			var tag = tags.splice( position, 1 )[ 0 ];
			if ( ! tag ) {
				return;
			}
			notice.hidden = true;
			render();
			sync();
			announce( format( i18n.removed, tag.short ) );
		}

		function render() {
			list.textContent = '';
			tags.forEach( function ( tag, position ) {
				var item = document.createElement( 'li' );
				item.className = 'g2rd-wpjm-geo-tags__tag' + ( tag.region ? ' g2rd-wpjm-geo-tags__tag--region' : '' );
				item.title = tag.text;
				var icon = document.createElement( 'span' );
				icon.className = 'g2rd-wpjm-geo-tags__icon';
				icon.setAttribute( 'aria-hidden', 'true' );
				icon.textContent = tag.region ? ICON_REGION : ICON_CITY;
				var text = document.createElement( 'span' );
				text.className = 'g2rd-wpjm-geo-tags__text';
				text.textContent = tag.short;
				var remove = document.createElement( 'button' );
				remove.type = 'button';
				remove.className = 'g2rd-wpjm-geo-tags__remove';
				remove.setAttribute( 'aria-label', format( i18n.remove, tag.short ) );
				remove.textContent = '\u2715';
				remove.addEventListener( 'click', function () {
					removeAt( position );
					input.focus();
				} );
				item.appendChild( icon );
				item.appendChild( text );
				item.appendChild( remove );
				list.appendChild( item );
			} );
			list.hidden = ! tags.length;
		}

		// Ajoute un lieu ; isRegion force la couleur des régions (proposition choisie).
		function add( text, isRegion, silent ) {
			var value = String( text || '' ).trim();
			if ( ! value ) {
				return false;
			}
			var id = normalize( value ) || value.toLowerCase();
			if ( tags.some( function ( tag ) {
				return tag.id === id;
			} ) ) {
				if ( ! silent ) {
					announce( format( i18n.duplicate, value ) );
				}
				return false;
			}
			if ( tags.length >= max ) {
				notice.textContent = i18n.limit || '';
				notice.hidden = false;
				announce( i18n.limit );
				return false;
			}
			var short = value.split( ',' )[ 0 ].trim() || value;
			tags.push( { text: value, short: short, id: id, region: !! ( isRegion || findRegion( value, index ) ) } );
			render();
			if ( ! silent ) {
				sync();
				announce( format( i18n.added, short ) );
			}
			return true;
		}

		// Texte en cours → étiquettes (Entrée, sortie du champ, séparateur tapé ou collé).
		function commit() {
			var rest = [];
			split( input.value ).forEach( function ( piece ) {
				if ( ! add( piece, false, false ) && tags.length >= max ) {
					rest.push( piece );
				}
			} );
			input.value = rest.join( ' ; ' );
		}

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Backspace' === event.key && '' === input.value && tags.length ) {
				removeAt( tags.length - 1 );
			}
		} );
		input.addEventListener( 'input', function () {
			if ( LIVE.test( input.value ) ) {
				commit();
			}
		} );

		split( native.value ).forEach( function ( piece ) {
			add( piece, false, true );
		} );
		validate();

		return {
			root: root,
			input: input,
			add: add,
			commit: commit,
			values: function () {
				return tags.map( function ( tag ) {
					return tag.text;
				} );
			}
		};
	}

	window.g2rdWpjmGeoTags = {
		create: create,
		split: split,
		normalize: normalize,
		regionIndex: regionIndex,
		findRegion: findRegion,
		searchRegions: searchRegions,
		icons: { city: ICON_CITY, region: ICON_REGION }
	};
}( window, document ) );

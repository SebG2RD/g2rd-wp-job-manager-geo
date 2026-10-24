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
	var MAX_LENGTH = 2000;
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

	// Même découpage que PlaceParser::split() côté serveur : 2 000 caractères lus, « <br> » sépare
	// comme un retour à la ligne, balises retirées, 30 morceaux au plus. Le texte reçu est déjà décodé
	// (valeur du DOM) : les entités ne sont pas traitées. keepVague garde les mentions sans lieu
	// (« Télétravail ») : le champ à étiquettes les conserve, seule la carte les ignore.
	function pieces( text, keepVague ) {
		var seen = Object.create( null );
		var out = [];
		String( text || '' ).slice( 0, MAX_LENGTH )
			.replace( /<br\s*\/?>/gi, '\n' )
			.replace( /<(script|style)[^>]*>[\s\S]*?<\/\1>/gi, '' )
			.replace( /<[^>]*>/g, '' )
			.split( SEPARATORS ).forEach( function ( part ) {
				var piece = String( part || '' ).replace( EDGES, '' );
				var id = normalize( piece ) || piece.toLowerCase();
				if ( out.length >= MAX_PIECES || ! piece || seen[ id ] || ( ! keepVague && VAGUE.indexOf( normalize( piece ) ) !== -1 ) ) {
					return;
				}
				seen[ id ] = true;
				out.push( piece );
			} );
		return out;
	}

	function split( text ) {
		return pieces( text, false );
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
		var candidates = [ wanted, key( wanted.replace( /^region /, '' ) ) ];
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
		var stripped = key( full.replace( /^region /, '' ) );
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
		return String( template || '%s' ).replace( '%s', function () {
			return value;
		} );
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
		var settled = ''; // Texte de la saisie au dernier état enregistré dans le champ natif.

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
		if ( required ) {
			input.setAttribute( 'aria-required', 'true' );
		}
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

		// Étiquettes, puis texte encore dans la saisie (lieux au-delà du plafond) : rien n'est perdu.
		function joined() {
			return tags.map( function ( tag ) {
				return tag.text;
			} ).concat( pieces( input.value, true ) ).join( ' ; ' );
		}

		function sync() {
			native.value = joined();
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

		// Identifiant (texte comparé) et code de région d'un lieu.
		function identify( value, region ) {
			var found = findRegion( value, index );
			return {
				id: normalize( value ) || value.toLowerCase(),
				code: ( 'string' === typeof region && region ) ? region : ( found ? found.code : '' ),
				region: !! ( region || found )
			};
		}

		// Déjà présent : même texte, ou, en saisie, même région sous une autre écriture.
		function known( who, sameRegion ) {
			return tags.some( function ( tag ) {
				return tag.id === who.id || ( sameRegion && '' !== who.code && tag.code === who.code );
			} );
		}

		// Ajoute un lieu. region : code de la région choisie dans les propositions (ou true) ;
		// à défaut, la région est reconnue d'après le texte.
		function add( text, region, silent ) {
			var value = String( text || '' ).trim();
			if ( ! value ) {
				return false;
			}
			var who = identify( value, region );
			// En saisie, deux écritures d'une même région (« IDF », « Île-de-France ») ne font qu'une étiquette,
			// comme côté serveur. À la réouverture, le texte enregistré est gardé tel quel (« Auvergne ; Rhône-Alpes »).
			if ( known( who, ! silent ) ) {
				if ( ! silent ) {
					announce( format( i18n.duplicate, value ) );
				}
				return false;
			}
			if ( tags.length >= max ) {
				if ( ! silent ) {
					notice.textContent = i18n.limit || '';
					notice.hidden = false;
					announce( i18n.limit );
				}
				return false;
			}
			var short = value.split( ',' )[ 0 ].trim() || value;
			tags.push( { text: value, short: short, id: who.id, code: who.code, region: who.region } );
			render();
			if ( ! silent ) {
				sync();
				announce( format( i18n.added, short ) );
			}
			return true;
		}

		// Texte en cours → étiquettes (Entrée, sortie du champ, séparateur tapé ou collé).
		function commit() {
			var value = input.value;
			var rest = [];
			if ( value === settled ) {
				return; // Saisie inchangée : le texte enregistré n'est pas réécrit.
			}
			input.value = '';
			pieces( value, true ).forEach( function ( piece ) {
				if ( ! add( piece, '', false ) && tags.length >= max && ! known( identify( piece, '' ), true ) ) {
					rest.push( piece ); // Refusé au plafond : reste dans la saisie (un doublon est abandonné).
				}
			} );
			input.value = rest.join( ' ; ' );
			settled = input.value;
			if ( joined() !== native.value ) {
				sync();
			}
		}

		input.addEventListener( 'keydown', function ( event ) {
			if ( 'Backspace' === event.key && ! event.repeat && '' === input.value && tags.length ) {
				removeAt( tags.length - 1 );
			}
		} );
		input.addEventListener( 'input', function () {
			if ( LIVE.test( input.value ) ) {
				commit();
			}
		} );

		// Réouverture : les lieux au-delà du plafond restent dans la saisie, sans message.
		var overflow = [];
		pieces( native.value, true ).forEach( function ( piece ) {
			if ( ! add( piece, '', true ) && tags.length >= max ) {
				overflow.push( piece );
			}
		} );
		input.value = overflow.join( ' ; ' );
		settled = input.value;
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

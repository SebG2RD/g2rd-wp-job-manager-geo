/**
 * Fonctions partagées par les moteurs de carte (Google et MapLibre) :
 * lecture paginée de l'endpoint REST, lieux multiples (un point par ville, une
 * entrée par région), regroupement des offres aux coordonnées identiques,
 * contenu des fenêtres (DOM + textContent, jamais de HTML) et démarrage
 * paresseux à l'approche de la zone visible.
 */
( function ( window, document ) {
	'use strict';

	var MAX_PAGES = 200;

	function parseJson( value ) {
		try {
			return JSON.parse( value || '{}' );
		} catch ( e ) {
			return {};
		}
	}

	function sprintf( template, number ) {
		return String( template || '%d' ).replace( '%d', String( number ) );
	}

	function safeUrl( url ) {
		return 'string' === typeof url && /^https?:\/\//i.test( url ) ? url : '';
	}

	function placeLabel( job ) {
		var parts = [];
		if ( job.city ) {
			parts.push( job.city );
		}
		if ( job.country && job.country !== job.city ) {
			parts.push( job.country );
		}
		return parts.length ? parts.join( ', ' ) : ( job.location || '' );
	}

	function addLine( parent, className, text ) {
		if ( ! text ) {
			return;
		}
		var node = document.createElement( 'div' );
		node.className = className;
		node.textContent = text;
		parent.appendChild( node );
	}

	function addText( parent, tagName, className, text ) {
		if ( ! text ) {
			return;
		}
		var node = document.createElement( tagName );
		node.className = className;
		node.textContent = text;
		parent.appendChild( node );
	}

	function buildJobNode( job, i18n, compact ) {
		var wrapper = document.createElement( compact ? 'li' : 'div' );
		wrapper.className = 'g2rd-wpjm-geo-iw__job';

		var url = safeUrl( job.permalink );
		var title = document.createElement( compact && url ? 'a' : 'div' );
		title.className = 'g2rd-wpjm-geo-iw__title';
		title.textContent = job.title || '';
		if ( compact && url ) {
			title.href = url;
		}
		wrapper.appendChild( title );

		addLine( wrapper, 'g2rd-wpjm-geo-iw__company', job.company );
		if ( ! compact ) {
			addLine( wrapper, 'g2rd-wpjm-geo-iw__place', placeLabel( job ) );
		}
		if ( Array.isArray( job._also ) && job._also.length ) {
			addLine( wrapper, 'g2rd-wpjm-geo-iw__also', ( i18n.alsoAt ? i18n.alsoAt + ' ' : '' ) + job._also.join( ', ' ) );
		}
		addLine( wrapper, 'g2rd-wpjm-geo-iw__type', job.job_type );

		if ( ! compact && url ) {
			var link = document.createElement( 'a' );
			link.className = 'g2rd-wpjm-geo-iw__link';
			link.href = url;
			link.textContent = i18n.view || '';
			wrapper.appendChild( link );
		}
		return wrapper;
	}

	function buildInfoContent( jobs, i18n ) {
		var root = document.createElement( 'div' );
		root.className = 'g2rd-wpjm-geo-iw';

		if ( 1 === jobs.length ) {
			root.appendChild( buildJobNode( jobs[ 0 ], i18n, false ) );
			return root;
		}

		addLine( root, 'g2rd-wpjm-geo-iw__heading', sprintf( i18n.manyJobs, jobs.length ) );
		addLine( root, 'g2rd-wpjm-geo-iw__place', placeLabel( jobs[ 0 ] ) );

		var list = document.createElement( 'ul' );
		list.className = 'g2rd-wpjm-geo-iw__list';
		jobs.forEach( function ( job ) {
			list.appendChild( buildJobNode( job, i18n, true ) );
		} );
		root.appendChild( list );
		return root;
	}

	function jobCount( count, i18n ) {
		return 1 === count ? String( i18n.oneJob || '1' ) : sprintf( i18n.manyJobsShort, count );
	}

	function regionTitle( region, i18n ) {
		var label = region.label || '';
		var template = 'department' === region.kind && i18n.departmentTitle ? i18n.departmentTitle : i18n.regionTitle;
		return String( template || '%s' ).replace( '%s', function () {
			return label;
		} ) + ' \u2014 ' + jobCount( region.jobs.length, i18n );
	}

	function buildRegionContent( region, i18n ) {
		var root = document.createElement( 'div' );
		root.className = 'g2rd-wpjm-geo-iw g2rd-wpjm-geo-iw--region';
		addLine( root, 'g2rd-wpjm-geo-iw__heading', regionTitle( region, i18n ) );
		var list = document.createElement( 'ul' );
		list.className = 'g2rd-wpjm-geo-iw__list';
		region.jobs.forEach( function ( job ) {
			list.appendChild( buildJobNode( job, i18n, true ) );
		} );
		root.appendChild( list );
		return root;
	}

	// Lieux d'une offre (champ places de l'API) ; sans liste, l'offre garde son point unique.
	function placesOf( job ) {
		var list = Array.isArray( job.places ) ? job.places.filter( function ( place ) {
			return place && ( 'city' === place.type || '' !== areaCode( place ) );
		} ) : [];
		return list.length ? list : [ { type: 'city', label: '', lat: job.latitude, lng: job.longitude } ];
	}

	// Code d'une zone (région ou département), chaîne vide pour une ville. Celui d'un département est préfixé « D » :
	// le département 28 (Eure-et-Loir) n'est pas la région 28 (Normandie).
	function areaCode( place ) {
		if ( 'region' === place.type && 'string' === typeof place.region && place.region ) {
			return place.region;
		}
		return 'department' === place.type && 'string' === typeof place.department && place.department ? 'D' + place.department : '';
	}

	// Boîte d'une région recopiée si valide (4 nombres, bornes, ouest < est, sud < nord), sinon null.
	function validBounds( box ) {
		if ( ! Array.isArray( box ) || 4 !== box.length ) {
			return null;
		}
		for ( var i = 0; i < 4; i++ ) {
			if ( 'number' !== typeof box[ i ] || ! isFinite( box[ i ] ) ) {
				return null;
			}
		}
		if ( box[ 0 ] < -180 || box[ 2 ] > 180 || box[ 0 ] >= box[ 2 ] || box[ 1 ] < -90 || box[ 3 ] > 90 || box[ 1 ] >= box[ 3 ] ) {
			return null;
		}
		return box.slice();
	}

	/**
	 * Une copie de l'offre par ville (coordonnées de la ville) et une entrée par
	 * zone (région ou département, kind « region » ou « department »). Chaque copie porte `_also` : les autres lieux de l'offre.
	 */
	function splitPlaces( jobs ) {
		var points = [];
		var regions = Object.create( null );
		var order = [];
		jobs.forEach( function ( job ) {
			// Un même lieu répété dans une offre n'est gardé qu'une fois.
			var seen = Object.create( null );
			var places = placesOf( job ).filter( function ( place ) {
				var key;
				if ( areaCode( place ) ) {
					key = 'r' + areaCode( place );
				} else {
					var cLat = parseFloat( place.lat );
					var cLng = parseFloat( place.lng );
					if ( ! isFinite( cLat ) || ! isFinite( cLng ) ) {
						return true; // Écarté plus tard par groupByCoordinates().
					}
					key = cLat.toFixed( 5 ) + ',' + cLng.toFixed( 5 );
				}
				if ( seen[ key ] ) {
					return false;
				}
				seen[ key ] = true;
				return true;
			} );
			places.forEach( function ( place, index ) {
				var copy = {};
				Object.keys( job ).forEach( function ( name ) {
					if ( 'places' !== name ) {
						copy[ name ] = job[ name ];
					}
				} );
				var also = [];
				places.forEach( function ( other, i ) {
					if ( i !== index && other.label && other.label !== place.label && -1 === also.indexOf( other.label ) ) {
						also.push( other.label );
					}
				} );
				copy._also = also;
				var area = areaCode( place );
				if ( area ) {
					var lat = parseFloat( place.lat );
					var lng = parseFloat( place.lng );
					if ( ! isFinite( lat ) || ! isFinite( lng ) || Math.abs( lat ) > 90 || Math.abs( lng ) > 180 ) {
						return;
					}
					if ( ! regions[ area ] ) {
						regions[ area ] = { code: area, kind: place.type, label: place.label || '', position: { lat: lat, lng: lng }, bounds: validBounds( place.bbox ), jobs: [] };
						order.push( area );
					}
					regions[ area ].jobs.push( copy );
					return;
				}
				copy.latitude = place.lat;
				copy.longitude = place.lng;
				// La copie d'indice 0 garde city/country de WP Job Manager : le serveur garantit que le
				// premier lieu correspond aux coordonnées de l'offre (sinon la liste est remplacée par
				// ce seul point) ; un premier lieu écarté par clean_place() peut toutefois décaler les
				// indices. Les autres copies prennent le libellé de leur lieu.
				if ( index > 0 ) {
					copy.city = place.label || '';
					copy.country = '';
					copy.location = '';
				} else if ( ! copy.city && place.label ) {
					copy.city = place.label;
				}
				points.push( copy );
			} );
		} );
		return {
			points: points,
			regions: order.map( function ( code ) {
				return regions[ code ];
			} )
		};
	}

	function groupByCoordinates( jobs ) {
		var groups = {};
		var order = [];
		jobs.forEach( function ( job ) {
			var lat = parseFloat( job.latitude );
			var lng = parseFloat( job.longitude );
			if ( ! isFinite( lat ) || ! isFinite( lng ) || Math.abs( lat ) > 90 || Math.abs( lng ) > 180 ) {
				return;
			}
			var key = lat.toFixed( 5 ) + ',' + lng.toFixed( 5 );
			if ( ! groups[ key ] ) {
				groups[ key ] = { position: { lat: lat, lng: lng }, jobs: [] };
				order.push( key );
			}
			groups[ key ].jobs.push( job );
		} );
		return order.map( function ( key ) {
			return groups[ key ];
		} );
	}

	function createFetcher( container ) {
		var restUrl = container.getAttribute( 'data-rest-url' ) || '';
		var perPage = parseInt( container.getAttribute( 'data-per-page' ), 10 ) || 250;
		var nonce = container.getAttribute( 'data-nonce' ) || '';

		function fetchPage( page, useNonce ) {
			var url = restUrl + ( restUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + 'page=' + page + '&per_page=' + perPage;
			var options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
			if ( useNonce && nonce ) {
				options.headers[ 'X-WP-Nonce' ] = nonce;
			}
			return window.fetch( url, options ).then( function ( response ) {
				// Nonce expiré (page servie par un cache) : nouvelle tentative en tant que visiteur anonyme.
				if ( 403 === response.status && useNonce && nonce ) {
					nonce = '';
					return fetchPage( page, false );
				}
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				var totalPages = parseInt( response.headers.get( 'X-WP-TotalPages' ), 10 ) || 1;
				return response.json().then( function ( jobs ) {
					return { jobs: Array.isArray( jobs ) ? jobs : [], totalPages: totalPages };
				} );
			} );
		}

		function fetchAll() {
			var all = [];
			function next( page ) {
				return fetchPage( page, true ).then( function ( result ) {
					all = all.concat( result.jobs );
					if ( page < result.totalPages && page < MAX_PAGES ) {
						return next( page + 1 );
					}
					return all;
				} );
			}
			return next( 1 );
		}

		return { fetchAll: fetchAll };
	}

	function observe( initMap ) {
		function init() {
			var containers = document.querySelectorAll( '.g2rd-wpjm-geo-map:not([data-g2rd-init])' );
			if ( ! containers.length || ! window.Promise || ! window.fetch ) {
				return;
			}
			var start = function ( container ) {
				if ( container.getAttribute( 'data-g2rd-init' ) ) {
					return;
				}
				container.setAttribute( 'data-g2rd-init', '1' );
				initMap( container );
			};
			if ( 'function' === typeof window.IntersectionObserver ) {
				var observer = new IntersectionObserver( function ( entries ) {
					entries.forEach( function ( entry ) {
						if ( entry.isIntersecting ) {
							observer.unobserve( entry.target );
							start( entry.target );
						}
					} );
				}, { rootMargin: '300px' } );
				Array.prototype.forEach.call( containers, function ( container ) {
					observer.observe( container );
				} );
			} else {
				Array.prototype.forEach.call( containers, start );
			}
		}

		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', init );
		} else {
			init();
		}
	}

	/**
	 * Messages de la carte (chargement, vide, erreur), communs aux deux moteurs.
	 * L'état vide propose le lien « Re-géocoder » quand la page le fournit (administrateurs).
	 */
	function statusController( container, i18n ) {
		var status = container.querySelector( '.g2rd-wpjm-geo-map__status' );
		function set( message, linkUrl ) {
			if ( ! status ) {
				return;
			}
			status.textContent = message || '';
			var url = message ? safeUrl( linkUrl ) : '';
			if ( url && i18n.regeocode ) {
				var link = document.createElement( 'a' );
				link.href = url;
				link.textContent = i18n.regeocode;
				status.appendChild( document.createTextNode( ' ' ) );
				status.appendChild( link );
			}
			status.hidden = ! message;
		}
		return {
			set: set,
			fail: function () {
				container.classList.add( 'has-error' );
				set( i18n.error );
			},
			empty: function () {
				container.classList.add( 'is-empty' );
				set( i18n.empty, container.getAttribute( 'data-admin-url' ) );
			}
		};
	}

	// Ligne d'une offre dans la liste sous la carte : titre, « entreprise · contrat », autres lieux.
	function placesJob( job, i18n ) {
		var item = document.createElement( 'li' );
		item.className = 'g2rd-wpjm-geo-places__job';
		var url = safeUrl( job.permalink );
		var title = document.createElement( url ? 'a' : 'span' );
		title.className = 'g2rd-wpjm-geo-places__title';
		title.textContent = job.title || '';
		if ( url ) {
			title.href = url;
		}
		item.appendChild( title );
		var meta = [ job.company, job.job_type ].filter( function ( part ) {
			return 'string' === typeof part && '' !== part.trim();
		} ).join( ' \u00b7 ' );
		addText( item, 'span', 'g2rd-wpjm-geo-places__meta', meta );
		if ( Array.isArray( job._also ) && job._also.length ) {
			addText( item, 'span', 'g2rd-wpjm-geo-places__also', ( i18n.alsoAt ? i18n.alsoAt + ' ' : '' ) + job._also.join( ', ' ) );
		}
		return item;
	}

	function placesSection( heading, jobs, i18n ) {
		var section = document.createElement( 'section' );
		section.className = 'g2rd-wpjm-geo-places__group';
		addText( section, 'h3', 'g2rd-wpjm-geo-places__heading', heading );
		var list = document.createElement( 'ul' );
		list.className = 'g2rd-wpjm-geo-places__jobs';
		jobs.forEach( function ( job ) {
			list.appendChild( placesJob( job, i18n ) );
		} );
		section.appendChild( list );
		return section;
	}

	function byLabel( a, b ) {
		return a.label.localeCompare( b.label, 'fr', { sensitivity: 'base' } );
	}

	/**
	 * Liste repliable des offres de la carte, juste après elle et sur toute la largeur
	 * du parent : accès au clavier et aux lecteurs d'écran quand les points sont dessinés
	 * dans un canevas (MapLibre). Une section par lieu (ordre alphabétique), puis une par région.
	 *
	 * @param {Element} container Conteneur de la carte.
	 * @param {Array}   groups    Groupes de groupByCoordinates().
	 * @param {Object}  i18n      Textes.
	 * @param {Array}   regions   Régions de splitPlaces() (facultatif).
	 * @return {Element} Élément details inséré.
	 */
	function placesList( container, groups, i18n, regions ) {
		var previous = container.nextElementSibling;
		if ( previous && previous.classList.contains( 'g2rd-wpjm-geo-map__places' ) ) {
			previous.parentNode.removeChild( previous );
		}
		// Une offre à plusieurs lieux apparaît dans plusieurs sections mais ne compte qu'une fois.
		var seen = Object.create( null );
		var count = 0;
		function remember( job ) {
			var key = undefined === job.id || null === job.id ? null : String( job.id );
			if ( null === key || ! seen[ key ] ) {
				if ( null !== key ) {
					seen[ key ] = true;
				}
				count++;
			}
		}
		// Les groupes de meme libelle (Paris, Paris 15e...) forment une seule section.
		var merged = Object.create( null );
		var mergedList = [];
		groups.forEach( function ( group ) {
			group.jobs.forEach( remember );
			var label = placeLabel( group.jobs[ 0 ] );
			var key = label.toLocaleLowerCase( 'fr' );
			if ( ! merged[ key ] ) {
				merged[ key ] = { label: label, jobs: [] };
				mergedList.push( merged[ key ] );
			}
			merged[ key ].jobs = merged[ key ].jobs.concat( group.jobs );
		} );
		var cities = mergedList.map( function ( entry ) {
			return { label: entry.label, heading: entry.label + ' \u2014 ' + jobCount( entry.jobs.length, i18n ), jobs: entry.jobs };
		} ).sort( byLabel );
		var areas = ( regions || [] ).map( function ( region ) {
			region.jobs.forEach( remember );
			return { label: region.label || '', heading: regionTitle( region, i18n ), jobs: region.jobs };
		} ).sort( byLabel );

		var details = document.createElement( 'details' );
		details.className = 'g2rd-wpjm-geo-map__places';
		var summary = document.createElement( 'summary' );
		summary.textContent = ( i18n.places || '' ) + ' (' + jobCount( count, i18n ) + ')';
		details.appendChild( summary );
		var body = document.createElement( 'div' );
		body.className = 'g2rd-wpjm-geo-places';
		cities.concat( areas ).forEach( function ( entry ) {
			body.appendChild( placesSection( entry.heading, entry.jobs, i18n ) );
		} );
		details.appendChild( body );
		container.parentNode.insertBefore( details, container.nextSibling );
		return details;
	}

	window.g2rdWpjmGeoMapCommon = {
		parseJson: parseJson,
		sprintf: sprintf,
		safeUrl: safeUrl,
		placeLabel: placeLabel,
		buildInfoContent: buildInfoContent,
		buildRegionContent: buildRegionContent,
		regionTitle: regionTitle,
		jobCount: jobCount,
		groupByCoordinates: groupByCoordinates,
		splitPlaces: splitPlaces,
		createFetcher: createFetcher,
		observe: observe,
		status: statusController,
		places: placesList
	};
}( window, document ) );

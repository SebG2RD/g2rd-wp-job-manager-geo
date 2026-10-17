/**
 * Fonctions partagées par les moteurs de carte (Google et MapLibre) :
 * lecture paginée de l'endpoint REST, regroupement des offres aux coordonnées
 * identiques, contenu des fenêtres (DOM + textContent, jamais de HTML) et
 * démarrage paresseux à l'approche de la zone visible.
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

	/**
	 * Liste repliable des offres de la carte, juste après elle : accès au clavier et aux
	 * lecteurs d'écran quand les points sont dessinés dans un canevas (MapLibre).
	 */
	function placesList( container, groups, i18n ) {
		var previous = container.nextElementSibling;
		if ( previous && previous.classList.contains( 'g2rd-wpjm-geo-map__places' ) ) {
			previous.parentNode.removeChild( previous );
		}
		var details = document.createElement( 'details' );
		details.className = 'g2rd-wpjm-geo-map__places';
		var summary = document.createElement( 'summary' );
		summary.textContent = i18n.places || '';
		details.appendChild( summary );
		groups.forEach( function ( group ) {
			details.appendChild( buildInfoContent( group.jobs, i18n ) );
		} );
		container.parentNode.insertBefore( details, container.nextSibling );
		return details;
	}

	window.g2rdWpjmGeoMapCommon = {
		parseJson: parseJson,
		sprintf: sprintf,
		safeUrl: safeUrl,
		placeLabel: placeLabel,
		buildInfoContent: buildInfoContent,
		groupByCoordinates: groupByCoordinates,
		createFetcher: createFetcher,
		observe: observe,
		status: statusController,
		places: placesList
	};
}( window, document ) );

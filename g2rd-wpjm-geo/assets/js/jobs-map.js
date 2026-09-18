/**
 * Carte mondiale des offres WP Job Manager.
 *
 * - Chargée uniquement quand la carte approche de la zone visible.
 * - Données paginées depuis /wp-json/g2rd-wpjm-geo/v1/jobs.
 * - Un marqueur par coordonnées exactes (liste des offres dans la fenêtre), clustering au-dessus.
 * - Tout le contenu est inséré via textContent : aucune donnée n'est interprétée comme du HTML.
 */
( function ( window, document ) {
	'use strict';

	var loader = window.g2rdWpjmGeoLoader;
	var COLOR = '#c0392b';
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

	function circleSvg( count, size ) {
		var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 ' + size + ' ' + size + '">' +
			'<circle cx="' + ( size / 2 ) + '" cy="' + ( size / 2 ) + '" r="' + ( size / 2 - 2 ) + '" fill="' + COLOR + '" fill-opacity="0.9" stroke="#fff" stroke-width="2"/>' +
			'</svg>';
		return 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent( svg );
	}

	function clusterSize( count ) {
		return count < 10 ? 36 : ( count < 100 ? 44 : 52 );
	}

	function initMap( container ) {
		var i18n = parseJson( container.getAttribute( 'data-i18n' ) );
		var canvas = container.querySelector( '.g2rd-wpjm-geo-map__canvas' );
		var status = container.querySelector( '.g2rd-wpjm-geo-map__status' );
		var restUrl = container.getAttribute( 'data-rest-url' ) || '';
		var perPage = parseInt( container.getAttribute( 'data-per-page' ), 10 ) || 250;
		var mapId = container.getAttribute( 'data-map-id' ) || '';
		var nonce = container.getAttribute( 'data-nonce' ) || '';
		var clustering = '1' === container.getAttribute( 'data-clustering' ) && !! window.markerClusterer;

		function setStatus( message ) {
			if ( status ) {
				status.textContent = message || '';
				status.hidden = ! message;
			}
		}

		function fail() {
			container.classList.add( 'has-error' );
			setStatus( i18n.error );
		}

		if ( ! canvas || ! restUrl || ! loader ) {
			fail();
			return;
		}

		document.addEventListener( 'g2rdWpjmGeoAuthFailure', fail );

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

		Promise.all( [
			loader.load().then( function ( maps ) {
				return Promise.all( [ maps.importLibrary( 'maps' ), maps.importLibrary( 'core' ), maps.importLibrary( 'marker' ) ] );
			} ),
			fetchAll()
		] ).then( function ( results ) {
			var mapsLib = results[ 0 ][ 0 ];
			var coreLib = results[ 0 ][ 1 ];
			var markerLib = results[ 0 ][ 2 ];
			// Les marqueurs avancés (recommandés par Google) exigent un Map ID ; sinon, marqueurs classiques.
			var useAdvanced = !! ( mapId && markerLib.AdvancedMarkerElement );
			var groups = groupByCoordinates( results[ 1 ] );

			var mapOptions = {
				center: { lat: 20, lng: 0 },
				zoom: 2,
				minZoom: 2,
				maxZoom: 18,
				streetViewControl: false,
				mapTypeControl: false,
				fullscreenControl: true,
				gestureHandling: 'cooperative' // Sur mobile : défilement de la page à un doigt, carte à deux doigts.
			};
			if ( mapId ) {
				mapOptions.mapId = mapId;
			}

			var map = new mapsLib.Map( canvas, mapOptions );
			var infoWindow = new mapsLib.InfoWindow( { maxWidth: 320 } );
			var bounds = new coreLib.LatLngBounds();

			function createMarker( position, count, title, zIndex ) {
				var marker;
				if ( useAdvanced ) {
					var options = { position: position, title: title, zIndex: zIndex, gmpClickable: true };
					if ( count > 1 ) {
						var bubble = document.createElement( 'div' );
						bubble.className = 'g2rd-wpjm-geo-marker';
						bubble.textContent = String( count );
						options.content = bubble;
					}
					marker = new markerLib.AdvancedMarkerElement( options );
				} else {
					var legacy = { position: position, title: title, zIndex: zIndex };
					if ( count > 1 ) {
						var size = clusterSize( count ) - 8;
						legacy.icon = {
							url: circleSvg( count, size ),
							scaledSize: new coreLib.Size( size, size ),
							anchor: new coreLib.Point( size / 2, size / 2 )
						};
						legacy.label = { text: String( count ), color: '#fff', fontSize: '12px', fontWeight: '700' };
					}
					marker = new markerLib.Marker( legacy );
				}
				return marker;
			}

			var markers = groups.map( function ( group ) {
				var count = group.jobs.length;
				var title = 1 === count ? ( group.jobs[ 0 ].title || '' ) : sprintf( i18n.manyJobs, count );
				var marker = createMarker( group.position, count, title, count );
				marker.g2rdCount = count;
				marker.addListener( 'click', function () {
					infoWindow.setContent( buildInfoContent( group.jobs, i18n ) );
					infoWindow.open( { map: map, anchor: marker } );
				} );
				bounds.extend( group.position );
				return marker;
			} );

			if ( clustering && markers.length > 1 ) {
				new window.markerClusterer.MarkerClusterer( {
					map: map,
					markers: markers,
					renderer: {
						render: function ( cluster ) {
							// Le nombre affiché est celui des offres, pas celui des lieux.
							var total = cluster.markers.reduce( function ( sum, item ) {
								return sum + ( item.g2rdCount || 1 );
							}, 0 );
							return createMarker( cluster.position, Math.max( total, 2 ), sprintf( i18n.cluster, total ), 1000 + total );
						}
					}
				} );
			} else {
				markers.forEach( function ( marker ) {
					if ( useAdvanced ) {
						marker.map = map;
					} else {
						marker.setMap( map );
					}
				} );
			}

			if ( ! markers.length ) {
				setStatus( i18n.empty );
				container.classList.add( 'is-empty' );
			} else {
				setStatus( '' );
				if ( 1 === markers.length ) {
					map.setCenter( groups[ 0 ].position );
					map.setZoom( 10 );
				} else {
					map.fitBounds( bounds, 48 );
				}
			}
			container.classList.add( 'is-ready' );
		} ).catch( fail );
	}

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
}( window, document ) );

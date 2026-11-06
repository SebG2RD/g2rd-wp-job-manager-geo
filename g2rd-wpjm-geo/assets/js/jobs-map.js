/**
 * Carte mondiale des offres WP Job Manager — moteur Google Maps.
 *
 * - Chargée uniquement quand la carte approche de la zone visible (map-common.js).
 * - Un marqueur par coordonnées exactes (liste des offres dans la fenêtre), clustering au-dessus.
 * - Tout le contenu est inséré via textContent : aucune donnée n'est interprétée comme du HTML.
 */
( function ( window, document ) {
	'use strict';

	var C = window.g2rdWpjmGeoMapCommon;
	var loader = window.g2rdWpjmGeoLoader;
	var COLOR = '#c0392b';
	// Supercluster mesure le rayon sur une grille de 512 pour des tuiles de 256 : le double de MapLibre (40 ici = 20 la-bas).
	// Le zoom Google vaut le zoom MapLibre + 1 (14 devient 15).
	var CLUSTER_RADIUS = 40;
	var CLUSTER_MAX_ZOOM = 15;

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
		var i18n = C.parseJson( container.getAttribute( 'data-i18n' ) );
		var canvas = container.querySelector( '.g2rd-wpjm-geo-map__canvas' );
		var mapId = container.getAttribute( 'data-map-id' ) || '';
		var clustering = '1' === container.getAttribute( 'data-clustering' ) && !! window.markerClusterer;
		var ui = C.status( container, i18n );
		var fail = ui.fail;

		if ( ! canvas || ! container.getAttribute( 'data-rest-url' ) || ! loader ) {
			fail();
			return;
		}

		document.addEventListener( 'g2rdWpjmGeoAuthFailure', fail );

		Promise.all( [
			loader.load().then( function ( maps ) {
				return Promise.all( [ maps.importLibrary( 'maps' ), maps.importLibrary( 'core' ), maps.importLibrary( 'marker' ) ] );
			} ),
			C.createFetcher( container ).fetchAll()
		] ).then( function ( results ) {
			var mapsLib = results[ 0 ][ 0 ];
			var coreLib = results[ 0 ][ 1 ];
			var markerLib = results[ 0 ][ 2 ];
			// Les marqueurs avancés (recommandés par Google) exigent un Map ID ; sinon, marqueurs classiques.
			var useAdvanced = !! ( mapId && markerLib.AdvancedMarkerElement );
			var groups = C.groupByCoordinates( results[ 1 ] );

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
				var title = 1 === count ? ( group.jobs[ 0 ].title || '' ) : C.sprintf( i18n.manyJobs, count );
				var marker = createMarker( group.position, count, title, count );
				marker.g2rdCount = count;
				marker.addListener( 'click', function () {
					infoWindow.setContent( C.buildInfoContent( group.jobs, i18n ) );
					infoWindow.open( { map: map, anchor: marker } );
				} );
				bounds.extend( group.position );
				return marker;
			} );

			if ( clustering && markers.length > 1 ) {
				var clusterOptions = {
					map: map,
					markers: markers,
					renderer: {
						render: function ( cluster ) {
							// Le nombre affiché est celui des offres, pas celui des lieux.
							var total = cluster.markers.reduce( function ( sum, item ) {
								return sum + ( item.g2rdCount || 1 );
							}, 0 );
							return createMarker( cluster.position, Math.max( total, 2 ), C.sprintf( i18n.cluster, total ), 1000 + total );
						}
					}
				};
				if ( 'function' === typeof window.markerClusterer.SuperClusterAlgorithm ) {
					clusterOptions.algorithm = new window.markerClusterer.SuperClusterAlgorithm( { radius: CLUSTER_RADIUS, maxZoom: CLUSTER_MAX_ZOOM } );
				}
				new window.markerClusterer.MarkerClusterer( clusterOptions );
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
				ui.empty();
			} else {
				ui.set( '' );
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

	if ( C ) {
		C.observe( initMap );
	}
}( window, document ) );

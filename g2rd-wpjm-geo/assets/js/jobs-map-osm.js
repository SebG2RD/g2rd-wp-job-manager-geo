/**
 * Carte des offres en mode libre : MapLibre GL JS + fond OpenFreeMap.
 *
 * - MapLibre (embarqué dans le plugin) n'est chargé qu'à l'approche de la zone visible.
 * - Offres aux coordonnées identiques réunies en un point ; clustering natif dont
 *   les cercles comptent les offres (clusterProperties) et non les lieux.
 * - Fenêtres construites en DOM (textContent) par map-common.js.
 */
( function ( window, document ) {
	'use strict';

	var C = window.g2rdWpjmGeoMapCommon;
	var cfg = window.g2rdWpjmGeoOsm || {};
	var COLOR = '#c0392b';
	var SOURCE = 'g2rd-jobs';
	var maplibrePromise = null;

	function loadMaplibre() {
		if ( window.maplibregl ) {
			return Promise.resolve( window.maplibregl );
		}
		if ( maplibrePromise ) {
			return maplibrePromise;
		}
		maplibrePromise = new Promise( function ( resolve, reject ) {
			if ( cfg.maplibreCss && ! document.querySelector( 'link[data-g2rd-maplibre]' ) ) {
				var link = document.createElement( 'link' );
				link.rel = 'stylesheet';
				link.href = cfg.maplibreCss;
				link.setAttribute( 'data-g2rd-maplibre', '1' );
				document.head.appendChild( link );
			}
			var script = document.createElement( 'script' );
			script.src = cfg.maplibreJs;
			script.async = true;
			script.onload = function () {
				if ( window.maplibregl ) {
					resolve( window.maplibregl );
				} else {
					reject( new Error( 'maplibre-missing' ) );
				}
			};
			script.onerror = function () {
				reject( new Error( 'maplibre-network' ) );
			};
			document.head.appendChild( script );
		} );
		// Un échec n'est pas mémorisé : une nouvelle tentative reste possible.
		maplibrePromise.catch( function () {
			maplibrePromise = null;
		} );
		return maplibrePromise;
	}

	function supportsWebGL2() {
		try {
			return !! ( window.WebGL2RenderingContext && document.createElement( 'canvas' ).getContext( 'webgl2' ) );
		} catch ( e ) {
			return false;
		}
	}

	function symbolLayers( map ) {
		var style = map.getStyle();
		return ( ( style && style.layers ) || [] ).filter( function ( layer ) {
			return 'symbol' === layer.type && layer.layout;
		} );
	}

	function textFont( map ) {
		var layers = symbolLayers( map );
		for ( var i = 0; i < layers.length; i++ ) {
			var font = layers[ i ].layout[ 'text-font' ];
			if ( Array.isArray( font ) && font.length && 'string' === typeof font[ 0 ] ) {
				return font;
			}
		}
		return [ 'Noto Sans Regular' ];
	}

	function localizeLabels( map, lang ) {
		if ( ! /^[a-z]{2}$/.test( lang || '' ) ) {
			return;
		}
		symbolLayers( map ).forEach( function ( layer ) {
			var field = layer.layout[ 'text-field' ];
			// Seules les couches de noms : les numéros de route ou de maison gardent leur champ.
			if ( ! field || JSON.stringify( field ).indexOf( 'name' ) === -1 ) {
				return;
			}
			try {
				map.setLayoutProperty( layer.id, 'text-field', [ 'coalesce', [ 'get', 'name:' + lang ], [ 'get', 'name' ] ] );
			} catch ( e ) {
				// Couche au format inattendu : libellé d'origine conservé.
			}
		} );
	}

	function initMap( container ) {
		var i18n = C.parseJson( container.getAttribute( 'data-i18n' ) );
		var canvas = container.querySelector( '.g2rd-wpjm-geo-map__canvas' );
		var clustering = '1' === container.getAttribute( 'data-clustering' );
		var ui = C.status( container, i18n );
		var fail = ui.fail;

		if ( ! canvas || ! cfg.styleUrl || ! cfg.maplibreJs || ! container.getAttribute( 'data-rest-url' ) || ! supportsWebGL2() ) {
			fail();
			return;
		}

		Promise.all( [ loadMaplibre(), C.createFetcher( container ).fetchAll() ] ).then( function ( results ) {
			var ml = results[ 0 ];
			var groups = C.groupByCoordinates( results[ 1 ] );
			var jobsById = {};
			var features = groups.map( function ( group, index ) {
				jobsById[ index ] = group.jobs;
				return {
					type: 'Feature',
					properties: { gid: index, count: group.jobs.length },
					geometry: { type: 'Point', coordinates: [ group.position.lng, group.position.lat ] }
				};
			} );

			var loaded = false;
			var map = new ml.Map( {
				container: canvas,
				style: cfg.styleUrl,
				center: [ 0, 20 ],
				zoom: 1,
				cooperativeGestures: true,
				attributionControl: { compact: window.innerWidth < 782 }
			} );

			map.on( 'error', function () {
				// Avant le premier rendu (style ou tuiles injoignables) : message d'erreur. Après, une tuile manquante n'est pas bloquante.
				if ( ! loaded ) {
					fail();
				}
			} );

			map.on( 'load', function () {
				loaded = true;
				localizeLabels( map, cfg.language );
				var font = textFont( map );

				map.addSource( SOURCE, {
					type: 'geojson',
					data: { type: 'FeatureCollection', features: features },
					cluster: clustering,
					clusterMaxZoom: 14,
					clusterRadius: 50,
					clusterProperties: { jobs: [ '+', [ 'get', 'count' ] ] }
				} );
				map.addLayer( {
					id: 'g2rd-clusters',
					type: 'circle',
					source: SOURCE,
					filter: [ 'has', 'point_count' ],
					paint: {
						'circle-color': COLOR,
						'circle-opacity': 0.9,
						'circle-stroke-color': '#fff',
						'circle-stroke-width': 2,
						'circle-radius': [ 'step', [ 'get', 'jobs' ], 18, 10, 22, 100, 26 ]
					}
				} );
				map.addLayer( {
					id: 'g2rd-cluster-count',
					type: 'symbol',
					source: SOURCE,
					filter: [ 'has', 'point_count' ],
					layout: { 'text-field': [ 'to-string', [ 'get', 'jobs' ] ], 'text-font': font, 'text-size': 12, 'text-allow-overlap': true },
					paint: { 'text-color': '#fff' }
				} );
				map.addLayer( {
					id: 'g2rd-points',
					type: 'circle',
					source: SOURCE,
					filter: [ '!', [ 'has', 'point_count' ] ],
					paint: {
						'circle-color': COLOR,
						'circle-stroke-color': '#fff',
						'circle-stroke-width': 2,
						'circle-radius': [ 'case', [ '>', [ 'get', 'count' ], 1 ], 14, 8 ]
					}
				} );
				map.addLayer( {
					id: 'g2rd-point-count',
					type: 'symbol',
					source: SOURCE,
					filter: [ 'all', [ '!', [ 'has', 'point_count' ] ], [ '>', [ 'get', 'count' ], 1 ] ],
					layout: { 'text-field': [ 'to-string', [ 'get', 'count' ] ], 'text-font': font, 'text-size': 12, 'text-allow-overlap': true },
					paint: { 'text-color': '#fff' }
				} );

				map.on( 'click', 'g2rd-clusters', function ( event ) {
					var feature = event.features && event.features[ 0 ];
					if ( ! feature ) {
						return;
					}
					var center = feature.geometry.coordinates;
					Promise.resolve( map.getSource( SOURCE ).getClusterExpansionZoom( feature.properties.cluster_id ) ).then( function ( zoom ) {
						map.easeTo( { center: center, zoom: zoom } );
					} ).catch( function () {} );
				} );
				map.on( 'click', 'g2rd-points', function ( event ) {
					var feature = event.features && event.features[ 0 ];
					var jobs = feature && jobsById[ feature.properties.gid ];
					if ( ! jobs ) {
						return;
					}
					new ml.Popup( { maxWidth: '320px' } )
						.setLngLat( feature.geometry.coordinates )
						.setDOMContent( C.buildInfoContent( jobs, i18n ) )
						.addTo( map );
				} );
				[ 'g2rd-clusters', 'g2rd-points' ].forEach( function ( id ) {
					map.on( 'mouseenter', id, function () {
						map.getCanvas().style.cursor = 'pointer';
					} );
					map.on( 'mouseleave', id, function () {
						map.getCanvas().style.cursor = '';
					} );
				} );

				if ( ! features.length ) {
					ui.empty();
				} else {
					ui.set( '' );
					if ( 1 === features.length ) {
						map.jumpTo( { center: features[ 0 ].geometry.coordinates, zoom: 10 } );
					} else {
						var bounds = new ml.LngLatBounds();
						features.forEach( function ( feature ) {
							bounds.extend( feature.geometry.coordinates );
						} );
						map.fitBounds( bounds, { padding: 48, maxZoom: 12, duration: 0 } );
					}
				}
				container.classList.add( 'is-ready' );
			} );
		} ).catch( fail );
	}

	if ( C ) {
		C.observe( initMap );
	}
}( window, document ) );

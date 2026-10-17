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

	// MapLibre 5 utilise WebGL 2 et se replie sur WebGL 1. Le contexte de test est libéré aussitôt.
	function supportsWebGL() {
		try {
			var canvas = document.createElement( 'canvas' );
			var gl = canvas.getContext( 'webgl2' ) || canvas.getContext( 'webgl' );
			var lose = ( gl && 'function' === typeof gl.getExtension ) ? gl.getExtension( 'WEBGL_lose_context' ) : null;
			if ( lose ) {
				lose.loseContext();
			}
			return !! gl;
		} catch ( e ) {
			return false;
		}
	}

	// Textes de l'interface MapLibre (gestes, boutons) : une valeur absente ou vide ferait lever « Missing UI string ».
	function uiLocale() {
		var given = cfg.locale || {};
		var locale = {};
		Object.keys( given ).forEach( function ( key ) {
			if ( 'string' === typeof given[ key ] && given[ key ] ) {
				locale[ key ] = given[ key ];
			}
		} );
		return locale;
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

	// Crédit d'une source du style (HTML fourni par l'hôte du style ou du TileJSON) recopié en DOM :
	// seuls le texte et les liens http(s) sont repris. Le document de DOMParser est inerte
	// (aucun script exécuté, aucune image chargée) ; rien de son balisage n'est inséré tel quel.
	function appendCredit( html, target ) {
		var doc = new window.DOMParser().parseFromString( '<body>' + String( html ) + '</body>', 'text/html' );
		( function walk( parent ) {
			Array.prototype.forEach.call( parent.childNodes, function ( node ) {
				if ( 3 === node.nodeType ) {
					target.appendChild( document.createTextNode( node.textContent ) );
					return;
				}
				if ( 1 !== node.nodeType ) {
					return;
				}
				var tag = node.tagName.toLowerCase();
				if ( 'script' === tag || 'style' === tag || 'template' === tag ) {
					return;
				}
				var url = 'a' === tag ? C.safeUrl( node.getAttribute( 'href' ) ) : '';
				if ( url ) {
					var link = document.createElement( 'a' );
					link.href = url;
					link.target = '_blank';
					link.rel = 'noopener noreferrer';
					link.textContent = node.textContent;
					target.appendChild( link );
					return;
				}
				walk( node );
			} );
		} )( doc.body );
	}

	// Remplace le contrôle d'attribution de MapLibre, qui insère ces crédits par innerHTML derrière
	// un simple filtre en liste noire. Mêmes classes CSS et mêmes déclencheurs que l'original.
	function AttributionControl( compact ) {
		this.compact = compact;
		this.key = null;
	}
	AttributionControl.prototype.onAdd = function ( map ) {
		var self = this;
		this.map = map;
		this.container = document.createElement( 'div' );
		this.container.className = 'maplibregl-ctrl maplibregl-ctrl-attrib' + ( this.compact ? ' maplibregl-compact' : '' );
		if ( this.compact ) {
			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'maplibregl-ctrl-attrib-button';
			button.setAttribute( 'aria-label', ( cfg.locale && cfg.locale[ 'AttributionControl.ToggleAttribution' ] ) || 'Toggle attribution' );
			button.addEventListener( 'click', function () {
				self.container.classList.toggle( 'maplibregl-compact-show' );
			} );
			this.container.appendChild( button );
		}
		this.inner = document.createElement( 'div' );
		this.inner.className = 'maplibregl-ctrl-attrib-inner';
		this.container.appendChild( this.inner );
		this.onData = function ( e ) {
			if ( e && ( 'metadata' === e.sourceDataType || 'visibility' === e.sourceDataType || 'style' === e.dataType || 'terrain' === e.type ) ) {
				self.render();
			}
		};
		this.onLoad = function () {
			self.render();
		};
		map.on( 'load', this.onLoad );
		map.on( 'styledata', this.onData );
		map.on( 'sourcedata', this.onData );
		this.render();
		return this.container;
	};
	AttributionControl.prototype.onRemove = function () {
		if ( this.map && 'function' === typeof this.map.off ) {
			this.map.off( 'load', this.onLoad );
			this.map.off( 'styledata', this.onData );
			this.map.off( 'sourcedata', this.onData );
		}
		if ( this.container && this.container.parentNode ) {
			this.container.parentNode.removeChild( this.container );
		}
		this.map = null;
	};
	AttributionControl.prototype.render = function () {
		var map = this.map;
		var style = null;
		try {
			style = map.getStyle();
		} catch ( e ) {
			return; // Style pas encore chargé : un événement suivant relancera le rendu.
		}
		var specs = ( style && style.sources ) || {};
		var credits = [];
		Object.keys( specs ).forEach( function ( id ) {
			var source = map.getSource( id );
			var text = ( source && source.attribution ) || specs[ id ].attribution;
			if ( 'string' === typeof text && text && credits.indexOf( text ) === -1 ) {
				credits.push( text );
			}
		} );
		var key = credits.join( '\n' );
		if ( key === this.key ) {
			return;
		}
		this.key = key;
		while ( this.inner.firstChild ) {
			this.inner.removeChild( this.inner.firstChild );
		}
		credits.forEach( function ( html, index ) {
			if ( index ) {
				this.inner.appendChild( document.createTextNode( ' | ' ) );
			}
			appendCredit( html, this.inner );
		}, this );
	};

	function initMap( container ) {
		var i18n = C.parseJson( container.getAttribute( 'data-i18n' ) );
		var canvas = container.querySelector( '.g2rd-wpjm-geo-map__canvas' );
		var clustering = '1' === container.getAttribute( 'data-clustering' );
		var ui = C.status( container, i18n );
		var fail = ui.fail;

		if ( ! canvas || ! cfg.styleUrl || ! cfg.maplibreJs || ! container.getAttribute( 'data-rest-url' ) || ! supportsWebGL() ) {
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
				attributionControl: false, // Remplacé par AttributionControl (crédits en texte, ci-dessous).
				locale: uiLocale()
			} );
			// Boutons de zoom et plein écran (comme la carte Google) ; ni rotation ni inclinaison pour une carte d'offres.
			map.addControl( new ml.NavigationControl( { showCompass: false } ), 'top-right' );
			map.addControl( new ml.FullscreenControl(), 'top-right' );
			// Crédits obligatoires (OpenStreetMap, OpenMapTiles, fournisseur des tuiles), rendus en texte.
			map.addControl( new AttributionControl( window.innerWidth < 782 ), 'bottom-right' );
			map.dragRotate.disable();
			map.touchZoomRotate.disableRotation();
			map.touchPitch.disable();

			map.on( 'error', function () {
				// Avant le premier rendu (style ou tuiles injoignables) : message d'erreur. Après, une tuile manquante n'est pas bloquante.
				if ( ! loaded ) {
					fail();
				}
			} );

			map.on( 'load', function () {
				loaded = true;
				container.classList.remove( 'has-error' ); // Une erreur passagère avant le chargement ne doit pas rester affichée.
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
					// Les points du canevas ne prennent pas le focus : liste des offres sous la carte pour le clavier et les lecteurs d'écran.
					C.places( container, groups, i18n );
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

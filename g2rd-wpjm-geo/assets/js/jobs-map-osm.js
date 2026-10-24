/**
 * Carte des offres en mode libre : MapLibre GL JS + fond OpenFreeMap.
 *
 * - MapLibre (embarqué dans le plugin) n'est chargé qu'à l'approche de la zone visible.
 * - Offres aux coordonnées identiques réunies en un point ; clustering natif dont
 *   les cercles comptent les offres (clusterProperties) et non les lieux.
 * - Fenêtres construites en DOM (textContent) par map-common.js.
 * - Plusieurs lieux par offre : un point par ville ; chaque région est une étiquette
 *   dessinée dans le canevas (sous les points) et, une fois les contours chargés à la
 *   demande depuis le plugin, une zone colorée. Sans contours : étiquette et point
 *   au centre. Accès au clavier : la liste sous la carte, régions comprises.
 * - Une seule fenêtre ouverte par carte.
 */
( function ( window, document ) {
	'use strict';

	var C = window.g2rdWpjmGeoMapCommon;
	var cfg = window.g2rdWpjmGeoOsm || {};
	var COLOR = '#c0392b';
	var SOURCE = 'g2rd-jobs';
	var maplibrePromise = null;
	var REGION_COLOR = '#ef6c00';
	var REGION_LINE_COLOR = '#d35400';
	var LABEL_SOURCE = 'g2rd-region-labels';
	var REGION_SOURCE = 'g2rd-regions';
	var shapesPromise = null;

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

	// Contours des régions (GeoJSON du plugin, ODbL), chargés une seule fois et seulement si une offre régionale est affichée.
	function loadShapes() {
		if ( ! shapesPromise ) {
			shapesPromise = cfg.regionsUrl ? window.fetch( cfg.regionsUrl, { credentials: 'same-origin' } ).then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} ) : Promise.reject( new Error( 'no-regions-url' ) );
			shapesPromise.catch( function () {
				shapesPromise = null; // Un échec n'est pas mémorisé.
			} );
		}
		return shapesPromise;
	}

	// Curseur en main au survol d'un calque cliquable.
	function pointer( map, id ) {
		map.on( 'mouseenter', id, function () {
			map.getCanvas().style.cursor = 'pointer';
		} );
		map.on( 'mouseleave', id, function () {
			map.getCanvas().style.cursor = '';
		} );
	}

	// Un point ou un regroupement rendu à l'endroit du clic garde la priorité sur une région.
	function overPoints( map, event ) {
		return map.queryRenderedFeatures( event.point, { layers: [ 'g2rd-points', 'g2rd-clusters' ] } ).length > 0;
	}

	// Premier calque de texte du style (hors calques du plugin) : les zones passent dessous pour garder lisibles les noms du fond de carte.
	function firstStyleSymbol( map ) {
		var style = map.getStyle();
		var layers = ( style && style.layers ) || [];
		for ( var i = 0; i < layers.length; i++ ) {
			if ( 'symbol' === layers[ i ].type && 0 !== String( layers[ i ].id ).indexOf( 'g2rd-' ) ) {
				return layers[ i ].id;
			}
		}
		if ( map.getLayer( 'g2rd-region-label' ) ) {
			return 'g2rd-region-label';
		}
		return map.getLayer( 'g2rd-clusters' ) ? 'g2rd-clusters' : undefined;
	}

	// Étiquette « Région · n offres » dessinée dans le canevas, sous les points et les regroupements : ils restent visibles et cliquables.
	// Renvoie le gestionnaire de clic, partagé avec le point de secours.
	function addRegionLabels( map, regions, byCode, i18n, font, show ) {
		var features = regions.map( function ( region ) {
			var text = ( region.label ? region.label + ' \u00b7 ' : '' ) + C.jobCount( region.jobs.length, i18n );
			return {
				type: 'Feature',
				properties: { code: String( region.code ), text: text },
				geometry: { type: 'Point', coordinates: [ region.position.lng, region.position.lat ] }
			};
		} );
		map.addSource( LABEL_SOURCE, { type: 'geojson', data: { type: 'FeatureCollection', features: features } } );
		map.addLayer( {
			id: 'g2rd-region-label',
			type: 'symbol',
			source: LABEL_SOURCE,
			layout: {
				'text-field': [ 'get', 'text' ],
				'text-font': font,
				'text-size': 13,
				'text-anchor': 'top',
				'text-offset': [ 0, 0.8 ],
				'text-allow-overlap': true,
				'text-ignore-placement': true
			},
			paint: { 'text-color': '#9a3412', 'text-halo-color': '#fff', 'text-halo-width': 2 }
		}, map.getLayer( 'g2rd-clusters' ) ? 'g2rd-clusters' : undefined );
		var open = function ( event ) {
			if ( overPoints( map, event ) ) {
				return;
			}
			var feature = event.features && event.features[ 0 ];
			var region = feature && feature.properties ? byCode[ feature.properties.code ] : null;
			if ( region ) {
				show( feature.geometry.coordinates, C.buildRegionContent( region, i18n ) );
			}
		};
		map.on( 'click', 'g2rd-region-label', open );
		pointer( map, 'g2rd-region-label' );
		return open;
	}

	// Contours injoignables ou inutilisables : un point au centre de chaque région, sous son étiquette.
	function addRegionDots( map, open ) {
		map.addLayer( {
			id: 'g2rd-region-dot',
			type: 'circle',
			source: LABEL_SOURCE,
			paint: { 'circle-radius': 5, 'circle-color': '#9a3412', 'circle-stroke-color': '#fff', 'circle-stroke-width': 2 }
		}, map.getLayer( 'g2rd-region-label' ) ? 'g2rd-region-label' : undefined );
		map.on( 'click', 'g2rd-region-dot', open );
		pointer( map, 'g2rd-region-dot' );
	}

	function addZones( map, geojson, byCode, i18n, show ) {
		var features = ( ( geojson && Array.isArray( geojson.features ) ) ? geojson.features : [] ).filter( function ( feature ) {
			var geometry = feature && feature.geometry;
			return geometry && ( 'Polygon' === geometry.type || 'MultiPolygon' === geometry.type ) && Array.isArray( geometry.coordinates )
				&& feature.properties && byCode[ feature.properties.code ];
		} ).map( function ( feature ) {
			return { type: 'Feature', properties: { code: String( feature.properties.code ) }, geometry: feature.geometry };
		} );
		if ( ! features.length ) {
			throw new Error( 'no-shapes' );
		}
		map.addSource( REGION_SOURCE, {
			type: 'geojson',
			data: { type: 'FeatureCollection', features: features },
			promoteId: 'code',
			attribution: cfg.regionsCredit || ''
		} );
		// Sous les noms du fond de carte, donc aussi sous les points et les regroupements.
		var before = firstStyleSymbol( map );
		var hover = [ 'boolean', [ 'feature-state', 'hover' ], false ];
		map.addLayer( { id: 'g2rd-region-fill', type: 'fill', source: REGION_SOURCE, paint: { 'fill-color': REGION_COLOR, 'fill-opacity': [ 'case', hover, 0.32, 0.18 ] } }, before );
		map.addLayer( { id: 'g2rd-region-line', type: 'line', source: REGION_SOURCE, paint: { 'line-color': REGION_LINE_COLOR, 'line-width': [ 'case', hover, 3, 1.5 ] } }, before );

		var hovered = null;
		function setHover( code ) {
			if ( hovered ) {
				map.setFeatureState( { source: REGION_SOURCE, id: hovered }, { hover: false } );
			}
			hovered = code;
			if ( hovered ) {
				map.setFeatureState( { source: REGION_SOURCE, id: hovered }, { hover: true } );
			}
		}
		map.on( 'mousemove', 'g2rd-region-fill', function ( event ) {
			var feature = event.features && event.features[ 0 ];
			var code = feature && feature.properties ? String( feature.properties.code ) : null;
			if ( code !== hovered ) {
				setHover( code );
			}
		} );
		map.on( 'mouseleave', 'g2rd-region-fill', function () {
			setHover( null );
		} );
		pointer( map, 'g2rd-region-fill' );
		map.on( 'click', 'g2rd-region-fill', function ( event ) {
			// Un point, un regroupement ou une étiquette de la zone garde la priorité (l'étiquette ouvre déjà la région).
			if ( map.queryRenderedFeatures( event.point, { layers: [ 'g2rd-points', 'g2rd-clusters', 'g2rd-region-label' ] } ).length ) {
				return;
			}
			var feature = event.features && event.features[ 0 ];
			var region = feature && feature.properties ? byCode[ feature.properties.code ] : null;
			if ( region ) {
				show( event.lngLat, C.buildRegionContent( region, i18n ) );
			}
		} );
	}

	// Légende, contrôle MapLibre en haut à gauche (visible en plein écran, loin des crédits) : un rond (point d'une ville) et un carré (zone d'une région), décrits par le texte et non par la couleur.
	function addLegend( map, i18n ) {
		if ( ! i18n.legendCity || ! i18n.legendRegion ) {
			return;
		}
		var legend = document.createElement( 'ul' );
		legend.className = 'maplibregl-ctrl g2rd-wpjm-geo-map__legend';
		[ [ 'point', i18n.legendCity ], [ 'region', i18n.legendRegion ] ].forEach( function ( entry ) {
			var item = document.createElement( 'li' );
			var swatch = document.createElement( 'span' );
			swatch.className = 'g2rd-wpjm-geo-map__swatch g2rd-wpjm-geo-map__swatch--' + entry[ 0 ];
			swatch.setAttribute( 'aria-hidden', 'true' );
			item.appendChild( swatch );
			item.appendChild( document.createTextNode( entry[ 1 ] ) );
			legend.appendChild( item );
		} );
		map.addControl( {
			onAdd: function () {
				return legend;
			},
			onRemove: function () {
				if ( legend.parentNode ) {
					legend.parentNode.removeChild( legend );
				}
			}
		}, 'top-left' );
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
			var split = C.splitPlaces( results[ 1 ] );
			var groups = C.groupByCoordinates( split.points );
			var regions = split.regions;
			var byCode = Object.create( null );
			regions.forEach( function ( region ) {
				byCode[ region.code ] = region;
			} );
			var shapes = regions.length ? loadShapes() : null;
			if ( shapes ) {
				shapes.catch( function () {} ); // Échec traité au chargement de la carte (point de secours).
			}
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
			var attribution = new AttributionControl( window.innerWidth < 782 );
			map.addControl( attribution, 'bottom-right' );
			// Une seule fenêtre par carte : l'ouverture d'une nouvelle retire la précédente.
			var popup = null;
			function show( lngLat, node ) {
				if ( popup ) {
					popup.remove();
				}
				popup = new ml.Popup( { maxWidth: '320px' } )
					.setLngLat( lngLat )
					.setDOMContent( node )
					.addTo( map );
			}
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
					show( feature.geometry.coordinates, C.buildInfoContent( jobs, i18n ) );
				} );
				[ 'g2rd-clusters', 'g2rd-points' ].forEach( function ( id ) {
					pointer( map, id );
				} );

				if ( regions.length ) {
					// Étiquettes tout de suite, sans attendre les contours.
					var openRegion = addRegionLabels( map, regions, byCode, i18n, font, show );
					shapes.then( function ( geojson ) {
						addZones( map, geojson, byCode, i18n, show );
					} ).then( function () {
						return true;
					}, function () {
						addRegionDots( map, openRegion ); // Contours injoignables ou inutilisables : point au centre de chaque région.
						return false;
					} ).then( function ( zoned ) {
						if ( zoned ) {
							addLegend( map, i18n );
							attribution.render(); // Crédit des contours (ODbL).
						}
					} ).catch( function () {} );
				}

				var coords = features.map( function ( feature ) {
					return feature.geometry.coordinates;
				} ).concat( regions.map( function ( region ) {
					return [ region.position.lng, region.position.lat ];
				} ) );
				if ( ! coords.length ) {
					ui.empty();
				} else {
					ui.set( '' );
					// Les points du canevas ne prennent pas le focus : liste des offres sous la carte pour le clavier et les lecteurs d'écran.
					C.places( container, groups, i18n, regions );
					if ( 1 === coords.length ) {
						map.jumpTo( { center: coords[ 0 ], zoom: features.length ? 10 : 6 } );
					} else {
						var bounds = new ml.LngLatBounds();
						coords.forEach( function ( coord ) {
							bounds.extend( coord );
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

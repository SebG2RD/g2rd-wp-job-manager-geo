/**
 * Chargeur unique de l'API Google Maps JavaScript.
 *
 * - N'injecte le script Google qu'à la demande (load()).
 * - Réutilise une instance déjà chargée par une autre extension.
 * - Expose window.g2rdWpjmGeoLoader.load() → Promise<google.maps>.
 */
( function ( window, document ) {
	'use strict';

	var config = window.g2rdWpjmGeoConfig || {};
	var CALLBACK = 'g2rdWpjmGeoGoogleReady';
	var TIMEOUT = 15000;
	var promise = null;

	function isReady() {
		return !! ( window.google && window.google.maps && 'function' === typeof window.google.maps.importLibrary );
	}

	function waitForExisting( resolve, reject ) {
		var started = Date.now();
		( function poll() {
			if ( isReady() ) {
				resolve( window.google.maps );
			} else if ( Date.now() - started > TIMEOUT ) {
				reject( new Error( 'google-maps-timeout' ) );
			} else {
				window.setTimeout( poll, 100 );
			}
		}() );
	}

	function load() {
		if ( promise ) {
			return promise;
		}

		promise = new Promise( function ( resolve, reject ) {
			if ( isReady() ) {
				resolve( window.google.maps );
				return;
			}

			// Une autre extension a déjà demandé l'API : on attend son chargement plutôt que de la charger deux fois.
			if ( document.querySelector( 'script[src*="maps.googleapis.com/maps/api/js"]' ) ) {
				waitForExisting( resolve, reject );
				return;
			}

			if ( ! config.apiKey ) {
				reject( new Error( 'google-maps-no-key' ) );
				return;
			}

			var timer = window.setTimeout( function () {
				reject( new Error( 'google-maps-timeout' ) );
			}, TIMEOUT );

			window[ CALLBACK ] = function () {
				window.clearTimeout( timer );
				if ( isReady() ) {
					resolve( window.google.maps );
				} else {
					reject( new Error( 'google-maps-unavailable' ) );
				}
			};

			var params = [
				'key=' + encodeURIComponent( config.apiKey ),
				'v=weekly',
				'loading=async',
				'callback=' + CALLBACK
			];
			if ( config.language ) {
				params.push( 'language=' + encodeURIComponent( config.language ) );
			}

			var script = document.createElement( 'script' );
			script.src = 'https://maps.googleapis.com/maps/api/js?' + params.join( '&' );
			script.async = true;
			script.onerror = function () {
				window.clearTimeout( timer );
				reject( new Error( 'google-maps-network' ) );
			};
			document.head.appendChild( script );
		} );

		// Un échec n'est pas mémorisé : une nouvelle tentative reste possible (perte de réseau passagère).
		promise.catch( function () {
			promise = null;
		} );

		return promise;
	}

	// Clé refusée par Google (restriction de domaine, API non activée, facturation…).
	var previousAuthFailure = window.gm_authFailure;
	window.gm_authFailure = function () {
		document.dispatchEvent( new CustomEvent( 'g2rdWpjmGeoAuthFailure' ) );
		if ( 'function' === typeof previousAuthFailure ) {
			previousAuthFailure();
		}
	};

	window.g2rdWpjmGeoLoader = { load: load };
}( window, document ) );

/**
 * Outil « Re-géocoder les offres » : analyse, simulation, exécution par lots.
 * Tout le contenu est inséré par textContent.
 */
( function () {
	'use strict';

	var TONES = { ok: 'info', done: 'success', not_found: 'warning', error: 'danger', pending: 'neutral' };

	function init( root ) {
		var i18n = {};
		try {
			i18n = JSON.parse( root.getAttribute( 'data-i18n' ) || '{}' );
		} catch ( e ) {
			i18n = {};
		}
		var statusEl = root.querySelector( '[data-g2rd-regeo-status]' );
		var summaryEl = root.querySelector( '[data-g2rd-regeo-summary]' );
		var rowsEl = root.querySelector( '[data-g2rd-regeo-rows]' );
		var buttons = root.querySelectorAll( '[data-g2rd-regeo]' );
		var running = false;

		function setBusy( busy ) {
			running = busy;
			Array.prototype.forEach.call( buttons, function ( button ) {
				button.disabled = busy;
			} );
		}

		function renderSummary( summary ) {
			var list = document.createElement( 'ul' );
			list.className = 'g2rd-stack';
			[ 'total', 'google', 'osm', 'update', 'none', 'todo' ].forEach( function ( key ) {
				var item = document.createElement( 'li' );
				item.textContent = ( i18n[ key ] || key ) + ' : ' + ( summary[ key ] || 0 );
				list.appendChild( item );
			} );
			summaryEl.textContent = '';
			summaryEl.appendChild( list );
		}

		function tableBody() {
			var table = rowsEl.querySelector( 'table' );
			if ( table ) {
				return table.tBodies[ 0 ];
			}
			table = document.createElement( 'table' );
			table.className = 'widefat striped g2rd-table';
			var head = table.createTHead().insertRow();
			[ i18n.job, i18n.location, i18n.result, i18n.status ].forEach( function ( label ) {
				var th = document.createElement( 'th' );
				th.scope = 'col';
				th.textContent = label || '';
				head.appendChild( th );
			} );
			table.createTBody();
			rowsEl.appendChild( table );
			return table.tBodies[ 0 ];
		}

		function appendRows( rows ) {
			if ( ! rows.length ) {
				return;
			}
			var body = tableBody();
			rows.forEach( function ( row ) {
				var tr = body.insertRow();
				var idCell = tr.insertCell();
				if ( row.edit && /^https?:\/\//i.test( row.edit ) ) {
					var link = document.createElement( 'a' );
					link.href = row.edit;
					link.textContent = '#' + row.id;
					idCell.appendChild( link );
				} else {
					idCell.textContent = '#' + row.id;
				}
				tr.insertCell().textContent = row.location || '';
				tr.insertCell().textContent = row.result || '';
				var tag = document.createElement( 'span' );
				tag.className = 'g2rd-tag g2rd-tag--' + ( TONES[ row.status ] || 'neutral' );
				tag.textContent = ( i18n.statusLabels && i18n.statusLabels[ row.status ] ) || row.status || '';
				tr.insertCell().appendChild( tag );
			} );
		}

		function run( task ) {
			if ( running ) {
				return;
			}
			if ( 'run' === task && ! window.confirm( i18n.confirm ) ) {
				return;
			}
			rowsEl.textContent = '';
			statusEl.textContent = i18n.running || '';
			setBusy( true );

			function step( cursor ) {
				var body = new URLSearchParams();
				body.append( 'action', root.getAttribute( 'data-action' ) );
				body.append( 'nonce', root.getAttribute( 'data-nonce' ) );
				body.append( 'task', task );
				body.append( 'cursor', String( cursor ) );
				return window.fetch( root.getAttribute( 'data-ajax-url' ), { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) {
						// Le corps JSON porte le message du serveur, même en erreur HTTP (droits insuffisants, tâche inconnue).
						return response.json().then( function ( payload ) {
							return payload;
						}, function () {
							return null;
						} ).then( function ( payload ) {
							if ( ! response.ok || ! payload || ! payload.success ) {
								var error = new Error( 'HTTP ' + response.status );
								error.serverMessage = ( payload && payload.data && 'string' === typeof payload.data.message ) ? payload.data.message : '';
								throw error;
							}
							return payload;
						} );
					} )
					.then( function ( payload ) {
						var data = payload.data;
						renderSummary( data.summary || {} );
						appendRows( data.rows || [] );
						if ( data.done ) {
							if ( data.stopped ) {
								statusEl.textContent = i18n.stopped || '';
							} else {
								statusEl.textContent = 'simulate' === task ? i18n.done + ' ' + i18n.simulated : i18n.done;
							}
							setBusy( false );
							return null;
						}
						return step( data.cursor );
					} );
			}

			step( 0 ).catch( function ( error ) {
				statusEl.textContent = ( error && error.serverMessage ) || i18n.error || '';
				setBusy( false );
			} );
		}

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				run( button.getAttribute( 'data-g2rd-regeo' ) );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '.g2rd-wpjm-geo-regeocode' ), init );
	} );
}() );

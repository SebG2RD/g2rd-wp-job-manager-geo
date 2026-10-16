/**
 * Outil d'analyse / simulation / correction des offres Go Fetch Jobs (par lots).
 */
( function () {
	'use strict';

	var MAX_ROWS = 500;

	function init( root ) {
		var i18n = {};
		try {
			i18n = JSON.parse( root.getAttribute( 'data-i18n' ) || '{}' );
		} catch ( e ) {
			i18n = {};
		}

		var statusEl = root.querySelector( '.g2rd-wpjm-geo-tools__status' );
		var statsEl = root.querySelector( '.g2rd-wpjm-geo-tools__stats' );
		var rowsEl = root.querySelector( '.g2rd-wpjm-geo-tools__rows' );
		var dryRunEl = root.querySelector( '[data-g2rd-dry-run]' );
		var buttons = root.querySelectorAll( '[data-g2rd-run]' );
		var running = false;

		function setBusy( busy ) {
			running = busy;
			Array.prototype.forEach.call( buttons, function ( button ) {
				button.disabled = busy;
			} );
		}

		function renderStats( totals, task, dryRun ) {
			var keys = [ 'analyzed', 'email', 'url', 'empty', 'toFix' ];
			if ( 'fix' === task && ! dryRun ) {
				keys.push( 'fixed' );
			}
			var list = document.createElement( 'ul' );
			list.className = 'g2rd-stack';
			keys.forEach( function ( key ) {
				var item = document.createElement( 'li' );
				item.textContent = ( i18n[ key ] || key ) + ' : ' + totals[ key ];
				list.appendChild( item );
			} );
			statsEl.textContent = '';
			statsEl.appendChild( list );
		}

		function tableBody() {
			var table = rowsEl.querySelector( 'table' );
			if ( table ) {
				return table.tBodies[ 0 ];
			}
			table = document.createElement( 'table' );
			table.className = 'widefat striped g2rd-table';
			var caption = table.createCaption();
			caption.className   = 'screen-reader-text';
			caption.textContent = i18n.results || '';
			var head = table.createTHead().insertRow();
			[ i18n.job, i18n.current, i18n.next, i18n.reason ].forEach( function ( label ) {
				var th = document.createElement( 'th' );
				th.scope = 'col';
				th.textContent = ( label || '' ).replace( /\s*:\s*$/, '' );
				head.appendChild( th );
			} );
			table.createTBody();
			rowsEl.appendChild( table );
			return table.tBodies[ 0 ];
		}

		function appendRows( rows, shown ) {
			var body = null;
			rows.forEach( function ( row ) {
				if ( shown.count >= MAX_ROWS ) {
					shown.truncated = true;
					return;
				}
				shown.count++;
				body = body || tableBody();
				var tr = body.insertRow();
				tr.insertCell().textContent = '#' + row.id;
				[ row.old, row[ 'new' ] ].forEach( function ( value ) {
					var cell = tr.insertCell();
					cell.className = 'is-mono';
					cell.style.overflowWrap = 'anywhere';
					cell.textContent = value;
				} );
				tr.insertCell().textContent = row.reason;
			} );
		}

		function run( task ) {
			if ( running ) {
				return;
			}
			var dryRun = 'analyze' === task || ! dryRunEl || dryRunEl.checked;
			if ( 'fix' === task && ! dryRun && ! window.confirm( i18n.confirm ) ) {
				return;
			}

			var totals = { analyzed: 0, email: 0, url: 0, empty: 0, toFix: 0, fixed: 0 };
			var shown = { count: 0, truncated: false };
			rowsEl.textContent = '';
			statsEl.textContent = '';
			statusEl.textContent = i18n.running;
			setBusy( true );

			function step( cursor ) {
				var body = new URLSearchParams();
				body.append( 'action', root.getAttribute( 'data-action' ) );
				body.append( 'nonce', root.getAttribute( 'data-nonce' ) );
				body.append( 'task', task );
				body.append( 'cursor', String( cursor ) );
				body.append( 'dry_run', dryRun ? '1' : '0' );

				return window.fetch( root.getAttribute( 'data-ajax-url' ), {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} ).then( function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'HTTP ' + response.status );
					}
					return response.json();
				} ).then( function ( payload ) {
					if ( ! payload || ! payload.success ) {
						throw new Error( 'payload' );
					}
					var data = payload.data;
					Object.keys( totals ).forEach( function ( key ) {
						totals[ key ] += parseInt( data.stats[ key ], 10 ) || 0;
					} );
					renderStats( totals, task, dryRun );
					if ( 'fix' === task ) {
						appendRows( data.rows || [], shown );
					}
					if ( data.done ) {
						var message = i18n.done;
						if ( 'fix' === task && dryRun ) {
							message += ' ' + i18n.simulated;
						}
						if ( shown.truncated ) {
							message += ' ' + i18n.truncated;
						}
						statusEl.textContent = message;
						setBusy( false );
						return null;
					}
					return step( data.cursor );
				} );
			}

			step( 0 ).catch( function () {
				statusEl.textContent = i18n.error;
				setBusy( false );
			} );
		}

		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				run( button.getAttribute( 'data-g2rd-run' ) );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		Array.prototype.forEach.call( document.querySelectorAll( '.g2rd-wpjm-geo-tools' ), init );
	} );
}() );

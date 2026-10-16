/**
 * Page de réglages : interrupteurs, lignes propres à un fournisseur, barre d'enregistrement.
 */
( function () {
	'use strict';

	function init() {
		var form = document.querySelector( '.g2rd-wpjm-geo-settings' );
		if ( ! form ) {
			return;
		}

		// La classe is-checked du conteneur pilote l'apparence de l'interrupteur de WordPress.
		// Synchronisée dès le chargement : le navigateur peut restaurer l'état des cases (retour arrière) sans événement.
		Array.prototype.forEach.call( form.querySelectorAll( '.components-form-toggle__input' ), function ( input ) {
			var sync = function () {
				input.parentNode.classList.toggle( 'is-checked', input.checked );
			};
			sync();
			input.addEventListener( 'change', sync );
		} );

		// display et non hidden : la console donne display:flex aux lignes.
		function showProvider( value ) {
			Array.prototype.forEach.call( document.querySelectorAll( '[data-g2rd-provider]' ), function ( node ) {
				node.style.display = node.getAttribute( 'data-g2rd-provider' ) === value ? '' : 'none';
			} );
		}
		Array.prototype.forEach.call( form.querySelectorAll( 'input[type="radio"][name$="[provider]"]' ), function ( radio ) {
			if ( radio.checked ) {
				showProvider( radio.value );
			}
			radio.addEventListener( 'change', function () {
				if ( radio.checked ) {
					showProvider( radio.value );
				}
			} );
		} );

		// Barre d'enregistrement : visible sans JavaScript, sinon tant qu'un champ diffère de la valeur enregistrée.
		var bar = form.querySelector( '.g2rd-savebar' );
		if ( bar ) {
			var dirty = function () {
				return Array.prototype.some.call( form.querySelectorAll( 'input' ), function ( el ) {
					if ( 'checkbox' === el.type || 'radio' === el.type ) {
						return el.checked !== el.defaultChecked;
					}
					return 'hidden' !== el.type && el.value !== el.defaultValue;
				} );
			};
			var refresh = function () {
				bar.style.display = dirty() ? '' : 'none';
			};
			refresh();
			form.addEventListener( 'input', refresh );
			form.addEventListener( 'change', refresh );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

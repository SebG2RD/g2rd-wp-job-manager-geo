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
		Array.prototype.forEach.call( form.querySelectorAll( '.components-form-toggle__input' ), function ( input ) {
			input.addEventListener( 'change', function () {
				input.parentNode.classList.toggle( 'is-checked', input.checked );
			} );
		} );

		// display et non hidden : la console donne display:flex aux lignes.
		function showProvider( value ) {
			Array.prototype.forEach.call( document.querySelectorAll( '[data-g2rd-provider]' ), function ( node ) {
				node.style.display = node.getAttribute( 'data-g2rd-provider' ) === value ? '' : 'none';
			} );
		}
		Array.prototype.forEach.call( form.querySelectorAll( 'input[type="radio"][name$="[provider]"]' ), function ( radio ) {
			radio.addEventListener( 'change', function () {
				if ( radio.checked ) {
					showProvider( radio.value );
				}
			} );
		} );

		// Barre d'enregistrement : visible sans JavaScript, sinon affichée à la première modification.
		var bar = form.querySelector( '.g2rd-savebar' );
		if ( bar ) {
			bar.style.display = 'none';
			var reveal = function () {
				bar.style.display = '';
			};
			form.addEventListener( 'input', reveal );
			form.addEventListener( 'change', reveal );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

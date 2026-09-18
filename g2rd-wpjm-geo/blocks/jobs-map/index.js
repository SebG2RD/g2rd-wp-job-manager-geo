/**
 * Bloc « Carte des offres d’emploi » — éditeur.
 *
 * Écrit sans JSX pour éviter toute étape de compilation. Le rendu public est
 * réalisé côté serveur (JobsMap::render_block) ; l'éditeur affiche un aperçu
 * statique afin de ne pas charger Google Maps dans Gutenberg.
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var RangeControl = wp.components.RangeControl;

	wp.blocks.registerBlockType( 'g2rd-wpjm-geo/jobs-map', {
		edit: function ( props ) {
			var height = props.attributes.height || 0;
			var blockProps = useBlockProps( {
				style: {
					minHeight: Math.min( height || 320, 480 ) + 'px',
					display: 'flex',
					flexDirection: 'column',
					alignItems: 'center',
					justifyContent: 'center',
					gap: '4px',
					padding: '16px',
					textAlign: 'center',
					background: '#eef2f5',
					border: '1px dashed #8c99a5',
					boxSizing: 'border-box'
				}
			} );

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Affichage', 'g2rd-wpjm-geo' ), initialOpen: true },
						el( RangeControl, {
							label: __( 'Hauteur de la carte (px)', 'g2rd-wpjm-geo' ),
							help: __( '0 = hauteur par défaut définie dans les réglages du plugin.', 'g2rd-wpjm-geo' ),
							value: height,
							min: 0,
							max: 1200,
							step: 10,
							onChange: function ( value ) {
								var next = parseInt( value, 10 ) || 0;
								props.setAttributes( { height: next > 0 && next < 200 ? 200 : next } );
							}
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( 'strong', null, __( 'Carte des offres d’emploi', 'g2rd-wpjm-geo' ) ),
					el( 'span', null, __( 'La carte interactive s’affiche sur le site public.', 'g2rd-wpjm-geo' ) )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
}( window.wp ) );

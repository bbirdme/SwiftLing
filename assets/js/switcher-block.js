/**
 * Language switcher block in the editor. The block renders on the server, so the editor shows
 * exactly what visitors see; this script adds its settings to the sidebar and lets the block sit
 * inside the Navigation block, where block themes keep their menus.
 */
( function ( wp, config ) {
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const { InspectorControls, useBlockProps } = wp.blockEditor;
	const { ExternalLink, PanelBody, Placeholder, SelectControl, ToggleControl } = wp.components;
	const ServerSideRender = wp.serverSideRender;

	// The Navigation block accepts only the blocks it lists. Editors apply this filter again after
	// they load, so it reaches the Navigation block whenever it was registered.
	wp.hooks.addFilter( 'blocks.registerBlockType', 'swiftling/language-switcher-in-navigation', ( settings, name ) => {
		if ( 'core/navigation' !== name || ! Array.isArray( settings.allowedBlocks ) || settings.allowedBlocks.includes( 'swiftling/language-switcher' ) ) {
			return settings;
		}
		return { ...settings, allowedBlocks: [ ...settings.allowedBlocks, 'swiftling/language-switcher' ] };
	} );

	const empty = () =>
		el(
			Placeholder,
			{ icon: 'translation', label: __( 'Language switcher', 'swiftling' ) },
			__( 'The switcher shows once the site has a language besides the original that you can see.', 'swiftling' )
		);

	wp.blocks.registerBlockType( 'swiftling/language-switcher', {
		edit: ( { attributes, setAttributes } ) => {
			// A part not set on the block follows the Switcher screen.
			const shown = ( part ) => ( undefined === attributes[ part ] ? config.show[ part ] : attributes[ part ] );
			const toggle = ( part, label ) =>
				el( ToggleControl, {
					__nextHasNoMarginBottom: true,
					label,
					checked: shown( part ),
					onChange: ( value ) => setAttributes( { [ part ]: value } ),
				} );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Switcher', 'swiftling' ) },
						el( SelectControl, {
							__next40pxDefaultSize: true,
							__nextHasNoMarginBottom: true,
							label: __( 'Style', 'swiftling' ),
							value: attributes.type,
							options: [
								{ value: 'dropdown', label: __( 'Dropdown', 'swiftling' ) },
								{ value: 'horizontal', label: __( 'Horizontal list', 'swiftling' ) },
								{ value: 'vertical', label: __( 'Vertical list', 'swiftling' ) },
							],
							onChange: ( value ) => setAttributes( { type: value } ),
						} ),
						el( 'div', { style: { height: 16 } } ),
						toggle( 'flag', __( 'Flag', 'swiftling' ) ),
						shown( 'flag' ) &&
							el( SelectControl, {
								__next40pxDefaultSize: true,
								__nextHasNoMarginBottom: true,
								label: __( 'Flag size', 'swiftling' ),
								value: attributes.flagSize || config.show.flag_size,
								options: [
									{ value: 'small', label: __( 'Small', 'swiftling' ) },
									{ value: 'medium', label: __( 'Medium', 'swiftling' ) },
									{ value: 'large', label: __( 'Large', 'swiftling' ) },
								],
								onChange: ( value ) => setAttributes( { flagSize: value } ),
							} ),
						shown( 'flag' ) && el( 'div', { style: { height: 16 } } ),
						toggle( 'name', __( 'Language name', 'swiftling' ) ),
						toggle( 'code', __( 'Language code', 'swiftling' ) ),
						el(
							'p',
							{ className: 'components-base-control__help' },
							el( ExternalLink, { href: config.settingsUrl }, __( 'Flags and site-wide settings', 'swiftling' ) )
						)
					)
				),
				el(
					'div',
					useBlockProps( {
						// The links are for visitors; in the editor a click selects the block.
						onClickCapture: ( event ) => {
							if ( event.target instanceof Element && event.target.closest( 'a' ) ) {
								event.preventDefault();
							}
						},
					} ),
					el( ServerSideRender, {
						block: 'swiftling/language-switcher',
						attributes,
						// The wrapper above carries the colours and spacing; the render must not repeat them.
						skipBlockSupportAttributes: true,
						EmptyResponsePlaceholder: empty,
					} )
				)
			);
		},
		save: () => null,
	} );
} )( window.wp, window.swiftlingSwitcherBlock );

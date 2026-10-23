/**
 * Translations panel in the block editor sidebar. For each language, the state of its translation
 * and three ways it can show the post, as icon buttons with a tooltip:
 *
 * - translation: the post's texts translated in the translation editor;
 * - manual: a separate copy, changed by hand in the post's own editor;
 * - duplicate, Keep the original: the post as it is, untranslated, in the original's language.
 *
 * In a copy, the panel says whose copy it is.
 */
( function ( wp, data ) {
	const { createElement: el, createInterpolateElement, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { Button } = wp.components;
	const { useSelect } = wp.data;
	const PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) || wp.editPost.PluginDocumentSettingPanel;

	const bird = el(
		'svg',
		{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 64.86 36.57', width: 20, height: 12, 'aria-hidden': true },
		el( 'path', {
			fill: '#E8552D',
			d: 'M32.43 0C33.81 0 35.19 2.07 35.88 4.83C43.47 2.76 56.58 5.52 64.86 19.32C56.58 15.18 44.16 13.11 36.91 17.25C36.91 20.7 36.57 22.77 35.88 24.84L40.02 36.57L32.43 28.29L24.84 36.57L28.98 24.84C28.29 22.77 27.95 20.7 27.95 17.25C20.7 13.11 8.28 15.18 0 19.32C8.28 5.52 21.39 2.76 28.98 4.83C29.67 2.07 31.05 0 32.43 0Z',
		} )
	);

	// Stroke icons, 24 by 24: a speech bubble with lines, a page with a pen, two pages with an arrow.
	const icon = ( paths ) =>
		el(
			'svg',
			{ xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: 20, height: 20, 'aria-hidden': true, fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, strokeLinecap: 'round', strokeLinejoin: 'round' },
			paths.map( ( d, index ) => el( 'path', { key: index, d } ) )
		);
	const icons = {
		translation: icon( [ 'M4 5h16v11H9l-4 3.5V16H4z', 'M8 9h8', 'M8 12.5h5' ] ),
		manual: icon( [ 'M6 3h8l4 4v6', 'M14 3v4h4', 'M6 3v18h6', 'M14.5 20.5l5.5-5.5 1.5 1.5-5.5 5.5H14.5z' ] ),
		duplicate: icon( [ 'M8 8h11v12H8z', 'M5 16V4h11', 'M11.5 14h4', 'M13.5 12l2 2-2 2' ] ),
	};

	const labels = {
		reviewed: __( 'Reviewed', 'swiftling' ),
		machine: __( 'Machine translated', 'swiftling' ),
		official: __( 'Reviewed', 'swiftling' ),
		outdated: __( 'Original changed', 'swiftling' ),
		partial: __( 'Partly translated', 'swiftling' ),
		missing: __( 'Not translated', 'swiftling' ),
		duplicate: __( 'Keeps the original', 'swiftling' ),
	};

	// What a row says: the state of the translation, of the separate copy, or that it keeps the original.
	const rowState = ( language ) => {
		if ( 'manual' === language.mode && language.copy && language.copy.published ) {
			return 'outdated' === language.state
				? { key: 'outdated', text: __( 'Copy · original changed', 'swiftling' ) }
				: { key: 'reviewed', text: __( 'Separate copy', 'swiftling' ) };
		}
		if ( 'manual' === language.mode ) {
			return { key: 'draft', text: __( 'Copy in draft', 'swiftling' ) };
		}
		return { key: language.state, text: labels[ language.state ] || labels.missing };
	};

	const TranslationsPanel = () => {
		const [ languages, setLanguages ] = useState( data.languages );
		const [ busy, setBusy ] = useState( '' );
		const [ message, setMessage ] = useState( null );
		const { postId, dirty, isNew } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			return { postId: editor.getCurrentPostId(), dirty: editor.isEditedPostDirty(), isNew: editor.isEditedPostNew() };
		}, [] );
		const panel = ( children ) =>
			el( PluginDocumentSettingPanel, { name: 'swiftling-translations', title: __( 'Translations', 'swiftling' ), icon: bird, className: 'swiftling-panel' }, children );

		if ( data.copyOf ) {
			return panel( [
				el(
					'p',
					{ key: 'copy' },
					createInterpolateElement(
						data.copyOf.published
							/* translators: 1: language name, e.g. Deutsch, 2: title of the original. */
							? sprintf( __( 'This is the separate <strong>%1$s</strong> copy of “%2$s”. Visitors who open that page in %1$s see this one.', 'swiftling' ), data.copyOf.language, data.copyOf.title )
							/* translators: 1: language name, e.g. Deutsch, 2: title of the original. */
							: sprintf( __( 'This is the separate <strong>%1$s</strong> copy of “%2$s”. Visitors who open that page in %1$s see this one once it is published.', 'swiftling' ), data.copyOf.language, data.copyOf.title ),
						{ strong: el( 'strong' ) }
					)
				),
				el( 'p', { key: 'terms', className: 'swiftling-panel__hint' }, __( 'Categories and tags come from the original.', 'swiftling' ) ),
				el( Button, { key: 'original', variant: 'secondary', className: 'swiftling-panel__wide', href: data.copyOf.editorUrl }, __( 'Open the translations of the original', 'swiftling' ) ),
			] );
		}

		if ( ! languages.length ) {
			return panel( [
				el( 'p', { key: 'none' }, __( 'No languages chosen yet.', 'swiftling' ) ),
				el( Button, { key: 'languages', variant: 'secondary', href: data.languagesUrl }, __( 'Add languages', 'swiftling' ) ),
			] );
		}

		// Asks the server to change how a language shows the post, then shows the language as it is now.
		const choose = ( language, mode ) => {
			setBusy( language.slug );
			setMessage( null );
			return wp
				.apiFetch( { path: '/swiftling/v1/mode', method: 'POST', data: { post: postId, lang: language.slug, mode } } )
				.then( ( result ) => {
					setLanguages( ( list ) => list.map( ( item ) => ( item.slug === language.slug ? result.language : item ) ) );
					return result;
				} )
				.catch( ( failure ) => {
					setMessage( { error: true, text: ( failure && failure.message ) || __( 'This could not be changed.', 'swiftling' ) } );
					return null;
				} )
				.finally( () => setBusy( '' ) );
		};

		// Leaving a separate copy puts it in the trash, so that is asked first.
		const leavesCopy = ( language ) =>
			'manual' !== language.mode ||
			window.confirm(
				/* translators: %s: language name, e.g. Deutsch. */
				sprintf( __( 'The separate %s copy goes to the trash, where it can be restored. Go on?', 'swiftling' ), language.name )
			);

		const onTranslation = ( language ) => {
			if ( 'translation' === language.mode ) {
				window.location.href = language.url;
			} else if ( leavesCopy( language ) ) {
				choose( language, 'translation' ).then( ( result ) => result && ( window.location.href = result.language.url ) );
			}
		};
		const onManual = ( language ) => {
			if ( language.copy ) {
				window.location.href = language.copy.editUrl;
				return;
			}
			choose( language, 'manual' ).then( ( result ) => {
				if ( result ) {
					setMessage( {
						/* translators: %s: language name, e.g. Deutsch. */
						text: sprintf( __( 'The separate %s copy is made, as a draft, with the translations made so far. Edit it and publish it when it is ready.', 'swiftling' ), language.name ),
						editUrl: result.language.copy && result.language.copy.editUrl,
					} );
				}
			} );
		};
		const onDuplicate = ( language ) => {
			if ( 'duplicate' !== language.mode && leavesCopy( language ) ) {
				choose( language, 'duplicate' );
			}
		};

		const modeButton = ( language, mode, label, onClick, disabled ) =>
			el( Button, {
				key: mode,
				className: 'swiftling-panel__mode',
				icon: icons[ mode ],
				label,
				showTooltip: true,
				isPressed: language.mode === mode,
				disabled: disabled || busy === language.slug,
				onClick: () => onClick( language ),
			} );

		const languageRow = ( language ) => {
			const state = rowState( language );
			let manualLabel = __( 'Separate copy: make one and change anything in it, by hand', 'swiftling' );
			if ( language.copy ) {
				manualLabel = __( 'Separate copy: edit it in its own editor', 'swiftling' );
			} else if ( dirty ) {
				manualLabel = __( 'Separate copy: save first, the copy is made from what is saved', 'swiftling' );
			}
			return el(
				'li',
				{ key: language.slug, className: 'swiftling-panel__row' + ( busy === language.slug ? ' is-busy' : '' ) },
				el( 'span', { className: 'swiftling-panel__lang' }, language.code ),
				el(
					'span',
					{ className: 'swiftling-panel__about' },
					el( 'span', { className: 'swiftling-panel__name' }, language.name ),
					el( 'span', { className: 'swiftling-panel__state is-' + state.key }, state.text )
				),
				el(
					'span',
					{
						className: 'swiftling-panel__modes',
						role: 'group',
						/* translators: %s: language name, e.g. Deutsch. */
						'aria-label': sprintf( __( 'How %s shows this page', 'swiftling' ), language.name ),
					},
					modeButton( language, 'translation', __( 'Translation: the texts of this page, in the translation editor', 'swiftling' ), onTranslation, ! data.canChoose ),
					modeButton( language, 'manual', manualLabel, onManual, ! data.canChoose || ( ! language.copy && ( ! data.canCopy || dirty || isNew ) ) ),
					modeButton(
						language,
						'duplicate',
						/* translators: 1: language name, e.g. Deutsch, 2: the original's language, e.g. English. */
						sprintf( __( 'Keep the original: visitors in %1$s see this page in %2$s, as it is. It counts as done, so Translate everything skips it.', 'swiftling' ), language.name, data.source ),
						onDuplicate,
						! data.canChoose
					)
				)
			);
		};

		return panel( [
			el(
				'p',
				{ key: 'source' },
				createInterpolateElement(
					/* translators: %s: language name, e.g. Hrvatski. */
					sprintf( __( 'Written in <strong>%s</strong>.', 'swiftling' ), data.source ),
					{ strong: el( 'strong' ) }
				)
			),
			el( 'ul', { key: 'languages', className: 'swiftling-panel__languages' }, languages.map( languageRow ) ),
			message &&
				el(
					'div',
					{ key: 'message', className: 'swiftling-panel__message' + ( message.error ? ' is-error' : '' ), role: message.error ? 'alert' : 'status' },
					el( 'p', null, message.text ),
					message.editUrl && el( Button, { variant: 'primary', className: 'swiftling-panel__wide', href: message.editUrl }, __( 'Edit the copy', 'swiftling' ) )
				),
		] );
	};

	wp.plugins.registerPlugin( 'swiftling', { render: TranslationsPanel, icon: bird } );
} )( window.wp, window.swiftlingEditorPanel );

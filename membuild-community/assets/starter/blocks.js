/* The starter pages' live blocks in the editor: a live preview from the server, and a few settings. */
( function ( wp ) {
	const { registerBlockType } = wp.blocks
	const { createElement: el, Fragment } = wp.element
	const { InspectorControls, useBlockProps } = wp.blockEditor
	const { PanelBody, RangeControl, ToggleControl, TextareaControl, TextControl, SelectControl } = wp.components
	const SSR = wp.serverSideRender
	const { __ } = wp.i18n
	const T = 'membuild-community'
	const blocks = {
		courses: { title: __( 'Courses', T ), icon: 'welcome-learn-more', description: __( 'Your published courses, with their covers.', T ), controls: [ [ 'limit', 'range', __( 'How many', T ), 1, 12 ], [ 'details', 'toggle', __( 'Show what each is about and who’s taking it', T ) ], [ 'filters', 'toggle', __( 'Show the filter buttons', T ) ], [ 'more', 'toggle', __( 'Add a “More coming” tile', T ) ] ] },
		events: { title: __( 'Upcoming events', T ), icon: 'calendar-alt', description: __( 'The next live events from the community.', T ), controls: [ [ 'limit', 'range', __( 'How many', T ), 1, 8 ] ] },
		faces: { title: __( 'Member faces', T ), icon: 'groups', description: __( 'Members’ photos and how many you are.', T ), controls: [ [ 'count', 'range', __( 'Faces', T ), 3, 24 ], [ 'size', 'range', __( 'Size', T ), 24, 96 ], [ 'label', 'toggle', __( 'Show “… members and growing”', T ) ], [ 'note', 'line', __( 'A line under it', T ) ], [ 'gallery', 'toggle', __( 'Show as a gallery', T ) ] ] },
		plans: { title: __( 'Plans', T ), icon: 'money-alt', description: __( 'Free and your paid memberships, with prices from FluentCart.', T ), controls: [ [ 'free', 'text', __( 'What Free includes (one per line)', T ) ], [ 'paid', 'text', __( 'What paid plans include (one per line)', T ) ], [ 'trust', 'toggle', __( 'Show “Secure checkout · Cancel any time · No setup fees”', T ) ] ] },
		compare: { title: __( 'Compare plans', T ), icon: 'editor-table', description: __( 'Free beside your paid membership, a line per thing.', T ), controls: [ [ 'rows', 'text', __( 'One per line: what it is | yes or no (is it in Free?)', T ) ] ] },
		'contact-form': { title: __( 'Contact form', T ), icon: 'email', description: __( 'Messages go to the site’s admin email.', T ), controls: [ [ 'note', 'toggle', __( 'Show who replies', T ) ] ] },
		stat: { title: __( 'Community number', T ), icon: 'chart-bar', description: __( 'A number from your community, always up to date.', T ), controls: [ [ 'kind', 'select', __( 'Which number', T ), [ [ 'members', __( 'Members', T ) ], [ 'courses', __( 'Courses', T ) ], [ 'active', __( 'Members active today', T ) ] ] ], [ 'format', 'line', __( 'Words around it (%s is the number)', T ) ], [ 'pill', 'toggle', __( 'Show as a small label', T ) ], [ 'min', 'range', __( 'Only show from', T ), 0, 100 ], [ 'fallback', 'line', __( 'Shown instead, below that', T ) ] ] },
		peek: { title: __( 'Community preview', T ), icon: 'visibility', description: __( 'A small live preview: a question, a course, the next event or who’s around.', T ), controls: [ [ 'kind', 'select', __( 'Show', T ), [ [ 'question', __( 'A question and answer', T ) ], [ 'course', __( 'A course', T ) ], [ 'event', __( 'The next event', T ) ], [ 'people', __( 'Who’s active today', T ) ] ] ], [ 'question', 'line', __( 'Question', T ) ], [ 'answer', 'line', __( 'Answer', T ) ] ] },
		moments: { title: __( 'Community moments', T ), icon: 'heart', description: __( 'Little moments from the community, floating around the phone.', T ), controls: [] },
		team: { title: __( 'The team', T ), icon: 'admin-users', description: __( 'You and your moderators.', T ), controls: [ [ 'row', 'toggle', __( 'One row with a “Message a moderator” button', T ) ] ] },
	}
	Object.entries( blocks ).forEach( ( [ name, b ] ) => {
		registerBlockType( 'membuild-community/' + name, {
			apiVersion: 3,
			title: b.title,
			icon: b.icon,
			category: 'widgets',
			description: b.description,
			edit( { attributes, setAttributes } ) {
				const props = useBlockProps()
				const set = ( key ) => ( v ) => setAttributes( { [ key ]: v } )
				const control = ( [ key, kind, label, min, max ] ) =>
					kind === 'range' ? el( RangeControl, { key, label, min, max, value: attributes[ key ], onChange: set( key ) } )
					: kind === 'toggle' ? el( ToggleControl, { key, label, checked: !! attributes[ key ], onChange: set( key ) } )
					: kind === 'select' ? el( SelectControl, { key, label, value: attributes[ key ], options: min.map( ( [ value, l ] ) => ( { value, label: l } ) ), onChange: set( key ) } )
					: kind === 'line' ? el( TextControl, { key, label, value: attributes[ key ] || '', onChange: set( key ) } )
					: el( TextareaControl, { key, label, value: attributes[ key ], onChange: set( key ) } )
				return el( Fragment, null,
					b.controls.length ? el( InspectorControls, null, el( PanelBody, { title: b.title }, b.controls.map( control ) ) ) : null,
					el( 'div', props, el( SSR, { block: 'membuild-community/' + name, attributes } ) ) )
			},
			save: () => null,
		} )
	} )
} )( window.wp )

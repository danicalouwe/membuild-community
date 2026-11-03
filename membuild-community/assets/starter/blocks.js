/* The starter pages' live blocks in the editor: a live preview from the server, and a few settings. */
( function ( wp ) {
	const { registerBlockType } = wp.blocks
	const { createElement: el, Fragment } = wp.element
	const { InspectorControls, useBlockProps } = wp.blockEditor
	const { PanelBody, RangeControl, ToggleControl, TextareaControl, TextControl, SelectControl, Button, CheckboxControl, BaseControl } = wp.components
	const SSR = wp.serverSideRender
	const { __, sprintf } = wp.i18n
	const T = 'membuild-community'
	const blocks = {
		courses: { title: __( 'Community courses (live)', T ), icon: 'welcome-learn-more', description: __( 'Your published courses, with their covers.', T ), controls: [ [ 'limit', 'range', __( 'How many', T ), 1, 12 ], [ 'details', 'toggle', __( 'Show what each is about and who’s taking it', T ) ], [ 'filters', 'toggle', __( 'Show the filter buttons', T ) ], [ 'more', 'toggle', __( 'Add a “More coming” tile', T ) ] ] },
		events: { title: __( 'Upcoming events (live)', T ), icon: 'calendar-alt', description: __( 'The next live events from the community.', T ), controls: [ [ 'limit', 'range', __( 'How many', T ), 1, 8 ] ] },
		'upcoming-events': { title: __( 'Events list (live)', T ), icon: 'calendar', description: __( 'The next events, with a button to join or to open each one in the community. Meeting links and addresses stay inside the community.', T ), controls: [ [ 'count', 'range', __( 'How many', T ), 1, 12 ] ] },
		faces: { title: __( 'Member faces (live)', T ), icon: 'groups', description: __( 'Members’ photos and how many you are (shown from 50 members).', T ), controls: [ [ 'count', 'range', __( 'Faces', T ), 3, 24 ], [ 'size', 'range', __( 'Size', T ), 24, 96 ], [ 'label', 'toggle', __( 'Show “… members and growing”', T ) ], [ 'note', 'line', __( 'A line under it', T ) ], [ 'gallery', 'toggle', __( 'Show as a gallery', T ) ] ] },
		plans: { title: __( 'Plans (live)', T ), icon: 'money-alt', description: __( 'Your plans, with names, prices and what each includes from Memberships, so they match checkout.', T ), controls: [ [ 'showFree', 'free', __( 'Show a free plan', T ) ], [ 'badge', 'line', __( 'Badge on the main plan', T ) ], [ 'mainLine', 'line', __( 'Main plan line', T ), __( 'For people serious about growing.', T ) ], [ 'mainButton', 'line', __( 'Main plan button', T ), __( 'Become a …', T ) ], [ 'freeLine', 'line', __( 'Free plan line', T ), __( 'Everything you need to start.', T ) ], [ 'freeButton', 'line', __( 'Free plan button', T ), __( 'Join free', T ) ], [ 'free', 'text', __( 'What Free includes (one per line)', T ) ], [ 'trust', 'toggle', __( 'Show the money-back promise and “Cancel any time · Secure checkout”', T ) ], [ 'note', 'note', __( 'Plan names, prices and feature lines come from Memberships, so they match checkout.', T ) ] ] },
		compare: { title: __( 'Compare plans (live)', T ), icon: 'editor-table', description: __( 'A column per plan, a row per thing, with a tick where it’s included.', T ), controls: [ [ 'showFree', 'free', __( 'Show a free plan', T ) ], [ 'items', 'rows', __( 'Rows', T ) ] ] },
		'contact-form': { title: __( 'Contact form (live)', T ), icon: 'email', description: __( 'Messages go to the support email in Settings › General (or the site’s admin email).', T ), controls: [ [ 'note', 'toggle', __( 'Show who replies', T ) ] ] },
		stat: { title: __( 'Community number (live)', T ), icon: 'chart-bar', description: __( 'A number from your community, always up to date. Admins and test accounts aren’t counted.', T ), controls: [ [ 'kind', 'select', __( 'Which number', T ), [ [ 'members', __( 'Members', T ) ], [ 'courses', __( 'Courses', T ) ], [ 'active', __( 'Members active today', T ) ] ] ], [ 'format', 'line', __( 'Words around it (%s is the number)', T ) ], [ 'pill', 'toggle', __( 'Show as a small label', T ) ], [ 'min', 'from', __( 'Only show from', T ) ], [ 'fallback', 'line', __( 'Shown instead, below that', T ) ] ] },
		peek: { title: __( 'Community preview (live)', T ), icon: 'visibility', description: __( 'A small live preview: a question, a course, the next event or who’s around.', T ), controls: [ [ 'kind', 'select', __( 'Show', T ), [ [ 'question', __( 'A question and answer', T ) ], [ 'course', __( 'A course', T ) ], [ 'event', __( 'The next event', T ) ], [ 'people', __( 'Who’s active today', T ) ] ] ], [ 'question', 'line', __( 'Question', T ) ], [ 'answer', 'line', __( 'Answer', T ) ] ] },
		moments: { title: __( 'Community moments (live)', T ), icon: 'heart', description: __( 'Little moments from the community, floating around the picture.', T ), controls: [] },
		team: { title: __( 'The team (live)', T ), icon: 'admin-users', description: __( 'You and your moderators.', T ), controls: [ [ 'row', 'toggle', __( 'One row with a “Message a moderator” button (visitors get “Questions? Contact us”)', T ) ] ] },
		waitlist: { title: __( 'Coming soon email box (live)', T ), icon: 'email-alt', description: __( 'Collects emails before you open, and says how many people are waiting (from 20). Emails stay in your community.', T ), controls: [ [ 'button', 'line', __( 'Button', T ) ] ] },
		opening: { title: __( 'Opening day (live)', T ), icon: 'clock', description: __( '“Opening Mon, Dec 1”, from Settings › Main website › Coming soon.', T ), controls: [ [ 'fallback', 'line', __( 'Shown with no opening day', T ) ] ] },
	}
	const DEFAULT_FROM = { members: 50, active: 20, courses: 0 }

	// Compare: a row list with a tick per plan (replaces the old "words | yes" typing code).
	function Rows( { attributes, setAttributes } ) {
		const cols = ( window.mbcomStarter && window.mbcomStarter.columns ) || [ { key: 'free', name: __( 'Free', T ) } ]
		const paidKeys = cols.filter( ( c ) => c.key !== 'free' ).map( ( c ) => c.key )
		const items = attributes.items && attributes.items.length ? attributes.items : ( attributes.rows || '' ).split( '\n' ).filter( ( l ) => l.trim() ).map( ( l ) => {
			const [ label, free ] = l.split( '|' ).map( ( x ) => ( x || '' ).trim() )
			return { label, in: ( /^(yes|y|✓|true)$/i.test( free ) ? [ 'free' ] : [] ).concat( [ 'paid' ] ) }
		} )
		// "paid" means every paid plan; ticking one plan writes them out one by one.
		const has = ( row, key ) => row.in.includes( key ) || ( key !== 'free' && row.in.includes( 'paid' ) )
		const save = ( next ) => setAttributes( { items: next, rows: '' } )
		const tick = ( i, key, on ) => save( items.map( ( r, j ) => {
			if ( j !== i ) return r
			let list = r.in.includes( 'paid' ) ? r.in.filter( ( k ) => k !== 'paid' ).concat( paidKeys ) : r.in.slice()
			list = on ? Array.from( new Set( list.concat( [ key ] ) ) ) : list.filter( ( k ) => k !== key )
			return { label: r.label, in: list }
		} ) )
		const move = ( i, by ) => {
			const next = items.slice()
			const [ one ] = next.splice( i, 1 )
			next.splice( Math.max( 0, Math.min( next.length, i + by ) ), 0, one )
			save( next )
		}
		return el( BaseControl, { label: __( 'Rows', T ), help: __( 'A column per plan, so two paid plans just work.', T ) },
			items.map( ( r, i ) => el( 'div', { key: i, style: { borderTop: '1px solid #e7e9ec', padding: '8px 0' } },
				el( TextControl, { label: sprintf( __( 'Row %d', T ), i + 1 ), value: r.label, onChange: ( v ) => save( items.map( ( x, j ) => ( j === i ? { label: v, in: x.in } : x ) ) ) } ),
				el( 'div', { style: { display: 'flex', flexWrap: 'wrap', gap: '4px 14px' } }, cols.map( ( c ) => el( CheckboxControl, { key: c.key, label: c.name, checked: has( r, c.key ), onChange: ( on ) => tick( i, c.key, on ) } ) ) ),
				el( 'div', { style: { display: 'flex', gap: '4px' } },
					el( Button, { size: 'small', variant: 'tertiary', icon: 'arrow-up-alt2', label: __( 'Move up', T ), disabled: ! i, onClick: () => move( i, -1 ) } ),
					el( Button, { size: 'small', variant: 'tertiary', icon: 'arrow-down-alt2', label: __( 'Move down', T ), disabled: i === items.length - 1, onClick: () => move( i, 1 ) } ),
					el( Button, { size: 'small', variant: 'tertiary', isDestructive: true, onClick: () => save( items.filter( ( x, j ) => j !== i ) ) }, __( 'Remove', T ) ) ) ) ),
			el( Button, { variant: 'secondary', onClick: () => save( items.concat( [ { label: '', in: [ 'paid' ] } ] ) ) }, __( '+ Add a row', T ) ) )
	}

	Object.entries( blocks ).forEach( ( [ name, b ] ) => {
		registerBlockType( 'membuild-community/' + name, {
			apiVersion: 3,
			title: b.title,
			icon: b.icon,
			category: 'membuild-community',
			description: b.description,
			edit( props ) {
				const { attributes, setAttributes } = props
				const blockProps = useBlockProps()
				const set = ( key ) => ( v ) => setAttributes( { [ key ]: v } )
				const control = ( [ key, kind, label, min, max ] ) =>
					kind === 'range' ? el( RangeControl, { key, label, min, max, value: attributes[ key ], onChange: set( key ) } )
					: kind === 'toggle' ? el( ToggleControl, { key, label, checked: !! attributes[ key ], onChange: set( key ) } )
					: kind === 'free' ? el( ToggleControl, { key, label, checked: attributes[ key ] === null || attributes[ key ] === undefined ? !! ( window.mbcomStarter && ( window.mbcomStarter.columns || [] ).some( ( c ) => c.key === 'free' ) ) : !! attributes[ key ], help: __( 'Off when you have no free way in, like most paid-only communities.', T ), onChange: set( key ) } )
					: kind === 'from' ? el( RangeControl, { key, label, min: 0, max: 200, value: attributes[ key ] < 0 ? DEFAULT_FROM[ attributes.kind ] || 0 : attributes[ key ], help: __( 'Small numbers make a new community look empty. Starts at 20 active today and 50 members.', T ), onChange: set( key ) } )
					: kind === 'select' ? el( SelectControl, { key, label, value: attributes[ key ], options: min.map( ( [ value, l ] ) => ( { value, label: l } ) ), onChange: set( key ) } )
					: kind === 'line' ? el( TextControl, { key, label, value: attributes[ key ] || '', placeholder: min || '', onChange: set( key ) } )
					: kind === 'rows' ? el( Rows, { key, attributes, setAttributes } )
					: kind === 'note' ? el( 'p', { key, className: 'components-base-control__help' }, label )
					: el( TextareaControl, { key, label, value: attributes[ key ], onChange: set( key ) } )
				return el( Fragment, null,
					b.controls.length ? el( InspectorControls, null, el( PanelBody, { title: b.title }, b.controls.map( control ) ) ) : null,
					el( 'div', blockProps, el( SSR, { block: 'membuild-community/' + name, attributes } ) ) )
			},
			save: () => null,
		} )
	} )
} )( window.wp )

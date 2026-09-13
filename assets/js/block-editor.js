/* ShootCal block editor. Pasted references are data, never executable markup. */
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement, __ = wp.i18n.__, Reference = window.ShootCalEmbedReference;
	var TextControl = wp.components.TextControl, SelectControl = wp.components.SelectControl;
	var PanelBody = wp.components.PanelBody, ToggleControl = wp.components.ToggleControl;
	var settings = window.ShootCalWebCalendarBlock || {}, feedMonths = Math.max( 1, Math.min( 36, Number( settings.monthsDefault ) || 12 ) );
	wp.blocks.registerBlockType( 'shootcal-web-calendar/calendar', {
		edit: function ( props ) {
			var a = props.attributes, set = props.setAttributes;
			var urlReference = Reference.parse( a.url || '' );
			var reference = a.calendarId ? Reference.parse( a.calendarId ) : urlReference;
			var source = a.source === 'ical' ? 'ical' : a.source === 'shootcal' || a.calendarId || reference || ! a.url ? 'shootcal' : 'ical';
			var hosted = source === 'shootcal';
			var query = reference ? reference.query : {};
			query = hosted ? Object.assign( {}, query, Reference.cleanQuery( a.embedParams || {} ) ) : {};
			var view = a.embedView || query.view || 'default';
			var mode = a.mode === 'full' ? 'full' : 'availability';
			// Keep each source's input when switching between ShootCal and iCal.
			// A legacy hosted URL is migrated once before the URL field is reused.
			var feedUrl = a.url && ( ! urlReference || a.source === 'ical' || a.calendarId ) ? a.url : '';
			var value = hosted ? a.calendarId || ( urlReference ? a.url : '' ) || '' : feedUrl;
			function setSource( next ) {
				var change = { source: next };
				if ( ! a.calendarId && reference ) {
					change.calendarId = reference.token;
					change.url = feedUrl;
					change.embedParams = Object.assign( {}, reference.query, a.embedParams );
				}
				set( change );
			}
			function updateOptions( change ) {
				if ( hosted && reference && ! a.calendarId ) change = Object.assign( { calendarId: reference.token, url: feedUrl, embedParams: query }, change );
				set( change );
			}
			function setReference( input ) {
				var parsed = Reference.parse( input );
				if ( ! parsed ) { set( { source: 'shootcal', calendarId: input, url: feedUrl } ); return; }
				var change = { source: 'shootcal', calendarId: parsed.token, url: feedUrl };
				if ( input.trim() !== parsed.token ) {
					change.embedParams = parsed.query;
					change.embedView = parsed.query.view;
					change.theme = parsed.query.theme;
					change.months = parsed.query.months ? Number( parsed.query.months ) : undefined;
					change.firstDay = parsed.query.first_day !== undefined ? Number( parsed.query.first_day ) : undefined;
					change.mode = parsed.query.mode || 'availability';
				}
				set( change );
			}
			var sourceControl = el( SelectControl, {
				label: __( 'Calendar source', 'shootcal-web-calendar' ), value: source,
				options: [ { label: __( 'ShootCal', 'shootcal-web-calendar' ), value: 'shootcal' }, { label: __( 'Other calendar (iCal)', 'shootcal-web-calendar' ), value: 'ical' } ],
				onChange: setSource
			} );
			var referenceControl = el( TextControl, {
				label: hosted ? __( 'ShootCal calendar ID', 'shootcal-web-calendar' ) : __( 'iCal feed URL', 'shootcal-web-calendar' ),
				help: hosted ? __( 'Find your ID under Connect to website. An existing embed code works too.', 'shootcal-web-calendar' ) : __( 'Paste an HTTP or HTTPS iCal feed URL. Treat private feed URLs like passwords.', 'shootcal-web-calendar' ),
				type: hosted ? 'text' : 'url', value: value, autoComplete: 'off', spellCheck: false,
				placeholder: hosted ? __( 'Paste your ShootCal calendar ID', 'shootcal-web-calendar' ) : 'https://example.com/calendar.ics',
				onChange: hosted ? setReference : function ( input ) { set( { source: 'ical', url: input.trim() } ); }
			} );
			var monthsValue = a.months !== undefined ? String( a.months ) : query.months || String( hosted ? 12 : feedMonths );
			var firstDayValue = a.firstDay !== undefined ? String( a.firstDay ) : query.first_day || '';
			var calendarOptions = ! hosted || view === 'calendar' ? el( PanelBody, { title: __( 'Calendar options', 'shootcal-web-calendar' ), initialOpen: false },
				el( TextControl, { label: __( 'Months to show', 'shootcal-web-calendar' ), help: hosted ? __( 'Including the current month. Defaults to 12.', 'shootcal-web-calendar' ) : __( 'Including the current month. Defaults to your saved plugin setting.', 'shootcal-web-calendar' ), type: 'number', min: 1, max: 36, value: monthsValue,
					onChange: function ( input ) { var number = parseInt( input, 10 ), params = Object.assign( {}, query ); delete params.months; updateOptions( { months: isNaN( number ) || number < 1 ? undefined : Math.min( 36, number ), embedParams: params } ); } } ),
				el( SelectControl, { label: __( 'First day of week', 'shootcal-web-calendar' ), value: firstDayValue,
					options: [ { label: __( 'Calendar default', 'shootcal-web-calendar' ), value: '' }, { label: __( 'Sunday', 'shootcal-web-calendar' ), value: '0' }, { label: __( 'Monday', 'shootcal-web-calendar' ), value: '1' } ],
					onChange: function ( input ) { var params = Object.assign( {}, query ); delete params.first_day; updateOptions( { firstDay: input === '' ? undefined : Number( input ), embedParams: params } ); } } ),
				! hosted ? el( TextControl, { label: __( 'Timezone override', 'shootcal-web-calendar' ), help: __( 'Leave blank for your WordPress site timezone, or enter an IANA identifier such as America/New_York.', 'shootcal-web-calendar' ), value: a.timezone || '', onChange: function ( input ) { set( { timezone: input.trim() } ); } } ) : null
			) : null;
			var sidebar = el( wp.blockEditor.InspectorControls, null,
				el( PanelBody, { title: __( 'Display settings', 'shootcal-web-calendar' ), initialOpen: true },
					hosted ? el( SelectControl, { label: __( 'ShootCal display', 'shootcal-web-calendar' ), value: view,
						help: __( 'Follow ShootCal settings shows booking when enabled. Calendar only always shows the month calendar.', 'shootcal-web-calendar' ),
						options: [ { label: __( 'Follow ShootCal settings', 'shootcal-web-calendar' ), value: 'default' }, { label: __( 'Calendar only', 'shootcal-web-calendar' ), value: 'calendar' } ],
						onChange: function ( next ) { updateOptions( { embedView: next } ); } } ) : el( SelectControl, { label: __( 'Display mode', 'shootcal-web-calendar' ), value: mode,
						options: [ { label: __( 'Availability (free/busy)', 'shootcal-web-calendar' ), value: 'availability' }, { label: __( 'Full calendar (show events)', 'shootcal-web-calendar' ), value: 'full' } ], onChange: function ( next ) { set( { mode: next } ); } } ),
					! hosted && mode === 'availability' ? el( ToggleControl, { label: __( 'I can take more than one booking per day', 'shootcal-web-calendar' ), help: __( 'On: timed sessions show as Limited. Off: the first booking marks the whole day Booked.', 'shootcal-web-calendar' ), checked: a.multiSessionDay !== false, onChange: function ( next ) { set( { multiSessionDay: !! next } ); } } ) : null
				),
				! hosted && mode === 'availability' ? el( wp.blockEditor.PanelColorSettings, { title: __( 'Availability colors', 'shootcal-web-calendar' ), initialOpen: false,
					colorSettings: [ { label: __( 'Limited day color', 'shootcal-web-calendar' ), value: a.limitedColor || undefined, onChange: function ( next ) { set( { limitedColor: next || '' } ); } }, { label: __( 'Booked day color', 'shootcal-web-calendar' ), value: a.bookedColor || undefined, onChange: function ( next ) { set( { bookedColor: next || '' } ); } } ] } ) : null,
				calendarOptions
			);
			var preview = el( 'div', wp.blockEditor.useBlockProps( { className: 'shootcal-web-calendar__editor' } ),
				el( 'div', { className: 'shootcal-web-calendar__editor-header' },
					settings.logoUrl ? el( 'img', { className: 'shootcal-web-calendar__editor-logo', src: settings.logoUrl, width: 44, height: 44, alt: '' } ) : null,
					el( 'div', null, el( 'h3', null, __( 'ShootCal Calendar', 'shootcal-web-calendar' ) ), el( 'p', null, __( 'Your live calendar on your website.', 'shootcal-web-calendar' ) ) )
				),
				el( 'div', { className: 'shootcal-web-calendar__editor-fields' }, sourceControl,
					hosted ? el( 'a', { className: 'components-button is-secondary shootcal-web-calendar__editor-link', href: settings.bookingSettingsUrl || 'https://app.shootcal.com/app/clients/booking', target: '_blank', rel: 'noopener noreferrer', 'aria-label': __( 'Open Clients & Booking (opens in a new tab)', 'shootcal-web-calendar' ) }, __( 'Open Clients & Booking', 'shootcal-web-calendar' ), el( 'span', { 'aria-hidden': true }, ' ↗' ) ) : null,
					referenceControl,
					hosted && value && ! reference ? el( 'p', { role: 'alert' }, __( 'Enter a valid ShootCal calendar ID or a ShootCal embed reference.', 'shootcal-web-calendar' ) ) : null
				),
				el( 'p', { className: 'shootcal-web-calendar__editor-hint' }, __( 'Preview the page to see your calendar.', 'shootcal-web-calendar' ) )
			);
			return el( wp.element.Fragment, null, sidebar, preview );
		},
		save: function () { return null; }
	} );
} )( window.wp );

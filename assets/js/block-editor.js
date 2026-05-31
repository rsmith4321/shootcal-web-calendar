/* ShootCal Web Calendar - block editor script (vanilla JS, no build).
 *
 * Registers the `shootcal-web-calendar/calendar` block. The editor view shows a
 * static placeholder card with a Calendar URL field (paste a feed right into the
 * block, like the core Embed block) - NOT a live render. The frontend stylesheet
 * isn't loaded in the editor, so a server render of the real grid collapses into
 * an unstyled list; and re-rendering the live feed on every keystroke is
 * wasteful. The actual calendar is emitted by the PHP render callback on the
 * published page.
 *
 * No JSX, no bundler. Uses createElement directly so the file can be loaded as
 * a normal script with the WP global script handles as dependencies.
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var useBlockProps      = wp.blockEditor.useBlockProps;
	var InspectorControls  = wp.blockEditor.InspectorControls;
	var PanelColorSettings = wp.blockEditor.PanelColorSettings;
	var PanelBody          = wp.components.PanelBody;
	var Placeholder       = wp.components.Placeholder;
	var TextControl       = wp.components.TextControl;
	var SelectControl     = wp.components.SelectControl;
	var ToggleControl     = wp.components.ToggleControl;
	var __                = wp.i18n.__;

	registerBlockType( 'shootcal-web-calendar/calendar', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps    = useBlockProps();

			var monthsValue = ( attributes.months !== undefined && attributes.months !== null )
				? String( attributes.months )
				: '';
			var firstDayValue = ( attributes.firstDay === 1 ) ? '1' : '0';
			var timezoneValue = attributes.timezone || '';
			var modeValue     = ( attributes.mode === 'full' ) ? 'full' : 'availability';
			var urlValue      = attributes.url || '';
			var multiSessionDayValue = ( attributes.multiSessionDay === false ) ? false : true;
			var limitedColorValue = attributes.limitedColor || '';
			var bookedColorValue  = attributes.bookedColor || '';

			// Per-embed cell colors apply to availability mode only. Empty = the
			// built-in defaults (soft gold / coral); the server falls back when unset.
			var colorPanel = ( modeValue === 'availability' ) ? el( PanelColorSettings, {
				title: __( 'Availability colors', 'shootcal-web-calendar' ),
				initialOpen: false,
				colorSettings: [
					{
						label: __( 'Limited day color', 'shootcal-web-calendar' ),
						value: limitedColorValue || undefined,
						onChange: function ( v ) { setAttributes( { limitedColor: v || '' } ); }
					},
					{
						label: __( 'Booked day color', 'shootcal-web-calendar' ),
						value: bookedColorValue || undefined,
						onChange: function ( v ) { setAttributes( { bookedColor: v || '' } ); }
					}
				]
			} ) : null;

			var sidebar = el( InspectorControls, null,
				el( PanelBody, { title: __( 'Calendar source', 'shootcal-web-calendar' ), initialOpen: true },
					el( SelectControl, {
						label: __( 'Display mode', 'shootcal-web-calendar' ),
						help: __( 'Availability shows free/busy shading only. Full calendar shows each event title and time.', 'shootcal-web-calendar' ),
						value: modeValue,
						options: [
							{ label: __( 'Availability (free/busy)', 'shootcal-web-calendar' ), value: 'availability' },
							{ label: __( 'Full calendar (show events)', 'shootcal-web-calendar' ), value: 'full' }
						],
						onChange: function ( v ) {
							setAttributes( { mode: ( v === 'full' ) ? 'full' : 'availability' } );
						}
					} ),
					el( TextControl, {
						label: __( 'Calendar feed URL', 'shootcal-web-calendar' ),
						help: __( 'Paste the iCal (.ics) feed URL to display (required). Treat it like a password.', 'shootcal-web-calendar' ),
						type: 'url',
						value: urlValue,
						onChange: function ( v ) {
							setAttributes( { url: ( v || '' ).trim() } );
						}
					} ),
					( modeValue === 'availability' ) ? el( ToggleControl, {
						label: __( 'I can take more than one booking per day', 'shootcal-web-calendar' ),
						help: __( 'On: a day with timed sessions (and no all-day event) shows as "Limited" - partly booked, still open. Off: the first booking marks the whole day "Booked".', 'shootcal-web-calendar' ),
						checked: multiSessionDayValue,
						onChange: function ( v ) {
							setAttributes( { multiSessionDay: !! v } );
						}
					} ) : null
				),
				colorPanel,
				el( PanelBody, { title: __( 'Calendar overrides', 'shootcal-web-calendar' ), initialOpen: false },
					el( TextControl, {
						label: __( 'Months to show', 'shootcal-web-calendar' ),
						help: __( 'Leave blank to use the plugin default. ShootCal sources auto-detect from the feed.', 'shootcal-web-calendar' ),
						type: 'number',
						min: 1,
						max: 36,
						value: monthsValue,
						onChange: function ( v ) {
							var n = parseInt( v, 10 );
							setAttributes( { months: isNaN( n ) || n <= 0 ? undefined : n } );
						}
					} ),
					el( SelectControl, {
						label: __( 'First day of week', 'shootcal-web-calendar' ),
						value: firstDayValue,
						options: [
							{ label: __( 'Sunday', 'shootcal-web-calendar' ), value: '0' },
							{ label: __( 'Monday', 'shootcal-web-calendar' ), value: '1' }
						],
						onChange: function ( v ) {
							setAttributes( { firstDay: ( v === '1' ) ? 1 : 0 } );
						}
					} ),
					el( TextControl, {
						label: __( 'Timezone override', 'shootcal-web-calendar' ),
						help: __( 'IANA identifier, e.g. America/New_York. Leave blank to auto-detect from the feed.', 'shootcal-web-calendar' ),
						value: timezoneValue,
						onChange: function ( v ) {
							setAttributes( { timezone: ( v || '' ).trim() } );
						}
					} )
				)
			);

			// Override summary shown under the URL field.
			var summaryBits = [];
			summaryBits.push( ( modeValue === 'full' ) ? __( 'Mode: Full calendar', 'shootcal-web-calendar' ) : __( 'Mode: Availability', 'shootcal-web-calendar' ) );
			summaryBits.push( urlValue ? __( 'Feed: custom URL', 'shootcal-web-calendar' ) : __( 'Feed: Settings URL', 'shootcal-web-calendar' ) );
			summaryBits.push( monthsValue ? __( 'Months: ', 'shootcal-web-calendar' ) + monthsValue : __( 'Months: default', 'shootcal-web-calendar' ) );
			summaryBits.push( ( firstDayValue === '1' ) ? __( 'Week starts Monday', 'shootcal-web-calendar' ) : __( 'Week starts Sunday', 'shootcal-web-calendar' ) );
			summaryBits.push( timezoneValue ? __( 'Timezone: ', 'shootcal-web-calendar' ) + timezoneValue : __( 'Timezone: auto', 'shootcal-web-calendar' ) );

			// Static placeholder. The live calendar only renders on the published
			// page; the calendar source is configured once under Settings.
			var preview = el( 'div', blockProps,
				el( Placeholder, {
					icon: 'calendar-alt',
					label: __( 'ShootCal Web Calendar', 'shootcal-web-calendar' ),
					instructions: __( 'Your calendar renders here on the published page. Pick a display mode and optionally paste a feed URL in the block settings on the right, or leave the URL blank to use the one under Settings > ShootCal Web Calendar.', 'shootcal-web-calendar' )
				},
					el( 'p', { style: { margin: 0, fontSize: '12px', color: '#646970' } }, summaryBits.join( '  •  ' ) )
				)
			);

			return el( Fragment, null, sidebar, preview );
		},

		// Server-rendered: save() returns null and the render callback emits HTML on the frontend.
		save: function () {
			return null;
		}
	} );
} )( window.wp );

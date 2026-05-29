/* ShootCal Availability - block editor script (vanilla JS, no build).
 *
 * Registers the `shootcal-availability/calendar` block. The editor view shows a
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
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var Placeholder       = wp.components.Placeholder;
	var TextControl       = wp.components.TextControl;
	var SelectControl     = wp.components.SelectControl;
	var __                = wp.i18n.__;

	registerBlockType( 'shootcal-availability/calendar', {
		edit: function ( props ) {
			var attributes    = props.attributes;
			var setAttributes = props.setAttributes;
			var blockProps    = useBlockProps();

			var monthsValue = ( attributes.months !== undefined && attributes.months !== null )
				? String( attributes.months )
				: '';
			var firstDayValue = ( attributes.firstDay === 1 ) ? '1' : '0';
			var timezoneValue = attributes.timezone || '';

			var sidebar = el( InspectorControls, null,
				el( PanelBody, { title: __( 'Calendar overrides', 'shootcal-availability' ), initialOpen: false },
					el( TextControl, {
						label: __( 'Months to show', 'shootcal-availability' ),
						help: __( 'Leave blank to use the plugin default. ShootCal sources auto-detect from the feed.', 'shootcal-availability' ),
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
						label: __( 'First day of week', 'shootcal-availability' ),
						value: firstDayValue,
						options: [
							{ label: __( 'Sunday', 'shootcal-availability' ), value: '0' },
							{ label: __( 'Monday', 'shootcal-availability' ), value: '1' }
						],
						onChange: function ( v ) {
							setAttributes( { firstDay: ( v === '1' ) ? 1 : 0 } );
						}
					} ),
					el( TextControl, {
						label: __( 'Timezone override', 'shootcal-availability' ),
						help: __( 'IANA identifier, e.g. America/New_York. Leave blank to auto-detect from the feed.', 'shootcal-availability' ),
						value: timezoneValue,
						onChange: function ( v ) {
							setAttributes( { timezone: ( v || '' ).trim() } );
						}
					} )
				)
			);

			// Override summary shown under the URL field.
			var summaryBits = [];
			summaryBits.push( monthsValue ? __( 'Months: ', 'shootcal-availability' ) + monthsValue : __( 'Months: default', 'shootcal-availability' ) );
			summaryBits.push( ( firstDayValue === '1' ) ? __( 'Week starts Monday', 'shootcal-availability' ) : __( 'Week starts Sunday', 'shootcal-availability' ) );
			summaryBits.push( timezoneValue ? __( 'Timezone: ', 'shootcal-availability' ) + timezoneValue : __( 'Timezone: auto', 'shootcal-availability' ) );

			// Static placeholder. The live calendar only renders on the published
			// page; the calendar source is configured once under Settings.
			var preview = el( 'div', blockProps,
				el( Placeholder, {
					icon: 'calendar-alt',
					label: __( 'ShootCal Availability', 'shootcal-availability' ),
					instructions: __( 'Your availability calendar renders here on the published page. Set the calendar URL once under Settings > ShootCal Availability. Use the block settings on the right to override months, first day, or timezone for this embed.', 'shootcal-availability' )
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

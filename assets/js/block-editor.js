/* ShootCal Availability - block editor script (vanilla JS, no build).
 *
 * Registers the `shootcal-availability/calendar` block as a server-rendered
 * block. The edit view uses wp.serverSideRender so the editor preview is the
 * actual rendered calendar (same code path as the frontend), not a placeholder.
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
	var TextControl       = wp.components.TextControl;
	var SelectControl     = wp.components.SelectControl;
	var ServerSideRender  = wp.serverSideRender;
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

			var preview = el( 'div', blockProps,
				el( ServerSideRender, {
					block: 'shootcal-availability/calendar',
					attributes: attributes,
					EmptyResponsePlaceholder: function () {
						return el( 'div', { style: { padding: '1rem', border: '1px dashed #c3c4c7', borderRadius: '6px', color: '#646970' } },
							__( 'No availability to display yet. Configure your calendar source under Settings > ShootCal Availability.', 'shootcal-availability' )
						);
					},
					ErrorResponsePlaceholder: function ( props ) {
						return el( 'div', { style: { padding: '1rem', border: '1px solid #d63638', borderRadius: '6px', color: '#7f1d1d', background: '#fff5f5' } },
							el( 'strong', null, __( 'ShootCal Availability:', 'shootcal-availability' ) ),
							' ',
							( props && props.response && props.response.errorMsg ) || __( 'Could not render the calendar preview.', 'shootcal-availability' )
						);
					}
				} )
			);

			return el( Fragment, null, sidebar, preview );
		},

		// Server-rendered: save() returns null and the render callback emits HTML on the frontend.
		save: function () {
			return null;
		}
	} );
} )( window.wp );

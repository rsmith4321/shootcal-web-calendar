/* ShootCal Availability - admin settings page interactions */
( function ( $ ) {
	'use strict';

	function applySourceVisibility() {
		var source = $( 'input[name$="[source]"]:checked' ).val() || 'google';
		// URL fields
		$( '.shootcal-availability__row--google' ).toggle( source === 'google' );
		$( '.shootcal-availability__row--shootcal' ).toggle( source === 'shootcal' );
		// Display-section rows scoped by source (months_ahead vs. its ShootCal hint)
		$( '.shootcal-availability__row--google-only' ).toggle( source === 'google' );
		$( '.shootcal-availability__row--shootcal-only' ).toggle( source === 'shootcal' );
	}

	$( document ).on( 'change', 'input[name$="[source]"]', applySourceVisibility );

	$( document ).on( 'click', 'button[data-shootcal-test]', function ( e ) {
		e.preventDefault();
		var $btn       = $( this );
		var source     = $btn.data( 'shootcalTest' );
		var inputId    = source === 'shootcal' ? '#shootcal_feed_url' : '#ical_url';
		var url        = ( $( inputId ).val() || '' ).trim();
		var $row       = $btn.closest( 'p' );
		var $spinner   = $row.find( '.spinner' );
		var $result    = $row.find( '.shootcal-availability__test-result' );

		$result.removeClass( 'is-success is-error' ).text( '' );

		if ( ! url ) {
			$result.addClass( 'is-error' ).text( ShootCalAvailability.i18n.enterUrl );
			return;
		}

		$btn.prop( 'disabled', true );
		$spinner.css( 'visibility', 'visible' );

		$.post( ShootCalAvailability.ajaxUrl, {
			action: ShootCalAvailability.action,
			nonce:  ShootCalAvailability.nonce,
			url:    url
		} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				$result.addClass( 'is-success' ).text( resp.data.message );
			} else {
				var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message : ShootCalAvailability.i18n.networkError;
				$result.addClass( 'is-error' ).text( msg );
			}
		} ).fail( function () {
			$result.addClass( 'is-error' ).text( ShootCalAvailability.i18n.networkError );
		} ).always( function () {
			$btn.prop( 'disabled', false );
			$spinner.css( 'visibility', 'hidden' );
		} );
	} );

	$( applySourceVisibility );
} )( jQuery );

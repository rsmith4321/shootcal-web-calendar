/* ShootCal Availability - admin settings page interactions */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '#shootcal-test-connection', function ( e ) {
		e.preventDefault();
		var $btn     = $( this );
		var url      = ( $( '#calendar_url' ).val() || '' ).trim();
		var $row     = $btn.closest( 'p' );
		var $spinner = $row.find( '.spinner' );
		var $result  = $row.find( '.shootcal-availability__test-result' );

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
} )( jQuery );

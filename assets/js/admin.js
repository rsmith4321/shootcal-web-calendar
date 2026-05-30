/* ShootCal Web Calendar - admin settings page: shortcode generator.
 * Paste a feed URL, pick a mode, "Check feed & generate" validates the feed via
 * the same wp_safe_remote_get path the front end uses, then builds a copy-paste
 * shortcode. The shortcode tag string is renamed in lockstep with the slug.
 */
( function ( $ ) {
	'use strict';

	var C = window.ShootCalWebCalendar || { i18n: {} };

	function currentInputs() {
		var url    = ( $( '#shootcal-gen-url' ).val() || '' ).trim();
		var mode   = $( '#shootcal-gen-mode' ).val() || 'availability';
		var months = parseInt( $( '#shootcal-gen-months' ).val(), 10 );
		months = ( isNaN( months ) || months < 1 ) ? '' : Math.min( 36, months );
		var msd = $( '#shootcal-gen-msd' ).length ? $( '#shootcal-gen-msd' ).is( ':checked' ) : true;
		return { url: url, mode: mode, months: months, msd: msd };
	}

	function buildShortcode( v ) {
		var sc = '[shootcal_web_calendar';
		if ( 'full' === v.mode ) { sc += ' mode="full"'; }
		sc += ' url="' + v.url + '"';
		if ( v.months ) { sc += ' months="' + v.months + '"'; }
		// Sessions-per-day applies to availability mode only; default is on, so emit
		// it only when the user turned it off.
		if ( 'availability' === v.mode && ! v.msd ) { sc += ' multi_session_day="0"'; }
		sc += ']';
		return sc;
	}

	$( document ).on( 'click', '#shootcal-generate', function ( e ) {
		e.preventDefault();
		var $btn     = $( this );
		var v        = currentInputs();
		var $row     = $btn.closest( 'p' );
		var $spinner = $row.find( '.spinner' );
		var $result  = $row.find( '.shootcal-web-calendar__gen-result' );
		var $output  = $( '#shootcal-gen-output' );

		$result.removeClass( 'is-success is-error' ).text( '' );
		if ( ! v.url ) {
			$result.addClass( 'is-error' ).text( C.i18n.enterUrl );
			return;
		}

		$btn.prop( 'disabled', true );
		$spinner.css( 'visibility', 'visible' );

		$.post( C.ajaxUrl, { action: C.action, nonce: C.nonce, url: v.url } )
			.done( function ( resp ) {
				if ( resp && resp.success ) {
					var msg = resp.data.message || '';
					if ( resp.data.has_titles && 'full' !== v.mode ) {
						msg += '  ' + C.i18n.fullHint;
					}
					$result.addClass( 'is-success' ).text( msg );
					$( '#shootcal-gen-shortcode' ).val( buildShortcode( v ) );
					$output.show();
				} else {
					var em = ( resp && resp.data && resp.data.message ) ? resp.data.message : C.i18n.networkError;
					$result.addClass( 'is-error' ).text( em );
					$output.hide();
				}
			} )
			.fail( function () {
				$result.addClass( 'is-error' ).text( C.i18n.networkError );
				$output.hide();
			} )
			.always( function () {
				$btn.prop( 'disabled', false );
				$spinner.css( 'visibility', 'hidden' );
			} );
	} );

	// Keep the generated shortcode in sync if the user tweaks inputs afterward.
	$( document ).on( 'change input', '#shootcal-gen-mode, #shootcal-gen-months, #shootcal-gen-url, #shootcal-gen-msd', function () {
		var $output = $( '#shootcal-gen-output' );
		if ( ! $output.is( ':visible' ) ) { return; }
		var v = currentInputs();
		if ( v.url ) { $( '#shootcal-gen-shortcode' ).val( buildShortcode( v ) ); }
	} );

	$( document ).on( 'click', '#shootcal-gen-copy', function ( e ) {
		e.preventDefault();
		var field = document.getElementById( 'shootcal-gen-shortcode' );
		if ( ! field ) { return; }
		field.select();
		try { document.execCommand( 'copy' ); } catch ( err ) {}
		if ( window.navigator && window.navigator.clipboard ) {
			window.navigator.clipboard.writeText( field.value ).catch( function () {} );
		}
		$( this ).text( C.i18n.copied );
	} );
} )( jQuery );

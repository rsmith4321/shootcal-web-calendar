/* ShootCal settings: normalize references and generate a safe shortcode. */
( function ( $ ) {
	'use strict';
	var C = window.ShootCalWebCalendar || { i18n: {} };
	var Reference = window.ShootCalEmbedReference;
	var importedQuery = {}, importedToken = '', requestSerial = 0;
	function hosted() { return $( '#shootcal-gen-source' ).val() !== 'ical'; }
	function quote( value ) {
		return String( value ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' ).replace( /\[/g, '&#91;' ).replace( /\]/g, '&#93;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}
	function syncRows() {
		var isHosted = hosted(), calendar = $( '#shootcal-gen-view' ).val() === 'calendar';
		$( '.shootcal-gen-hosted-row' ).toggle( isHosted );
		$( '.shootcal-gen-ical-row' ).toggle( ! isHosted );
		$( '.shootcal-gen-months-row' ).toggle( ! isHosted || calendar );
		$( '.shootcal-gen-availability-row' ).toggle( ! isHosted && $( '#shootcal-gen-mode' ).val() !== 'full' );
		$( '#shootcal-generate' ).text( isHosted ? C.i18n.generate : C.i18n.checkGenerate );
	}
	function normalizeReference() {
		var field = $( '#shootcal-gen-calendar-id' ), parsed = Reference.parse( field.val() || '' );
		if ( ! parsed ) return;
		if ( field.val().trim() !== importedToken ) {
			importedQuery = parsed.query;
			importedToken = parsed.token;
			$( '#shootcal-gen-view' ).val( parsed.query.view || 'default' );
			$( '#shootcal-gen-months' ).val( parsed.query.months || '' );
		}
		field.val( parsed.token );
		syncRows();
	}
	function inputs() {
		var months = parseInt( $( '#shootcal-gen-months' ).val(), 10 );
		months = isNaN( months ) || months < 1 ? '' : String( Math.min( 36, months ) );
		if ( hosted() ) {
			var value = $( '#shootcal-gen-calendar-id' ).val() || '', parsed = Reference.parse( value );
			if ( ! parsed ) return null;
			var query = Object.assign( {}, value.trim() === importedToken ? importedQuery : parsed.query );
			var view = $( '#shootcal-gen-view' ).val();
			if ( view === 'calendar' ) { query.view = 'calendar'; }
			else { delete query.view; }
			if ( months ) query.months = months; else delete query.months;
			return { calendarId: parsed.token, query: Reference.cleanQuery( query ) };
		}
		var url = ( $( '#shootcal-gen-url' ).val() || '' ).trim();
		try { var parsedUrl = new URL( url ); if ( ! /^https?:$/.test( parsedUrl.protocol ) || parsedUrl.username || parsedUrl.password || /[\s<>"'\\]/.test( url ) ) return null; } catch ( e ) { return null; }
		return { url: url, mode: $( '#shootcal-gen-mode' ).val() || 'availability', months: months, msd: $( '#shootcal-gen-msd' ).is( ':checked' ), limited: $( '#shootcal-gen-limited-color' ).val(), booked: $( '#shootcal-gen-booked-color' ).val() };
	}
	function build( values ) {
		var attrs = values.calendarId ? { calendar_id: values.calendarId } : { source: 'ical', url: values.url };
		if ( values.calendarId ) Object.assign( attrs, values.query );
		else {
			if ( values.mode === 'full' ) attrs.mode = 'full';
			if ( values.months ) attrs.months = values.months;
			if ( values.mode !== 'full' ) {
				if ( ! values.msd ) attrs.multi_session_day = '0';
				if ( values.limited && values.limited !== '#fce3a8' ) attrs.limited_color = values.limited;
				if ( values.booked && values.booked !== '#f6b9a3' ) attrs.booked_color = values.booked;
			}
		}
		return '[shootcal_web_calendar' + Object.keys( attrs ).map( function ( key ) { return ' ' + key + '="' + quote( attrs[ key ] ) + '"'; } ).join( '' ) + ']';
	}
	$( document ).on( 'change', '#shootcal-gen-calendar-id', normalizeReference );
	$( document ).on( 'change input', '#shootcal-gen-source, #shootcal-gen-calendar-id, #shootcal-gen-url, #shootcal-gen-view, #shootcal-gen-mode, #shootcal-gen-months, #shootcal-gen-msd, #shootcal-gen-limited-color, #shootcal-gen-booked-color', function () {
		++requestSerial;
		syncRows();
		$( '#shootcal-gen-output' ).hide();
		$( '.shootcal-web-calendar__gen-result' ).removeClass( 'is-success is-error' ).text( '' );
		$( '#shootcal-gen-copy' ).text( C.i18n.copy );
	} );
	$( document ).on( 'click', '#shootcal-generate', function ( event ) {
		event.preventDefault();
		if ( hosted() ) normalizeReference();
		var values = inputs(), serial = ++requestSerial;
		var result = $( '.shootcal-web-calendar__gen-result' ), output = $( '#shootcal-gen-output' );
		result.removeClass( 'is-success is-error' ).text( '' ); output.hide();
		if ( ! values ) { result.addClass( 'is-error' ).text( hosted() ? C.i18n.invalidId : C.i18n.enterUrl ); return; }
		function show( message ) { result.addClass( 'is-success' ).text( message ); $( '#shootcal-gen-shortcode' ).val( build( values ) ); output.show(); }
		if ( values.calendarId ) { show( C.i18n.shootcalEmbed ); return; }
		var button = $( this ), spinner = button.closest( 'p' ).find( '.spinner' );
		button.prop( 'disabled', true ); spinner.css( 'visibility', 'visible' );
		$.post( C.ajaxUrl, { action: C.action, nonce: C.nonce, url: values.url } ).done( function ( response ) {
			if ( serial !== requestSerial ) return;
			if ( response && response.success ) {
				var message = response.data.message || '';
				if ( response.data.has_titles && values.mode !== 'full' ) message += ' ' + C.i18n.fullHint;
				show( message );
			} else result.addClass( 'is-error' ).text( response && response.data && response.data.message || C.i18n.networkError );
		} ).fail( function () { if ( serial === requestSerial ) result.addClass( 'is-error' ).text( C.i18n.networkError ); } ).always( function () { button.prop( 'disabled', false ); spinner.css( 'visibility', 'hidden' ); } );
	} );
	$( document ).on( 'click', '#shootcal-gen-copy', function ( event ) {
		event.preventDefault();
		var field = document.getElementById( 'shootcal-gen-shortcode' ), button = $( this );
		if ( ! field ) return;
		function fallback() { field.select(); try { if ( document.execCommand( 'copy' ) ) button.text( C.i18n.copied ); } catch ( e ) {} }
		if ( navigator.clipboard ) navigator.clipboard.writeText( field.value ).then( function () { button.text( C.i18n.copied ); }, fallback );
		else fallback();
	} );
	$( syncRows );
} )( jQuery );

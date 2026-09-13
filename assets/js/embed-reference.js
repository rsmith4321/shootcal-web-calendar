/* Shared ShootCal reference parser. Reads attributes; never executes pasted HTML. */
( function ( root ) {
	'use strict';
	var TOKEN = /^[A-Za-z0-9_-]{8,128}$/;
	var PRESENTATION = [ 'months', 'mode', 'first_day', 'view', 'theme', 'sc_card', 'sc_ink', 'sc_soft', 'sc_line', 'sc_accent', 'sc_font' ];
	function decode( value ) {
		return value.replace( /&(?:amp|quot|apos|lt|gt|#\d+|#x[0-9a-f]+);/gi, function ( entity ) {
			var key = entity.slice( 1, -1 ).toLowerCase();
			var named = { amp: '&', quot: '"', apos: "'", lt: '<', gt: '>' };
			if ( named[ key ] ) return named[ key ];
			var number = key.charAt( 1 ) === 'x' ? parseInt( key.slice( 2 ), 16 ) : parseInt( key.slice( 1 ), 10 );
			return number > 0 && number <= 0x10ffff ? String.fromCodePoint( number ) : '';
		} );
	}
	function cleanQuery( values ) {
		var out = {};
		PRESENTATION.forEach( function ( key ) {
			var value = values[ key ];
			if ( typeof value !== 'string' && typeof value !== 'number' ) return;
			value = String( value );
			if ( key === 'months' && /^\d+$/.test( value ) && +value >= 1 ) out[ key ] = String( Math.min( 36, +value ) );
			if ( key === 'mode' && /^(availability|full)$/.test( value ) ) out[ key ] = value;
			if ( key === 'first_day' && /^(0|1)$/.test( value ) ) out[ key ] = value;
			if ( key === 'view' && value === 'calendar' ) out[ key ] = value;
			if ( key === 'theme' && /^(light|dark)$/.test( value ) ) out[ key ] = value;
			if ( key.indexOf( 'sc_' ) === 0 && /^[0-9a-zA-Z]{1,10}$/.test( value ) ) out[ key ] = value;
		} );
		return out;
	}
	function parseUrl( input ) {
		// Require an ordinary absolute URL before asking the browser to parse it.
		if ( ! /^https?:\/\/(?:api\.shootcal\.com|feed\.shootcal\.com)\/[^\s\\]+$/i.test( input ) ) return null;
		var url;
		try { url = new URL( input ); } catch ( e ) { return null; }
		if ( url.username || url.password || url.port || url.hash ) return null;
		var match = url.hostname === 'api.shootcal.com' ? url.pathname.match( /^\/embed\/([A-Za-z0-9_-]{8,128})$/ )
			: url.hostname === 'feed.shootcal.com' ? url.pathname.match( /^\/([A-Za-z0-9_-]{8,128})\.ics$/ ) : null;
		if ( ! match ) return null;
		var query = {};
		url.searchParams.forEach( function ( value, key ) { query[ key ] = value; } );
		return { token: match[ 1 ], query: url.hostname === 'feed.shootcal.com' ? {} : cleanQuery( query ) };
	}
	function attributes( tag ) {
		var result = {}, match, pattern = /\s([a-z][a-z0-9_-]*)\s*=\s*(["'])(.*?)\2/gi;
		while ( ( match = pattern.exec( tag ) ) ) {
			var key = match[ 1 ].toLowerCase();
			if ( Object.prototype.hasOwnProperty.call( result, key ) ) return null;
			result[ key ] = decode( match[ 3 ] );
		}
		return result;
	}
	function parse( input ) {
		if ( typeof input !== 'string' || input.length > 16384 ) return null;
		input = input.trim();
		if ( TOKEN.test( input ) ) return { token: input, query: {} };
		var tag = input.match( /^<(iframe|script)\b[^>]*>/i );
		if ( ! tag ) return parseUrl( decode( input ) );
		var attrs = attributes( tag[ 0 ] );
		if ( ! attrs ) return null;
		if ( tag[ 1 ].toLowerCase() === 'iframe' ) return parseUrl( attrs.src || '' );
		if ( attrs.src !== 'https://api.shootcal.com/embed.js' ) return null;
		if ( attrs['data-src'] ) return parseUrl( attrs['data-src'] );
		return TOKEN.test( attrs['data-shootcal'] || '' ) ? { token: attrs['data-shootcal'], query: {} } : null;
	}
	var api = { parse: parse, cleanQuery: cleanQuery, isToken: function ( value ) { return typeof value === 'string' && TOKEN.test( value ); } };
	root.ShootCalEmbedReference = api;
	if ( typeof module === 'object' && module.exports ) module.exports = api;
} )( typeof window !== 'undefined' ? window : globalThis );

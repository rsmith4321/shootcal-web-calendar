/* ShootCal hosted frames: resize each instance independently, without inline scripts. */
( function () {
	'use strict';
	window.addEventListener( 'message', function ( event ) {
		if ( event.origin !== 'https://api.shootcal.com' ) return;
		var data = event.data;
		if ( ! data || ! data.shootcalEmbed ) return;
		var frames = document.querySelectorAll( 'iframe[data-shootcal-embed]' );
		for ( var i = 0; i < frames.length; i++ ) {
			var frame = frames[ i ];
			if ( event.source !== frame.contentWindow ) continue;
			if ( typeof data.height === 'number' && Number.isFinite( data.height ) && data.height > 0 && data.height <= 20000 ) {
				frame.style.height = Math.ceil( data.height ) + 'px';
			}
			if ( data.scrollToTop === true ) {
				var top = frame.getBoundingClientRect().top;
				if ( top < 0 ) window.scrollBy( { top: top - 16, behavior: 'auto' } );
			}
			return;
		}
	} );
} )();

/* ShootCal Web Calendar - paginated calendar navigation (Today / < / > / Month Year).
 *
 * Vanilla JS, no dependencies. All month panels are rendered server-side; this
 * script only changes which one is visible based on user input. With JS off,
 * the server-rendered "active" panel stays visible and the toolbar buttons
 * just do nothing (degraded but readable). The no-JS CSS fallback also
 * surfaces all panels at once for screen readers etc. when JS fails to run.
 */
( function () {
	'use strict';

	function activate( wrap, idx ) {
		var total = parseInt( wrap.dataset.shootcalTotal, 10 ) || 0;
		if ( idx < 0 ) idx = 0;
		if ( idx >= total ) idx = total - 1;
		wrap.dataset.shootcalActive = String( idx );

		// Show only the matching panel.
		var panels = wrap.querySelectorAll( '.shootcal-web-calendar__month-panel' );
		var monthName = '';
		var monthYear = '';
		var labelTextFallback = '';
		panels.forEach( function ( p ) {
			var match = parseInt( p.dataset.shootcalMonthIdx, 10 ) === idx;
			p.classList.toggle( 'is-active', match );
			if ( match ) {
				p.removeAttribute( 'hidden' );
				monthName = p.dataset.shootcalMonthName || '';
				monthYear = p.dataset.shootcalMonthYear || '';
				labelTextFallback = p.dataset.shootcalMonthLabel || '';
			} else {
				p.setAttribute( 'hidden', '' );
			}
		} );

		// Update the visible month label in the toolbar - keep the year styled
		// in the signature sunset accent via the dedicated span.
		var label = wrap.querySelector( '[data-shootcal-month-label]' );
		if ( label ) {
			if ( monthName && monthYear ) {
				// Rebuild label HTML. Both pieces are escaped server-side; here
				// they came through the DOM dataset which doesn't include markup.
				label.textContent = ''; // clear
				label.appendChild( document.createTextNode( monthName + ' ' ) );
				var yearSpan = document.createElement( 'span' );
				yearSpan.className = 'shootcal-web-calendar__nav-year';
				yearSpan.textContent = monthYear;
				label.appendChild( yearSpan );
			} else if ( labelTextFallback ) {
				label.textContent = labelTextFallback;
			}
		}

		// Enable/disable nav buttons at the boundaries + Today.
		var prev = wrap.querySelector( '.shootcal-web-calendar__btn--prev' );
		var next = wrap.querySelector( '.shootcal-web-calendar__btn--next' );
		var todayBtn = wrap.querySelector( '.shootcal-web-calendar__btn--today' );
		if ( prev ) prev.disabled = idx <= 0;
		if ( next ) next.disabled = idx >= total - 1;
		if ( todayBtn ) {
			var todayIdx = parseInt( wrap.dataset.shootcalToday, 10 );
			todayBtn.disabled = ( todayIdx === -1 ) || ( todayIdx === idx );
		}

		// Any popover the user had open belonged to the now-hidden panel.
		// Close everything so it doesn't visually re-appear when they navigate
		// back to that month. Also wipe inline edge-nudge transforms so the
		// next open of that cell re-measures fresh.
		wrap.querySelectorAll( '.shootcal-web-calendar__day.is-open, .shootcal-web-calendar__day.is-open-above' ).forEach( function ( c ) {
			c.classList.remove( 'is-open' );
			c.classList.remove( 'is-open-above' );
			var pop = c.querySelector( '.shootcal-web-calendar__bookings' );
			if ( pop ) pop.style.transform = '';
		} );
	}

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest && e.target.closest( '[data-shootcal-action]' );
		if ( ! btn ) return;
		var wrap = btn.closest( '.shootcal-web-calendar__wrap--paginated' );
		if ( ! wrap ) return;

		var current = parseInt( wrap.dataset.shootcalActive, 10 ) || 0;
		var todayIdx = parseInt( wrap.dataset.shootcalToday, 10 );
		var action = btn.dataset.shootcalAction;

		if ( action === 'prev' ) {
			activate( wrap, current - 1 );
		} else if ( action === 'next' ) {
			activate( wrap, current + 1 );
		} else if ( action === 'today' && todayIdx >= 0 ) {
			activate( wrap, todayIdx );
		}
	} );

	// Keyboard nav when focus is anywhere inside the calendar wrap.
	document.addEventListener( 'keydown', function ( e ) {
		var wrap = e.target.closest && e.target.closest( '.shootcal-web-calendar__wrap--paginated' );
		if ( ! wrap ) return;
		// Don't hijack typing in form controls (currently none in our markup, but be polite).
		if ( e.target.matches( 'input, textarea, select' ) ) return;

		var current = parseInt( wrap.dataset.shootcalActive, 10 ) || 0;
		var todayIdx = parseInt( wrap.dataset.shootcalToday, 10 );

		if ( e.key === 'ArrowLeft' ) {
			e.preventDefault();
			activate( wrap, current - 1 );
		} else if ( e.key === 'ArrowRight' ) {
			e.preventDefault();
			activate( wrap, current + 1 );
		} else if ( ( e.key === 't' || e.key === 'T' ) && todayIdx >= 0 ) {
			e.preventDefault();
			activate( wrap, todayIdx );
		}
	} );

	/* ---- Cell popover (mobile primarily) ----
	 *
	 * On a narrow viewport the bookings list inside each Limited cell is hidden
	 * via CSS; users tap a cell to pop it open as a small card. Tap elsewhere,
	 * tap the cell again, or press Esc to close. The class toggle runs at every
	 * viewport (zero cost on desktop, CSS just doesn't reveal a popover there),
	 * so there's no JS branching on width.
	 *
	 * Only cells with bookings (`.is-limited` plus a non-empty bookings list)
	 * are interactive. Booked-all-day cells already say it all via the chip.
	 */
	function closeAllPopovers() {
		document.querySelectorAll( '.shootcal-web-calendar__day.is-open, .shootcal-web-calendar__day.is-open-above' ).forEach( function ( c ) {
			c.classList.remove( 'is-open' );
			c.classList.remove( 'is-open-above' );
			clearPopoverHorizontal( c );
		} );
	}

	/**
	 * The popover is allowed (via CSS) to grow wider than the cell so booking
	 * times don't wrap. CSS centers it under the cell with translateX(-50%);
	 * here we measure after open and shift the transform horizontally if it
	 * would spill past the calendar card's left/right edge. Result: edge cells
	 * get a popover that aligns to the card edge instead of overflowing.
	 */
	function adjustPopoverHorizontal( cell ) {
		var wrap = cell.closest( '.shootcal-web-calendar__wrap' );
		var popover = cell.querySelector( '.shootcal-web-calendar__bookings' );
		if ( ! wrap || ! popover ) return;

		// Reset any prior nudge so we measure the freshly-centered position.
		popover.style.transform = '';

		var wrapRect = wrap.getBoundingClientRect();
		var popRect  = popover.getBoundingClientRect();
		var GAP      = 6; // px breathing room at the calendar edge

		var leftOverflow  = ( wrapRect.left + GAP ) - popRect.left;
		var rightOverflow = popRect.right - ( wrapRect.right - GAP );

		if ( leftOverflow > 0 ) {
			popover.style.transform = 'translateX(calc(-50% + ' + leftOverflow + 'px))';
		} else if ( rightOverflow > 0 ) {
			popover.style.transform = 'translateX(calc(-50% - ' + rightOverflow + 'px))';
		}
	}

	function clearPopoverHorizontal( cell ) {
		var popover = cell.querySelector( '.shootcal-web-calendar__bookings' );
		if ( popover ) popover.style.transform = '';
	}

	document.addEventListener( 'click', function ( e ) {
		var cell = e.target.closest && e.target.closest( '.shootcal-web-calendar__day' );

		// Tap outside any cell - close everything.
		if ( ! cell ) {
			closeAllPopovers();
			return;
		}

		// Different cell tapped - close any previous before considering this one.
		var openCell = document.querySelector( '.shootcal-web-calendar__day.is-open' );
		if ( openCell && openCell !== cell ) {
			openCell.classList.remove( 'is-open' );
			openCell.classList.remove( 'is-open-above' );
			clearPopoverHorizontal( openCell );
		}

		// Only Limited cells with bookings are interactive.
		if ( cell.classList.contains( 'is-limited' ) ) {
			if ( cell.querySelector( '.shootcal-web-calendar__bookings' ) ) {
				var willOpen = ! cell.classList.contains( 'is-open' );
				cell.classList.toggle( 'is-open' );
				if ( willOpen ) {
					// Always open downward. On the last row the popover simply
					// overflows below the calendar card (the wrap is
					// overflow:visible), which reads better than flipping up and
					// covering the events in the row above.
					cell.classList.remove( 'is-open-above' );
					// Now that the popover is visible, measure + nudge if it
					// would overflow the card horizontally.
					adjustPopoverHorizontal( cell );
				} else {
					cell.classList.remove( 'is-open-above' );
					clearPopoverHorizontal( cell );
				}
			}
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			closeAllPopovers();
		}
	} );

	/* --- AJAX hydration (only used when "Page caching" mode is on) ---
	 * The page ships a lightweight placeholder so it stays fully page-cacheable;
	 * here we fetch the freshly-rendered calendar from admin-ajax (which the page
	 * cache doesn't store) and swap it in. The delegated click/keydown handlers
	 * above then work on the injected markup without any re-binding. */
	function hydrateLazy() {
		if ( typeof window.ShootCalWebCalendarFront === 'undefined' ) return;
		var boxes = document.querySelectorAll( '.shootcal-web-calendar__lazy[data-shootcal-lazy]' );
		boxes.forEach( function ( box ) {
			var body = new URLSearchParams();
			body.set( 'action', 'shootcal_web_calendar_render' );
			if ( box.dataset.shootcalUrl ) body.set( 'url', box.dataset.shootcalUrl );
			if ( box.dataset.shootcalMode ) body.set( 'mode', box.dataset.shootcalMode );
			if ( box.dataset.shootcalMonths ) body.set( 'months', box.dataset.shootcalMonths );
			if ( typeof box.dataset.shootcalFirstDay !== 'undefined' ) body.set( 'first_day', box.dataset.shootcalFirstDay );
			if ( box.dataset.shootcalTimezone ) body.set( 'timezone', box.dataset.shootcalTimezone );
			if ( box.dataset.shootcalMsd ) body.set( 'msd', box.dataset.shootcalMsd );
			if ( box.dataset.shootcalLimitedColor ) body.set( 'limited_color', box.dataset.shootcalLimitedColor );
			if ( box.dataset.shootcalBookedColor ) body.set( 'booked_color', box.dataset.shootcalBookedColor );
			if ( box.dataset.shootcalSig ) body.set( 'sig', box.dataset.shootcalSig );

			fetch( window.ShootCalWebCalendarFront.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
				credentials: 'same-origin'
			} ).then( function ( r ) {
				return r.ok ? r.text() : '';
			} ).then( function ( html ) {
				if ( html ) {
					box.outerHTML = html;
				} else {
					box.removeAttribute( 'data-shootcal-lazy' );
				}
			} ).catch( function () {
				box.removeAttribute( 'data-shootcal-lazy' );
			} );
		} );
	}

	if ( document.readyState !== 'loading' ) {
		hydrateLazy();
	} else {
		document.addEventListener( 'DOMContentLoaded', hydrateLazy );
	}
} )();

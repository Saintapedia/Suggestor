/*!
 * SaintapediaSuggest dashboard helpers.
 *
 * Progressive enhancement only — every action on the dashboard is a plain
 * form submit that works with scripting disabled. This file adds the
 * select-all checkbox and a confirmation before a bulk change.
 */
( function () {
	'use strict';

	function init() {
		var selectAll = document.getElementById( 'sps-select-all' );
		var boxes = Array.prototype.slice.call(
			document.querySelectorAll( '.sps-row-check' )
		);

		if ( selectAll && boxes.length ) {
			selectAll.addEventListener( 'change', function () {
				boxes.forEach( function ( box ) {
					box.checked = selectAll.checked;
				} );
			} );

			boxes.forEach( function ( box ) {
				box.addEventListener( 'change', function () {
					selectAll.checked = boxes.every( function ( b ) {
						return b.checked;
					} );
				} );
			} );
		}

		// A bulk action can touch up to a full page of suggestions, so make
		// an empty or accidental submit visible before it happens.
		Array.prototype.forEach.call(
			document.querySelectorAll( '.sps-bulk-btn' ),
			function ( btn ) {
				btn.addEventListener( 'click', function ( e ) {
					var checked = boxes.filter( function ( b ) {
						return b.checked;
					} ).length;
					if ( !checked ) {
						return;
					}
					if ( checked > 1 && !window.confirm(
						mw.msg( 'saintapediasuggest-bulk-confirm', checked )
					) ) {
						e.preventDefault();
					}
				} );
			}
		);
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

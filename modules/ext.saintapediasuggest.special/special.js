/*!
 * SaintapediaSuggest dashboard helpers.
 *
 * Progressive enhancement only — every action on the dashboard is a plain
 * form submit that works with scripting disabled. This file adds the
 * select-all checkbox, a confirmation before a bulk change, and Copy for
 * the proposed value (the value remains selectable without scripting).
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

		Array.prototype.forEach.call(
			document.querySelectorAll( '.sps-copy' ),
			function ( btn ) {
				btn.addEventListener( 'click', function () {
					var item = btn.closest( '.sps-item' );
					var span = item ? item.querySelector( '.sps-copy-value' ) : null;
					var text = span ? span.textContent : '';
					if ( !text ) {
						return;
					}
					copyText( text, function () {
						var original = btn.getAttribute( 'data-sps-label' ) || btn.textContent;
						btn.setAttribute( 'data-sps-label', original );
						btn.textContent = mw.msg( 'saintapediasuggest-copied' );
						window.setTimeout( function () {
							btn.textContent = original;
						}, 1500 );
					} );
				} );
			}
		);
	}

	function copyText( text, done ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done ).catch( function () {
				fallbackCopy( text, done );
			} );
			return;
		}
		fallbackCopy( text, done );
	}

	function fallbackCopy( text, done ) {
		var ta = document.createElement( 'textarea' );
		ta.value = text;
		ta.setAttribute( 'readonly', '' );
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild( ta );
		ta.select();
		try {
			if ( document.execCommand( 'copy' ) ) {
				done();
			}
		} catch ( e ) {
			// Value stays selectable in the row.
		}
		document.body.removeChild( ta );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

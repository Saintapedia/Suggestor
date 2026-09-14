/*!
 * SaintapediaSuggest reader widget.
 *
 * A floating button that opens a panel: pick one of this page's suggestable
 * Cargo fields, see what is currently stored, propose something else.
 *
 * The field list arrives from mw.config (computed server-side in
 * Hooks::onBeforePageDisplay). The API re-validates every target and
 * re-reads the current value on submit, so nothing here is trusted — this
 * file is a convenience layer, not a security boundary.
 *
 * Public mode: open to anonymous readers, hCaptcha when required server-side.
 */
( function () {
	'use strict';

	var config = {
		mode: mw.config.get( 'spsMode' ) || 'public',
		pageId: mw.config.get( 'spsPageId' ),
		fields: mw.config.get( 'spsFields' ) || [],
		maxValueLength: mw.config.get( 'spsMaxValueLength' ) || 500,
		enableEmail: mw.config.get( 'spsEnableEmail' ) || false,
		requireCaptcha: mw.config.get( 'spsRequireCaptcha' ) || false,
		captchaMisconfigured: mw.config.get( 'spsCaptchaMisconfigured' ) || false,
		hCaptchaSiteKey: mw.config.get( 'spsHCaptchaSiteKey' ) || '',
		stacked: mw.config.get( 'spsStacked' ) || false,
		entryPoint: mw.config.get( 'spsEntryPoint' ) || 'button'
	};

	if ( !config.pageId || !config.fields.length ) {
		return;
	}

	// Dismissal is per tab (sessionStorage), not per browser: a reader who
	// hides the button should still see it on their next visit.
	var fabStorageKey = 'sps-fab-hidden:' + ( mw.config.get( 'wgDBname' ) || '' );

	var hcaptchaScriptPromise = null;
	var hcaptchaWidgetId = null;
	var captchaRendered = false;

	function isFabHidden() {
		try {
			return sessionStorage.getItem( fabStorageKey ) === '1';
		} catch ( e ) {
			return false;
		}
	}

	function setFabHidden( hidden ) {
		try {
			if ( hidden ) {
				sessionStorage.setItem( fabStorageKey, '1' );
			} else {
				sessionStorage.removeItem( fabStorageKey );
			}
		} catch ( e ) {
			// Private browsing: dismissal simply does not persist.
		}
	}

	/**
	 * Minimal element builder. Text goes in via textContent, never innerHTML —
	 * every value here can originate from a wiki page or a reader.
	 *
	 * @param {string} tag
	 * @param {Object} [attrs]
	 * @param {Array|string} [children]
	 * @return {HTMLElement}
	 */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k.indexOf( 'on' ) === 0 && typeof attrs[ k ] === 'function' ) {
				node.addEventListener( k.slice( 2 ), attrs[ k ] );
			} else if ( k === 'text' ) {
				node.textContent = attrs[ k ];
			} else {
				node.setAttribute( k, attrs[ k ] );
			}
		} );
		if ( typeof children === 'string' ) {
			node.textContent = children;
		} else {
			( children || [] ).forEach( function ( c ) {
				if ( c ) {
					node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
				}
			} );
		}
		return node;
	}

	var HCAPTCHA_SRC = 'https://hcaptcha.com/1/api.js?render=explicit';

	/**
	 * Wait for an hCaptcha script another script already started loading.
	 *
	 * SaintapediaFeedback ships the same widget pattern and injects the same
	 * URL. Appending a second tag for a script that is already in flight makes
	 * hCaptcha initialise twice, so when a tag is already present we poll for
	 * the global instead of adding our own.
	 */
	function waitForHCaptcha( timeoutMs ) {
		return new Promise( function ( resolve, reject ) {
			var waited = 0;
			var step = 100;
			( function poll() {
				if ( window.hcaptcha ) {
					resolve();
					return;
				}
				waited += step;
				if ( waited >= timeoutMs ) {
					reject( new Error( 'hcaptcha-load-timeout' ) );
					return;
				}
				setTimeout( poll, step );
			}() );
		} );
	}

	function loadHCaptchaScript() {
		if ( window.hcaptcha ) {
			return Promise.resolve();
		}
		if ( hcaptchaScriptPromise ) {
			return hcaptchaScriptPromise;
		}

		// Another extension on this page may already have injected it.
		if ( document.querySelector( 'script[src^="https://hcaptcha.com/1/api.js"]' ) ) {
			hcaptchaScriptPromise = waitForHCaptcha( 15000 ).catch( function ( e ) {
				hcaptchaScriptPromise = null;
				throw e;
			} );
			return hcaptchaScriptPromise;
		}

		hcaptchaScriptPromise = new Promise( function ( resolve, reject ) {
			var s = document.createElement( 'script' );
			s.src = HCAPTCHA_SRC;
			s.async = true;
			s.defer = true;
			s.onload = function () {
				resolve();
			};
			s.onerror = function () {
				hcaptchaScriptPromise = null;
				reject( new Error( 'hcaptcha-load-failed' ) );
			};
			document.head.appendChild( s );
		} );
		return hcaptchaScriptPromise;
	}

	function getCaptchaToken() {
		if ( !config.requireCaptcha ) {
			return '';
		}
		if ( !window.hcaptcha || hcaptchaWidgetId === null ) {
			return '';
		}
		try {
			return window.hcaptcha.getResponse( hcaptchaWidgetId ) || '';
		} catch ( e ) {
			return '';
		}
	}

	function resetCaptcha() {
		if ( window.hcaptcha && hcaptchaWidgetId !== null ) {
			try {
				window.hcaptcha.reset( hcaptchaWidgetId );
			} catch ( e ) {
				// nothing useful to do
			}
		}
	}

	function buildWidget() {
		var selectedIndex = -1;

		var fab = el( 'button', {
			type: 'button',
			class: 'sps-fab',
			id: 'sps-suggest',
			'aria-haspopup': 'dialog',
			'aria-expanded': 'false',
			title: mw.msg( 'saintapediasuggest-button-label' ),
			text: mw.msg( 'saintapediasuggest-button-label' )
		} );

		var hideBtn = el( 'button', {
			type: 'button',
			class: 'sps-fab-hide',
			title: mw.msg( 'saintapediasuggest-hide' ),
			'aria-label': mw.msg( 'saintapediasuggest-hide' ),
			text: '×'
		} );

		var fieldSelect = el( 'select', {
			class: 'sps-field-select',
			id: 'sps-field-select'
		}, [
			el( 'option', { value: '', text: mw.msg( 'saintapediasuggest-field-placeholder' ) } )
		] );

		/*
		 * Group the picker so a reader can tell what they are correcting.
		 *
		 * A page may hold rows in more than one Cargo table, and more than one
		 * row in the same table (three sightings on a saint's page). Both get
		 * their own grouping level, but only when they are actually present:
		 * on the common one-table one-row page the dropdown is still a flat
		 * list of field names, with no structure the reader has to read past.
		 */
		( function buildFieldOptions() {
			var tableOrder = [];
			var byTable = {};

			config.fields.forEach( function ( f, i ) {
				if ( !byTable[ f.table ] ) {
					byTable[ f.table ] = { rowOrder: [], rows: {} };
					tableOrder.push( f.table );
				}
				var t = byTable[ f.table ];
				var key = String( f.rowId );
				if ( !t.rows[ key ] ) {
					t.rows[ key ] = { label: f.rowLabel, count: f.rowCount, entries: [] };
					t.rowOrder.push( key );
				}
				t.rows[ key ].entries.push( { index: i, field: f } );
			} );

			var multipleTables = tableOrder.length > 1;

			tableOrder.forEach( function ( table ) {
				var t = byTable[ table ];
				var multipleRows = t.rowOrder.length > 1;

				t.rowOrder.forEach( function ( key ) {
					var row = t.rows[ key ];

					// Label the group by whichever dimensions actually vary.
					var groupLabel = null;
					if ( multipleTables && multipleRows ) {
						groupLabel = table + ' · ' + row.label;
					} else if ( multipleRows ) {
						groupLabel = row.label;
					} else if ( multipleTables ) {
						groupLabel = table;
					}

					var target = fieldSelect;
					if ( groupLabel !== null ) {
						target = el( 'optgroup', { label: groupLabel } );
						fieldSelect.appendChild( target );
					}

					row.entries.forEach( function ( entry ) {
						target.appendChild( el( 'option', {
							value: String( entry.index ),
							text: entry.field.field
						} ) );
					} );
				} );
			} );
		}() );

		var currentValue = el( 'div', { class: 'sps-current' } );

		var suggestedInput = el( 'input', {
			type: 'text',
			class: 'sps-suggested',
			id: 'sps-suggested',
			maxlength: String( config.maxValueLength ),
			placeholder: mw.msg( 'saintapediasuggest-suggested-placeholder' )
		} );

		var commentInput = el( 'textarea', {
			class: 'sps-comment',
			id: 'sps-comment',
			rows: '2',
			maxlength: config.mode === 'enterprise' ? '5000' : '500',
			placeholder: mw.msg( 'saintapediasuggest-comment-placeholder' )
		} );

		var emailInput = null;
		if ( config.enableEmail ) {
			emailInput = el( 'input', {
				type: 'email',
				class: 'sps-email',
				id: 'sps-email',
				maxlength: '255',
				placeholder: mw.msg( 'saintapediasuggest-email-placeholder' )
			} );
		}

		var captchaMount = null;
		if ( config.requireCaptcha ) {
			captchaMount = el( 'div', { class: 'sps-hcaptcha', id: 'sps-hcaptcha' } );
		}

		var status = el( 'div', {
			class: 'sps-status',
			role: 'status',
			'aria-live': 'polite'
		} );

		var submitBtn = el( 'button', {
			type: 'submit',
			class: 'sps-submit',
			text: mw.msg( 'saintapediasuggest-submit' )
		} );

		var cancelBtn = el( 'button', {
			type: 'button',
			class: 'sps-cancel',
			text: mw.msg( 'saintapediasuggest-cancel' )
		} );

		var form = el( 'form', { class: 'sps-form' }, [
			el( 'label', { for: 'sps-field-select', text: mw.msg( 'saintapediasuggest-field-label' ) } ),
			fieldSelect,
			currentValue,
			el( 'label', { for: 'sps-suggested', text: mw.msg( 'saintapediasuggest-suggested-label' ) } ),
			suggestedInput,
			el( 'label', { for: 'sps-comment', text: mw.msg( 'saintapediasuggest-comment-label' ) } ),
			commentInput,
			emailInput ? el( 'label', { for: 'sps-email', text: mw.msg( 'saintapediasuggest-email-label' ) } ) : null,
			emailInput,
			captchaMount,
			status,
			el( 'div', { class: 'sps-actions' }, [ submitBtn, cancelBtn ] )
		] );

		var panel = el( 'div', {
			class: 'sps-panel',
			role: 'dialog',
			'aria-modal': 'false',
			'aria-labelledby': 'sps-panel-title',
			hidden: 'hidden'
		}, [
			el( 'h2', { id: 'sps-panel-title', text: mw.msg( 'saintapediasuggest-panel-title' ) } ),
			el( 'p', { class: 'sps-subtitle', text: mw.msg( 'saintapediasuggest-panel-subtitle' ) } ),
			form
		] );

		// In 'none' mode the panel still exists and still works — only this
		// extension's own button is withheld, because something else (a
		// sibling extension, a gadget) opens it through the hook below. That
		// is what lets a wiki running two feedback tools show one entry point
		// instead of two competing floating buttons.
		var ownButton = config.entryPoint !== 'none';
		var root = el(
			'div',
			{ class: 'sps-root' + ( ownButton ? '' : ' sps-root--no-button' ) },
			ownButton ? [ panel, fab, hideBtn ] : [ panel ]
		);

		/* ------------------------------------------------------- behaviour */

		function showCurrent() {
			var f = config.fields[ selectedIndex ];
			if ( !f ) {
				currentValue.textContent = '';
				return;
			}
			var text = mw.msg( 'saintapediasuggest-current-label' ) + ' ' +
				( f.value !== '' ? f.value : mw.msg( 'saintapediasuggest-current-empty' ) );
			// Restate the row when the table has more than one, so the reader
			// can confirm they picked the entry they were reading.
			if ( f.rowCount > 1 ) {
				text += ' — ' + f.rowLabel;
			}
			currentValue.textContent = text;
		}

		fieldSelect.addEventListener( 'change', function () {
			selectedIndex = fieldSelect.value === '' ? -1 : parseInt( fieldSelect.value, 10 );
			showCurrent();
			// Pre-fill with the stored value so the reader edits rather than
			// retypes — most corrections are small.
			var f = config.fields[ selectedIndex ];
			if ( f ) {
				// Always overwrite: whatever was typed belonged to the
				// previously selected field. Carrying it over would submit
				// that text as the proposed value for a field it was never
				// written for.
				suggestedInput.value = f.value;
			}
			status.textContent = '';
		} );

		function ensureCaptcha() {
			if ( !config.requireCaptcha ) {
				return Promise.resolve();
			}
			if ( config.captchaMisconfigured || !config.hCaptchaSiteKey ) {
				return Promise.reject( new Error( 'captcha-unavailable' ) );
			}
			return loadHCaptchaScript().then( function () {
				if ( captchaRendered || !window.hcaptcha ) {
					return;
				}
				hcaptchaWidgetId = window.hcaptcha.render( captchaMount, {
					sitekey: config.hCaptchaSiteKey
				} );
				captchaRendered = true;
			} );
		}

		function openPanel() {
			panel.hidden = false;
			fab.setAttribute( 'aria-expanded', 'true' );
			fieldSelect.focus();
			ensureCaptcha().catch( function () {
				status.textContent = mw.msg( 'saintapediasuggest-error-captcha-unavailable' );
				status.className = 'sps-status sps-status-error';
			} );
		}

		function closePanel() {
			panel.hidden = true;
			fab.setAttribute( 'aria-expanded', 'false' );
			fab.focus();
		}

		fab.addEventListener( 'click', function () {
			if ( panel.hidden ) {
				openPanel();
			} else {
				closePanel();
			}
		} );

		cancelBtn.addEventListener( 'click', closePanel );

		hideBtn.addEventListener( 'click', function () {
			setFabHidden( true );
			root.classList.add( 'sps-hidden' );
			closePanel();
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && !panel.hidden ) {
				closePanel();
			}
		} );

		// Toolbox link (and the sidebar entry) restore a dismissed button.
		function restoreFromHash() {
			if ( window.location.hash === '#sps-suggest' ) {
				setFabHidden( false );
				root.classList.remove( 'sps-hidden' );
				openPanel();
			}
		}
		window.addEventListener( 'hashchange', restoreFromHash );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var field = config.fields[ selectedIndex ];
			if ( !field ) {
				status.textContent = mw.msg( 'saintapediasuggest-error-nofield' );
				status.className = 'sps-status sps-status-error';
				return;
			}

			var suggested = suggestedInput.value.trim();
			if ( suggested === '' ) {
				status.textContent = mw.msg( 'saintapediasuggest-error-novalue' );
				status.className = 'sps-status sps-status-error';
				return;
			}
			if ( suggested === String( field.value ).trim() ) {
				status.textContent = mw.msg( 'saintapediasuggest-error-unchanged' );
				status.className = 'sps-status sps-status-error';
				return;
			}

			var params = {
				action: 'saintapediasuggest',
				format: 'json',
				formatversion: 2,
				pageid: config.pageId,
				table: field.table,
				field: field.field,
				suggestedvalue: suggested
			};
			if ( field.rowId ) {
				params.rowid = field.rowId;
			}
			if ( commentInput.value.trim() !== '' ) {
				params.comment = commentInput.value.trim();
			}
			if ( emailInput && emailInput.value.trim() !== '' ) {
				params.email = emailInput.value.trim();
			}
			if ( config.requireCaptcha ) {
				params.captchaWord = getCaptchaToken();
			}

			submitBtn.disabled = true;
			status.textContent = '';
			status.className = 'sps-status';

			new mw.Api().postWithToken( 'csrf', params ).then( function () {
				form.hidden = true;
				panel.appendChild( el( 'div', { class: 'sps-success' }, [
					el( 'h3', { text: mw.msg( 'saintapediasuggest-success-title' ) } ),
					el( 'p', { text: mw.msg( 'saintapediasuggest-success-body' ) } )
				] ) );
			} ).catch( function ( code ) {
				var msgKey = 'saintapediasuggest-error-generic';
				if ( code === 'sps-ratelimit' ) {
					msgKey = 'saintapediasuggest-error-ratelimit';
				} else if ( code === 'sps-captcha' ) {
					msgKey = 'saintapediasuggest-error-captcha';
				} else if ( code === 'sps-captcha-unavailable' ) {
					msgKey = 'saintapediasuggest-error-captcha-unavailable';
				} else if ( code === 'sps-nofield' ) {
					msgKey = 'saintapediasuggest-error-nofield';
				} else if ( code === 'sps-norow' ) {
					msgKey = 'saintapediasuggest-error-norow';
				} else if ( code === 'sps-novalue' ) {
					msgKey = 'saintapediasuggest-error-novalue';
				} else if ( code === 'sps-unchanged' ) {
					msgKey = 'saintapediasuggest-error-unchanged';
				} else if ( code === 'sps-namespace' ) {
					msgKey = 'saintapediasuggest-error-namespace';
				} else if ( code === 'sps-disabled' ) {
					msgKey = 'saintapediasuggest-error-disabled';
				}
				status.textContent = mw.msg( msgKey );
				status.className = 'sps-status sps-status-error';
				submitBtn.disabled = false;
				resetCaptcha();
			} );
		} );

		/*
		 * Public API for other extensions and gadgets.
		 *
		 *   mw.hook( 'saintapediasuggest.open' ).fire();
		 *   mw.hook( 'saintapediasuggest.open' ).fire( { field: 'Phone' } );
		 *
		 * Deliberately a hook rather than a global: the caller does not need
		 * this module loaded, or even installed — firing into a hook nobody
		 * listens on is a no-op, so the integration degrades to nothing
		 * instead of a TypeError.
		 */
		mw.hook( 'saintapediasuggest.open' ).add( function ( options ) {
			setFabHidden( false );
			root.classList.remove( 'sps-hidden' );

			// Preselect a field when the caller named one, so a deep link can
			// land the reader on the right row of the picker.
			if ( options && options.field ) {
				for ( var i = 0; i < config.fields.length; i++ ) {
					var f = config.fields[ i ];
					if ( f.field === options.field &&
						( !options.table || f.table === options.table ) ) {
						fieldSelect.value = String( i );
						fieldSelect.dispatchEvent( new Event( 'change' ) );
						break;
					}
				}
			}
			openPanel();
		} );

		if ( config.stacked ) {
			root.classList.add( 'sps-root--stacked' );
		}

		if ( isFabHidden() ) {
			root.classList.add( 'sps-hidden' );
		}

		document.body.appendChild( root );
		restoreFromHash();

		/*
		 * Tell anyone who cares that this page has suggestable fields, so a
		 * sibling extension can offer the option only when it would lead
		 * somewhere. Fired after the widget is in the DOM, so a handler may
		 * open it immediately.
		 */
		mw.hook( 'saintapediasuggest.ready' ).fire( {
			pageId: config.pageId,
			fieldCount: config.fields.length,
			tables: config.fields.map( function ( f ) {
				return f.table;
			} ).filter( function ( t, i, all ) {
				return all.indexOf( t ) === i;
			} ),
			ownButton: ownButton
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', buildWidget );
	} else {
		buildWidget();
	}
}() );

/**
 * ZipLogger settings screen. Everything works without this script; it adds two things:
 *  - a confirmation before destructive actions;
 *  - the live panels of the dashboard. Each one asks WordPress (admin-ajax, as an administrator, with a
 *    nonce) for a finished, escaped fragment. The browser never holds a ZipLogger credential and never
 *    talks to ZipLogger.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		if ( ! form || ! form.getAttribute ) {
			return;
		}
		var message = form.getAttribute( 'data-ziplogger-confirm' );
		if ( message && ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );

	function loadPanel( panel, refresh ) {
		var body = panel.querySelector( '.ziplogger-live-body' );
		if ( ! body || ! window.fetch ) {
			return;
		}
		var data = new window.FormData();
		data.append( 'action', 'ziplogger_panel' );
		data.append( 'panel', panel.getAttribute( 'data-ziplogger-panel' ) );
		data.append( 'nonce', panel.getAttribute( 'data-nonce' ) );
		if ( refresh ) {
			data.append( 'refresh', '1' );
		}
		body.setAttribute( 'aria-busy', 'true' );
		window.fetch( panel.getAttribute( 'data-ajax' ), { method: 'POST', credentials: 'same-origin', body: data } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data && typeof json.data.html === 'string' ) {
					body.innerHTML = json.data.html; // Built and escaped by WordPress for an administrator.
				} else {
					body.textContent = ( json && json.data && json.data.message ) || 'Unavailable.';
				}
			} )
			.catch( function () {
				body.textContent = 'Live data could not be loaded.';
			} )
			.then( function () {
				body.removeAttribute( 'aria-busy' );
			} );
	}

	function init() {
		var panels = document.querySelectorAll( '[data-ziplogger-panel]' );
		Array.prototype.forEach.call( panels, function ( panel ) {
			loadPanel( panel, false );
			var button = panel.querySelector( '.ziplogger-live-refresh' );
			if ( button ) {
				button.addEventListener( 'click', function () {
					loadPanel( panel, true );
				} );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

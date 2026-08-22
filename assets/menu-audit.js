/* AI Menu Audit — front-end. No dependencies. */
( function () {
	'use strict';

	var STEPS = [
		'Reading through your items and prices',
		'Comparing against category benchmarks',
		'Looking for pricing opportunities',
		'Checking structure and customer appeal',
		'Writing your recommendations'
	];

	function init( wrap ) {
		var form    = wrap.querySelector( '.ma-form' );
		var loading = wrap.querySelector( '.ma-loading' );
		var result  = wrap.querySelector( '.ma-result' );
		var error   = wrap.querySelector( '.ma-error' );
		var button  = wrap.querySelector( '.ma-submit' );
		var file    = wrap.querySelector( 'input[type="file"]' );
		var fileName = wrap.querySelector( '.ma-file-name' );
		var stepEl  = wrap.querySelector( '.ma-loading-step' );

		var stepTimer = null;
		var pollTimer = null;
		var defaultFileText = fileName ? fileName.textContent : '';

		if ( file ) {
			file.addEventListener( 'change', function () {
				fileName.textContent = file.files.length ? file.files[0].name : defaultFileText;
			} );
		}

		function showError( message ) {
			error.textContent = message;
			error.hidden = false;
			button.disabled = false;
			button.textContent = button.dataset.label || button.textContent;
			loading.hidden = true;
			form.hidden = false;
			error.scrollIntoView( { behavior: 'smooth', block: 'center' } );
		}

		function markInvalid( field ) {
			var label = field.closest( '.ma-field' );
			if ( label ) {
				label.classList.add( 'ma-invalid' );
			}
			field.addEventListener( 'input', function once() {
				if ( label ) {
					label.classList.remove( 'ma-invalid' );
				}
				field.removeEventListener( 'input', once );
			} );
		}

		function validate() {
			var required = form.querySelectorAll( '[required]' );

			for ( var i = 0; i < required.length; i++ ) {
				if ( ! required[ i ].value.trim() ) {
					markInvalid( required[ i ] );
					required[ i ].focus();
					return 'Please fill in the highlighted field.';
				}
			}

			var email = form.querySelector( '[name="email"]' );
			if ( email && ! /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test( email.value.trim() ) ) {
				markInvalid( email );
				email.focus();
				return 'That email address does not look right.';
			}

			var menu = form.querySelector( '[name="menu"]' );
			var hasFile = file && file.files.length;

			if ( ! hasFile && menu.value.trim().length < 40 ) {
				markInvalid( menu );
				menu.focus();
				return 'Please paste your menu, or upload it as a file.';
			}

			return null;
		}

		function cycleSteps() {
			var i = 0;
			stepEl.textContent = STEPS[0];

			stepTimer = setInterval( function () {
				i = Math.min( i + 1, STEPS.length - 1 );
				stepEl.textContent = STEPS[ i ];
			}, 9000 );
		}

		function post( action, data ) {
			var body = data instanceof FormData ? data : new FormData();

			if ( ! ( data instanceof FormData ) ) {
				Object.keys( data ).forEach( function ( key ) {
					body.append( key, data[ key ] );
				} );
			}

			body.append( 'action', action );
			body.append( 'nonce', maAudit.nonce );

			return fetch( maAudit.ajaxUrl, {
				method: 'POST',
				body: body,
				credentials: 'same-origin'
			} ).then( function ( response ) {
				return response.json();
			} );
		}

		function poll( token, attempt ) {
			attempt = attempt || 0;

			// ~4 minutes at 3s, which is well past the slowest observed audit.
			if ( attempt > 80 ) {
				showError( 'This is taking longer than expected. Your report will still be emailed to you shortly.' );
				return;
			}

			post( 'ma_status', { token: token } ).then( function ( response ) {
				if ( ! response.success ) {
					showError( ( response.data && response.data.message ) || 'Something went wrong.' );
					if ( response.data && response.data.debug ) {
						console.warn( '[Menu Audit]', response.data.debug );
					}
					return;
				}

				if ( 'complete' !== response.data.status ) {
					pollTimer = setTimeout( function () {
						poll( token, attempt + 1 );
					}, 3000 );
					return;
				}

				finish( response.data );
			} ).catch( function () {
				pollTimer = setTimeout( function () {
					poll( token, attempt + 1 );
				}, 4000 );
			} );
		}

		function finish( data ) {
			clearInterval( stepTimer );
			clearTimeout( pollTimer );

			loading.hidden = true;
			result.innerHTML = data.html +
				'<div class="ma-actions"><a href="' + data.url + '" target="_blank" rel="noopener">Open the full report</a></div>';
			result.hidden = false;
			result.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			error.hidden = true;

			var problem = validate();
			if ( problem ) {
				showError( problem );
				return;
			}

			button.dataset.label = button.textContent;
			button.disabled = true;
			button.textContent = 'Sending…';

			post( 'ma_submit', new FormData( form ) ).then( function ( response ) {
				if ( ! response.success ) {
					showError( ( response.data && response.data.message ) || 'Something went wrong. Please try again.' );
					return;
				}

				if ( response.data.redirect ) {
					window.location.href = response.data.redirect;
					return;
				}

				form.hidden = true;
				loading.hidden = false;
				cycleSteps();
				poll( response.data.token );
			} ).catch( function () {
				showError( 'We could not reach the server. Please check your connection and try again.' );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wraps = document.querySelectorAll( '.ma-wrap' );
		for ( var i = 0; i < wraps.length; i++ ) {
			init( wraps[ i ] );
		}
	} );
}() );

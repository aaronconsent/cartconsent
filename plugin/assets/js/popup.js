/**
 * Consent Resolve for WooCommerce — cart-saver popup.
 * Shows once per session on exit-intent (desktop) or after a delay. Requires an
 * explicit consent tick; submitting sends a double-opt-in confirmation email.
 */
( function () {
	var C = window.CRWPopup || {};
	if ( ! C.rest ) { return; }
	try { if ( sessionStorage.getItem( 'crw_popup_seen' ) ) { return; } } catch ( e ) {}

	var shown = false;

	function build() {
		var overlay = document.createElement( 'div' );
		overlay.style.cssText = 'position:fixed;inset:0;z-index:100000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:16px;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif';
		var box = document.createElement( 'div' );
		box.style.cssText = 'background:#fff;border-radius:14px;max-width:420px;width:100%;padding:22px 24px;box-shadow:0 12px 40px rgba(0,0,0,.25)';
		overlay.appendChild( box );

		var close = document.createElement( 'button' );
		close.textContent = '×';
		close.setAttribute( 'aria-label', 'Close' );
		close.style.cssText = 'background:none;border:0;font-size:1.4rem;color:#888;float:right;cursor:pointer;margin:-8px -8px 0 0';
		close.addEventListener( 'click', function () { overlay.remove(); } );

		var h = document.createElement( 'h2' );
		h.textContent = C.title || 'Save your cart';
		h.style.cssText = 'margin:0 0 6px;font-size:1.25rem;color:#14161d';
		var p = document.createElement( 'p' );
		p.textContent = C.message || '';
		p.style.cssText = 'margin:0 0 14px;color:#4b5563;line-height:1.5;font-size:.95rem';

		var email = document.createElement( 'input' );
		email.type = 'email';
		email.placeholder = 'you@example.com';
		email.required = true;
		email.style.cssText = 'width:100%;box-sizing:border-box;padding:.6rem .7rem;border:1px solid #d0d5dd;border-radius:8px;font-size:1rem;margin-bottom:10px';

		var cl = document.createElement( 'label' );
		cl.style.cssText = 'display:flex;gap:8px;align-items:flex-start;font-size:.85rem;color:#4b5563;line-height:1.4;margin-bottom:14px';
		var cb = document.createElement( 'input' );
		cb.type = 'checkbox';
		var ct = document.createElement( 'span' );
		ct.textContent = C.consent || 'Email me a reminder about my cart.';
		cl.appendChild( cb );
		cl.appendChild( ct );

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.textContent = C.button || 'Save my cart';
		btn.style.cssText = 'width:100%;background:var(--cr-accent,#1F6FEB);color:#fff;border:0;border-radius:8px;padding:.7rem;font-size:1rem;cursor:pointer';

		var note = document.createElement( 'p' );
		note.style.cssText = 'margin:10px 0 0;font-size:.8rem;color:#6b7280;min-height:1em';

		btn.addEventListener( 'click', function () {
			var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
			if ( ! re.test( email.value ) ) { note.textContent = 'Please enter a valid email.'; return; }
			if ( ! cb.checked ) { note.textContent = 'Please tick the box to continue.'; return; }
			btn.disabled = true; note.textContent = 'Saving…';
			fetch( C.rest, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce || '' },
				body: JSON.stringify( { email: email.value, consent: cb.checked } )
			} ).then( function ( r ) { return r.json(); } ).then( function () {
				box.innerHTML = '';
				var d = document.createElement( 'p' );
				d.textContent = C.done || 'Check your inbox to confirm.';
				d.style.cssText = 'margin:0;color:#14161d;font-size:1rem;text-align:center;padding:8px 0';
				box.appendChild( d );
				setTimeout( function () { overlay.remove(); }, 3500 );
			} ).catch( function () { btn.disabled = false; note.textContent = 'Something went wrong — try again.'; } );
		} );

		box.appendChild( close );
		box.appendChild( h );
		box.appendChild( p );
		box.appendChild( email );
		box.appendChild( cl );
		box.appendChild( btn );
		box.appendChild( note );
		overlay.addEventListener( 'click', function ( e ) { if ( e.target === overlay ) { overlay.remove(); } } );
		return overlay;
	}

	function trigger() {
		if ( shown ) { return; }
		shown = true;
		try { sessionStorage.setItem( 'crw_popup_seen', '1' ); } catch ( e ) {}
		document.body.appendChild( build() );
	}

	if ( 'timer' === C.trigger ) {
		setTimeout( trigger, Math.max( 3, C.delay || 20 ) * 1000 );
	} else {
		// Exit intent (desktop) with a timed fallback for touch devices.
		document.addEventListener( 'mouseout', function ( e ) {
			if ( ! e.relatedTarget && e.clientY <= 0 ) { trigger(); }
		} );
		setTimeout( trigger, Math.max( 20, ( C.delay || 20 ) * 2 ) * 1000 );
	}
}() );

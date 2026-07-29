/**
 * Consent Resolve for WooCommerce — web-push opt-in on the checkout page.
 * Consent-first: shows a dismissible prompt; only subscribes on an explicit
 * click and after the browser grants notification permission.
 */
( function () {
	var C = window.CRWPush || {};
	if ( ! C.sw || ! C.vapid || ! ( 'serviceWorker' in navigator ) || ! ( 'PushManager' in window ) || ! ( 'Notification' in window ) ) {
		return;
	}
	if ( Notification.permission === 'denied' ) {
		return;
	}

	function toKey( base64 ) {
		var pad = '='.repeat( ( 4 - base64.length % 4 ) % 4 );
		var raw = atob( ( base64 + pad ).replace( /-/g, '+' ).replace( /_/g, '/' ) );
		var arr = new Uint8Array( raw.length );
		for ( var i = 0; i < raw.length; i++ ) { arr[ i ] = raw.charCodeAt( i ); }
		return arr;
	}
	function email() {
		var el = document.querySelector( '#billing_email, #email, input[type=email]' );
		return el ? el.value : '';
	}

	var box;
	function subscribe() {
		Notification.requestPermission().then( function ( p ) {
			if ( p !== 'granted' ) { return; }
			navigator.serviceWorker.register( C.sw, { scope: '/' } ).then( function ( reg ) {
				return reg.pushManager.subscribe( { userVisibleOnly: true, applicationServerKey: toKey( C.vapid ) } );
			} ).then( function ( sub ) {
				fetch( C.rest, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce || '' },
					body: JSON.stringify( { subscription: sub, email: email() } )
				} );
				if ( box ) { box.remove(); }
			} ).catch( function () {} );
		} );
	}

	function prompt() {
		if ( Notification.permission === 'granted' ) { subscribe(); return; }
		box = document.createElement( 'div' );
		box.style.cssText = 'position:fixed;left:16px;bottom:16px;z-index:99999;background:#fff;border:1px solid #e2e4ea;border-radius:12px;padding:12px 14px;max-width:320px;box-shadow:0 6px 24px rgba(0,0,0,.12);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif';
		var close = document.createElement( 'button' );
		close.textContent = '×';
		close.style.cssText = 'background:none;border:0;color:#888;float:right;cursor:pointer;font-size:1.1rem;margin:-4px -4px 0 0';
		close.addEventListener( 'click', function () { box.remove(); } );
		var msg = document.createElement( 'div' );
		msg.textContent = C.prompt || 'Get a reminder';
		msg.style.cssText = 'font-size:.9rem;color:#14161d;margin-bottom:8px';
		var btn = document.createElement( 'button' );
		btn.textContent = C.button || 'Notify me';
		btn.style.cssText = 'background:#1F6FEB;color:#fff;border:0;border-radius:8px;padding:.45rem .9rem;cursor:pointer;font-size:.9rem';
		btn.addEventListener( 'click', subscribe );
		box.appendChild( close );
		box.appendChild( msg );
		box.appendChild( btn );
		document.body.appendChild( box );
	}

	// Only prompt if not already subscribed.
	navigator.serviceWorker.getRegistration().then( function ( reg ) {
		if ( ! reg ) { prompt(); return; }
		reg.pushManager.getSubscription().then( function ( s ) { if ( ! s ) { prompt(); } } );
	} ).catch( prompt );
}() );

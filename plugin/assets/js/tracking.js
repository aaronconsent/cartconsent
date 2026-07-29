/**
 * Consent Resolve for WooCommerce — ecommerce event bridge.
 * Loaded only after marketing consent is granted (the PHP side gates it), so
 * these events never fire pre-consent. Bridges WooCommerce cart/checkout events
 * to Meta Pixel + GA4. Purchase is fired separately, once, on the thank-you page.
 */
( function () {
	var T = window.CRWTrack || {};
	var cur = T.currency || 'USD';

	function meta( event, data ) {
		if ( T.pixel && window.fbq ) {
			try { window.fbq( 'track', event, data || {} ); } catch ( e ) {}
		}
	}
	function ga( event, data ) {
		if ( T.ga4 && window.gtag ) {
			try { window.gtag( 'event', event, data || {} ); } catch ( e ) {}
		}
	}

	// Add to cart — classic (jQuery body event) + Blocks (native DOM event).
	function onAddToCart() {
		meta( 'AddToCart', { currency: cur } );
		ga( 'add_to_cart', { currency: cur } );
	}
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'added_to_cart', onAddToCart );
	}
	document.body.addEventListener( 'wc-blocks_added_to_cart', onAddToCart );

	// Begin checkout — fire once when the checkout page is shown.
	if ( T.checkout ) {
		meta( 'InitiateCheckout', { currency: cur } );
		ga( 'begin_checkout', { currency: cur } );
	}
}() );

<?php
/**
 * On-site cart-saver popup (exit-intent + timed). Consent-first and spam-safe:
 * the shopper enters their email with an explicit consent tick, and we DON'T
 * start a recovery sequence until they confirm via a double-opt-in email. So a
 * submitted address that was never confirmed is never mailed a sequence.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Popup capture + confirmation.
 */
class CRW_Popup {

	const NS = 'crw/v1';

	/**
	 * Hook up.
	 */
	public function register() {
		add_action( 'init', array( $this, 'maybe_confirm' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		// Always hook enqueue (fires after init); it self-gates. Reading options at
		// plugins_loaded would build translated defaults pre-init (JIT-textdomain notice).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the popup on store pages (not the thank-you page).
	 */
	public function enqueue() {
		if ( is_admin() || ! function_exists( 'is_woocommerce' ) ) {
			return;
		}
		if ( ! CRW_Options::get( 'popup.enabled', false ) || ! CRW_Options::get( 'capture.enabled', true ) ) {
			return;
		}
		$show = is_woocommerce() || is_cart() || is_checkout();
		if ( ! $show || ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) ) {
			return;
		}
		wp_enqueue_script( 'crw-popup', CRW_URL . 'assets/js/popup.js', array(), CRW_VERSION, true );
		wp_localize_script(
			'crw-popup',
			'CRWPopup',
			array(
				'rest'    => esc_url_raw( rest_url( self::NS . '/popup' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'trigger' => (string) CRW_Options::get( 'popup.trigger', 'exit' ),
				'delay'   => (int) CRW_Options::get( 'popup.delay_seconds', 20 ),
				'title'   => (string) CRW_Options::get( 'popup.title', __( 'Wait — save your cart', 'cartconsent' ) ),
				'message' => (string) CRW_Options::get( 'popup.message', __( 'Enter your email and we’ll hold your cart and send you a link to finish later.', 'cartconsent' ) ),
				'consent' => (string) CRW_Options::get( 'popup.consent_label', __( 'Email me a reminder about my cart. Unsubscribe anytime.', 'cartconsent' ) ),
				'button'  => (string) CRW_Options::get( 'popup.button', __( 'Save my cart', 'cartconsent' ) ),
				'done'    => __( 'Check your inbox to confirm and we’ll hold your cart.', 'cartconsent' ),
			)
		);
	}

	/**
	 * REST route.
	 */
	public function routes() {
		register_rest_route(
			self::NS,
			'/popup',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'capture' ),
				'permission_callback' => function ( $req ) {
					return (bool) wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' );
				},
			)
		);
	}

	/**
	 * Handle a popup submission: require the consent tick, snapshot the live
	 * cart, store it pending, and send the confirmation email.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function capture( $req ) {
		if ( ! $this->rate_ok() ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'rate' ), 429 );
		}
		$email    = sanitize_email( (string) $req->get_param( 'email' ) );
		$consent  = ! empty( $req->get_param( 'consent' ) );
		if ( '' === $email || ! is_email( $email ) || ! $consent ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return new WP_REST_Response( array( 'ok' => false ), 200 );
		}
		$cart  = WC()->cart;
		$items = array();
		foreach ( $cart->get_cart() as $ci ) {
			$product = isset( $ci['data'] ) && is_object( $ci['data'] ) ? $ci['data'] : null;
			$items[] = array(
				'product_id'   => (int) ( $ci['product_id'] ?? 0 ),
				'variation_id' => (int) ( $ci['variation_id'] ?? 0 ),
				'quantity'     => (int) ( $ci['quantity'] ?? 1 ),
				'name'         => $product ? $product->get_name() : '',
			);
		}
		$id = CRW_Carts_Store::store_pending(
			array(
				'email'       => $email,
				'user_id'     => get_current_user_id(),
				'items'       => $items,
				'item_count'  => (int) $cart->get_cart_contents_count(),
				'currency'    => get_woocommerce_currency(),
				'total_cents' => (int) round( (float) $cart->get_total( 'edit' ) * 100 ),
				'gpc'         => class_exists( 'CRW_Recovery_Consent' ) ? ( CRW_Recovery_Consent::gpc() ? 1 : 0 ) : 0,
				'region'      => class_exists( 'CRW_Recovery_Consent' ) ? CRW_Recovery_Consent::region_code() : '',
			)
		);
		if ( $id ) {
			CRW_Events::log( $id, 'popup_capture' );
			// Per-address throttle (in addition to the per-IP one above) so a
			// victim's inbox can't be flooded with confirmation emails from many
			// IPs. The pending cart is still stored; we just cap the mailing.
			if ( CRW_Rate::ok( 'popmail:' . CRW_Crypto::email_hash( $email ), 2, DAY_IN_SECONDS ) ) {
				CRW_Mailer::send_confirmation( CRW_Carts_Store::get( $id ), $email );
			}
		}
		return new WP_REST_Response( array( 'ok' => (bool) $id ), 200 );
	}

	/**
	 * Per-IP rate limit (light) to prevent confirmation-email abuse.
	 */
	protected function rate_ok() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification
		$key = 'crw_pop_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 5 ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	/* ------------------------------------------------------------- confirm */

	/**
	 * The double-opt-in confirmation token for a hashed email.
	 *
	 * @param string $email_hash Hashed email.
	 */
	public static function token( $email_hash ) {
		return hash_hmac( 'sha256', 'confirm:' . (string) $email_hash, CRW_Unsubscribe::secret() );
	}

	/**
	 * The confirmation link for a shopper.
	 *
	 * @param string $email Email.
	 */
	public static function confirm_link( $email ) {
		$hash = CRW_Crypto::email_hash( $email );
		return add_query_arg( array( 'crw_confirm' => $hash, 't' => self::token( $hash ) ), home_url( '/' ) );
	}

	/**
	 * Handle a confirmation click → activate the shopper's pending carts.
	 */
	public function maybe_confirm() {
		if ( empty( $_GET['crw_confirm'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$hash  = sanitize_text_field( wp_unslash( $_GET['crw_confirm'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$token = isset( $_GET['t'] ) ? (string) wp_unslash( $_GET['t'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$ok    = 1 === preg_match( '/^[0-9a-f]{64}$/', $hash ) && hash_equals( self::token( $hash ), $token );
		if ( $ok ) {
			CRW_Carts_Store::activate_pending( $hash );
		}
		status_header( $ok ? 200 : 403 );
		nocache_headers();
		$title = $ok ? __( 'Cart saved', 'cartconsent' ) : __( 'Link not valid', 'cartconsent' );
		$msg   = $ok ? __( 'Thanks! We’ll hold your cart and email you a link to finish.', 'cartconsent' ) : __( 'This confirmation link is not valid.', 'cartconsent' );
		echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $title ) . '</title>';
		echo '<div style="max-width:34rem;margin:12vh auto;padding:0 1.25rem;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#14161d;text-align:center">';
		echo '<h1 style="font-size:1.4rem">' . esc_html( $title ) . '</h1><p style="color:#4b5563;line-height:1.6">' . esc_html( $msg ) . '</p>';
		echo '<p style="margin-top:1.5rem"><a href="' . esc_url( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) ) . '" style="color:' . esc_attr( CRW_Options::get( 'style.accent', '#1F6FEB' ) ) . '">' . esc_html__( 'Return to checkout', 'cartconsent' ) . '</a></p></div>';
		exit;
	}
}

<?php
/**
 * Web push channel: serves the service worker, captures subscriptions, and
 * sends encrypted pushes. A second, consent-gated recovery channel alongside
 * email — only shoppers who granted browser-notification permission receive it.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Push controller.
 */
class CRW_Push {

	const NS = 'crw/v1';

	/**
	 * Hook up.
	 */
	public function register() {
		// Serve the service worker from the site root (so its scope is the site).
		add_action( 'init', array( $this, 'maybe_serve_sw' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		// Always hook enqueue (which fires after init); it self-gates via ready().
		// Reading options here — at plugins_loaded — would build the translated
		// defaults before init and trip _load_textdomain_just_in_time.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether push is usable (enabled + crypto + VAPID keys).
	 */
	public static function ready() {
		return CRW_Hosted::active() && CRW_Options::get( 'push.enabled', false ) && CRW_Push_Crypto::available() && '' !== self::vapid_public_b64();
	}

	/**
	 * The stored VAPID keypair, generated once.
	 */
	public static function vapid() {
		$v = get_option( 'crw_vapid' );
		if ( is_array( $v ) && ! empty( $v['private_pem'] ) && ! empty( $v['public'] ) ) {
			return $v;
		}
		if ( ! CRW_Push_Crypto::available() ) {
			return null;
		}
		$kp = CRW_Push_Crypto::generate_keypair();
		if ( ! $kp ) {
			return null;
		}
		$v = array( 'private_pem' => $kp['private_pem'], 'public' => base64_encode( $kp['public'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		update_option( 'crw_vapid', $v, false );
		return $v;
	}

	/**
	 * The VAPID public key as base64url (the browser applicationServerKey).
	 */
	public static function vapid_public_b64() {
		$v = self::vapid();
		return $v ? CRW_Push_Crypto::b64url_encode( base64_decode( $v['public'] ) ) : ''; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Output the service worker when requested at the site root.
	 */
	public function maybe_serve_sw() {
		if ( empty( $_GET['crw_sw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		$sw = @file_get_contents( CRW_DIR . 'assets/js/sw.js' ); // phpcs:ignore
		echo $sw ? $sw : '/* sw */'; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Assets on the checkout page.
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! self::ready() ) {
			return;
		}
		wp_enqueue_script( 'crw-push', CRW_URL . 'assets/js/push.js', array(), CRW_VERSION, true );
		wp_localize_script(
			'crw-push',
			'CRWPush',
			array(
				'sw'     => add_query_arg( 'crw_sw', '1', home_url( '/' ) ),
				'vapid'  => self::vapid_public_b64(),
				'rest'   => esc_url_raw( rest_url( self::NS . '/push/subscribe' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'prompt' => __( 'Get a reminder if you don’t finish checking out', 'cartconsent' ),
				'button' => __( 'Notify me', 'cartconsent' ),
			)
		);
	}

	/**
	 * REST routes.
	 */
	public function routes() {
		register_rest_route(
			self::NS,
			'/push/subscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'subscribe' ),
				'permission_callback' => function ( $req ) {
					return (bool) wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' );
				},
			)
		);
	}

	/**
	 * Store a subscription (linked to a hashed email + user id).
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function subscribe( $req ) {
		if ( ! CRW_Rate::ok( CRW_Rate::ip_key( 'push_sub' ), 20, HOUR_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		$sub = (array) $req->get_param( 'subscription' );
		// Only ever link a subscription to a VERIFIED-owned email — the logged-in
		// user's account email. A posted email is attacker-controllable and would
		// let someone route a victim's recovery push (and cart token) to their
		// own browser. Guests are stored by nothing (unmatched, but harmless).
		$hash = '';
		if ( is_user_logged_in() ) {
			$u = wp_get_current_user();
			if ( $u && is_email( $u->user_email ) ) {
				$hash = CRW_Crypto::email_hash( $u->user_email );
			}
		}
		$ok = CRW_Push_Store::save( $sub, $hash, get_current_user_id() );
		return new WP_REST_Response( array( 'ok' => (bool) $ok ), $ok ? 201 : 200 );
	}

	/* --------------------------------------------------------------- sending */

	/**
	 * Push a recovery notification for a cart (best-effort; one channel of many).
	 *
	 * @param array $row Cart row.
	 */
	public static function push_cart( array $row ) {
		if ( ! self::ready() ) {
			return false;
		}
		$sub = CRW_Push_Store::for_cart( $row );
		if ( ! $sub ) {
			return false;
		}
		$first   = trim( (string) ( $row['first_name'] ?? '' ) );
		$payload = wp_json_encode(
			array(
				'title' => '' !== $first ? sprintf( /* translators: %s name */ __( '%s, your cart is waiting', 'cartconsent' ), $first ) : __( 'Your cart is waiting', 'cartconsent' ),
				'body'  => __( 'Tap to finish checking out — your items are still saved.', 'cartconsent' ),
				'url'   => CRW_Recovery::url( $row ),
			)
		);
		$ok = self::send( $sub, $payload );
		if ( $ok ) {
			CRW_Events::log( (int) $row['id'], 'push_sent' );
		}
		return $ok;
	}

	/**
	 * Encrypt + deliver a push to one subscription.
	 *
	 * @param array  $sub     Subscription row (endpoint/p256dh/auth).
	 * @param string $payload JSON payload.
	 */
	public static function send( array $sub, $payload ) {
		$vapid = self::vapid();
		if ( ! $vapid ) {
			return false;
		}
		// Defense in depth: never POST to a non-push host, even if an old row
		// predates the save-time allowlist.
		if ( ! CRW_Push_Store::endpoint_allowed( (string) ( $sub['endpoint'] ?? '' ) ) ) {
			return false;
		}
		$enc = CRW_Push_Crypto::encrypt( (string) $sub['p256dh'], (string) $sub['auth'], (string) $payload );
		if ( ! is_array( $enc ) ) {
			return false;
		}
		$subject = 'mailto:' . ( sanitize_email( (string) get_option( 'admin_email' ) ) ?: 'admin@example.com' );
		$vh      = CRW_Push_Crypto::vapid_headers( (string) $sub['endpoint'], $vapid['private_pem'], base64_decode( $vapid['public'] ), $subject ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions

		$res = wp_remote_post(
			(string) $sub['endpoint'],
			array(
				'timeout' => 12,
				'headers' => array_merge( $enc['headers'], $vh, array( 'TTL' => '86400' ) ),
				'body'    => $enc['body'],
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 404 === $code || 410 === $code ) {
			CRW_Push_Store::delete( (string) $sub['endpoint'] ); // Expired — forget it.
		}
		return $code >= 200 && $code < 300;
	}
}

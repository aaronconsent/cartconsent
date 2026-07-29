<?php
/**
 * One-click unsubscribe for recovery emails. Prefetch-safe: a GET shows a
 * confirmation page and only a POST performs the opt-out (RFC 8058), so email
 * scanners can't unsubscribe shoppers by accident.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Signed unsubscribe links + handler.
 */
class CRW_Unsubscribe {

	const OPT_SECRET = 'crw_unsub_secret';

	/**
	 * Hook the front-end handler (cheap; returns unless the param is present).
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'maybe_handle' ) );
	}

	/**
	 * Per-install signing secret, generated once.
	 */
	public static function secret() {
		$s = get_option( self::OPT_SECRET );
		if ( ! is_string( $s ) || strlen( $s ) < 32 ) {
			$s = wp_generate_password( 48, false );
			update_option( self::OPT_SECRET, $s, false );
		}
		return $s;
	}

	/**
	 * HMAC token bound to a hashed email (never the raw address).
	 *
	 * @param string $email_hash email_hash from CRW_Crypto.
	 */
	protected static function token_for_hash( $email_hash ) {
		return hash_hmac( 'sha256', (string) $email_hash, self::secret() );
	}

	/**
	 * Token for an email (used by tests + callers that hold the address).
	 *
	 * @param string $email Email.
	 */
	public static function token( $email ) {
		return self::token_for_hash( CRW_Crypto::email_hash( $email ) );
	}

	/**
	 * The unsubscribe URL for a shopper. Carries only the ONE-WAY email hash and
	 * an HMAC token — never the raw email, so nothing PII leaks via Referer/logs.
	 *
	 * @param string $email Email.
	 */
	public static function link( $email ) {
		$hash = CRW_Crypto::email_hash( $email );
		return add_query_arg(
			array(
				'crw_unsub' => $hash,
				't'         => self::token_for_hash( $hash ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Handle a request. GET → confirm; POST → suppress + record + propagate.
	 */
	public static function maybe_handle() {
		if ( ! isset( $_REQUEST['crw_unsub'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$hash   = sanitize_text_field( wp_unslash( $_REQUEST['crw_unsub'] ) ); // phpcs:ignore WordPress.Security
		$token  = isset( $_REQUEST['t'] ) ? (string) wp_unslash( $_REQUEST['t'] ) : ''; // phpcs:ignore WordPress.Security
		$method = strtoupper( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET' );

		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) || ! hash_equals( self::token_for_hash( $hash ), $token ) ) {
			self::page( __( 'This unsubscribe link is not valid.', 'consent-resolve-woo' ), false );
			return;
		}
		if ( 'POST' !== $method ) {
			self::confirm( $hash, $token );
			return;
		}

		CRW_Carts_Store::suppress( $hash, 'unsubscribe' );
		CRW_Events::log( 0, 'unsubscribed' );

		// Record the withdrawal + remove from ad audiences.
		if ( class_exists( 'CRW_Consent' ) ) {
			CRW_Consent::record(
				array(
					'event_type' => 'withdraw',
					'method'     => 'unsubscribe',
					'categories' => array( 'functional' => true, 'marketing' => false ),
				)
			);
		}
		$email = CRW_Carts_Store::email_by_hash( $hash ); // best-effort, for the edge opt-out
		if ( '' !== $email ) {
			CRW_Connection::optout( $email );
		}

		self::page( __( 'You have been unsubscribed and will not receive further cart emails.', 'consent-resolve-woo' ), true );
	}

	/**
	 * The GET confirmation page (POST button; no side effects).
	 *
	 * @param string $hash  Verified email hash.
	 * @param string $token Verified token.
	 */
	private static function confirm( $hash, $token ) {
		status_header( 200 );
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		$title = __( 'Unsubscribe', 'consent-resolve-woo' );
		echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<div style="max-width:34rem;margin:12vh auto;padding:0 1.25rem;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#14161d;text-align:center">';
		echo '<h1 style="font-size:1.4rem;margin:0 0 .6rem">' . esc_html( $title ) . '</h1>';
		echo '<p style="color:#4b5563;line-height:1.6">' . esc_html__( 'Click below to stop receiving cart reminder emails.', 'consent-resolve-woo' ) . '</p>';
		echo '<form method="post" action="' . esc_url( home_url( '/' ) ) . '" style="margin-top:1.25rem">';
		echo '<input type="hidden" name="crw_unsub" value="' . esc_attr( $hash ) . '">';
		echo '<input type="hidden" name="t" value="' . esc_attr( $token ) . '">';
		echo '<button type="submit" style="background:#1F6FEB;color:#fff;border:0;border-radius:8px;padding:.7rem 1.25rem;font-size:1rem;cursor:pointer">' . esc_html__( 'Unsubscribe me', 'consent-resolve-woo' ) . '</button>';
		echo '</form></div>';
		exit;
	}

	/**
	 * Render a minimal confirmation/error page and stop.
	 *
	 * @param string $message Message.
	 * @param bool   $ok      Success vs error.
	 */
	private static function page( $message, $ok ) {
		status_header( $ok ? 200 : 403 );
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		$title = $ok ? __( 'Unsubscribed', 'consent-resolve-woo' ) : __( 'Unsubscribe failed', 'consent-resolve-woo' );
		echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer">';
		echo '<title>' . esc_html( $title ) . '</title>';
		echo '<div style="max-width:34rem;margin:12vh auto;padding:0 1.25rem;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#14161d;text-align:center">';
		echo '<h1 style="font-size:1.4rem;margin:0 0 .6rem">' . esc_html( $title ) . '</h1>';
		echo '<p style="color:#4b5563;line-height:1.6">' . esc_html( $message ) . '</p>';
		echo '<p style="margin-top:1.5rem"><a href="' . esc_url( home_url( '/' ) ) . '" style="color:#1F6FEB">' . esc_html( get_bloginfo( 'name' ) ) . '</a></p>';
		echo '</div>';
		exit;
	}
}

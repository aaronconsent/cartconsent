<?php
/**
 * Hosted consent delivery. This version of CartConsent uses the Consent
 * Resolve javascript and cookie banner EXCLUSIVELY — activated by the API key
 * on the Connection screen. Without a connection the banner is not served and
 * the recovery machinery stands down (capture, sends, popup, push).
 *
 * @package CartConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Injects the hosted tag and centralizes "is the module active?" checks.
 */
class CRW_Hosted {

	const CDN       = 'https://cdn.consentresolve.com/consentresolve.js';
	const DASHBOARD = 'https://app.consentresolve.com/';

	/**
	 * Hook up.
	 */
	public function register() {
		add_action( 'wp_head', array( $this, 'inject_tag' ), 1 );
	}

	/**
	 * The module is active only when a Consent Resolve API key + Site ID are
	 * present. Everything user-facing keys off this.
	 */
	public static function active() {
		return CRW_Connection::is_connected();
	}

	/**
	 * Whether the hosted cookie banner is being served.
	 */
	public static function banner_active() {
		return self::active();
	}

	/**
	 * Hosted dashboard URL (Consent Records and Privacy Requests live there).
	 */
	public static function dashboard_url() {
		return self::DASHBOARD;
	}

	/**
	 * Inject the hosted Consent Resolve tag (banner + consent + identity).
	 */
	public function inject_tag() {
		if ( is_admin() || ! self::active() ) {
			return;
		}
		$c = CRW_Connection::config();
		echo "\n<!-- CartConsent (Consent Resolve) -->\n";
		printf( '<script src="%s" async></script>' . "\n", esc_url( self::CDN ) );
		printf(
			'<script>window.ConsentResolveQ=window.ConsentResolveQ||[];window.ConsentResolveQ.push(["init",%s]);window.ConsentResolveQ.push(["page"]);</script>' . "\n",
			wp_json_encode( array( 'siteId' => $c['site_id'] ) ) // phpcs:ignore WordPress.Security.EscapeOutput -- JSON of a sanitized ID.
		);
	}
}

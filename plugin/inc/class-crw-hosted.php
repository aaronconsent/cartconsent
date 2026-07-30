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

	const CDN         = 'https://cdn.consentresolve.com/consentresolve.js';
	const DASHBOARD   = 'https://app.consentresolve.com/';
	const SETTINGS_ID = 'NToLbWs-EAo6fS'; // Usercentrics settings for this product (fixed; siteId varies per store).

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
	 * The embed snippet for the connected site — the platform's exact tag
	 * format. Single source of truth: inject_tag() outputs this verbatim, so
	 * the admin's copy-paste display can never drift from reality. The loader
	 * is deliberately synchronous: the inline init right after it needs the
	 * ConsentResolve global to exist.
	 */
	public static function embed_snippet() {
		$c = CRW_Connection::config();
		return '<script src="' . self::CDN . '"></script>'
			. "<script>ConsentResolve.init({siteId:'" . esc_js( $c['site_id'] ) . "',usercentricsSettingsId:'" . esc_js( self::SETTINGS_ID ) . "',gcm:true});ConsentResolve.page();</script>";
	}

	/**
	 * Inject the hosted Consent Resolve tag (banner + consent + identity).
	 */
	public function inject_tag() {
		if ( is_admin() || ! self::active() ) {
			return;
		}
		echo "\n<!-- CartConsent (Consent Resolve) -->\n";
		echo self::embed_snippet() . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed markup + esc_js()'d ids.
	}
}

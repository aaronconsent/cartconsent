<?php
/**
 * The plugin's own CartConsent account — Site ID + API key — and the signed
 * edge calls that power consent-filtered audience sync and opt-out propagation.
 * Self-contained: no dependency on the Consent Resolve core plugin.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edge connection + credentials.
 */
class CRW_Connection {

	const BASE = 'https://edge.consentresolve.com';

	/**
	 * Stored credentials (api_key may come from a wp-config constant).
	 */
	public static function config() {
		$c = (array) CRW_Options::get( 'connection', array() );
		$key = defined( 'CONSENT_RESOLVE_WOO_API_KEY' ) && CONSENT_RESOLVE_WOO_API_KEY
			? CONSENT_RESOLVE_WOO_API_KEY
			: ( $c['api_key'] ?? '' );
		return array(
			'site_id' => (string) ( $c['site_id'] ?? '' ),
			'api_key' => (string) $key,
		);
	}

	/**
	 * Whether a CartConsent account is connected.
	 */
	public static function is_connected() {
		$c = self::config();
		return '' !== $c['site_id'] && '' !== $c['api_key'];
	}

	/**
	 * Available resolution credits, cached for 10 minutes. Returns an int, or
	 * null when the edge is unreachable / not yet provisioned (fail-open: the
	 * dashboard shows "n/a" rather than an error).
	 */
	public static function credits() {
		if ( ! self::is_connected() ) {
			return null;
		}
		$cached = get_transient( 'crw_credits' );
		if ( false !== $cached ) {
			return 'na' === $cached ? null : (int) $cached;
		}
		$res     = self::signed_request( '/v1/credits', 'POST', array() );
		$credits = is_array( $res ) && isset( $res['credits'] ) ? (int) $res['credits'] : null;
		set_transient( 'crw_credits', null === $credits ? 'na' : $credits, 10 * MINUTE_IN_SECONDS );
		return $credits;
	}

	/**
	 * A signed edge request. Returns the decoded body, or null (fail-open).
	 *
	 * @param string $path   Path.
	 * @param string $method HTTP method.
	 * @param array  $data   Body.
	 */
	public static function signed_request( $path, $method = 'POST', $data = array() ) {
		$c = self::config();
		if ( '' === $c['api_key'] ) {
			return null;
		}
		$body = wp_json_encode( $data );
		$args = array(
			'method'  => $method,
			'timeout' => 12,
			'headers' => array(
				'Content-Type'   => 'application/json',
				'X-CR-Site'      => $c['site_id'],
				'X-CR-Signature' => hash_hmac( 'sha256', $body, $c['api_key'] ),
				'X-CR-Timestamp' => (string) time(),
				'X-CR-Event-Id'  => wp_generate_uuid4(),
			),
		);
		if ( 'GET' !== $method ) {
			$args['body'] = $body;
		}
		$res = wp_remote_request( self::BASE . $path, $args );
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 400 ) {
			return null;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $res ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Propagate an opt-out / removal to the edge (also honored by Consent
	 * Resolve core when present, for a unified opt-out).
	 *
	 * @param string $email Email.
	 */
	public static function optout( $email ) {
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}
		if ( self::is_connected() ) {
			self::signed_request( '/v1/optout', 'POST', array( 'email' => $email ) );
		} elseif ( class_exists( 'CR_Edge' ) ) {
			// Fall back to the core plugin's connection if it happens to be present.
			CR_Edge::signed_request( '/v1/optout', 'POST', array( 'email' => $email ) );
		}
	}

	/**
	 * Add a consented shopper to the retargeting audience via the edge.
	 *
	 * @param string $email Email.
	 * @param string $source Source tag.
	 */
	public static function audience_add( $email, $source = 'woo_abandoned_cart' ) {
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}
		if ( self::is_connected() ) {
			self::signed_request( '/v1/audiences/add', 'POST', array( 'email' => $email, 'source' => $source, 'basis' => 'optin' ) );
		} elseif ( class_exists( 'CR_Edge' ) ) {
			CR_Edge::signed_request( '/v1/audiences/add', 'POST', array( 'email' => $email, 'source' => $source, 'basis' => 'optin' ) );
		}
	}
}

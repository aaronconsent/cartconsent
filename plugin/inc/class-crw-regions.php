<?php
/**
 * Regulatory engine — region profiles + consent categories for the built-in
 * consent banner. Self-contained: the active region is chosen manually, or
 * resolved from CDN geo headers when auto-detect is on.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Region profiles + the WP-Consent-API-standard category set.
 */
class CRW_Regions {

	/** EU/EEA + UK + Switzerland (opt-in). */
	const OPT_IN_COUNTRIES = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'GB', 'UK', 'IS', 'LI', 'NO', 'CH' );

	/**
	 * The five standard consent categories (WP Consent API set).
	 */
	public static function categories() {
		return array(
			'functional'           => array( 'label' => __( 'Essential', 'consent-resolve-woo' ), 'desc' => __( 'Required for the site to work — security, load balancing, and remembering your choices. Always on.', 'consent-resolve-woo' ), 'locked' => true ),
			'preferences'          => array( 'label' => __( 'Preferences', 'consent-resolve-woo' ), 'desc' => __( 'Remembers choices like language, region, and layout.', 'consent-resolve-woo' ), 'locked' => false ),
			'statistics'           => array( 'label' => __( 'Statistics', 'consent-resolve-woo' ), 'desc' => __( 'Helps us understand how visitors use the site so we can improve it.', 'consent-resolve-woo' ), 'locked' => false ),
			'statistics_anonymous' => array( 'label' => __( 'Anonymous statistics', 'consent-resolve-woo' ), 'desc' => __( 'Aggregate, non-identifying usage measurement.', 'consent-resolve-woo' ), 'locked' => false ),
			'marketing'            => array( 'label' => __( 'Marketing', 'consent-resolve-woo' ), 'desc' => __( 'Used to measure and personalize advertising across sites.', 'consent-resolve-woo' ), 'locked' => false ),
		);
	}

	/**
	 * Region profile definitions (no translated text — read on logic paths).
	 */
	public static function profiles() {
		return array(
			'us'        => array( 'model' => 'opt_out', 'default_grant' => array( 'functional', 'preferences', 'statistics', 'statistics_anonymous', 'marketing' ), 'gpc_mandatory' => true, 'do_not_sell' => true ),
			'eu'        => array( 'model' => 'opt_in', 'default_grant' => array( 'functional' ), 'gpc_mandatory' => true, 'do_not_sell' => false ),
			'canada'    => array( 'model' => 'opt_in', 'default_grant' => array( 'functional' ), 'gpc_mandatory' => true, 'do_not_sell' => false ),
			'worldwide' => array( 'model' => 'opt_in', 'default_grant' => array( 'functional' ), 'gpc_mandatory' => true, 'do_not_sell' => false ),
		);
	}

	/** Translated labels (display only). */
	public static function labels() {
		return array(
			'us'        => __( 'United States (opt-out + Do Not Sell/Share)', 'consent-resolve-woo' ),
			'eu'        => __( 'EU / UK / EEA / Switzerland (opt-in)', 'consent-resolve-woo' ),
			'canada'    => __( 'Canada (opt-in)', 'consent-resolve-woo' ),
			'worldwide' => __( 'Worldwide (opt-in — safest)', 'consent-resolve-woo' ),
		);
	}

	/**
	 * Label for a key.
	 *
	 * @param string $key Key.
	 */
	public static function label( $key ) {
		$l = self::labels();
		return $l[ $key ] ?? $l['us'];
	}

	/**
	 * The active region KEY: auto-detected from geo when enabled, else the
	 * manual setting.
	 */
	public static function active_key() {
		if ( CRW_Options::get( 'banner.auto_region', true ) ) {
			$key = self::from_geo();
			if ( '' !== $key ) {
				return $key;
			}
		}
		$manual   = (string) CRW_Options::get( 'banner.region', 'us' );
		$profiles = self::profiles();
		return isset( $profiles[ $manual ] ) ? $manual : 'us';
	}

	/**
	 * Resolve a region key from CDN geo headers, or '' if unknown.
	 */
	protected static function from_geo() {
		$country = '';
		foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_X_COUNTRY_CODE' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$c = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), 0, 2 ) );
				if ( preg_match( '/^[A-Z]{2}$/', $c ) && 'XX' !== $c ) {
					$country = $c;
					break;
				}
			}
		}
		if ( '' === $country ) {
			return '';
		}
		if ( 'US' === $country ) {
			return 'us';
		}
		if ( 'CA' === $country ) {
			return 'canada';
		}
		if ( in_array( $country, self::OPT_IN_COUNTRIES, true ) ) {
			return 'eu';
		}
		return 'worldwide';
	}

	/**
	 * The active profile, plus a resolved `banner` flag and `mode`.
	 */
	public static function profile() {
		$key      = self::active_key();
		$profiles = self::profiles();
		$p        = $profiles[ $key ] ?? $profiles['us'];
		$p['key']    = $key;
		$p['mode']   = $p['model'];
		$p['banner'] = (bool) CRW_Options::get( 'banner.enabled', true );
		return $p;
	}

	/**
	 * Categories granted before an explicit choice under the active profile.
	 */
	public static function default_grants() {
		$p = self::profile();
		return isset( $p['default_grant'] ) ? $p['default_grant'] : array( 'functional' );
	}
}

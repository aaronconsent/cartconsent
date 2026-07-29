<?php
/**
 * The recovery consent gate. Decides whether an abandoning shopper may be
 * emailed and whether they may feed a retargeting audience — by region,
 * explicit opt-in, and Global Privacy Control. (Distinct from CRW_Consent,
 * which is the cookie-banner consent authority.)
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consent decisions for cart recovery.
 */
class CRW_Recovery_Consent {

	/**
	 * The visitor's jurisdiction model for THIS request: 'opt_in' | 'opt_out'.
	 */
	public static function region_model() {
		// Prefer our own geo-aware region engine.
		if ( class_exists( 'CRW_Regions' ) ) {
			$p = CRW_Regions::profile();
			return ( isset( $p['model'] ) && 'opt_in' === $p['model'] ) ? 'opt_in' : 'opt_out';
		}
		// Then Consent Resolve core, if present.
		if ( class_exists( 'CR_Jurisdictions' ) && CR_Jurisdictions::enabled() ) {
			return ( CR_Jurisdictions::OPT_IN === CR_Jurisdictions::mode() ) ? 'opt_in' : 'opt_out';
		}
		return 'opt_in' === CRW_Options::get( 'consent.fallback_model', 'opt_out' ) ? 'opt_in' : 'opt_out';
	}

	/**
	 * Best-effort 2-letter country code (from CDN geo headers).
	 */
	public static function region_code() {
		foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_X_COUNTRY_CODE' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$c = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ), 0, 2 ) );
				if ( preg_match( '/^[A-Z]{2}$/', $c ) && 'XX' !== $c ) {
					return $c;
				}
			}
		}
		return '';
	}

	/**
	 * A GPC opt-out present + honored?
	 */
	public static function gpc() {
		if ( ! CRW_Options::get( 'consent.honor_gpc', true ) ) {
			return false;
		}
		return isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === (string) $_SERVER['HTTP_SEC_GPC']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Explicit marketing opt-in from any recognized source.
	 *
	 * @param bool $checkbox Checkout consent box ticked.
	 */
	public static function has_optin( $checkbox ) {
		if ( $checkbox ) {
			return true;
		}
		if ( function_exists( 'wp_has_consent' ) && wp_has_consent( 'marketing' ) ) {
			return true;
		}
		if ( class_exists( 'CRW_Consent' ) && CRW_Consent::allows( 'marketing' ) ) {
			return true;
		}
		if ( class_exists( 'CR_Consent' ) && CR_Consent::allows( 'marketing' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Resolve the lawful basis for emailing: 'optin' | 'legitimate' | 'none'.
	 *
	 * @param bool $opted_in Explicit opt-in.
	 * @param bool $gpc      GPC present.
	 */
	public static function resolve_basis( $opted_in, $gpc ) {
		if ( $opted_in ) {
			return 'optin';
		}
		$basis = CRW_Options::get( 'consent.basis', 'jurisdiction' );
		if ( 'optin_only' === $basis ) {
			return 'none';
		}
		if ( 'all_unsub' === $basis ) {
			return $gpc ? 'none' : 'legitimate';
		}
		if ( 'opt_in' === self::region_model() ) {
			return 'none';
		}
		return $gpc ? 'none' : 'legitimate';
	}

	/**
	 * May we email under this basis?
	 *
	 * @param string $basis Basis.
	 */
	public static function can_email( $basis ) {
		return in_array( $basis, array( 'optin', 'legitimate' ), true );
	}

	/**
	 * May this shopper feed a retargeting audience? Explicit opt-in + no GPC.
	 *
	 * @param string $basis Basis.
	 * @param bool   $gpc   GPC.
	 */
	public static function can_audience( $basis, $gpc ) {
		return 'optin' === $basis && ! $gpc;
	}

	/**
	 * Human label for a stored basis.
	 *
	 * @param string $basis Basis.
	 */
	public static function basis_label( $basis ) {
		$map = array(
			'optin'      => __( 'Opted in', 'consent-resolve-woo' ),
			'legitimate' => __( 'Soft opt-in', 'consent-resolve-woo' ),
			'none'       => __( 'No consent', 'consent-resolve-woo' ),
		);
		return $map[ $basis ] ?? $basis;
	}
}

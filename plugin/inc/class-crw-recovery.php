<?php
/**
 * Cart recovery: a tokened link that rehydrates the exact cart and (optionally)
 * applies a single-use incentive coupon, then sends the shopper to checkout.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recovery link handler + coupon minting.
 */
class CRW_Recovery {

	const QUERY = 'crw_recover';

	/**
	 * Hook the front-end handler late, once WC()->cart exists.
	 */
	public function register() {
		add_action( 'template_redirect', array( $this, 'maybe_restore' ), 5 );
	}

	/**
	 * The recovery URL for a cart row.
	 *
	 * @param array $row Cart row.
	 */
	public static function url( array $row ) {
		$base = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' );
		return add_query_arg( self::QUERY, rawurlencode( (string) $row['cart_token'] ), $base );
	}

	/**
	 * Restore a cart from a token and redirect to checkout.
	 */
	public function maybe_restore() {
		if ( empty( $_GET[ self::QUERY ] ) || ! function_exists( 'WC' ) || ! WC()->cart ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$row = CRW_Carts_Store::get_by_token( $token );
		// Ignore links for carts that are done (recovered/unsubscribed) — nothing
		// to restore, and it avoids re-applying a spent coupon.
		if ( ! $row || in_array( (string) $row['status'], array( 'recovered', 'unsubscribed' ), true ) ) {
			return;
		}

		$cart = WC()->cart;
		$cart->empty_cart();
		foreach ( CRW_Carts_Store::items( $row ) as $item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			$cart->add_to_cart(
				$pid,
				max( 1, (int) ( $item['quantity'] ?? 1 ) ),
				(int) ( $item['variation_id'] ?? 0 ),
				isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array()
			);
		}

		// Re-apply the incentive coupon if one was issued for this cart.
		if ( ! empty( $row['coupon_code'] ) && ! $cart->has_discount( $row['coupon_code'] ) ) {
			$cart->apply_coupon( $row['coupon_code'] );
		}

		CRW_Events::log( (int) $row['id'], 'recovery_clicked' );

		wp_safe_redirect( function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ) );
		exit;
	}

	/**
	 * Mint (once) a single-use coupon locked to the shopper's email for a cart.
	 * Returns the coupon code, or '' if coupons are disabled/unavailable.
	 *
	 * @param array  $row   Cart row.
	 * @param string $email Decrypted shopper email.
	 */
	public static function mint_coupon( array $row, $email ) {
		if ( ! CRW_Options::get( 'coupon.enabled', false ) || ! class_exists( 'WC_Coupon' ) ) {
			return '';
		}
		if ( ! empty( $row['coupon_code'] ) ) {
			return (string) $row['coupon_code']; // Already minted.
		}
		// Never mint an unrestricted coupon we can't lock to a shopper.
		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}
		$prefix = preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) CRW_Options::get( 'coupon.prefix', 'COMEBACK' ) ) );
		$code   = ( $prefix ? $prefix : 'COMEBACK' ) . '-' . strtoupper( bin2hex( random_bytes( 6 ) ) );

		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' === CRW_Options::get( 'coupon.type', 'percent' ) ? 'fixed_cart' : 'percent' );
		$coupon->set_amount( (float) CRW_Options::get( 'coupon.amount', 10 ) );
		$coupon->set_individual_use( true );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$days = max( 1, (int) CRW_Options::get( 'coupon.expiry_days', 7 ) );
		$coupon->set_date_expires( time() + $days * DAY_IN_SECONDS );
		if ( $email && is_email( $email ) ) {
			$coupon->set_email_restrictions( array( $email ) ); // Only this shopper can redeem it.
		}
		$coupon->set_description( 'Consent Resolve cart recovery' );
		$coupon->save();

		CRW_Carts_Store::set_coupon( (int) $row['id'], $code );
		return $code;
	}

	/**
	 * A human coupon label for merge tags, e.g. "10%" or "$10".
	 */
	public static function coupon_label() {
		$amt = (float) CRW_Options::get( 'coupon.amount', 10 );
		if ( 'fixed_cart' === CRW_Options::get( 'coupon.type', 'percent' ) ) {
			return ( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$' ) . rtrim( rtrim( number_format( $amt, 2 ), '0' ), '.' );
		}
		return rtrim( rtrim( number_format( $amt, 2 ), '0' ), '.' ) . '%';
	}
}

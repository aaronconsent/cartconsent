<?php
/**
 * Tiny transient-backed rate limiter for the public endpoints (a shared
 * anonymous nonce is only light CSRF friction, not a throttle).
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rate limiting.
 */
class CRW_Rate {

	/**
	 * Whether an action keyed by $key is still within budget. Increments the
	 * counter as a side effect.
	 *
	 * @param string $key    Bucket key (already namespaced/hashed).
	 * @param int    $max    Max events per window.
	 * @param int    $window Window in seconds.
	 */
	public static function ok( $key, $max, $window ) {
		$window = max( 1, (int) $window );
		// Fixed time-window bucket: the key rolls over every $window seconds, so a
		// continuously-busy shared IP (corporate NAT) always recovers at the next
		// window boundary. (A plain per-hit set_transient re-extends the TTL on
		// every request, so the window would never elapse and the IP would stay
		// blocked indefinitely.)
		$slice = (int) floor( time() / $window );
		$k     = 'crw_rl_' . md5( (string) $key . '|' . $window . '|' . $slice );
		$n     = (int) get_transient( $k );
		if ( $n >= (int) $max ) {
			return false;
		}
		set_transient( $k, $n + 1, $window + 60 );
		return true;
	}

	/**
	 * A hashed key for the current request IP.
	 *
	 * @param string $prefix Prefix.
	 */
	public static function ip_key( $prefix ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification
		return $prefix . ':' . $ip;
	}
}

<?php
/**
 * Lost-revenue estimate. The free plugin can MEASURE two of the three factors:
 * anonymous carts (guest WooCommerce sessions with items — counted locally,
 * no PII touched) and the store's own recovery rate. Only the resolution rate
 * is assumed (merchant-adjustable, default 20%). Everything shown is labeled
 * an estimate with the math visible — never blended into measured revenue.
 *
 * @package CartConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Anonymous-cart observation + the estimate math.
 */
class CRW_Estimates {

	const SEEN_OPT   = 'crw_anon_seen'; // [ hash => expires_ts ] — session keys, hashed.
	const SCAN_LOCK  = 'crw_anon_scanned';
	const EVENT_TYPE = 'anon_cart';

	/**
	 * Observe guest sessions that hold a cart, at most once an hour (called from
	 * the recovery cron). Each new session is logged ONCE as an 'anon_cart'
	 * event carrying the cart's real value. Session keys are stored only as
	 * one-way hashes with a short TTL — no identifier, no PII.
	 */
	public static function maybe_scan() {
		if ( get_transient( self::SCAN_LOCK ) ) {
			return;
		}
		set_transient( self::SCAN_LOCK, 1, HOUR_IN_SECONDS );

		global $wpdb;
		$table = $wpdb->prefix . 'woocommerce_sessions';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
			return;
		}
		$rows = $wpdb->get_results( "SELECT session_key, session_value FROM {$table} WHERE session_expiry > UNIX_TIMESTAMP() LIMIT 500", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( empty( $rows ) ) {
			return;
		}

		$seen = self::seen();
		$now  = time();
		foreach ( $rows as $row ) {
			// Logged-in customers have a numeric session key (their user id); the
			// anonymous shoppers resolution could name are the guest sessions.
			if ( is_numeric( $row['session_key'] ) ) {
				continue;
			}
			$hash = hash( 'sha256', 'anon:' . $row['session_key'] );
			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}
			$cents = self::session_cart_cents( (string) $row['session_value'] );
			// Woo sessions live ~48h; remember a bit longer so we never double count.
			$seen[ $hash ] = $now + 4 * DAY_IN_SECONDS;
			if ( $cents > 0 ) {
				CRW_Events::log( 0, self::EVENT_TYPE, $cents );
			}
		}
		self::save_seen( $seen );
	}

	/**
	 * A submitted checkout is not anonymous — mark its session so it is never
	 * (or no longer) counted toward the anonymous pool going forward.
	 */
	public static function mark_session_captured() {
		if ( ! function_exists( 'WC' ) || ! WC()->session || ! is_callable( array( WC()->session, 'get_customer_id' ) ) ) {
			return;
		}
		$key = (string) WC()->session->get_customer_id();
		if ( '' === $key ) {
			return;
		}
		$seen = self::seen();
		$seen[ hash( 'sha256', 'anon:' . $key ) ] = time() + 4 * DAY_IN_SECONDS;
		self::save_seen( $seen );
	}

	/**
	 * Parse a serialized Woo session for its cart total, in cents (0 = no cart).
	 *
	 * @param string $blob Raw session_value.
	 */
	protected static function session_cart_cents( $blob ) {
		$data = maybe_unserialize( $blob );
		if ( ! is_array( $data ) ) {
			return 0;
		}
		$cart = isset( $data['cart'] ) ? maybe_unserialize( $data['cart'] ) : null;
		if ( empty( $cart ) || ! is_array( $cart ) ) {
			return 0;
		}
		$totals = isset( $data['cart_totals'] ) ? maybe_unserialize( $data['cart_totals'] ) : null;
		if ( is_array( $totals ) && isset( $totals['total'] ) && (float) $totals['total'] > 0 ) {
			return (int) round( (float) $totals['total'] * 100 );
		}
		$sum = 0;
		foreach ( $cart as $item ) {
			if ( is_array( $item ) && isset( $item['line_total'] ) ) {
				$sum += (float) $item['line_total'];
			}
		}
		return (int) round( $sum * 100 );
	}

	/* ------------------------------------------------------------- estimate */

	/**
	 * The estimate, with every factor exposed so the UI can show its math.
	 * Returns null when there is nothing observed yet.
	 */
	public static function estimate() {
		global $wpdb;
		$events = $wpdb->prefix . 'crw_events';
		$since  = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$anon   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(value_cents),0) AS cents FROM {$events} WHERE type = %s AND created_at >= %s", self::EVENT_TYPE, $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( empty( $anon ) || (int) $anon['n'] < 1 ) {
			return null;
		}

		$f         = CRW_Carts_Store::funnel();
		$captured  = (int) ( $f['captured'] ?? 0 );
		$recovered = (int) ( $f['recovered'] ?? 0 );
		// Measured recovery rate; a young store with no outcomes yet uses a
		// deliberately conservative 10% and the UI says so.
		$rec_rate     = $captured > 0 && $recovered > 0 ? $recovered / $captured : 0.10;
		$rec_measured = $captured > 0 && $recovered > 0;

		$res_rate = max( 1, min( 100, (int) CRW_Options::get( 'estimates.resolution_rate', 20 ) ) ) / 100;

		return self::estimate_from( (int) $anon['n'], (int) $anon['cents'], $res_rate, $rec_rate, $rec_measured );
	}

	/**
	 * Pure math, separated for tests: anonymous pool × resolution rate ×
	 * recovery rate.
	 *
	 * @param int   $anon_count   Anonymous carts observed (30d).
	 * @param int   $anon_cents   Their combined value.
	 * @param float $res_rate     Assumed resolution rate (0-1).
	 * @param float $rec_rate     Recovery rate (0-1).
	 * @param bool  $rec_measured Whether the recovery rate is measured or a fallback.
	 */
	public static function estimate_from( $anon_count, $anon_cents, $res_rate, $rec_rate, $rec_measured = true ) {
		$resolvable       = (int) round( $anon_count * $res_rate );
		$recoverable_cents = (int) round( $anon_cents * $res_rate * $rec_rate );
		return array(
			'anon_count'        => (int) $anon_count,
			'anon_cents'        => (int) $anon_cents,
			'res_rate'          => $res_rate,
			'rec_rate'          => $rec_rate,
			'rec_measured'      => (bool) $rec_measured,
			'resolvable'        => $resolvable,
			'recoverable_cents' => $recoverable_cents,
		);
	}

	/* ----------------------------------------------------------- seen store */

	/** @return array hash => expiry, pruned. */
	protected static function seen() {
		$seen = get_option( self::SEEN_OPT, array() );
		if ( ! is_array( $seen ) ) {
			return array();
		}
		$now = time();
		foreach ( $seen as $hash => $exp ) {
			if ( (int) $exp < $now ) {
				unset( $seen[ $hash ] );
			}
		}
		return $seen;
	}

	/**
	 * Persist the seen set (bounded — prune oldest beyond 5,000 entries).
	 *
	 * @param array $seen Hash => expiry.
	 */
	protected static function save_seen( array $seen ) {
		if ( count( $seen ) > 5000 ) {
			asort( $seen );
			$seen = array_slice( $seen, -5000, null, true );
		}
		update_option( self::SEEN_OPT, $seen, false );
	}
}

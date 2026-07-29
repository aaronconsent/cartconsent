<?php
/**
 * Minimal, PII-free event log for reporting (captures, sends, recoveries).
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * crw_events writer + simple aggregates.
 */
class CRW_Events {

	/**
	 * Record an event.
	 *
	 * @param int    $cart_id     Cart row id (0 if n/a).
	 * @param string $type        captured | email_sent | recovered | unsubscribed | audience_synced.
	 * @param int    $value_cents Optional monetary value.
	 */
	public static function log( $cart_id, $type, $value_cents = 0, $label = '' ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB
			$wpdb->prefix . 'crw_events',
			array(
				'cart_id'     => (int) $cart_id,
				'type'        => substr( (string) $type, 0, 24 ),
				'label'       => '' !== (string) $label ? substr( (string) $label, 0, 80 ) : null,
				'value_cents' => (int) $value_cents,
				'created_at'  => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Count events of a type carrying a given label (for A/B + per-sequence stats).
	 *
	 * @param string $type  Type.
	 * @param string $label Exact label.
	 */
	public static function count_label( $type, $label ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}crw_events WHERE type=%s AND label=%s", (string) $type, (string) $label ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Count events of a type within the last N days.
	 *
	 * @param string $type Type.
	 * @param int    $days Window.
	 */
	public static function count( $type, $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}crw_events WHERE type=%s AND created_at >= %s", (string) $type, $since ) ); // phpcs:ignore WordPress.DB
	}
}

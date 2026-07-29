<?php
/**
 * Web-push subscription storage. A subscription is linked to a shopper by
 * hashed email and/or user id so an abandoned cart can be matched to the
 * browser that should receive the push.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes crw_push.
 */
class CRW_Push_Store {

	protected static function table() {
		global $wpdb;
		return $wpdb->prefix . 'crw_push';
	}

	/**
	 * Save (upsert by endpoint) a subscription.
	 *
	 * @param array  $sub        { endpoint, keys: { p256dh, auth } }.
	 * @param string $email_hash Hashed email (or '').
	 * @param int    $user_id    User id (0 for guests).
	 * @return bool
	 */
	public static function save( array $sub, $email_hash, $user_id ) {
		global $wpdb;
		$endpoint = esc_url_raw( (string) ( $sub['endpoint'] ?? '' ) );
		$p256dh   = (string) ( $sub['keys']['p256dh'] ?? '' );
		$auth     = (string) ( $sub['keys']['auth'] ?? '' );
		if ( '' === $endpoint || '' === $p256dh || '' === $auth || ! self::endpoint_allowed( $endpoint ) ) {
			return false;
		}
		$now  = current_time( 'mysql', true );
		$data = array(
			'p256dh'     => substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $p256dh ), 0, 120 ),
			'auth'       => substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', $auth ), 0, 40 ),
			'email_hash' => $email_hash ? substr( (string) $email_hash, 0, 64 ) : null,
			'user_id'    => (int) $user_id,
			'updated_at' => $now,
		);
		$table = self::table();
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE endpoint = %s", $endpoint ) ); // phpcs:ignore WordPress.DB
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
			return true;
		}
		$data['endpoint']   = $endpoint;
		$data['created_at'] = $now;
		return (bool) $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB
	}

	/**
	 * SSRF guard: only ever store/POST to a real push-service host. The endpoint
	 * is attacker-supplied (it comes from the browser's PushSubscription) and is
	 * later fetched server-side, so an unrestricted value could point wp_remote_post
	 * at an internal address. Match the four browser push backends.
	 *
	 * @param string $endpoint Subscription endpoint URL.
	 */
	public static function endpoint_allowed( $endpoint ) {
		$host = wp_parse_url( (string) $endpoint, PHP_URL_HOST );
		$sch  = wp_parse_url( (string) $endpoint, PHP_URL_SCHEME );
		if ( 'https' !== $sch || ! $host ) {
			return false;
		}
		$host = strtolower( $host );
		foreach ( array( 'fcm.googleapis.com', 'android.googleapis.com', '.push.services.mozilla.com', '.notify.windows.com', '.push.apple.com' ) as $ok ) {
			if ( $host === ltrim( $ok, '.' ) || ( '.' === $ok[0] && substr( $host, - strlen( $ok ) ) === $ok ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The best subscription for a cart row (match by email hash, then user id).
	 *
	 * @param array $row Cart row.
	 */
	public static function for_cart( array $row ) {
		global $wpdb;
		$table = self::table();
		if ( ! empty( $row['email_hash'] ) ) {
			$s = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s ORDER BY id DESC LIMIT 1", (string) $row['email_hash'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
			if ( $s ) {
				return $s;
			}
		}
		if ( ! empty( $row['user_id'] ) ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1", (int) $row['user_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		return null;
	}

	/**
	 * Delete a subscription by endpoint (e.g. on a 404/410 from the push service).
	 *
	 * @param string $endpoint Endpoint.
	 */
	public static function delete( $endpoint ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'endpoint' => esc_url_raw( (string) $endpoint ) ) ); // phpcs:ignore WordPress.DB
	}
}

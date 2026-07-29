<?php
/**
 * Consent state + tamper-evident, hash-chained consent records + WP Consent API
 * provider. This is the site's consent authority when the built-in banner runs.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * The consent authority.
 */
class CRW_Consent {

	const COOKIE  = 'crw_consent';
	const VISITOR = 'crw_vid';

	/**
	 * Register as a WP Consent API provider.
	 */
	public function register() {
		// Defer to init: reading options at plugins_loaded builds the translated
		// defaults before init and trips _load_textdomain_just_in_time.
		add_action( 'init', array( $this, 'wire' ) );
	}

	/**
	 * Option-dependent wiring (runs on init).
	 */
	public function wire() {
		if ( ! CRW_Options::get( 'banner.enabled', true ) ) {
			return;
		}
		add_filter(
			'wp_get_consent_type',
			function () {
				$p = CRW_Regions::profile();
				return 'opt_in' === $p['model'] ? 'optin' : 'optout';
			}
		);
		add_filter( 'wp_consent_api_registered_' . CRW_BASENAME, '__return_true' );
	}

	/**
	 * The consent cookie parsed to a category => bool map, honoring GPC.
	 */
	public static function state() {
		$granted = array( 'functional' => true );
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			foreach ( CRW_Regions::default_grants() as $cat ) {
				$granted[ $cat ] = true;
			}
		} else {
			$raw  = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$data = json_decode( $raw, true );
			if ( is_array( $data ) && ! empty( $data['c'] ) && is_array( $data['c'] ) ) {
				foreach ( CRW_Regions::categories() as $cat => $_m ) {
					$granted[ $cat ] = ( 'functional' === $cat ) ? true : ! empty( $data['c'][ $cat ] );
				}
			}
		}
		if ( self::gpc_signal() ) {
			$granted['marketing']            = false;
			$granted['statistics']           = false;
			$granted['statistics_anonymous'] = false;
		}
		return $granted;
	}

	/**
	 * Whether a GPC opt-out is present + honored.
	 */
	protected static function gpc_signal() {
		if ( ! CRW_Options::get( 'signals.gpc', true ) ) {
			return false;
		}
		return isset( $_SERVER['HTTP_SEC_GPC'] ) && '1' === (string) $_SERVER['HTTP_SEC_GPC']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Whether a category currently has consent (server-side).
	 *
	 * @param string $category Category.
	 */
	public static function allows( $category ) {
		$s = self::state();
		return ! empty( $s[ $category ] );
	}

	/**
	 * Append a hash-chained consent record.
	 *
	 * @param array $args event_type, method, categories, visitor_id.
	 * @return string|false Record hash or false.
	 */
	public static function record( array $args ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'crw_consent_records';
		$visitor = isset( $args['visitor_id'] ) ? substr( preg_replace( '/[^a-f0-9\-]/', '', (string) $args['visitor_id'] ), 0, 36 ) : self::visitor_id();
		$cats    = isset( $args['categories'] ) && is_array( $args['categories'] ) ? $args['categories'] : array();

		$payload = array(
			'visitor_id' => $visitor,
			'event_type' => isset( $args['event_type'] ) ? substr( (string) $args['event_type'], 0, 24 ) : 'grant',
			'method'     => isset( $args['method'] ) ? substr( (string) $args['method'], 0, 24 ) : 'banner',
			'categories' => wp_json_encode( $cats ),
			'region'     => CRW_Regions::active_key(),
			'ip_trunc'   => self::truncate_ip(),
			'ua_hash'    => hash( 'sha256', isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '' ), // phpcs:ignore WordPress.Security.NonceVerification
			'created_at' => current_time( 'mysql', true ), // GMT, to match every other crw_ table (carts/push/events/suppression).
		);
		$prev = $wpdb->get_var( "SELECT record_hash FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB
		$payload['prev_hash']   = $prev ? $prev : null;
		$payload['record_hash'] = hash( 'sha256', ( $prev ? $prev : '' ) . wp_json_encode( $payload ) );

		$ok = $wpdb->insert( $table, $payload ); // phpcs:ignore WordPress.DB
		return $ok ? $payload['record_hash'] : false;
	}

	/**
	 * Verify the chain — true, or the id of the first broken link.
	 */
	public static function verify_chain() {
		global $wpdb;
		$table = $wpdb->prefix . 'crw_consent_records';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB
		$prev  = null;
		foreach ( (array) $rows as $r ) {
			$payload = array(
				'visitor_id' => $r['visitor_id'],
				'event_type' => $r['event_type'],
				'method'     => $r['method'],
				'categories' => $r['categories'],
				'region'     => $r['region'],
				'ip_trunc'   => $r['ip_trunc'],
				'ua_hash'    => $r['ua_hash'],
				'created_at' => $r['created_at'],
				'prev_hash'  => $prev,
			);
			$expected = hash( 'sha256', ( $prev ? $prev : '' ) . wp_json_encode( $payload ) );
			if ( ! hash_equals( $expected, (string) $r['record_hash'] ) ) {
				return (int) $r['id'];
			}
			$prev = $r['record_hash'];
		}
		return true;
	}

	/**
	 * Stable pseudonymous visitor id.
	 */
	public static function visitor_id() {
		if ( ! empty( $_COOKIE[ self::VISITOR ] ) ) {
			$v = preg_replace( '/[^a-f0-9\-]/', '', sanitize_text_field( wp_unslash( $_COOKIE[ self::VISITOR ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( 36 === strlen( $v ) ) {
				return $v;
			}
		}
		return wp_generate_uuid4();
	}

	/**
	 * Truncate the IP for minimization (v4 → /24, v6 → /48).
	 */
	protected static function truncate_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$p    = explode( '.', $ip );
			$p[3] = '0';
			return implode( '.', $p );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = @inet_pton( $ip ); // phpcs:ignore
			if ( false !== $packed && 16 === strlen( $packed ) ) {
				return (string) inet_ntop( substr( $packed, 0, 6 ) . str_repeat( "\0", 10 ) );
			}
		}
		return '0.0.0.0';
	}

	/**
	 * Records for the admin screen (newest first).
	 *
	 * @param int $limit Limit.
	 */
	public static function records( $limit = 200 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'crw_consent_records';
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Total record count.
	 */
	public static function count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}crw_consent_records" ); // phpcs:ignore WordPress.DB
	}
}

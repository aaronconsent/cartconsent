<?php
/**
 * Activation, tables, options, capabilities, scheduled events.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Install + upgrade routines.
 */
class CRW_Install {

	const DB_VERSION = 5;
	const CRON_HOOK  = 'crw_process_queue';

	/**
	 * Fired on activation.
	 */
	public static function activate() {
		self::create_tables();
		CRW_Options::seed();
		self::add_caps();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'crw_five_minutes', self::CRON_HOOK );
		}
		update_option( 'crw_db_version', self::DB_VERSION );
	}

	/**
	 * Fired on deactivation. Data is preserved; only the schedule is cleared.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Create/upgrade custom tables via dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		// Abandoned carts. The shopper email is stored ENCRYPTED (email_enc); a
		// salted hash drives dedupe/suppression and a masked copy is for display.
		$carts = "CREATE TABLE {$p}crw_carts (
			id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			cart_token    CHAR(32)     NOT NULL,
			email_enc     TEXT         NULL,
			email_hash    CHAR(64)     NULL,
			email_masked  VARCHAR(120) NULL,
			first_name    VARCHAR(80)  NULL,
			user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
			items         LONGTEXT     NULL,
			item_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			currency      CHAR(3)      NOT NULL DEFAULT 'USD',
			total_cents   BIGINT       NOT NULL DEFAULT 0,
			status        VARCHAR(16)  NOT NULL DEFAULT 'active',
			sequence_id   VARCHAR(40)  NOT NULL DEFAULT 'default',
			consent_basis VARCHAR(16)  NOT NULL DEFAULT 'none',
			gpc           TINYINT UNSIGNED NOT NULL DEFAULT 0,
			region        VARCHAR(8)   NULL,
			step          TINYINT UNSIGNED NOT NULL DEFAULT 0,
			audience_synced TINYINT UNSIGNED NOT NULL DEFAULT 0,
			send_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			emails_sent   TINYINT UNSIGNED NOT NULL DEFAULT 0,
			coupon_code   VARCHAR(64)  NULL,
			order_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
			recovered_cents BIGINT     NOT NULL DEFAULT 0,
			last_activity DATETIME     NOT NULL,
			next_action_at DATETIME    NULL,
			created_at    DATETIME     NOT NULL,
			updated_at    DATETIME     NOT NULL,
			UNIQUE KEY uq_token (cart_token),
			KEY idx_status (status),
			KEY idx_next (next_action_at),
			KEY idx_email (email_hash),
			KEY idx_user (user_id)
		) {$charset};";
		dbDelta( $carts );

		// Suppression: one-way hashes of unsubscribed / opted-out shoppers.
		$suppress = "CREATE TABLE {$p}crw_suppression (
			id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			email_hash CHAR(64)     NOT NULL,
			reason     VARCHAR(24)  NOT NULL,
			created_at DATETIME     NOT NULL,
			UNIQUE KEY uq_hash (email_hash)
		) {$charset};";
		dbDelta( $suppress );

		// Tamper-evident, hash-chained consent records (the built-in CMP).
		$records = "CREATE TABLE {$p}crw_consent_records (
			id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			visitor_id  VARCHAR(40)  NOT NULL,
			event_type  VARCHAR(24)  NOT NULL,
			method      VARCHAR(24)  NOT NULL,
			categories  TEXT         NULL,
			region      VARCHAR(16)  NULL,
			ip_trunc    VARCHAR(64)  NULL,
			ua_hash     CHAR(64)     NULL,
			prev_hash   CHAR(64)     NULL,
			record_hash CHAR(64)     NOT NULL,
			created_at  DATETIME     NOT NULL,
			KEY idx_created (created_at),
			KEY idx_visitor (visitor_id)
		) {$charset};";
		dbDelta( $records );

		// Web-push subscriptions.
		$push = "CREATE TABLE {$p}crw_push (
			id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			endpoint   VARCHAR(500) NOT NULL,
			p256dh     VARCHAR(120) NOT NULL,
			auth       VARCHAR(40)  NOT NULL,
			email_hash CHAR(64)     NULL,
			user_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME     NOT NULL,
			updated_at DATETIME     NOT NULL,
			UNIQUE KEY uq_endpoint (endpoint(191)),
			KEY idx_email (email_hash),
			KEY idx_user (user_id)
		) {$charset};";
		dbDelta( $push );

		// Lightweight event log for reporting (no PII).
		$events = "CREATE TABLE {$p}crw_events (
			id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
			cart_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type       VARCHAR(24)  NOT NULL,
			label      VARCHAR(80)  NULL,
			value_cents BIGINT      NOT NULL DEFAULT 0,
			created_at DATETIME     NOT NULL,
			KEY idx_type (type),
			KEY idx_cart (cart_id),
			KEY idx_label (label),
			KEY idx_created (created_at)
		) {$charset};";
		dbDelta( $events );
	}

	/**
	 * Grant the manage capability to shop managers + admins.
	 */
	public static function add_caps() {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->add_cap( 'crw_manage' );
			}
		}
	}

	/**
	 * Custom five-minute cron schedule for the recovery queue.
	 *
	 * @param array $schedules Existing schedules.
	 */
	public static function cron_schedule( $schedules ) {
		$schedules['crw_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Consent Resolve)', 'consent-resolve-woo' ),
		);
		return $schedules;
	}
}

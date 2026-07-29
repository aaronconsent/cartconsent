<?php
/**
 * Data access for abandoned carts + suppression. All shopper email handling
 * goes through here so encryption/hashing/masking stay in one place.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes crw_carts + crw_suppression.
 */
class CRW_Carts_Store {

	/** @return string prefixed carts table. */
	protected static function table() {
		global $wpdb;
		return $wpdb->prefix . 'crw_carts';
	}

	/**
	 * Create or update an active cart, keyed by email hash (a returning shopper
	 * re-abandoning updates their existing row rather than duplicating).
	 *
	 * @param array $d Cart data (email, first_name, user_id, items, item_count,
	 *                 currency, total_cents, consent_basis, region, cart_token).
	 * @return int Row id (0 on failure/skip).
	 */
	public static function upsert( array $d ) {
		global $wpdb;
		$email = isset( $d['email'] ) ? sanitize_email( (string) $d['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return 0;
		}
		$hash = CRW_Crypto::email_hash( $email );
		if ( self::is_suppressed( $hash ) ) {
			return 0; // Never re-capture a suppressed shopper.
		}
		$now   = current_time( 'mysql', true );
		$table = self::table();

		$row = array(
			'email_enc'     => CRW_Crypto::encrypt( $email ),
			'email_hash'    => $hash,
			'email_masked'  => CRW_Crypto::mask( $email ),
			'first_name'    => substr( sanitize_text_field( (string) ( $d['first_name'] ?? '' ) ), 0, 80 ),
			'user_id'       => (int) ( $d['user_id'] ?? 0 ),
			'items'         => wp_json_encode( array_values( (array) ( $d['items'] ?? array() ) ) ),
			'item_count'    => (int) ( $d['item_count'] ?? 0 ),
			'currency'      => substr( (string) ( $d['currency'] ?? 'USD' ), 0, 3 ),
			'total_cents'   => (int) ( $d['total_cents'] ?? 0 ),
			'consent_basis' => in_array( ( $d['consent_basis'] ?? 'none' ), array( 'optin', 'legitimate', 'none' ), true ) ? $d['consent_basis'] : 'none',
			'gpc'           => ! empty( $d['gpc'] ) ? 1 : 0,
			'region'        => substr( (string) ( $d['region'] ?? '' ), 0, 8 ),
			'last_activity' => $now,
			'updated_at'    => $now,
		);

		// Reconcile against ANY live row for this shopper — including a
		// 'pending_confirm' popup capture — so a checkout capture upgrades that
		// single row rather than spawning a second concurrent sequence (which
		// would double-mail the shopper once the popup opt-in later confirms).
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, order_id FROM {$table} WHERE email_hash = %s AND status IN ('active','abandoned','pending_confirm') ORDER BY id DESC LIMIT 1", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $existing ) {
			// Re-activate + refresh; reset the sequence so the fresh cart gets the
			// full recovery flow from step 0. Also clear the previous cart's
			// per-cart state so the restarted sequence doesn't inherit a stale
			// (possibly expired/consumed) coupon, a carried-over retry count that
			// could prematurely mark it lost, or a set audience_synced flag that
			// would suppress re-syncing the fresh cart.
			$row['status']          = 'active';
			$row['step']            = 0;
			$row['emails_sent']     = 0;
			$row['send_attempts']   = 0;
			$row['audience_synced'] = 0;
			$row['coupon_code']     = null;
			$row['next_action_at']  = null;
			$wpdb->update( $table, $row, array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB
			return (int) $existing['id'];
		}

		$row['cart_token'] = isset( $d['cart_token'] ) && $d['cart_token'] ? substr( (string) $d['cart_token'], 0, 32 ) : self::token();
		$row['status']     = 'active';
		$row['created_at'] = $now;
		$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	/**
	 * A random URL-safe recovery token.
	 */
	public static function token() {
		return substr( bin2hex( random_bytes( 16 ) ), 0, 32 );
	}

	/**
	 * Store a PENDING (unconfirmed) cart from the on-site popup. It does not
	 * enter the recovery queue until the shopper confirms via the double-opt-in
	 * email, so a submitted address the shopper never confirmed is never mailed
	 * a recovery sequence.
	 *
	 * @param array $d Cart data (email/items/…, must already be basis 'optin').
	 * @return int Row id (0 = skipped).
	 */
	public static function store_pending( array $d ) {
		global $wpdb;
		$email = isset( $d['email'] ) ? sanitize_email( (string) $d['email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			return 0;
		}
		$hash = CRW_Crypto::email_hash( $email );
		if ( self::is_suppressed( $hash ) ) {
			return 0;
		}
		$table = self::table();
		// If the shopper is ALREADY captured on a lawful basis (they reached
		// checkout, or a prior popup opt-in confirmed), they're mid-sequence and
		// emailable — don't touch that row. Downgrading it to 'pending_confirm'
		// would silently halt the live sequence and let purge() delete it in 24h.
		$live = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s AND status IN ('active','abandoned') ORDER BY id DESC LIMIT 1", $hash ) ); // phpcs:ignore WordPress.DB
		if ( $live ) {
			return $live;
		}
		// Otherwise reuse only an existing unconfirmed pending row (re-submitting
		// the popup refreshes it), or insert a fresh one.
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s AND status = 'pending_confirm' ORDER BY id DESC LIMIT 1", $hash ) ); // phpcs:ignore WordPress.DB
		$now = current_time( 'mysql', true );
		$row = array(
			'email_enc'     => CRW_Crypto::encrypt( $email ),
			'email_hash'    => $hash,
			'email_masked'  => CRW_Crypto::mask( $email ),
			'first_name'    => substr( sanitize_text_field( (string) ( $d['first_name'] ?? '' ) ), 0, 80 ),
			'user_id'       => (int) ( $d['user_id'] ?? 0 ),
			'items'         => wp_json_encode( array_values( (array) ( $d['items'] ?? array() ) ) ),
			'item_count'    => (int) ( $d['item_count'] ?? 0 ),
			'currency'      => substr( (string) ( $d['currency'] ?? 'USD' ), 0, 3 ),
			'total_cents'   => (int) ( $d['total_cents'] ?? 0 ),
			'consent_basis' => 'optin',
			'gpc'           => ! empty( $d['gpc'] ) ? 1 : 0,
			'region'        => substr( (string) ( $d['region'] ?? '' ), 0, 8 ),
			'status'        => 'pending_confirm',
			'last_activity' => $now,
			'updated_at'    => $now,
		);
		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) ); // phpcs:ignore WordPress.DB
			return (int) $existing;
		}
		$row['cart_token'] = self::token();
		$row['created_at'] = $now;
		$wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	/**
	 * Activate a shopper's pending carts once they confirm (double opt-in).
	 *
	 * @param string $email_hash Hashed email.
	 * @return int Rows activated.
	 */
	public static function activate_pending( $email_hash ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='active', last_activity=%s, updated_at=%s WHERE email_hash=%s AND status='pending_confirm'", current_time( 'mysql', true ), current_time( 'mysql', true ), (string) $email_hash ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Fetch a row by id.
	 *
	 * @param int $id Id.
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Fetch a row by recovery token.
	 *
	 * @param string $token Token.
	 */
	public static function get_by_token( $token ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE cart_token = %s", (string) $token ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * The decrypted email for a row (only when actually needed — at send time).
	 *
	 * @param array $row Cart row.
	 */
	public static function email( array $row ) {
		return CRW_Crypto::decrypt( (string) ( $row['email_enc'] ?? '' ) );
	}

	/**
	 * Best-effort decrypt of an email by its hash (for propagating an opt-out to
	 * the edge). Returns '' when no matching cart survives.
	 *
	 * @param string $email_hash Hashed email.
	 */
	public static function email_by_hash( $email_hash ) {
		global $wpdb;
		$enc = $wpdb->get_var( $wpdb->prepare( "SELECT email_enc FROM {$wpdb->prefix}crw_carts WHERE email_hash = %s AND email_enc IS NOT NULL ORDER BY id DESC LIMIT 1", (string) $email_hash ) ); // phpcs:ignore WordPress.DB
		return $enc ? CRW_Crypto::decrypt( (string) $enc ) : '';
	}

	/**
	 * Decode a row's item list.
	 *
	 * @param array $row Cart row.
	 */
	public static function items( array $row ) {
		$items = json_decode( (string) ( $row['items'] ?? '[]' ), true );
		return is_array( $items ) ? $items : array();
	}

	/**
	 * Active carts that have been idle beyond the threshold (ready to enter a
	 * recovery sequence). Returned so the scheduler can pick a segment per cart.
	 *
	 * @param int $minutes Idle threshold.
	 * @param int $limit   Batch size.
	 */
	public static function newly_idle( $minutes, $limit = 100 ) {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $minutes * MINUTE_IN_SECONDS );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status='active' AND last_activity < %s ORDER BY id ASC LIMIT %d", $cutoff, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Assign a cart to a sequence and schedule its first step (or mark 'lost'
	 * when no sequence matches — nothing to send).
	 *
	 * @param int         $id          Row id.
	 * @param string      $sequence_id Sequence id ('' = no match → lost).
	 * @param string|null $next_at     First-step datetime.
	 */
	public static function assign_sequence( $id, $sequence_id, $next_at ) {
		global $wpdb;
		if ( '' === (string) $sequence_id ) {
			$wpdb->update( self::table(), array( 'status' => 'lost', 'next_action_at' => null, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
			return;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'status'         => 'abandoned',
				'sequence_id'    => substr( (string) $sequence_id, 0, 40 ),
				'step'           => 0,
				'next_action_at' => $next_at,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Abandoned carts whose next action is due (for the queue).
	 *
	 * @param int $limit Batch size.
	 */
	public static function due( $limit = 50 ) {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status='abandoned' AND next_action_at IS NOT NULL AND next_action_at <= %s ORDER BY next_action_at ASC LIMIT %d", $now, (int) $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Advance a cart to the next sequence step (or mark 'lost' when finished).
	 *
	 * @param int         $id      Row id.
	 * @param int         $step    New step number.
	 * @param string|null $next_at MySQL datetime for the next send, or null.
	 * @param string      $status  New status (abandoned|lost).
	 */
	public static function advance( $id, $step, $next_at, $status = 'abandoned' ) {
		global $wpdb;
		$table = self::table();
		$wpdb->update( // phpcs:ignore WordPress.DB
			$table,
			array(
				'step'           => (int) $step,
				'emails_sent'    => (int) $step,
				'send_attempts'  => 0, // Reset attempt counter for the new step.
				'next_action_at' => $next_at,
				'status'         => $status,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Atomically claim a cart before sending: push its next action forward, but
	 * ONLY if it still holds the exact due-time we read. This is a compare-and-
	 * swap — if a concurrent/overlapping cron run already claimed it, its
	 * next_action_at no longer matches and our UPDATE affects zero rows, so the
	 * caller must skip the send. Prevents the SELECT-then-UPDATE double-send race.
	 *
	 * @param int    $id           Row id.
	 * @param string $expected_due The next_action_at we read in due() (the CAS guard).
	 * @param string $next_at      New (pushed-out) next_action_at.
	 * @return bool True if we won the claim (send may proceed).
	 */
	public static function claim( $id, $expected_due, $next_at ) {
		global $wpdb;
		$table = self::table();
		$rows  = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET next_action_at=%s, updated_at=%s WHERE id=%d AND status='abandoned' AND next_action_at=%s", $next_at, current_time( 'mysql', true ), (int) $id, (string) $expected_due ) ); // phpcs:ignore WordPress.DB
		return $rows === 1;
	}

	/**
	 * Record a failed send attempt.
	 *
	 * @param int $id       Row id.
	 * @param int $attempts New attempt count.
	 */
	public static function bump_attempts( $id, $attempts ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'send_attempts' => (int) $attempts ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Mark a cart as already synced to the retargeting audience (idempotency).
	 *
	 * @param int $id Row id.
	 */
	public static function mark_synced( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'audience_synced' => 1 ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Store the coupon code minted for a cart.
	 *
	 * @param int    $id   Row id.
	 * @param string $code Coupon code.
	 */
	public static function set_coupon( $id, $code ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'coupon_code' => substr( (string) $code, 0, 64 ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Mark the active/abandoned cart(s) for an email as recovered by an order.
	 *
	 * @param string $email_hash Hashed email.
	 * @param int    $order_id   Order id.
	 * @param int    $cents      Recovered order total, cents.
	 * @return int Rows updated.
	 */
	public static function mark_recovered( $email_hash, $order_id, $cents ) {
		global $wpdb;
		$table = self::table();
		// Include 'lost' — a cart whose sequence merely finished. Many shoppers
		// convert AFTER the last email lands, and those recoveries must still be
		// attributed or the headline recovery-rate/revenue metric undercounts.
		// Attribute to the SINGLE newest matching cart (ORDER BY id DESC LIMIT 1):
		// a repeat abandoner accumulates multiple 'lost' rows, and stamping the
		// same order onto all of them would multiply-count one purchase's revenue.
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='recovered', order_id=%d, recovered_cents=%d, next_action_at=NULL, updated_at=%s WHERE email_hash=%s AND status IN ('active','abandoned','lost') ORDER BY id DESC LIMIT 1", (int) $order_id, (int) $cents, current_time( 'mysql', true ), (string) $email_hash ) ); // phpcs:ignore WordPress.DB
	}

	/* ------------------------------------------------------------ suppression */

	/**
	 * Add a hashed email to the suppression list.
	 *
	 * @param string $email_hash Hashed email.
	 * @param string $reason     unsubscribe | gpc | erased | complaint.
	 */
	public static function suppress( $email_hash, $reason ) {
		global $wpdb;
		if ( '' === (string) $email_hash ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}crw_suppression (email_hash, reason, created_at) VALUES (%s,%s,%s)", (string) $email_hash, substr( (string) $reason, 0, 24 ), current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB
		// Stop any live sequence for this shopper.
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status='unsubscribed', next_action_at=NULL WHERE email_hash=%s AND status IN ('active','abandoned')", (string) $email_hash ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Is this hashed email suppressed?
	 *
	 * @param string $email_hash Hashed email.
	 */
	public static function is_suppressed( $email_hash ) {
		global $wpdb;
		if ( '' === (string) $email_hash ) {
			return false;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->prefix}crw_suppression WHERE email_hash = %s LIMIT 1", (string) $email_hash ) ); // phpcs:ignore WordPress.DB
	}

	/* ------------------------------------------------------------- reporting */

	/**
	 * Dashboard funnel + recovered-revenue figures.
	 */
	public static function funnel() {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( "SELECT
			SUM(status IN ('abandoned','lost','recovered','unsubscribed','active')) AS captured,
			SUM(status IN ('abandoned','lost')) AS open,
			SUM(status='recovered') AS recovered,
			SUM(emails_sent) AS emails_sent,
			COALESCE(SUM(CASE WHEN status='recovered' THEN recovered_cents ELSE 0 END),0) AS recovered_cents,
			COALESCE(SUM(CASE WHEN status IN ('abandoned','lost') THEN total_cents ELSE 0 END),0) AS open_cents
			FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $row : array();
	}

	/**
	 * Per-sequence performance (carts entered, recovered, revenue).
	 */
	public static function sequence_stats() {
		global $wpdb;
		$table = self::table();
		$rows  = (array) $wpdb->get_results( "SELECT sequence_id,
			COUNT(*) AS carts,
			SUM(status='recovered') AS recovered,
			COALESCE(SUM(CASE WHEN status='recovered' THEN recovered_cents ELSE 0 END),0) AS revenue_cents
			FROM {$table}
			WHERE status IN ('abandoned','lost','recovered','unsubscribed')
			GROUP BY sequence_id", ARRAY_A ); // phpcs:ignore WordPress.DB
		return $rows;
	}

	/**
	 * Recent carts for the admin list.
	 *
	 * @param array $args status, limit.
	 */
	public static function recent( array $args = array() ) {
		global $wpdb;
		$table  = self::table();
		$limit  = (int) ( $args['limit'] ?? 50 );
		$where  = "status <> 'active'";
		$params = array();
		if ( ! empty( $args['status'] ) ) {
			$where    = 'status = %s';
			$params[] = $args['status'];
		}
		$params[] = $limit;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC LIMIT %d", $params ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Retention + data-minimization purge:
	 *  - carts older than the retention window (any status),
	 *  - carts that never gained a lawful basis to email ('none') after 24h.
	 *
	 * @param int $retention_days Retention window.
	 * @return int Rows deleted.
	 */
	public static function purge( $retention_days ) {
		global $wpdb;
		$table   = self::table();
		$deleted = 0;

		// Data-minimization: drop non-consented captures + never-confirmed popup
		// captures quickly (nothing lawful to do with them) — the auto-purge
		// incumbents don't offer.
		$soon    = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE (consent_basis='none' OR status='pending_confirm') AND created_at < %s", $soon ) ); // phpcs:ignore WordPress.DB

		$days = max( 1, (int) $retention_days );
		$old  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $old ) ); // phpcs:ignore WordPress.DB
		return $deleted;
	}
}

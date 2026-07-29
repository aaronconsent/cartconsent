<?php
/**
 * Retargeting audiences — the consent-filtered wedge. Only shoppers who gave an
 * explicit advertising/marketing opt-in AND carry no GPC / Do-Not-Sell-Share
 * signal are eligible, and their removal on unsubscribe/opt-out is automatic.
 *
 * Sync happens through the Consent Resolve edge (hashing + platform calls live
 * server-side, never in the browser). Without the edge configured this records
 * intent locally and no data leaves the site.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Audience eligibility + sync.
 */
class CRW_Audiences {

	/**
	 * Whether a cart row may feed a retargeting audience.
	 *
	 * @param array $row Cart row.
	 */
	public static function eligible( array $row ) {
		if ( ! CRW_Options::get( 'audiences.enabled', false ) ) {
			return false;
		}
		// Explicit opt-in only (soft opt-in never auto-enables ad sync), AND no
		// GPC / Do-Not-Sell-Share signal was present when the cart was captured.
		return CRW_Recovery_Consent::can_audience( (string) ( $row['consent_basis'] ?? 'none' ), ! empty( $row['gpc'] ) );
	}

	/**
	 * Sync an eligible abandoner to the retargeting audience — exactly once.
	 *
	 * @param array $row Cart row.
	 */
	public static function maybe_sync( array $row ) {
		if ( ! self::eligible( $row ) || ! empty( $row['audience_synced'] ) ) {
			return;
		}
		$email = CRW_Carts_Store::email( $row );
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}
		if ( CRW_Carts_Store::is_suppressed( CRW_Crypto::email_hash( $email ) ) ) {
			return;
		}

		// The edge does the hashing + Meta/Google membership; consented add only.
		CRW_Connection::audience_add( $email, 'woo_abandoned_cart' );
		CRW_Carts_Store::mark_synced( (int) $row['id'] );
		CRW_Events::log( (int) $row['id'], 'audience_synced' );
	}
}

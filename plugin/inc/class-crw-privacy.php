<?php
/**
 * WordPress privacy-tools integration: export + erase a shopper's stored cart
 * data on a data-subject request. Storing abandoned-cart emails is exactly the
 * processing reviewers expect to be covered here.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exporter + eraser for crw_carts.
 */
class CRW_Privacy {

	/**
	 * Hook up.
	 */
	public function register() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'eraser' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Exporters.
	 */
	public function exporter( $exporters ) {
		$exporters['cartconsent'] = array(
			'exporter_friendly_name' => __( 'CartConsent — Abandoned carts', 'cartconsent' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Erasers.
	 */
	public function eraser( $erasers ) {
		$erasers['cartconsent'] = array(
			'eraser_friendly_name' => __( 'CartConsent — Abandoned carts', 'cartconsent' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export a shopper's stored carts.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 */
	public function export( $email, $page = 1 ) {
		global $wpdb;
		$hash  = CRW_Crypto::email_hash( $email );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, item_count, total_cents, currency, status, consent_basis, created_at FROM {$wpdb->prefix}crw_carts WHERE email_hash = %s", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'group_id'    => 'crw_carts',
				'group_label' => __( 'Abandoned carts', 'cartconsent' ),
				'item_id'     => 'crw-cart-' . (int) $r['id'],
				'data'        => array(
					array( 'name' => __( 'Items', 'cartconsent' ), 'value' => (int) $r['item_count'] ),
					array( 'name' => __( 'Cart total', 'cartconsent' ), 'value' => number_format( (int) $r['total_cents'] / 100, 2 ) . ' ' . $r['currency'] ),
					array( 'name' => __( 'Status', 'cartconsent' ), 'value' => $r['status'] ),
					array( 'name' => __( 'Consent basis', 'cartconsent' ), 'value' => $r['consent_basis'] ),
					array( 'name' => __( 'Captured', 'cartconsent' ), 'value' => $r['created_at'] ),
				),
			);
		}
		return array( 'data' => $items, 'done' => true );
	}

	/**
	 * Erase a shopper's stored carts + add them to suppression.
	 *
	 * @param string $email Email.
	 * @param int    $page  Page.
	 */
	public function erase( $email, $page = 1 ) {
		global $wpdb;
		$hash    = CRW_Crypto::email_hash( $email );
		$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}crw_carts WHERE email_hash = %s", $hash ) ); // phpcs:ignore WordPress.DB
		// Suppress so we never re-capture them, and propagate any audience removal.
		CRW_Carts_Store::suppress( $hash, 'erased' );
		CRW_Connection::optout( $email );
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}

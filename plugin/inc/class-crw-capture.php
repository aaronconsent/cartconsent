<?php
/**
 * Capture engine. The plugin's differentiator lives here: we capture an
 * abandoning shopper only on a deliberate action (a submitted checkout, or the
 * email field completed on the checkout page) — never by sniffing keystrokes —
 * and we stamp the lawful basis (opt-in vs soft opt-in) on every record. A cart
 * with no lawful basis to email is not stored at all.
 *
 * Works with the classic shortcode checkout and the Cart/Checkout Blocks, and
 * reads orders only through the CRUD layer (HPOS-safe).
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cart capture + recovery matching.
 */
class CRW_Capture {

	const OPTIN_FIELD = 'crw_optin';
	const OPTIN_META  = '_crw_optin';
	const RECOVERED_META = '_crw_recovered_flag';

	/**
	 * Hook up.
	 */
	public function register() {
		// Defer option-dependent wiring to woocommerce_init. It fires from WC's
		// own init() (WP init, priority 0), so translations are already safe to
		// load (no pre-init _load_textdomain_just_in_time notice) AND it is the
		// correct, still-early moment to register Checkout Blocks fields.
		add_action( 'woocommerce_init', array( $this, 'wire' ) );
	}

	/**
	 * Option-dependent wiring (runs on woocommerce_init).
	 */
	public function wire() {
		// Recovery matching runs UNCONDITIONALLY — it is consent-independent
		// bookkeeping: a paid order must clear that shopper's carts even if
		// capture is turned off, otherwise the queue would keep emailing/pushing
		// recovery messages for an order they already completed.
		add_action( 'woocommerce_payment_complete', array( $this, 'recover_from_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'recover_on_status' ), 20, 4 );

		if ( ! CRW_Options::get( 'capture.enabled', true ) ) {
			return;
		}

		// Marketing-consent checkbox on the classic checkout.
		if ( CRW_Options::get( 'consent.checkout_checkbox', true ) ) {
			add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_checkbox' ) );
			add_action( 'woocommerce_checkout_create_order', array( $this, 'save_optin_classic' ), 10, 2 );
			// Cart/Checkout Blocks: a real, server-verified consent field.
			$this->register_block_field();
		}

		// Capture from a submitted order (both checkouts). We deliberately do NOT
		// capture unverified emails typed into a field and abandoned — we only
		// store shoppers who actually placed an order, so an address can never be
		// entered on someone else's behalf.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_from_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'capture_from_order' ), 20, 1 );
	}

	/* --------------------------------------------------------------- checkbox */

	/**
	 * Print the marketing-consent checkbox (classic checkout).
	 */
	public function render_checkbox() {
		$label   = (string) CRW_Options::get( 'consent.checkbox_label', '' );
		$checked = (bool) CRW_Options::get( 'consent.checkbox_default_checked', false );
		echo '<p class="form-row crw-optin"><label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="' . esc_attr( self::OPTIN_FIELD ) . '" value="1" ' . checked( $checked, true, false ) . '> <span>' . esc_html( $label ) . '</span></label></p>';
	}

	/**
	 * Persist the classic checkbox choice onto the order.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data.
	 */
	public function save_optin_classic( $order, $data ) {
		$opt = ! empty( $_POST[ self::OPTIN_FIELD ] ); // phpcs:ignore WordPress.Security.NonceVerification
		$order->update_meta_data( self::OPTIN_META, $opt ? '1' : '0' );
	}

	/**
	 * Register a marketing-consent checkbox on the Cart/Checkout Blocks using
	 * WooCommerce's additional-checkout-fields API (WC 8.9+). It renders in the
	 * contact section and is saved to the order automatically — server-verified,
	 * no client JS to trust.
	 */
	public function register_block_field() {
		// Called from wire() which already runs on woocommerce_init, so register
		// the field directly (re-hooking the currently-firing action is fragile).
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return; // Older WooCommerce — classic checkbox still applies.
		}
		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => 'cartconsent/marketing-optin',
					'label'    => (string) CRW_Options::get( 'consent.checkbox_label', __( 'Email me about my cart', 'cartconsent' ) ),
					'location' => 'contact',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( \Exception $e ) {
			// Field already registered or unsupported — ignore.
		}
	}

	/**
	 * Read the block additional-field opt-in from an order (best-effort across
	 * the meta-key shapes WooCommerce has used).
	 *
	 * @param WC_Order $order Order.
	 */
	protected function block_optin( $order ) {
		foreach ( array( '_wc_other/cartconsent/marketing-optin', '_cartconsent/marketing-optin', 'cartconsent/marketing-optin' ) as $key ) {
			$v = $order->get_meta( $key );
			if ( '' !== $v && null !== $v ) {
				return in_array( (string) $v, array( '1', 'true', 'yes' ), true );
			}
		}
		return false;
	}

	/**
	 * Capture from a just-created order (pending/failed = a real abandonment
	 * candidate). Robust for both classic and block checkouts.
	 *
	 * @param int|WC_Order $order Order id or object.
	 */
	public function capture_from_order( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return;
		}
		// A paid order is not an abandonment; skip.
		if ( $order->is_paid() ) {
			return;
		}
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$vid       = (int) $item->get_variation_id();
			$variation = array();
			if ( $vid ) {
				$vp = wc_get_product( $vid );
				if ( $vp && is_callable( array( $vp, 'get_variation_attributes' ) ) ) {
					$variation = (array) $vp->get_variation_attributes();
				}
			}
			$items[] = array(
				'product_id'   => (int) $item->get_product_id(),
				'variation_id' => $vid,
				'variation'    => $variation,
				'quantity'     => (int) $item->get_quantity(),
				'name'         => $item->get_name(),
				'line_total'   => (float) $item->get_total(),
			);
		}
		if ( empty( $items ) ) {
			return;
		}
		$checkbox = '1' === (string) $order->get_meta( self::OPTIN_META ) || $this->block_optin( $order );
		$id       = $this->store(
			array(
				'email'      => $order->get_billing_email(),
				'first_name' => $order->get_billing_first_name(),
				'user_id'    => (int) $order->get_user_id(),
				'items'      => $items,
				'item_count' => count( $items ),
				'currency'   => $order->get_currency(),
				'total_cents' => (int) round( (float) $order->get_total() * 100 ),
			),
			$checkbox
		);
		if ( $id ) {
			$order->update_meta_data( '_crw_cart_id', $id );
			$order->save();
			CRW_Estimates::mark_session_captured();
		}
	}

	/**
	 * Resolve consent + persist a capture. Central gate: nothing is stored
	 * unless there is a lawful basis to email the shopper.
	 *
	 * @param array $data     Cart data (email/items/…).
	 * @param bool  $checkbox Explicit opt-in from the checkout box.
	 * @return int Row id (0 = not stored).
	 */
	protected function store( array $data, $checkbox ) {
		$opted_in = CRW_Recovery_Consent::has_optin( $checkbox );
		$gpc      = CRW_Recovery_Consent::gpc();
		$basis    = CRW_Recovery_Consent::resolve_basis( $opted_in, $gpc );
		if ( ! CRW_Recovery_Consent::can_email( $basis ) ) {
			return 0; // No lawful basis → we do not store the shopper at all.
		}
		$data['consent_basis'] = $basis;
		$data['region']        = CRW_Recovery_Consent::region_code();
		$data['gpc']           = $gpc ? 1 : 0; // Persisted so audience sync can honor it later.
		$id = CRW_Carts_Store::upsert( $data );
		if ( $id ) {
			CRW_Events::log( $id, 'captured', (int) ( $data['total_cents'] ?? 0 ) );
		}
		return $id;
	}

	/* -------------------------------------------------------------- recovery */

	/**
	 * On payment complete, mark the shopper's open carts recovered.
	 *
	 * @param int $order_id Order id.
	 */
	public function recover_from_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || '1' === (string) $order->get_meta( self::RECOVERED_META ) ) {
			return;
		}
		$email = $order->get_billing_email();
		if ( ! $email ) {
			return;
		}
		$hash = CRW_Crypto::email_hash( $email );
		$n    = CRW_Carts_Store::mark_recovered( $hash, (int) $order_id, (int) round( (float) $order->get_total() * 100 ) );
		if ( $n > 0 ) {
			CRW_Events::log( 0, 'recovered', (int) round( (float) $order->get_total() * 100 ) );
			$order->update_meta_data( self::RECOVERED_META, '1' );
			$order->save();
		}
	}

	/**
	 * Catch orders that reach a paid status without firing payment_complete.
	 *
	 * @param int      $order_id   Order id.
	 * @param string   $old_status Old.
	 * @param string   $new_status New.
	 * @param WC_Order $order      Order.
	 */
	public function recover_on_status( $order_id, $old_status, $new_status, $order ) {
		if ( in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
			$this->recover_from_order( $order_id );
		}
	}
}

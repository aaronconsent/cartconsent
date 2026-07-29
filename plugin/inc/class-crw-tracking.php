<?php
/**
 * Retargeting-ready tracking. Google Consent Mode v2 defaults are always set;
 * the Meta Pixel + GA4 only load once marketing consent is granted (via Consent
 * Resolve core or the WP Consent API), and cart/checkout/purchase events fire
 * behind the same gate. Purchase is deduplicated per order.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consent-gated ecommerce event tracking.
 */
class CRW_Tracking {

	/**
	 * Hook up when at least one destination id is configured.
	 */
	public function register() {
		// Defer option-dependent wiring to init (avoids pre-init translated defaults).
		add_action( 'init', array( $this, 'wire' ) );
	}

	/**
	 * Option-dependent wiring (runs on init; all hooks below fire after init).
	 */
	public function wire() {
		if ( ! CRW_Options::get( 'tracking.events', true ) ) {
			return;
		}
		if ( ! $this->pixel() && ! $this->ga4() ) {
			return;
		}
		add_action( 'wp_head', array( $this, 'head' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_thankyou', array( $this, 'purchase' ), 20, 1 );
	}

	/** Meta Pixel id (or ''). */
	protected function pixel() {
		return trim( (string) CRW_Options::get( 'tracking.meta_pixel_id', '' ) );
	}

	/** GA4 measurement id (or ''). */
	protected function ga4() {
		return trim( (string) CRW_Options::get( 'tracking.ga4_id', '' ) );
	}

	/**
	 * Whether this visitor has granted marketing/advertising consent. Fail-closed:
	 * with no consent mechanism present, we do not load ad trackers.
	 */
	public static function consent_granted() {
		// Our OWN bundled banner is the authority in the standalone default
		// config; check it first. (Without this branch, a site with no CR core
		// and no WP Consent API plugin always returned false, so pixel/GA4/the
		// Purchase event never loaded even after a shopper accepted marketing.)
		if ( class_exists( 'CRW_Consent' ) && ! class_exists( 'CR_Consent' ) ) {
			return (bool) CRW_Consent::allows( 'marketing' );
		}
		if ( class_exists( 'CR_Consent' ) ) {
			return (bool) CR_Consent::allows( 'marketing' );
		}
		if ( function_exists( 'wp_has_consent' ) ) {
			return (bool) wp_has_consent( 'marketing' );
		}
		return false;
	}

	/**
	 * Consent Mode v2 defaults + gated pixel/GA4 bootstrap in <head>.
	 */
	public function head() {
		if ( is_admin() ) {
			return;
		}
		// Consent Mode default — denied until the CMP updates it. Skip it when our
		// own banner (CRW_Frontend, wp_head pri 0) is already emitting a (more
		// complete) default, or when Consent Resolve core owns the page — otherwise
		// two gtag("consent","default") blocks print on every request.
		$banner_owns_default = CRW_Options::get( 'banner.enabled', true ) && ! class_exists( 'CR_Frontend' ) && ! class_exists( 'CR_Consent' );
		if ( CRW_Options::get( 'tracking.consent_mode', true ) && ! $banner_owns_default ) {
			echo "\n<!-- Consent Resolve for WooCommerce -->\n";
			echo '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",wait_for_update:500});</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( ! self::consent_granted() ) {
			return; // No marketing consent → no ad trackers load.
		}
		$pixel = $this->pixel();
		if ( '' !== $pixel ) {
			echo '<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version="2.0";n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,"script","https://connect.facebook.net/en_US/fbevents.js");fbq("init",' . wp_json_encode( $pixel ) . ');fbq("track","PageView");</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$ga = $this->ga4();
		if ( '' !== $ga ) {
			echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr( $ga ) . '"></script>';
			echo '<script>gtag("js",new Date());gtag("config",' . wp_json_encode( $ga ) . ');</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * Enqueue the cart/checkout event bridge (only when consent is granted).
	 */
	public function enqueue() {
		if ( is_admin() || ! self::consent_granted() ) {
			return;
		}
		wp_enqueue_script( 'crw-tracking', CRW_URL . 'assets/js/tracking.js', array(), CRW_VERSION, true );
		wp_localize_script(
			'crw-tracking',
			'CRWTrack',
			array(
				'pixel'    => $this->pixel(),
				'ga4'      => $this->ga4(),
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
				'checkout' => function_exists( 'is_checkout' ) && is_checkout(),
			)
		);
	}

	/**
	 * Fire the Purchase event once per order on the thank-you page.
	 *
	 * @param int $order_id Order id.
	 */
	public function purchase( $order_id ) {
		if ( ! self::consent_granted() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || '1' === (string) $order->get_meta( '_crw_purchase_tracked' ) ) {
			return;
		}
		$value   = (float) $order->get_total();
		$currency = $order->get_currency();
		$ids     = array();
		$contents = array();
		foreach ( $order->get_items() as $item ) {
			$pid       = (int) $item->get_product_id();
			$ids[]     = (string) $pid;
			$contents[] = array( 'id' => (string) $pid, 'quantity' => (int) $item->get_quantity() );
		}
		// Shared event id so a server-side CAPI event (edge) can deduplicate.
		$event_id = 'crw_' . (int) $order_id;

		$data = wp_json_encode(
			array(
				'value'      => $value,
				'currency'   => $currency,
				'content_ids' => $ids,
				'contents'   => $contents,
				'event_id'   => $event_id,
				'pixel'      => $this->pixel(),
				'ga4'        => $this->ga4(),
			)
		);
		echo '<script>window.CRWPurchase=' . $data . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<script>' . $this->purchase_js() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput

		$order->update_meta_data( '_crw_purchase_tracked', '1' );
		$order->save();
	}

	/**
	 * Inline purchase-firing JS (kept tiny; reads window.CRWPurchase).
	 */
	protected function purchase_js() {
		return 'try{var p=window.CRWPurchase||{};if(p.pixel&&window.fbq){fbq("track","Purchase",{value:p.value,currency:p.currency,content_ids:p.content_ids,contents:p.contents,content_type:"product"},{eventID:p.event_id});}if(p.ga4&&window.gtag){gtag("event","purchase",{transaction_id:p.event_id,value:p.value,currency:p.currency,items:(p.content_ids||[]).map(function(id){return{item_id:id};})});}}catch(e){}';
	}
}

<?php
/**
 * Setup Wizard — a one-screen quick start covering the essentials: consent
 * banner region, sender identity, and the recovery basics.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guided first-run setup.
 */
class CRW_Wizard {

	const CAP = 'crw_manage';

	/**
	 * Register the save handler (menu item lives in CRW_Admin::menu).
	 */
	public function register() {
		add_action( 'admin_post_crw_save_wizard', array( __CLASS__, 'save' ) );
	}

	/**
	 * Render the wizard.
	 */
	public static function render() {
		$b   = CRW_Options::get( 'banner' );
		$em  = CRW_Options::get( 'emails' );
		$cap = CRW_Options::get( 'capture' );
		$cp  = CRW_Options::get( 'coupon' );
		$done = (bool) get_option( 'crw_setup_complete' );

		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Setup Wizard', 'cartconsent' ) . '</h1>';
		if ( isset( $_GET['crw_msg'] ) && 'wizard' === $_GET['crw_msg'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All set! Your consent banner and cart recovery are live.', 'cartconsent' ) . '</p></div>';
		}
		echo '<p class="description" style="max-width:75ch">' . esc_html__( 'Three quick things and you are live: how consent works for your visitors, who your recovery emails come from, and when a cart counts as abandoned. You can change everything later.', 'cartconsent' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crw_save_wizard' );
		echo '<input type="hidden" name="action" value="crw_save_wizard">';

		echo '<div class="crw-card"><h2>' . esc_html__( '1. Consent', 'cartconsent' ) . '</h2>';
		printf( '<p><label><input type="checkbox" name="banner_enabled" value="1" %s> %s</label></p>', checked( $b['enabled'], true, false ), esc_html__( 'Show the cookie consent banner (turn off if another CMP handles it)', 'cartconsent' ) );
		echo '<p><label>' . esc_html__( 'Primary region', 'cartconsent' ) . ' <select name="region">';
		foreach ( CRW_Regions::labels() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $b['region'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label> <span class="description">' . esc_html__( 'Auto-detected per visitor when your CDN passes geo headers.', 'cartconsent' ) . '</span></p></div>';

		echo '<div class="crw-card"><h2>' . esc_html__( '2. Sender', 'cartconsent' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'From name', 'cartconsent' ) . '<br><input type="text" name="from_name" value="' . esc_attr( $em['from_name'] ) . '" class="regular-text" placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'From address', 'cartconsent' ) . '<br><input type="email" name="from_email" value="' . esc_attr( $em['from_email'] ) . '" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '"></label></p>';
		if ( '' === trim( (string) get_option( 'woocommerce_store_address', '' ) ) ) {
			echo '<p class="description" style="color:#a3282a">' . esc_html__( 'Tip: set your store address in WooCommerce → Settings → General — it is required in the email footer.', 'cartconsent' ) . '</p>';
		}
		echo '</div>';

		echo '<div class="crw-card"><h2>' . esc_html__( '3. Recovery', 'cartconsent' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Consider a cart abandoned after (minutes)', 'cartconsent' ) . ' <input type="number" min="5" name="abandon_after" value="' . esc_attr( (int) $cap['abandon_after_minutes'] ) . '" class="small-text"></label></p>';
		printf( '<p><label><input type="checkbox" name="coupon_enabled" value="1" %s> %s</label></p>', checked( $cp['enabled'], true, false ), esc_html__( 'Offer a single-use recovery coupon', 'cartconsent' ) );
		echo '</div>';

		echo '<p><button class="button button-primary button-hero">' . esc_html( $done ? __( 'Save', 'cartconsent' ) : __( 'Finish setup', 'cartconsent' ) ) . '</button></p></form></div>';
	}

	/**
	 * Persist the wizard answers.
	 */
	public static function save() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'crw_save_wizard' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cartconsent' ) );
		}
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidationSanitization
		$o  = CRW_Options::all();
		$o['banner']['enabled'] = ! empty( $in['banner_enabled'] );
		$o['banner']['region']  = array_key_exists( ( $in['region'] ?? '' ), CRW_Regions::profiles() ) ? $in['region'] : 'us';
		$o['emails']['from_name']  = sanitize_text_field( $in['from_name'] ?? '' );
		$o['emails']['from_email'] = sanitize_email( $in['from_email'] ?? '' );
		$o['capture']['abandon_after_minutes'] = max( 5, (int) ( $in['abandon_after'] ?? 30 ) );
		$o['coupon']['enabled'] = ! empty( $in['coupon_enabled'] );
		CRW_Options::save( $o );
		update_option( 'crw_setup_complete', 1 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'crw-wizard', 'crw_msg' => 'wizard' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}

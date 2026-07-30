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
		$em   = CRW_Options::get( 'emails' );
		$cap  = CRW_Options::get( 'capture' );
		$cp   = CRW_Options::get( 'coupon' );
		$conn = CRW_Connection::config();
		$done = (bool) get_option( 'crw_setup_complete' );

		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Setup Wizard', 'cartconsent' ) . '</h1>';
		if ( isset( $_GET['crw_msg'] ) && 'wizard' === $_GET['crw_msg'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'All set! Your cookie banner and cart recovery are live.', 'cartconsent' ) . '</p></div>';
		}
		echo '<p class="description" style="max-width:75ch">' . esc_html__( 'Your free cookie banner is already live. Three quick things to finish: optionally connect Consent Resolve, set who your recovery emails come from, and choose when a cart counts as abandoned. You can change everything later.', 'cartconsent' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crw_save_wizard' );
		echo '<input type="hidden" name="action" value="crw_save_wizard">';

		echo '<div class="crw-card"><h2>' . esc_html__( '1. Connect Consent Resolve (optional)', 'cartconsent' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Optional. The free built-in banner is already serving; connecting swaps it for the hosted Consent Resolve banner and unlocks visitor resolution.', 'cartconsent' ) . ' <a href="' . esc_url( CRW_Hosted::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get an API key', 'cartconsent' ) . ' &#8599;</a></p>';
		echo '<p><label>' . esc_html__( 'Site ID', 'cartconsent' ) . '<br><input type="text" name="conn_site_id" value="' . esc_attr( $conn['site_id'] ) . '" class="regular-text"></label></p>';
		if ( defined( 'CONSENT_RESOLVE_WOO_API_KEY' ) ) {
			echo '<p>' . esc_html__( 'API key: set via CONSENT_RESOLVE_WOO_API_KEY in wp-config.php.', 'cartconsent' ) . '</p>';
		} else {
			echo '<p><label>' . esc_html__( 'API key', 'cartconsent' ) . '<br><input type="password" name="conn_api_key" value="' . esc_attr( $conn['api_key'] ) . '" class="regular-text" autocomplete="off"></label></p>';
		}
		echo CRW_Hosted::active()
			? '<p><span style="color:#1d7f43;font-weight:600">&#10003; ' . esc_html__( 'Connected.', 'cartconsent' ) . '</span></p>'
			: '';
		echo '</div>';

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
		$o['connection']['site_id'] = sanitize_text_field( $in['conn_site_id'] ?? '' );
		if ( ! defined( 'CONSENT_RESOLVE_WOO_API_KEY' ) && isset( $in['conn_api_key'] ) ) {
			$o['connection']['api_key'] = sanitize_text_field( $in['conn_api_key'] );
		}
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

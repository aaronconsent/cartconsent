<?php
/**
 * Connection screen — the activation switch for this version of CartConsent.
 * The Consent Resolve javascript + cookie banner are used exclusively and are
 * activated by the API key entered here; without it the module stands down.
 *
 * (The former local Banner & Settings, Consent Records, and Privacy Requests
 * screens are retired — those surfaces live in the hosted Consent Resolve
 * dashboard, linked from the Dashboard tiles.)
 *
 * @package CartConsent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Connection admin.
 */
class CRW_Cmp_Admin {

	const CAP = 'crw_manage';

	/**
	 * Hook up.
	 */
	public function register() {
		add_action( 'admin_post_crw_save_connection', array( __CLASS__, 'save_connection' ) );
	}

	/**
	 * Nonce + capability guard for admin-post handlers.
	 *
	 * @param string $nonce Action name.
	 */
	private static function guard( $nonce ) {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( $nonce ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cartconsent' ) );
		}
	}

	/* ------------------------------------------------------------- Connection */

	/**
	 * Render the Connection screen.
	 */
	public static function render_connection() {
		$c      = CRW_Connection::config();
		$const  = defined( 'CONSENT_RESOLVE_WOO_API_KEY' );
		$active = CRW_Hosted::active();

		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Connection', 'cartconsent' ) . '</h1>';
		if ( isset( $_GET['crw_msg'] ) && 'conn' === $_GET['crw_msg'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Connection saved.', 'cartconsent' ) . '</p></div>';
		}

		echo '<div class="crw-card" style="max-width:760px"><h2 style="margin-top:0">' . esc_html__( 'Consent Resolve account', 'cartconsent' ) . '</h2>';
		echo '<p class="description" style="max-width:70ch">' . esc_html__( 'Your API key activates CartConsent. It switches on the Consent Resolve cookie banner (served by the hosted javascript), cart capture, recovery sends, the popup, and web push — and unlocks visitor resolution when your plan includes credits.', 'cartconsent' ) . '</p>';

		if ( $active ) {
			echo '<p><span style="color:#1d7f43;font-weight:600">&#10003; ' . esc_html__( 'Connected — CartConsent is active.', 'cartconsent' ) . '</span></p>';
			$credits = CRW_Connection::credits();
			if ( null !== $credits ) {
				echo '<p class="description">' . esc_html( sprintf( /* translators: %s number */ __( 'Resolution credits available: %s', 'cartconsent' ), number_format_i18n( $credits ) ) ) . '</p>';
			}
		} else {
			echo '<p><span style="color:#a3282a;font-weight:600">&#9679; ' . esc_html__( 'Not connected — the cookie banner is not served and recovery is paused.', 'cartconsent' ) . '</span> <a href="' . esc_url( CRW_Hosted::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get an API key', 'cartconsent' ) . ' &#8599;</a></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crw_save_connection' );
		echo '<input type="hidden" name="action" value="crw_save_connection">';
		echo '<p><label>' . esc_html__( 'Site ID', 'cartconsent' ) . '<br><input type="text" name="connection[site_id]" value="' . esc_attr( $c['site_id'] ) . '" class="regular-text"></label></p>';
		if ( $const ) {
			echo '<p>' . esc_html__( 'API key: set via CONSENT_RESOLVE_WOO_API_KEY in wp-config.php.', 'cartconsent' ) . '</p>';
		} else {
			echo '<p><label>' . esc_html__( 'API key', 'cartconsent' ) . '<br><input type="password" name="connection[api_key]" value="' . esc_attr( $c['api_key'] ) . '" class="regular-text" autocomplete="off"></label></p>';
		}
		echo '<p><button class="button button-primary">' . esc_html__( 'Save connection', 'cartconsent' ) . '</button></p></form></div>';

		echo '<div class="crw-card" style="max-width:760px"><h2 style="margin-top:0">' . esc_html__( 'Consent Records & Privacy Requests', 'cartconsent' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Consent records and data-subject requests are managed in your Consent Resolve dashboard alongside the banner.', 'cartconsent' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( CRW_Hosted::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open the Consent Resolve dashboard', 'cartconsent' ) . ' &#8599;</a></p></div>';
		echo '</div>';
	}

	/**
	 * Save credentials.
	 */
	public static function save_connection() {
		self::guard( 'crw_save_connection' );
		$in = wp_unslash( $_POST['connection'] ?? array() ); // phpcs:ignore WordPress.Security.ValidationSanitization
		$o  = CRW_Options::all();
		$o['connection']['site_id'] = sanitize_text_field( $in['site_id'] ?? '' );
		if ( ! defined( 'CONSENT_RESOLVE_WOO_API_KEY' ) && isset( $in['api_key'] ) ) {
			$o['connection']['api_key'] = sanitize_text_field( $in['api_key'] );
		}
		CRW_Options::save( $o );
		delete_transient( 'crw_credits' ); // New key → refetch credits.
		wp_safe_redirect( add_query_arg( array( 'page' => 'crw-connection', 'crw_msg' => 'conn' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}

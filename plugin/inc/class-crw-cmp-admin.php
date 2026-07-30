<?php
/**
 * Banner & Settings (the free, bundled cookie banner) + the Connection screen.
 * The free banner ships forever; connecting a Consent Resolve API key upgrades
 * the site to the hosted javascript + banner (which then serves exclusively)
 * and unlocks visitor resolution. Consent Records and Privacy Requests live in
 * the hosted Consent Resolve dashboard, linked from the Dashboard tiles.
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
		add_action( 'admin_post_crw_save_banner', array( __CLASS__, 'save_banner' ) );
	}

	/**
	 * Checkbox row.
	 *
	 * @param string $name    Field name.
	 * @param string $label   Label.
	 * @param bool   $checked Checked.
	 */
	private static function toggle( $name, $label, $checked ) {
		printf( '<p><label><input type="checkbox" name="%s" value="1" %s> %s</label></p>', esc_attr( $name ), checked( $checked, true, false ), esc_html( $label ) );
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
		echo '<p class="description" style="max-width:70ch">' . esc_html__( 'Optional. CartConsent — the free cookie banner and cart recovery — works without an account. Connecting swaps your banner for the hosted Consent Resolve javascript (managed rules, records, and privacy requests in your dashboard) and unlocks visitor resolution when your plan includes credits.', 'cartconsent' ) . '</p>';

		if ( $active ) {
			echo '<p><span style="color:#1d7f43;font-weight:600">&#10003; ' . esc_html__( 'Connected — the hosted Consent Resolve banner is serving.', 'cartconsent' ) . '</span></p>';
			$credits = CRW_Connection::credits();
			if ( null !== $credits ) {
				echo '<p class="description">' . esc_html( sprintf( /* translators: %s number */ __( 'Resolution credits available: %s', 'cartconsent' ), number_format_i18n( $credits ) ) ) . '</p>';
			}
		} else {
			echo '<p><span style="color:#8a8a8a;font-weight:600">&#9675; ' . esc_html__( 'Not connected — running the free built-in banner. Everything works; connect to upgrade.', 'cartconsent' ) . '</span> <a href="' . esc_url( CRW_Hosted::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get an API key', 'cartconsent' ) . ' &#8599;</a></p>';
		}

		$demo = defined( 'CARTCONSENT_DEMO' ) && CARTCONSENT_DEMO;
		// Demo site: sample credentials are pre-filled so a visitor can flip the
		// switch and watch the dashboard change.
		$site_val = $c['site_id'];
		$key_val  = $c['api_key'];
		if ( $demo && ! $active ) {
			$site_val = $site_val ? $site_val : 'demo-summit-outfitters';
			$key_val  = $key_val ? $key_val : 'demo-sample-api-key-2026';
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Demo store: a sample Site ID and API key are pre-filled below. Click the button to see the connected experience.', 'cartconsent' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crw_save_connection' );
		echo '<input type="hidden" name="action" value="crw_save_connection">';
		echo '<p><label>' . esc_html__( 'Site ID', 'cartconsent' ) . '<br><input type="text" name="connection[site_id]" value="' . esc_attr( $site_val ) . '" class="regular-text"></label></p>';
		if ( $const ) {
			echo '<p>' . esc_html__( 'API key: set via CONSENT_RESOLVE_WOO_API_KEY in wp-config.php.', 'cartconsent' ) . '</p>';
		} else {
			echo '<p><label>' . esc_html__( 'API key', 'cartconsent' ) . '<br><input type="password" name="connection[api_key]" value="' . esc_attr( $key_val ) . '" class="regular-text" autocomplete="off"></label></p>';
		}
		if ( $demo && ! $active ) {
			echo '<p><button class="button button-primary button-hero">' . esc_html__( 'See what happens when you enable it →', 'cartconsent' ) . '</button></p></form></div>';
		} else {
			echo '<p><button class="button button-primary">' . esc_html__( 'Save connection', 'cartconsent' ) . '</button></p></form></div>';
		}
		if ( $demo && $active ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:-6px 0 14px">';
			wp_nonce_field( 'crw_save_connection' );
			echo '<input type="hidden" name="action" value="crw_save_connection"><input type="hidden" name="demo_reset" value="1">';
			echo '<button class="button">' . esc_html__( '← Switch back to the free view', 'cartconsent' ) . '</button></form>';
		}

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
		$demo = defined( 'CARTCONSENT_DEMO' ) && CARTCONSENT_DEMO;
		$o    = CRW_Options::all();
		if ( $demo && ! empty( $_POST['demo_reset'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$o['connection']['site_id'] = '';
			$o['connection']['api_key'] = '';
			CRW_Options::save( $o );
			delete_transient( 'crw_credits' );
			wp_safe_redirect( add_query_arg( array( 'page' => 'crw-dashboard', 'crw_msg' => 'free' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		$in = wp_unslash( $_POST['connection'] ?? array() ); // phpcs:ignore WordPress.Security.ValidationSanitization
		$was_connected              = CRW_Connection::is_connected();
		$o['connection']['site_id'] = sanitize_text_field( $in['site_id'] ?? '' );
		if ( ! defined( 'CONSENT_RESOLVE_WOO_API_KEY' ) && isset( $in['api_key'] ) ) {
			$o['connection']['api_key'] = sanitize_text_field( $in['api_key'] );
		}
		CRW_Options::save( $o );
		delete_transient( 'crw_credits' ); // New key → refetch credits.
		// On the demo (and on any first-time connect), land on the Dashboard so
		// the before/after is immediately visible.
		if ( CRW_Connection::is_connected() && ( $demo || ! $was_connected ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'crw-dashboard', 'crw_msg' => 'connected' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'crw-connection', 'crw_msg' => 'conn' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* --------------------------------------------------------- Banner & Settings */

	public static function render_banner() {
		$b = CRW_Options::get( 'banner' );
		$s = CRW_Options::get( 'style' );
		$sig = CRW_Options::get( 'signals' );
		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Banner & Settings', 'cartconsent' ) . '</h1>';
		if ( class_exists( 'CR_Consent' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Consent Resolve (the standalone CMP plugin) is active, so it is handling your consent banner. These settings apply only if you deactivate it.', 'cartconsent' ) . '</p></div>';
		}
		if ( CRW_Hosted::active() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'You are connected to Consent Resolve, so the hosted banner is serving. These free-banner settings apply whenever you are not connected.', 'cartconsent' ) . '</p></div>';
		}
		if ( isset( $_GET['crw_msg'] ) && 'banner' === $_GET['crw_msg'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Banner settings saved.', 'cartconsent' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crw_save_banner' );
		echo '<input type="hidden" name="action" value="crw_save_banner">';

		echo '<div class="crw-card"><h2>' . esc_html__( 'Cookie banner', 'cartconsent' ) . '</h2>';
		self::toggle( 'banner[enabled]', __( 'Show the consent banner + preference center', 'cartconsent' ), $b['enabled'] );
		self::toggle( 'banner[auto_region]', __( 'Auto-detect each visitor\'s region (from your CDN\'s geo headers)', 'cartconsent' ), $b['auto_region'] );
		echo '<p><label>' . esc_html__( 'Region when not auto-detected', 'cartconsent' ) . ' <select name="banner[region]">';
		foreach ( CRW_Regions::labels() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $b['region'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Layout', 'cartconsent' ) . ' <select name="banner[layout]">';
		foreach ( array( 'box' => 'Box', 'bar-bottom' => 'Bottom bar', 'bar-top' => 'Top bar', 'modal' => 'Modal', 'corner' => 'Corner' ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $b['layout'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Title', 'cartconsent' ) . '<br><input type="text" name="banner[title]" value="' . esc_attr( $b['title'] ) . '" class="large-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Message', 'cartconsent' ) . '<br><textarea name="banner[message]" rows="3" class="large-text">' . esc_textarea( $b['message'] ) . '</textarea></label></p>';
		foreach ( array( 'accept_label' => __( 'Accept button', 'cartconsent' ), 'reject_label' => __( 'Reject button', 'cartconsent' ), 'prefs_label' => __( 'Preferences button', 'cartconsent' ), 'save_label' => __( 'Save button', 'cartconsent' ) ) as $k => $l ) {
			echo '<p><label>' . esc_html( $l ) . '<br><input type="text" name="banner[' . esc_attr( $k ) . ']" value="' . esc_attr( $b[ $k ] ) . '" class="regular-text"></label></p>';
		}
		echo '</div>';

		echo '<div class="crw-card"><h2>' . esc_html__( 'Style', 'cartconsent' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Accent', 'cartconsent' ) . ' <input type="text" name="style[accent]" value="' . esc_attr( $s['accent'] ) . '" class="small-text"></label> ';
		echo '<label>' . esc_html__( 'Text', 'cartconsent' ) . ' <input type="text" name="style[text]" value="' . esc_attr( $s['text'] ) . '" class="small-text"></label> ';
		echo '<label>' . esc_html__( 'Surface', 'cartconsent' ) . ' <input type="text" name="style[surface]" value="' . esc_attr( $s['surface'] ) . '" class="small-text"></label> ';
		echo '<label>' . esc_html__( 'Radius', 'cartconsent' ) . ' <input type="number" name="style[radius]" value="' . esc_attr( (int) $s['radius'] ) . '" class="small-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Custom CSS', 'cartconsent' ) . '<br><textarea name="style[custom_css]" rows="3" class="large-text code">' . esc_textarea( $s['custom_css'] ) . '</textarea></label></p>';
		echo '</div>';

		echo '<div class="crw-card"><h2>' . esc_html__( 'Signals & compliance', 'cartconsent' ) . '</h2>';
		self::toggle( 'signals[gpc]', __( 'Honor Global Privacy Control (required in CA/CO/CT)', 'cartconsent' ), $sig['gpc'] );
		self::toggle( 'signals[dnt]', __( 'Honor Do Not Track', 'cartconsent' ), $sig['dnt'] );
		self::toggle( 'consent_mode', __( 'Set Google Consent Mode v2 defaults', 'cartconsent' ), CRW_Options::get( 'consent_mode', true ) );
		self::toggle( 'uet_consent', __( 'Set Microsoft UET Consent Mode defaults', 'cartconsent' ), CRW_Options::get( 'uet_consent', true ) );
		self::toggle( 'do_not_sell', __( 'Show a "Do Not Sell or Share" option in opt-out regions', 'cartconsent' ), CRW_Options::get( 'do_not_sell', true ) );
		echo '<p class="description">' . esc_html__( 'Add the cookie banner\'s reopen link anywhere with the [crw_manage_consent] shortcode.', 'cartconsent' ) . '</p>';
		echo '</div>';

		echo '<p><button class="button button-primary button-hero">' . esc_html__( 'Save banner settings', 'cartconsent' ) . '</button></p></form></div>';
	}

	public static function save_banner() {
		self::guard( 'crw_save_banner' );
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidationSanitization
		$o  = CRW_Options::all();
		$o['banner']['enabled']     = ! empty( $in['banner']['enabled'] );
		$o['banner']['auto_region'] = ! empty( $in['banner']['auto_region'] );
		$o['banner']['region']      = array_key_exists( ( $in['banner']['region'] ?? '' ), CRW_Regions::profiles() ) ? $in['banner']['region'] : 'us';
		$o['banner']['layout']      = sanitize_key( $in['banner']['layout'] ?? 'box' );
		$o['banner']['title']       = sanitize_text_field( $in['banner']['title'] ?? '' );
		$o['banner']['message']     = sanitize_textarea_field( $in['banner']['message'] ?? '' );
		foreach ( array( 'accept_label', 'reject_label', 'prefs_label', 'save_label' ) as $k ) {
			$o['banner'][ $k ] = sanitize_text_field( $in['banner'][ $k ] ?? '' );
		}
		foreach ( array( 'accent', 'text', 'surface' ) as $k ) {
			$o['style'][ $k ] = sanitize_text_field( $in['style'][ $k ] ?? '' );
		}
		$o['style']['radius']     = max( 0, (int) ( $in['style']['radius'] ?? 14 ) );
		$o['style']['custom_css'] = wp_strip_all_tags( (string) ( $in['style']['custom_css'] ?? '' ) );
		$o['signals']['gpc']      = ! empty( $in['signals']['gpc'] );
		$o['signals']['dnt']      = ! empty( $in['signals']['dnt'] );
		$o['consent_mode']        = ! empty( $in['consent_mode'] );
		$o['uet_consent']         = ! empty( $in['uet_consent'] );
		$o['do_not_sell']         = ! empty( $in['do_not_sell'] );
		CRW_Options::save( $o );
		wp_safe_redirect( add_query_arg( array( 'page' => 'crw-banner', 'crw_msg' => 'banner' ), admin_url( 'admin.php' ) ) );
		exit;
	}


}

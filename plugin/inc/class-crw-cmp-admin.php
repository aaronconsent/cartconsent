<?php
/**
 * Admin screens for the built-in consent surface: Banner & Settings, Consent
 * Records, Privacy Requests, and the Connection (credentials) page.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * CMP admin pages + handlers.
 */
class CRW_Cmp_Admin {

	const CAP = 'crw_manage';

	/**
	 * Register save/export handlers (menu items live in CRW_Admin::menu).
	 */
	public function register() {
		add_action( 'admin_post_crw_save_banner', array( __CLASS__, 'save_banner' ) );
		add_action( 'admin_post_crw_save_connection', array( __CLASS__, 'save_connection' ) );
		add_action( 'admin_post_crw_dsar_done', array( __CLASS__, 'dsar_done' ) );
		add_action( 'admin_post_crw_export_records', array( __CLASS__, 'export_records' ) );
	}

	private static function guard( $nonce ) {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( $nonce ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cartconsent' ) );
		}
	}

	private static function toggle( $name, $label, $checked ) {
		printf( '<p><label><input type="checkbox" name="%s" value="1" %s> %s</label></p>', esc_attr( $name ), checked( $checked, true, false ), esc_html( $label ) );
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

	/* ---------------------------------------------------------- Consent Records */

	public static function render_records() {
		$rows   = CRW_Consent::records( 200 );
		$intact = CRW_Consent::verify_chain();
		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Consent Records', 'cartconsent' ) . '</h1>';
		echo '<p class="description" style="max-width:75ch">' . esc_html__( 'Every consent choice on your banner is written here as a tamper-evident, hash-chained record — proof of who consented to what, and when. Stored in your own database, never metered.', 'cartconsent' ) . '</p>';

		echo '<p>';
		echo true === $intact
			? '<span style="color:#1d7f43;font-weight:600">&#10003; ' . esc_html__( 'Chain intact — records are unmodified.', 'cartconsent' ) . '</span>'
			: '<span style="color:#a3282a;font-weight:600">&#9679; ' . esc_html( sprintf( /* translators: %d id */ __( 'Chain broken at record #%d.', 'cartconsent' ), (int) $intact ) ) . '</span>';
		echo ' &nbsp; <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=crw_export_records' ), 'crw_export_records' ) ) . '">' . esc_html__( 'Export CSV', 'cartconsent' ) . '</a></p>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When', 'cartconsent' ) . '</th><th>' . esc_html__( 'Visitor', 'cartconsent' ) . '</th><th>' . esc_html__( 'Event', 'cartconsent' ) . '</th><th>' . esc_html__( 'Method', 'cartconsent' ) . '</th><th>' . esc_html__( 'Categories', 'cartconsent' ) . '</th><th>' . esc_html__( 'Region', 'cartconsent' ) . '</th></tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No consent records yet.', 'cartconsent' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$cats = json_decode( (string) $r['categories'], true );
			$on   = is_array( $cats ) ? implode( ', ', array_keys( array_filter( $cats ) ) ) : '';
			echo '<tr>';
			echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
			echo '<td><code>' . esc_html( substr( (string) $r['visitor_id'], 0, 8 ) ) . '</code></td>';
			echo '<td>' . esc_html( $r['event_type'] ) . '</td>';
			echo '<td>' . esc_html( $r['method'] ) . '</td>';
			echo '<td>' . esc_html( $on ) . '</td>';
			echo '<td>' . esc_html( $r['region'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Neutralize CSV formula injection: a cell a spreadsheet would treat as a
	 * formula (leading = + - @, tab, or CR) is prefixed with a single quote so
	 * Excel/Sheets render it as text. (Our own fields are near-always safe, but
	 * event_type/method are stored values — belt and suspenders.)
	 *
	 * @param string $v Cell value.
	 */
	protected static function csv_cell( $v ) {
		$v = (string) $v;
		if ( '' !== $v && strpbrk( $v[0], "=+-@\t\r" ) !== false ) {
			return "'" . $v;
		}
		return $v;
	}

	public static function export_records() {
		self::guard( 'crw_export_records' );
		$rows = CRW_Consent::records( 100000 );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=consent-records.csv' );
		$fh = fopen( 'php://output', 'w' );
		fputcsv( $fh, array( 'created_at', 'visitor_id', 'event_type', 'method', 'categories', 'region', 'ip_trunc', 'record_hash' ) );
		foreach ( $rows as $r ) {
			fputcsv( $fh, array_map( array( __CLASS__, 'csv_cell' ), array( $r['created_at'], $r['visitor_id'], $r['event_type'], $r['method'], $r['categories'], $r['region'], $r['ip_trunc'], $r['record_hash'] ) ) );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/* ---------------------------------------------------------- Privacy Requests */

	public static function render_privacy() {
		$rows = CRW_Rights::queue();
		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Privacy Requests', 'cartconsent' ) . '</h1>';
		if ( isset( $_GET['crw_msg'] ) && 'dsar' === $_GET['crw_msg'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Request marked done.', 'cartconsent' ) . '</p></div>';
		}

		echo '<div class="crw-card" style="max-width:840px"><h2 style="margin-top:0">' . esc_html__( 'What this is', 'cartconsent' ) . '</h2>';
		echo '<p class="description" style="max-width:75ch">' . esc_html__( 'Under CCPA/CPRA, GDPR, and similar laws, people can ask you to access, correct, or delete their personal information, or to stop the "sale or sharing" of it — and you must respond within a set time (45 days in California). This screen collects those requests, starts the clock, and tracks each to completion. "Delete" and "Do Not Sell or Share" requests also record a consent withdrawal and remove the person from any connected advertising audiences automatically.', 'cartconsent' ) . '</p>';
		echo '<h3 style="margin-bottom:4px">' . esc_html__( 'Add the request form to your site', 'cartconsent' ) . '</h3>';
		echo '<p>' . esc_html__( 'Put this shortcode on any page — your privacy policy is the usual place:', 'cartconsent' ) . '</p>';
		echo '<p><input type="text" readonly value="[crw_dsar_form]" onfocus="this.select()" class="code" style="width:200px;font-family:monospace;padding:4px 8px"></p>';
		echo '<h3 style="margin-bottom:4px">' . esc_html__( 'How to handle a request', 'cartconsent' ) . '</h3>';
		echo '<ol style="max-width:75ch;margin-top:4px">';
		echo '<li>' . esc_html__( 'Verify the requester before acting — requests arrive unverified.', 'cartconsent' ) . '</li>';
		echo '<li>' . esc_html__( 'Respond before the "Respond by" date. Overdue rows are flagged in red.', 'cartconsent' ) . '</li>';
		echo '<li>' . wp_kses_post( sprintf( /* translators: 1: export link 2: erase link */ __( 'Fulfil access/delete across your whole site with WordPress %1$s and %2$s — this plugin registers its stored data there.', 'cartconsent' ), '<a href="' . esc_url( admin_url( 'export-personal-data.php' ) ) . '">' . esc_html__( 'Tools → Export Personal Data', 'cartconsent' ) . '</a>', '<a href="' . esc_url( admin_url( 'erase-personal-data.php' ) ) . '">' . esc_html__( 'Erase Personal Data', 'cartconsent' ) . '</a>' ) ) . '</li>';
		echo '<li>' . esc_html__( 'Mark the request "done" once you have responded — your record that the deadline was met.', 'cartconsent' ) . '</li>';
		echo '</ol></div>';

		echo '<h2>' . esc_html__( 'Requests', 'cartconsent' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Received', 'cartconsent' ) . '</th><th>' . esc_html__( 'Name', 'cartconsent' ) . '</th><th>' . esc_html__( 'Email', 'cartconsent' ) . '</th><th>' . esc_html__( 'Type', 'cartconsent' ) . '</th><th>' . esc_html__( 'Respond by', 'cartconsent' ) . '</th><th>' . esc_html__( 'Status', 'cartconsent' ) . '</th><th></th></tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No privacy requests yet.', 'cartconsent' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			$overdue = ( 'new' === $r['status'] && $r['deadline'] && $r['deadline'] < gmdate( 'Y-m-d' ) );
			echo '<tr>';
			printf( '<td>%s</td><td>%s</td><td>%s</td><td>%s</td><td%s>%s</td><td>%s</td>',
				esc_html( $r['created'] ), esc_html( $r['name'] ), esc_html( $r['email'] ), esc_html( $r['type'] ),
				$overdue ? ' style="color:#a3282a;font-weight:600"' : '', esc_html( $r['deadline'] ),
				esc_html( 'new' === $r['status'] ? __( 'Open', 'cartconsent' ) : __( 'Completed', 'cartconsent' ) )
			);
			echo '<td>';
			if ( 'new' === $r['status'] ) {
				echo '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=crw_dsar_done&id=' . (int) $r['id'] ), 'crw_dsar_done_' . $r['id'] ) ) . '">' . esc_html__( 'Mark done', 'cartconsent' ) . '</a>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function dsar_done() {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'crw_dsar_done_' . $id ) || ! $id || get_post_type( $id ) !== CRW_Rights::CPT ) {
			wp_die( esc_html__( 'Not allowed.', 'cartconsent' ) );
		}
		// The admin has verified the requester — NOW apply a delete/opt-out
		// withdrawal (suppress + audience removal + a consent-log entry). Doing
		// this on the public intake would let anyone erase an arbitrary address.
		$type  = (string) get_post_meta( $id, 'crw_type', true );
		$email = sanitize_email( (string) get_post_meta( $id, 'crw_email', true ) );
		if ( in_array( $type, array( 'delete', 'opt_out' ), true ) && '' !== $email && is_email( $email ) && '1' !== (string) get_post_meta( $id, 'crw_applied', true ) ) {
			CRW_Carts_Store::suppress( CRW_Crypto::email_hash( $email ), 'erased' );
			CRW_Connection::optout( $email );
			if ( class_exists( 'CRW_Consent' ) ) {
				CRW_Consent::record(
					array(
						'event_type' => 'withdraw',
						'method'     => 'dsar',
						'categories' => array( 'functional' => true, 'marketing' => false, 'statistics' => false ),
					)
				);
			}
			update_post_meta( $id, 'crw_applied', '1' );
		}
		update_post_meta( $id, 'crw_status', 'done' );
		wp_safe_redirect( add_query_arg( array( 'page' => 'crw-privacy', 'crw_msg' => 'dsar' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------------------------------------- Connection */

	public static function render_connection() {
		$c        = CRW_Connection::config();
		$const    = defined( 'CONSENT_RESOLVE_WOO_API_KEY' );
		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Connection', 'cartconsent' ) . '</h1>';
		if ( isset( $_GET['crw_msg'] ) && 'conn' === $_GET['crw_msg'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Connection saved.', 'cartconsent' ) . '</p></div>';
		}
		echo '<div class="crw-card" style="max-width:760px"><h2 style="margin-top:0">' . esc_html__( 'CartConsent account', 'cartconsent' ) . '</h2>';
		echo '<p class="description" style="max-width:70ch">' . esc_html__( 'Optional. Connect your CartConsent account to push consent-filtered retargeting audiences to Meta and Google (hashing happens at the edge) and to propagate opt-outs. Everything else works without it.', 'cartconsent' ) . '</p>';
		echo CRW_Connection::is_connected()
			? '<p><span style="color:#1d7f43;font-weight:600">&#10003; ' . esc_html__( 'Connected.', 'cartconsent' ) . '</span></p>'
			: '<p><span class="description">' . esc_html__( 'Not connected.', 'cartconsent' ) . '</span></p>';
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

		echo '<div class="crw-card" style="max-width:760px"><h2 style="margin-top:0">' . esc_html__( 'Local keys', 'cartconsent' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Your Meta Pixel and GA4 IDs (for on-site retargeting + purchase tracking) live in Cart Recovery Settings → Retargeting. No account needed.', 'cartconsent' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=crw-settings' ) ) . '">' . esc_html__( 'Open Cart Recovery Settings', 'cartconsent' ) . '</a></p></div>';
		echo '</div>';
	}

	public static function save_connection() {
		self::guard( 'crw_save_connection' );
		$in = wp_unslash( $_POST['connection'] ?? array() ); // phpcs:ignore WordPress.Security.ValidationSanitization
		$o  = CRW_Options::all();
		$o['connection']['site_id'] = sanitize_text_field( $in['site_id'] ?? '' );
		if ( ! defined( 'CONSENT_RESOLVE_WOO_API_KEY' ) && isset( $in['api_key'] ) ) {
			$o['connection']['api_key'] = sanitize_text_field( $in['api_key'] );
		}
		CRW_Options::save( $o );
		wp_safe_redirect( add_query_arg( array( 'page' => 'crw-connection', 'crw_msg' => 'conn' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}

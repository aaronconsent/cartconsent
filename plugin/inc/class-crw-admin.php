<?php
/**
 * Admin UI: recovery dashboard, abandoned-carts list, and settings.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
class CRW_Admin {

	const CAP  = 'crw_manage';
	const SLUG = 'crw-dashboard';

	/**
	 * Hook up.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_crw_save_settings', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Menu tree.
	 */
	public function menu() {
		add_menu_page( __( 'Consent Resolve', 'consent-resolve-woo' ), __( 'Consent Resolve', 'consent-resolve-woo' ), self::CAP, self::SLUG, array( $this, 'page_dashboard' ), 'dashicons-shield', 56 );
		add_submenu_page( self::SLUG, __( 'Dashboard', 'consent-resolve-woo' ), __( 'Dashboard', 'consent-resolve-woo' ), self::CAP, self::SLUG, array( $this, 'page_dashboard' ) );
		add_submenu_page( self::SLUG, __( 'Setup Wizard', 'consent-resolve-woo' ), __( 'Setup Wizard', 'consent-resolve-woo' ), self::CAP, 'crw-wizard', array( 'CRW_Wizard', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Banner & Settings', 'consent-resolve-woo' ), __( 'Banner & Settings', 'consent-resolve-woo' ), self::CAP, 'crw-banner', array( 'CRW_Cmp_Admin', 'render_banner' ) );
		add_submenu_page( self::SLUG, __( 'Consent Records', 'consent-resolve-woo' ), __( 'Consent Records', 'consent-resolve-woo' ), self::CAP, 'crw-records', array( 'CRW_Cmp_Admin', 'render_records' ) );
		add_submenu_page( self::SLUG, __( 'Privacy Requests', 'consent-resolve-woo' ), __( 'Privacy Requests', 'consent-resolve-woo' ), self::CAP, 'crw-privacy', array( 'CRW_Cmp_Admin', 'render_privacy' ) );
		add_submenu_page( self::SLUG, __( 'Cart Recovery', 'consent-resolve-woo' ), __( 'Cart Recovery', 'consent-resolve-woo' ), self::CAP, 'crw-carts', array( $this, 'page_carts' ) );
		add_submenu_page( self::SLUG, __( 'Analytics', 'consent-resolve-woo' ), __( 'Analytics', 'consent-resolve-woo' ), self::CAP, 'crw-analytics', array( $this, 'page_analytics' ) );
		add_submenu_page( self::SLUG, __( 'Cart Recovery Settings', 'consent-resolve-woo' ), __( 'Cart Recovery Settings', 'consent-resolve-woo' ), self::CAP, 'crw-settings', array( $this, 'page_settings' ) );
		add_submenu_page( self::SLUG, __( 'Connection', 'consent-resolve-woo' ), __( 'Connection', 'consent-resolve-woo' ), self::CAP, 'crw-connection', array( 'CRW_Cmp_Admin', 'render_connection' ) );
	}

	/**
	 * Styles.
	 *
	 * @param string $hook Current screen hook.
	 */
	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'crw-' ) && false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'crw-admin', CRW_URL . 'assets/css/admin.css', array(), CRW_VERSION );
	}

	/* ------------------------------------------------------------- dashboard */

	/**
	 * The recovery dashboard.
	 */
	public function page_dashboard() {
		$f         = CRW_Carts_Store::funnel();
		$captured  = (int) ( $f['captured'] ?? 0 );
		$recovered = (int) ( $f['recovered'] ?? 0 );
		$rate      = $captured > 0 ? round( $recovered / $captured * 100 ) : 0;

		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Cart Recovery', 'consent-resolve-woo' ) . '</h1>';
		$this->flash();
		$this->health_strip();

		echo '<div class="crw-cards">';
		$this->card( number_format_i18n( $captured ), __( 'Carts captured', 'consent-resolve-woo' ) );
		$this->card( number_format_i18n( $recovered ), __( 'Recovered', 'consent-resolve-woo' ) );
		$this->card( $rate . '%', __( 'Recovery rate', 'consent-resolve-woo' ), $rate >= 10 ? 'good' : '' );
		$this->card( $this->money( (int) ( $f['recovered_cents'] ?? 0 ) ), __( 'Revenue recovered', 'consent-resolve-woo' ), 'good' );
		echo '</div>';

		echo '<div class="crw-cards">';
		$this->card( $this->money( (int) ( $f['open_cents'] ?? 0 ) ), __( 'Open cart value', 'consent-resolve-woo' ) );
		$this->card( number_format_i18n( (int) ( $f['open'] ?? 0 ) ), __( 'Open carts', 'consent-resolve-woo' ) );
		$this->card( number_format_i18n( CRW_Events::count( 'email_sent', 30 ) ), __( 'Emails sent (30d)', 'consent-resolve-woo' ) );
		$this->card( number_format_i18n( CRW_Events::count( 'recovery_clicked', 30 ) ), __( 'Recovery clicks (30d)', 'consent-resolve-woo' ) );
		echo '</div>';

		// Consent posture — the differentiator, stated plainly.
		echo '<div class="crw-card crw-note"><h2 style="margin-top:0">' . esc_html__( 'Your consent posture', 'consent-resolve-woo' ) . '</h2>';
		echo '<p>' . esc_html( $this->posture_text() ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Consent Resolve only stores and emails shoppers with a lawful basis, stamps that basis on every record, and auto-purges the rest. This is why recovery emails land in the inbox — not the spam folder.', 'consent-resolve-woo' ) . '</p></div>';

		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=crw-carts' ) ) . '">' . esc_html__( 'View abandoned carts', 'consent-resolve-woo' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=crw-settings' ) ) . '">' . esc_html__( 'Settings', 'consent-resolve-woo' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * A one-line description of the active consent behavior.
	 */
	private function posture_text() {
		$basis = CRW_Options::get( 'consent.basis', 'jurisdiction' );
		$map   = array(
			'optin_only'   => __( 'Emailing only shoppers who explicitly opt in (strictest).', 'consent-resolve-woo' ),
			'jurisdiction' => __( 'Region-aware: opt-out regions get a soft opt-in with unsubscribe; opt-in regions (EU/UK) require an explicit checkbox.', 'consent-resolve-woo' ),
			'all_unsub'    => __( 'Emailing everyone who leaves an address, always with a one-click unsubscribe.', 'consent-resolve-woo' ),
		);
		$txt = $map[ $basis ] ?? '';
		if ( CRW_Options::get( 'consent.honor_gpc', true ) ) {
			$txt .= ' ' . __( 'Global Privacy Control is honored.', 'consent-resolve-woo' );
		}
		return $txt;
	}

	/**
	 * Health strip.
	 */
	private function health_strip() {
		$items = array(
			array( (bool) CRW_Options::get( 'capture.enabled', true ), __( 'Capture active', 'consent-resolve-woo' ) ),
			array( (bool) wp_next_scheduled( CRW_Install::CRON_HOOK ), __( 'Recovery queue scheduled', 'consent-resolve-woo' ) ),
			array( '' !== trim( (string) CRW_Options::get( 'emails.from_email', '' ) ) || (bool) get_option( 'admin_email' ), __( 'Sender address set', 'consent-resolve-woo' ) ),
			array( '' !== trim( (string) get_option( 'woocommerce_store_address', '' ) ), __( 'Store address (for CAN-SPAM)', 'consent-resolve-woo' ) ),
			array( CRW_Crypto::available(), __( 'Email encryption available', 'consent-resolve-woo' ) ),
		);
		echo '<div class="crw-health">';
		foreach ( $items as $it ) {
			printf(
				'<div class="crw-health-item"><span style="color:%s">%s</span> %s</div>',
				$it[0] ? '#1d7f43' : '#a3282a',
				$it[0] ? '&#10003;' : '&#9679;',
				esc_html( $it[1] )
			);
		}
		echo '</div>';
	}

	/* ----------------------------------------------------------------- carts */

	/**
	 * Abandoned-carts list.
	 */
	public function page_carts() {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$rows   = CRW_Carts_Store::recent( array( 'limit' => 100, 'status' => $status ) );
		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Abandoned Carts', 'consent-resolve-woo' ) . '</h1>';

		echo '<ul class="subsubsub"><li><a href="' . esc_url( admin_url( 'admin.php?page=crw-carts' ) ) . '"' . ( '' === $status ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'consent-resolve-woo' ) . '</a> | </li>';
		foreach ( array( 'abandoned' => __( 'In progress', 'consent-resolve-woo' ), 'recovered' => __( 'Recovered', 'consent-resolve-woo' ), 'lost' => __( 'Lost', 'consent-resolve-woo' ) ) as $s => $label ) {
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=crw-carts&status=' . $s ) ) . '"' . ( $status === $s ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a> | </li>';
		}
		echo '</ul>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Shopper', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Items', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Value', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Consent', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Emails', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Status', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Updated', 'consent-resolve-woo' ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No abandoned carts yet.', 'consent-resolve-woo' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr>';
			echo '<td><strong>' . esc_html( $r['email_masked'] ) . '</strong>' . ( $r['first_name'] ? '<br><small>' . esc_html( $r['first_name'] ) . '</small>' : '' ) . '</td>';
			echo '<td>' . (int) $r['item_count'] . '</td>';
			echo '<td>' . esc_html( $this->money( (int) $r['total_cents'], $r['currency'] ) ) . '</td>';
			echo '<td>' . esc_html( CRW_Recovery_Consent::basis_label( $r['consent_basis'] ) ) . '</td>';
			echo '<td>' . (int) $r['emails_sent'] . '</td>';
			echo '<td>' . esc_html( $this->status_label( $r['status'] ) ) . '</td>';
			echo '<td>' . esc_html( mysql2date( get_option( 'date_format' ) . ' H:i', $r['updated_at'] ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	/**
	 * Friendly status label.
	 *
	 * @param string $status Status.
	 */
	private function status_label( $status ) {
		$map = array(
			'abandoned'    => __( 'In sequence', 'consent-resolve-woo' ),
			'recovered'    => __( 'Recovered', 'consent-resolve-woo' ),
			'lost'         => __( 'Lost', 'consent-resolve-woo' ),
			'unsubscribed' => __( 'Unsubscribed', 'consent-resolve-woo' ),
			'active'       => __( 'Active', 'consent-resolve-woo' ),
		);
		return $map[ $status ] ?? $status;
	}

	/* ------------------------------------------------------------- analytics */

	/**
	 * Recovery analytics: channels, per-sequence performance, and A/B splits.
	 */
	public function page_analytics() {
		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Recovery Analytics', 'consent-resolve-woo' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Last 30 days.', 'consent-resolve-woo' ) . '</p>';

		// Channels.
		echo '<h2>' . esc_html__( 'Channels', 'consent-resolve-woo' ) . '</h2><div class="crw-cards">';
		$this->card( number_format_i18n( CRW_Events::count( 'email_sent', 30 ) ), __( 'Recovery emails', 'consent-resolve-woo' ) );
		$this->card( number_format_i18n( CRW_Events::count( 'push_sent', 30 ) ), __( 'Web pushes', 'consent-resolve-woo' ) );
		$this->card( number_format_i18n( CRW_Events::count( 'popup_capture', 30 ) ), __( 'Popup captures', 'consent-resolve-woo' ) );
		$this->card( number_format_i18n( CRW_Events::count( 'recovery_clicked', 30 ) ), __( 'Recovery clicks', 'consent-resolve-woo' ) );
		echo '</div>';

		// Per-sequence.
		echo '<h2>' . esc_html__( 'By sequence', 'consent-resolve-woo' ) . '</h2>';
		$stats = CRW_Carts_Store::sequence_stats();
		$names = array();
		foreach ( CRW_Options::sequences() as $seq ) {
			$names[ (string) $seq['id'] ] = (string) ( $seq['name'] ?? $seq['id'] );
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Sequence', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Carts', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Recovered', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Rate', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Revenue', 'consent-resolve-woo' ) . '</th></tr></thead><tbody>';
		if ( empty( $stats ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No data yet.', 'consent-resolve-woo' ) . '</td></tr>';
		}
		foreach ( $stats as $r ) {
			$carts = (int) $r['carts'];
			$rec   = (int) $r['recovered'];
			$rate  = $carts > 0 ? round( $rec / $carts * 100 ) : 0;
			echo '<tr><td><strong>' . esc_html( $names[ (string) $r['sequence_id'] ] ?? $r['sequence_id'] ) . '</strong></td><td>' . esc_html( number_format_i18n( $carts ) ) . '</td><td>' . esc_html( number_format_i18n( $rec ) ) . '</td><td>' . (int) $rate . '%</td><td>' . esc_html( $this->money( (int) $r['revenue_cents'] ) ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// A/B subject splits.
		$has_ab = false;
		$ab_out = '';
		foreach ( CRW_Options::sequences() as $seq ) {
			foreach ( array_values( (array) ( $seq['steps'] ?? array() ) ) as $sti => $step ) {
				$subs = isset( $step['subjects'] ) && is_array( $step['subjects'] ) ? array_values( array_filter( $step['subjects'] ) ) : array();
				if ( count( $subs ) < 2 ) {
					continue;
				}
				$has_ab = true;
				$ab_out .= '<tr><td colspan="3"><strong>' . esc_html( ( $seq['name'] ?? $seq['id'] ) . ' — ' . sprintf( /* translators: %d step */ __( 'step %d', 'consent-resolve-woo' ), $sti + 1 ) ) . '</strong></td></tr>';
				foreach ( $subs as $vi => $subject ) {
					$sends  = CRW_Events::count_label( 'email_sent', (string) $seq['id'] . ':' . $sti . ':' . $vi );
					$ab_out .= '<tr><td style="padding-left:24px">' . esc_html( 'A' === chr( 65 + $vi ) || $vi < 26 ? chr( 65 + $vi ) : ( $vi + 1 ) ) . '</td><td>' . esc_html( wp_trim_words( $subject, 12 ) ) . '</td><td>' . esc_html( number_format_i18n( $sends ) ) . ' ' . esc_html__( 'sent', 'consent-resolve-woo' ) . '</td></tr>';
				}
			}
		}
		if ( $has_ab ) {
			echo '<h2>' . esc_html__( 'A/B subject tests', 'consent-resolve-woo' ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Variant', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Subject', 'consent-resolve-woo' ) . '</th><th>' . esc_html__( 'Sends', 'consent-resolve-woo' ) . '</th></tr></thead><tbody>' . $ab_out . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}

	/* -------------------------------------------------------------- settings */

	/**
	 * Settings page.
	 */
	public function page_settings() {
		$capture   = CRW_Options::get( 'capture' );
		$consent   = CRW_Options::get( 'consent' );
		$emails    = CRW_Options::get( 'emails' );
		$coupon    = CRW_Options::get( 'coupon' );
		$tracking  = CRW_Options::get( 'tracking' );
		$audiences = CRW_Options::get( 'audiences' );

		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Cart Recovery — Settings', 'consent-resolve-woo' ) . '</h1>';
		$this->flash();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crw_save_settings' );
		echo '<input type="hidden" name="action" value="crw_save_settings">';

		// Capture.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Capture', 'consent-resolve-woo' ) . '</h2>';
		$this->toggle( 'capture[enabled]', __( 'Capture abandoning shoppers', 'consent-resolve-woo' ), $capture['enabled'] );
		echo '<p class="description">' . esc_html__( 'We capture only on a deliberate action (a submitted checkout or a completed email field) — never by watching keystrokes.', 'consent-resolve-woo' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Consider a cart abandoned after (minutes of inactivity)', 'consent-resolve-woo' ) . ' <input type="number" min="5" name="capture[abandon_after_minutes]" value="' . esc_attr( (int) $capture['abandon_after_minutes'] ) . '" class="small-text"></label></p>';
		echo '</div>';

		// Consent.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Consent', 'consent-resolve-woo' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Who may we email?', 'consent-resolve-woo' ) . '<br><select name="consent[basis]">';
		foreach ( array(
			'jurisdiction' => __( 'Region-aware (recommended)', 'consent-resolve-woo' ),
			'optin_only'   => __( 'Only explicit opt-ins (strictest)', 'consent-resolve-woo' ),
			'all_unsub'    => __( 'Everyone, with unsubscribe (most aggressive)', 'consent-resolve-woo' ),
		) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '" ' . selected( $consent['basis'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'When location is unknown, treat visitors as', 'consent-resolve-woo' ) . ' <select name="consent[fallback_model]">';
		foreach ( array( 'opt_out' => __( 'US-style opt-out (soft opt-in allowed)', 'consent-resolve-woo' ), 'opt_in' => __( 'EU-style opt-in (require checkbox)', 'consent-resolve-woo' ) ) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '" ' . selected( $consent['fallback_model'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><br><span class="description">' . esc_html__( 'Install Consent Resolve to detect each visitor\'s region automatically.', 'consent-resolve-woo' ) . '</span></p>';
		$this->toggle( 'consent[checkout_checkbox]', __( 'Show a marketing-consent checkbox at checkout', 'consent-resolve-woo' ), $consent['checkout_checkbox'] );
		echo '<p><label>' . esc_html__( 'Checkbox label', 'consent-resolve-woo' ) . '<br><input type="text" name="consent[checkbox_label]" value="' . esc_attr( $consent['checkbox_label'] ) . '" class="large-text"></label></p>';
		$this->toggle( 'consent[honor_gpc]', __( 'Honor Global Privacy Control (suppress soft opt-ins + audiences)', 'consent-resolve-woo' ), $consent['honor_gpc'] );
		echo '</div>';

		// Emails.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Emails', 'consent-resolve-woo' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'From name', 'consent-resolve-woo' ) . '<br><input type="text" name="emails[from_name]" value="' . esc_attr( $emails['from_name'] ) . '" class="regular-text" placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'From address', 'consent-resolve-woo' ) . '<br><input type="email" name="emails[from_email]" value="' . esc_attr( $emails['from_email'] ) . '" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '"></label><br><span class="description">' . esc_html__( 'Use an address on a domain with SPF/DKIM set up for best deliverability.', 'consent-resolve-woo' ) . '</span></p>';
		echo '<p><label>' . esc_html__( 'Reply-to (optional)', 'consent-resolve-woo' ) . '<br><input type="email" name="emails[reply_to]" value="' . esc_attr( $emails['reply_to'] ) . '" class="regular-text"></label></p>';
		echo '</div>';

		// Sequences (multi-sequence + segmentation + A/B).
		echo '<div class="crw-card"><h2>' . esc_html__( 'Recovery sequences', 'consent-resolve-woo' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each cart enters the first matching sequence (by segment). Merge tags: {first_name} {store_name} {cart_items} {cart_total} {recovery_url} {coupon} {coupon_code}. Put multiple subject lines (one per line) to A/B-test them.', 'consent-resolve-woo' ) . '</p>';
		echo '<div id="crw-sequences">';
		$seqs = CRW_Options::sequences();
		foreach ( $seqs as $si => $seq ) {
			$this->render_sequence( $si, $seq );
		}
		echo '</div>';
		echo '<p><button type="button" class="button" id="crw-add-seq">+ ' . esc_html__( 'Add sequence', 'consent-resolve-woo' ) . '</button></p>';
		$this->sequences_js( count( $seqs ) );
		echo '</div>';

		// Coupon.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Recovery coupon', 'consent-resolve-woo' ) . '</h2>';
		$this->toggle( 'coupon[enabled]', __( 'Offer a single-use coupon (locked to the shopper)', 'consent-resolve-woo' ), $coupon['enabled'] );
		echo '<p><label>' . esc_html__( 'Type', 'consent-resolve-woo' ) . ' <select name="coupon[type]"><option value="percent" ' . selected( $coupon['type'], 'percent', false ) . '>' . esc_html__( 'Percent', 'consent-resolve-woo' ) . '</option><option value="fixed_cart" ' . selected( $coupon['type'], 'fixed_cart', false ) . '>' . esc_html__( 'Fixed cart', 'consent-resolve-woo' ) . '</option></select></label> ';
		echo '<label>' . esc_html__( 'Amount', 'consent-resolve-woo' ) . ' <input type="number" min="1" step="0.01" name="coupon[amount]" value="' . esc_attr( $coupon['amount'] ) . '" class="small-text"></label> ';
		echo '<label>' . esc_html__( 'Expires (days)', 'consent-resolve-woo' ) . ' <input type="number" min="1" name="coupon[expiry_days]" value="' . esc_attr( (int) $coupon['expiry_days'] ) . '" class="small-text"></label></p>';
		echo '</div>';

		// Channels — web push.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Web push channel', 'consent-resolve-woo' ) . '</h2>';
		$this->toggle( 'push[enabled]', __( 'Also recover carts by browser push notification', 'consent-resolve-woo' ), CRW_Options::get( 'push.enabled', false ) );
		if ( class_exists( 'CRW_Push_Crypto' ) && ! CRW_Push_Crypto::available() ) {
			echo '<p class="description" style="color:#a3282a">' . esc_html__( 'Your server is missing the OpenSSL EC support web push needs — contact your host.', 'consent-resolve-woo' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Shoppers are asked (with a dismissible prompt at checkout) to allow notifications. Those who opt in get a push alongside each recovery email. Self-hosted — no third-party account or cost.', 'consent-resolve-woo' ) . '</p>';
		}
		echo '</div>';

		// Cart-saver popup.
		$pop = CRW_Options::get( 'popup' );
		echo '<div class="crw-card"><h2>' . esc_html__( 'Cart-saver popup', 'consent-resolve-woo' ) . '</h2>';
		$this->toggle( 'popup[enabled]', __( 'Show an exit-intent popup that saves the cart by email', 'consent-resolve-woo' ), $pop['enabled'] );
		echo '<p class="description">' . esc_html__( 'Spam-safe: the shopper ticks a consent box, and we only start reminders after they confirm via a double-opt-in email.', 'consent-resolve-woo' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Trigger', 'consent-resolve-woo' ) . ' <select name="popup[trigger]"><option value="exit" ' . selected( $pop['trigger'], 'exit', false ) . '>' . esc_html__( 'Exit intent', 'consent-resolve-woo' ) . '</option><option value="timer" ' . selected( $pop['trigger'], 'timer', false ) . '>' . esc_html__( 'After a delay', 'consent-resolve-woo' ) . '</option></select></label> ';
		echo '<label>' . esc_html__( 'Delay (seconds)', 'consent-resolve-woo' ) . ' <input type="number" min="3" name="popup[delay_seconds]" value="' . esc_attr( (int) $pop['delay_seconds'] ) . '" class="small-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Title', 'consent-resolve-woo' ) . '<br><input type="text" name="popup[title]" value="' . esc_attr( $pop['title'] ) . '" class="regular-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Message', 'consent-resolve-woo' ) . '<br><textarea name="popup[message]" rows="2" class="large-text">' . esc_textarea( $pop['message'] ) . '</textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Consent label', 'consent-resolve-woo' ) . '<br><input type="text" name="popup[consent_label]" value="' . esc_attr( $pop['consent_label'] ) . '" class="large-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Button', 'consent-resolve-woo' ) . '<br><input type="text" name="popup[button]" value="' . esc_attr( $pop['button'] ) . '" class="regular-text"></label></p>';
		echo '</div>';

		// Retargeting.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Retargeting', 'consent-resolve-woo' ) . '</h2>';
		$this->toggle( 'tracking[consent_mode]', __( 'Set Google Consent Mode v2 defaults', 'consent-resolve-woo' ), $tracking['consent_mode'] );
		echo '<p><label>' . esc_html__( 'Meta Pixel ID', 'consent-resolve-woo' ) . '<br><input type="text" name="tracking[meta_pixel_id]" value="' . esc_attr( $tracking['meta_pixel_id'] ) . '" class="regular-text"></label></p>';
		echo '<p><label>' . esc_html__( 'GA4 Measurement ID', 'consent-resolve-woo' ) . '<br><input type="text" name="tracking[ga4_id]" value="' . esc_attr( $tracking['ga4_id'] ) . '" class="regular-text"></label></p>';
		echo '<p class="description">' . esc_html__( 'Pixels load only after marketing consent is granted. Add-to-cart, checkout, and purchase events fire behind the same gate.', 'consent-resolve-woo' ) . '</p>';
		$this->toggle( 'audiences[enabled]', __( 'Build a consent-filtered retargeting audience of abandoners (requires Consent Resolve)', 'consent-resolve-woo' ), $audiences['enabled'] );
		echo '<p class="description">' . esc_html__( 'Only explicit opt-ins with no Do-Not-Sell/Share signal are added; unsubscribes and erasures are removed automatically.', 'consent-resolve-woo' ) . '</p>';
		echo '</div>';

		// Retention.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Data retention', 'consent-resolve-woo' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Delete abandoned carts after (days)', 'consent-resolve-woo' ) . ' <input type="number" min="1" name="retention_days" value="' . esc_attr( (int) CRW_Options::get( 'retention_days', 60 ) ) . '" class="small-text"></label><br><span class="description">' . esc_html__( 'Captures with no lawful basis to email are dropped within 24 hours automatically (data minimization).', 'consent-resolve-woo' ) . '</span></p>';
		echo '</div>';

		echo '<p><button class="button button-primary button-hero">' . esc_html__( 'Save settings', 'consent-resolve-woo' ) . '</button></p>';
		echo '</form></div>';
	}

	/* ------------------------------------------------------------- save + ui */

	/**
	 * Persist settings.
	 */
	public function save() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'crw_save_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'consent-resolve-woo' ) );
		}
		$in = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidationSanitization
		$s  = CRW_Options::all();

		$s['capture']['enabled']               = ! empty( $in['capture']['enabled'] );
		$s['capture']['abandon_after_minutes'] = max( 5, (int) ( $in['capture']['abandon_after_minutes'] ?? 30 ) );

		$s['consent']['basis']             = in_array( ( $in['consent']['basis'] ?? '' ), array( 'jurisdiction', 'optin_only', 'all_unsub' ), true ) ? $in['consent']['basis'] : 'jurisdiction';
		$s['consent']['fallback_model']    = ( 'opt_in' === ( $in['consent']['fallback_model'] ?? '' ) ) ? 'opt_in' : 'opt_out';
		$s['consent']['checkout_checkbox'] = ! empty( $in['consent']['checkout_checkbox'] );
		$s['consent']['checkbox_label']    = sanitize_text_field( (string) ( $in['consent']['checkbox_label'] ?? '' ) );
		$s['consent']['honor_gpc']         = ! empty( $in['consent']['honor_gpc'] );

		$s['emails']['from_name']  = sanitize_text_field( (string) ( $in['emails']['from_name'] ?? '' ) );
		$s['emails']['from_email'] = sanitize_email( (string) ( $in['emails']['from_email'] ?? '' ) );
		$s['emails']['reply_to']   = sanitize_email( (string) ( $in['emails']['reply_to'] ?? '' ) );
		if ( isset( $in['seq'] ) && is_array( $in['seq'] ) ) {
			$sequences = array();
			foreach ( $in['seq'] as $seq ) {
				$name = sanitize_text_field( (string) ( $seq['name'] ?? '' ) );
				// Build steps.
				$steps = array();
				foreach ( (array) ( $seq['steps'] ?? array() ) as $step ) {
					$subjects = array();
					foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $step['subjects'] ?? '' ) ) as $line ) {
						$line = sanitize_text_field( $line );
						if ( '' !== $line ) {
							$subjects[] = $line;
						}
					}
					$body = sanitize_textarea_field( (string) ( $step['body'] ?? '' ) );
					if ( empty( $subjects ) && '' === $body ) {
						continue; // Skip a blank step slot.
					}
					$steps[] = array(
						'delay_minutes' => max( 1, (int) ( $step['delay_minutes'] ?? 60 ) ),
						'subjects'      => $subjects ? $subjects : array( '' ),
						'body'          => $body,
						'coupon'        => ! empty( $step['coupon'] ),
					);
				}
				if ( '' === $name && empty( $steps ) ) {
					continue; // Skip a blank sequence slot.
				}
				$id = sanitize_key( (string) ( $seq['id'] ?? '' ) );
				if ( '' === $id ) {
					$id = 'seq_' . substr( md5( $name . wp_json_encode( $steps ) ), 0, 8 );
				}
				$segment = array();
				foreach ( array( 'min_total', 'max_total', 'products', 'categories', 'countries' ) as $sk ) {
					$segment[ $sk ] = sanitize_text_field( (string) ( $seq['segment'][ $sk ] ?? '' ) );
				}
				$sequences[] = array(
					'id'      => $id,
					'name'    => '' !== $name ? $name : $id,
					'enabled' => ! empty( $seq['enabled'] ),
					'segment' => $segment,
					'steps'   => $steps ? $steps : CRW_Options::default_steps(),
				);
			}
			if ( $sequences ) {
				$s['emails']['sequences'] = $sequences;
				unset( $s['emails']['sequence'] ); // Drop any legacy single-sequence key.
			}
		}

		$s['coupon']['enabled']     = ! empty( $in['coupon']['enabled'] );
		$s['coupon']['type']        = ( 'fixed_cart' === ( $in['coupon']['type'] ?? '' ) ) ? 'fixed_cart' : 'percent';
		$s['coupon']['amount']      = max( 0, (float) ( $in['coupon']['amount'] ?? 10 ) );
		$s['coupon']['expiry_days'] = max( 1, (int) ( $in['coupon']['expiry_days'] ?? 7 ) );

		$s['tracking']['consent_mode'] = ! empty( $in['tracking']['consent_mode'] );
		$s['tracking']['meta_pixel_id'] = sanitize_text_field( (string) ( $in['tracking']['meta_pixel_id'] ?? '' ) );
		$s['tracking']['ga4_id']       = sanitize_text_field( (string) ( $in['tracking']['ga4_id'] ?? '' ) );
		$s['audiences']['enabled']     = ! empty( $in['audiences']['enabled'] );
		$s['push']['enabled']          = ! empty( $in['push']['enabled'] );
		$s['popup']['enabled']         = ! empty( $in['popup']['enabled'] );
		$s['popup']['trigger']         = ( 'timer' === ( $in['popup']['trigger'] ?? '' ) ) ? 'timer' : 'exit';
		$s['popup']['delay_seconds']   = max( 3, (int) ( $in['popup']['delay_seconds'] ?? 20 ) );
		$s['popup']['title']           = sanitize_text_field( (string) ( $in['popup']['title'] ?? '' ) );
		$s['popup']['message']         = sanitize_textarea_field( (string) ( $in['popup']['message'] ?? '' ) );
		$s['popup']['consent_label']   = sanitize_text_field( (string) ( $in['popup']['consent_label'] ?? '' ) );
		$s['popup']['button']          = sanitize_text_field( (string) ( $in['popup']['button'] ?? '' ) );

		$s['retention_days'] = max( 1, (int) ( $in['retention_days'] ?? 60 ) );

		CRW_Options::save( $s );
		wp_safe_redirect( add_query_arg( array( 'page' => 'crw-settings', 'crw_msg' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function flash() {
		$m = isset( $_GET['crw_msg'] ) ? sanitize_key( wp_unslash( $_GET['crw_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'saved' === $m ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'consent-resolve-woo' ) . '</p></div>';
		}
	}

	private function card( $n, $label, $cls = '' ) {
		printf( '<div class="crw-card crw-stat %s"><div class="crw-stat-n">%s</div><div class="crw-stat-l">%s</div></div>', esc_attr( $cls ), esc_html( $n ), esc_html( $label ) );
	}

	private function toggle( $name, $label, $checked ) {
		printf( '<p><label><input type="checkbox" name="%s" value="1" %s> %s</label></p>', esc_attr( $name ), checked( $checked, true, false ), esc_html( $label ) );
	}

	/**
	 * Render one sequence block (name, enabled, segment, steps).
	 *
	 * @param string $si  Sequence index (string; may be a JS placeholder).
	 * @param array  $seq Sequence.
	 */
	private function render_sequence( $si, $seq ) {
		$seg = (array) ( $seq['segment'] ?? array() );
		$val = static function ( $v ) {
			return is_array( $v ) ? implode( ', ', $v ) : (string) $v;
		};
		echo '<div class="crw-card crw-seq" style="background:#fbfbfc" data-si="' . esc_attr( $si ) . '">';
		echo '<input type="hidden" name="seq[' . esc_attr( $si ) . '][id]" value="' . esc_attr( $seq['id'] ?? '' ) . '">';
		echo '<p><label><strong>' . esc_html__( 'Sequence name', 'consent-resolve-woo' ) . '</strong> <input type="text" name="seq[' . esc_attr( $si ) . '][name]" value="' . esc_attr( $seq['name'] ?? '' ) . '" placeholder="' . esc_attr__( 'e.g. High-value carts', 'consent-resolve-woo' ) . '"></label> ';
		echo '<label style="margin-left:14px"><input type="checkbox" name="seq[' . esc_attr( $si ) . '][enabled]" value="1" ' . checked( ! empty( $seq['enabled'] ), true, false ) . '> ' . esc_html__( 'Enabled', 'consent-resolve-woo' ) . '</label> ';
		echo '<button type="button" class="button-link crw-del-seq" style="color:#a3282a;margin-left:14px">' . esc_html__( 'Remove', 'consent-resolve-woo' ) . '</button></p>';

		echo '<details><summary style="cursor:pointer">' . esc_html__( 'Segment — who enters this sequence', 'consent-resolve-woo' ) . '</summary><div style="padding:8px 0">';
		echo '<p><label>' . esc_html__( 'Cart total between', 'consent-resolve-woo' ) . ' <input type="number" step="0.01" name="seq[' . esc_attr( $si ) . '][segment][min_total]" value="' . esc_attr( $val( $seg['min_total'] ?? '' ) ) . '" class="small-text" placeholder="' . esc_attr__( 'min', 'consent-resolve-woo' ) . '"> ' . esc_html__( 'and', 'consent-resolve-woo' ) . ' <input type="number" step="0.01" name="seq[' . esc_attr( $si ) . '][segment][max_total]" value="' . esc_attr( $val( $seg['max_total'] ?? '' ) ) . '" class="small-text" placeholder="' . esc_attr__( 'max', 'consent-resolve-woo' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Product IDs (comma-separated)', 'consent-resolve-woo' ) . '<br><input type="text" name="seq[' . esc_attr( $si ) . '][segment][products]" value="' . esc_attr( $val( $seg['products'] ?? '' ) ) . '" class="regular-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Category slugs or IDs', 'consent-resolve-woo' ) . '<br><input type="text" name="seq[' . esc_attr( $si ) . '][segment][categories]" value="' . esc_attr( $val( $seg['categories'] ?? '' ) ) . '" class="regular-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Countries (2-letter, comma-separated)', 'consent-resolve-woo' ) . '<br><input type="text" name="seq[' . esc_attr( $si ) . '][segment][countries]" value="' . esc_attr( $val( $seg['countries'] ?? '' ) ) . '" class="regular-text" placeholder="US, CA"></label></p>';
		echo '<p class="description">' . esc_html__( 'Leave everything blank for a catch-all sequence. Carts enter the first matching enabled sequence.', 'consent-resolve-woo' ) . '</p></div></details>';

		echo '<div class="crw-steps" data-si="' . esc_attr( $si ) . '">';
		foreach ( array_values( (array) ( $seq['steps'] ?? array() ) ) as $sti => $step ) {
			$this->render_step( $si, $sti, $step );
		}
		echo '</div>';
		echo '<p><button type="button" class="button button-small crw-add-step" data-si="' . esc_attr( $si ) . '">+ ' . esc_html__( 'Add step', 'consent-resolve-woo' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * Render one step within a sequence.
	 *
	 * @param string $si   Sequence index.
	 * @param string $sti  Step index.
	 * @param array  $step Step.
	 */
	private function render_step( $si, $sti, $step ) {
		$subjects = isset( $step['subjects'] ) && is_array( $step['subjects'] ) ? $step['subjects'] : array( (string) ( $step['subject'] ?? '' ) );
		$base     = 'seq[' . esc_attr( $si ) . '][steps][' . esc_attr( $sti ) . ']';
		echo '<div class="crw-step">';
		echo '<p><label>' . esc_html__( 'Send after (minutes)', 'consent-resolve-woo' ) . ' <input type="number" min="1" name="' . $base . '[delay_minutes]" value="' . esc_attr( (int) ( $step['delay_minutes'] ?? 60 ) ) . '" class="small-text"></label> ';
		echo '<label style="margin-left:14px"><input type="checkbox" name="' . $base . '[coupon]" value="1" ' . checked( ! empty( $step['coupon'] ), true, false ) . '> ' . esc_html__( 'Coupon', 'consent-resolve-woo' ) . '</label> ';
		echo '<button type="button" class="button-link crw-del-step" style="color:#a3282a;margin-left:10px">' . esc_html__( 'Remove', 'consent-resolve-woo' ) . '</button></p>';
		echo '<p><label>' . esc_html__( 'Subject lines (one per line = A/B test)', 'consent-resolve-woo' ) . '<br><textarea name="' . $base . '[subjects]" rows="2" class="large-text">' . esc_textarea( implode( "\n", array_map( 'strval', $subjects ) ) ) . '</textarea></label></p>';
		echo '<p><textarea name="' . $base . '[body]" rows="5" class="large-text" placeholder="' . esc_attr__( 'Message body', 'consent-resolve-woo' ) . '">' . esc_textarea( (string) ( $step['body'] ?? '' ) ) . '</textarea></p></div>';
	}

	/**
	 * Inline JS to add/remove sequences + steps (clone from PHP-rendered templates).
	 *
	 * @param int $count Existing sequence count (starting index for new ones).
	 */
	private function sequences_js( $count ) {
		ob_start();
		$this->render_sequence( '__SI__', array( 'id' => '', 'name' => '', 'enabled' => true, 'segment' => array(), 'steps' => array( array( 'delay_minutes' => 60, 'subjects' => array( '' ), 'body' => '', 'coupon' => false ) ) ) );
		$seq_tpl = str_replace( '__STI__', '0', ob_get_clean() );
		ob_start();
		$this->render_step( '__SI__', '__STI__', array( 'delay_minutes' => 60, 'subjects' => array( '' ), 'body' => '', 'coupon' => false ) );
		$step_tpl = ob_get_clean();
		?>
		<script>
		(function(){
			var seqTpl = <?php echo wp_json_encode( $seq_tpl ); ?>, stepTpl = <?php echo wp_json_encode( $step_tpl ); ?>;
			var si = <?php echo (int) $count; ?>, stiCounter = 1000;
			var add = document.getElementById('crw-add-seq');
			if ( add ) {
				add.addEventListener('click', function(){
					var d = document.createElement('div');
					d.innerHTML = seqTpl.replace(/__SI__/g, 's' + (si++));
					document.getElementById('crw-sequences').appendChild(d.firstElementChild);
				});
			}
			document.addEventListener('click', function(e){
				var t = e.target;
				if ( t.classList.contains('crw-add-step') ) {
					var v = t.getAttribute('data-si');
					var box = document.querySelector('.crw-steps[data-si="' + v + '"]');
					if ( box ) {
						var d = document.createElement('div');
						d.innerHTML = stepTpl.replace(/__SI__/g, v).replace(/__STI__/g, 'n' + (stiCounter++));
						box.appendChild(d.firstElementChild);
					}
				} else if ( t.classList.contains('crw-del-step') ) {
					var st = t.closest('.crw-step'); if ( st ) st.remove();
				} else if ( t.classList.contains('crw-del-seq') ) {
					var sq = t.closest('.crw-seq'); if ( sq ) sq.remove();
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * Format cents as a currency string for the admin.
	 *
	 * @param int    $cents    Cents.
	 * @param string $currency Optional ISO code.
	 */
	private function money( $cents, $currency = '' ) {
		$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency ? $currency : null ) : '$';
		return html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' ) . number_format_i18n( max( 0, (int) $cents ) / 100, 2 );
	}
}

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
		add_menu_page( __( 'CartConsent', 'cartconsent' ), __( 'CartConsent', 'cartconsent' ), self::CAP, self::SLUG, array( $this, 'page_dashboard' ), 'dashicons-shield', 56 );
		add_submenu_page( self::SLUG, __( 'Dashboard', 'cartconsent' ), __( 'Dashboard', 'cartconsent' ), self::CAP, self::SLUG, array( $this, 'page_dashboard' ) );
		add_submenu_page( self::SLUG, __( 'Setup Wizard', 'cartconsent' ), __( 'Setup Wizard', 'cartconsent' ), self::CAP, 'crw-wizard', array( 'CRW_Wizard', 'render' ) );
		add_submenu_page( self::SLUG, __( 'Banner & Settings', 'cartconsent' ), __( 'Banner & Settings', 'cartconsent' ), self::CAP, 'crw-banner', array( 'CRW_Cmp_Admin', 'render_banner' ) );
		// Consent Records + Privacy Requests live in the hosted Consent Resolve
		// dashboard now (linked from the Dashboard tiles) — no local pages.
		add_submenu_page( self::SLUG, __( 'Cart Recovery', 'cartconsent' ), __( 'Cart Recovery', 'cartconsent' ), self::CAP, 'crw-carts', array( $this, 'page_carts' ) );
		add_submenu_page( self::SLUG, __( 'Analytics', 'cartconsent' ), __( 'Analytics', 'cartconsent' ), self::CAP, 'crw-analytics', array( $this, 'page_analytics' ) );
		add_submenu_page( self::SLUG, __( 'Cart Recovery Settings', 'cartconsent' ), __( 'Cart Recovery Settings', 'cartconsent' ), self::CAP, 'crw-settings', array( $this, 'page_settings' ) );
		add_submenu_page( self::SLUG, __( 'Connection', 'cartconsent' ), __( 'Connection', 'cartconsent' ), self::CAP, 'crw-connection', array( 'CRW_Cmp_Admin', 'render_connection' ) );
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
		$active    = CRW_Hosted::active();

		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'CartConsent', 'cartconsent' ) . '</h1>';
		$this->flash();

		// Free tier is fully functional; connecting is the upgrade path.
		if ( ! $active ) {
			echo '<div class="crw-card crw-connect-cta"><h2 style="margin-top:0">' . esc_html__( 'You are on the free banner — everything works, free forever', 'cartconsent' ) . '</h2>';
			echo '<p>' . esc_html__( 'The built-in cookie banner and cart recovery are live. Connect a Consent Resolve API key to upgrade to the hosted banner (managed rules, records, and privacy requests in your dashboard) and to add visitor resolution — naming the shoppers who abandon anonymously.', 'cartconsent' ) . '</p>';
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=crw-connection' ) ) . '">' . esc_html__( 'Connect Consent Resolve', 'cartconsent' ) . '</a> <a class="button" href="' . esc_url( CRW_Hosted::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Learn about visitor resolution', 'cartconsent' ) . ' &#8599;</a></p></div>';
		}

		// 1) Stats — the free view shows what the plugin captures on its own
		// (consented shoppers + anonymous COUNTS); the connected view shows the
		// full picture.
		if ( $active ) {
			echo '<div class="crw-cards">';
			$this->card( number_format_i18n( $captured ), __( 'Carts captured', 'cartconsent' ) );
			$this->card( number_format_i18n( $recovered ), __( 'Recovered', 'cartconsent' ) );
			$this->card( $rate . '%', __( 'Recovery rate', 'cartconsent' ), $rate >= 10 ? 'good' : '' );
			$this->card( $this->money( (int) ( $f['recovered_cents'] ?? 0 ) ), __( 'Revenue recovered', 'cartconsent' ), 'good' );
			echo '</div>';

			echo '<div class="crw-cards">';
			$this->card( $this->money( (int) ( $f['open_cents'] ?? 0 ) ), __( 'Open cart value', 'cartconsent' ) );
			$this->card( number_format_i18n( (int) ( $f['open'] ?? 0 ) ), __( 'Open carts', 'cartconsent' ) );
			$this->card( number_format_i18n( CRW_Events::count( 'email_sent', 30 ) ), __( 'Emails sent (30d)', 'cartconsent' ) );
			$this->card( number_format_i18n( CRW_Events::count( 'recovery_clicked', 30 ) ), __( 'Recovery clicks (30d)', 'cartconsent' ) );
			echo '</div>';
		} else {
			$anon    = class_exists( 'CRW_Estimates' ) ? CRW_Estimates::anon_30d() : array( 'n' => 0, 'cents' => 0 );
			$consent = $this->consent_stats_30d();
			$optin   = $this->optin_rate();
			$store   = $this->store_30d();
			$avg     = $anon['n'] > 0 ? (int) round( $anon['cents'] / $anon['n'] ) : 0;

			// No recovery-performance cards here: capturing and recovering carts is
			// a connected superpower, so those numbers don't exist on the free plan.
			echo '<div class="crw-cards">';
			$this->card( number_format_i18n( $anon['n'] ), __( 'Abandoned carts observed (30d)', 'cartconsent' ) );
			$this->card( $this->money( (int) $anon['cents'] ), __( 'Abandoned cart value (30d)', 'cartconsent' ) );
			$this->card( $this->money( $avg ), __( 'Avg abandoned cart', 'cartconsent' ) );
			$this->card( number_format_i18n( $store['orders'] ), __( 'Store orders (30d)', 'cartconsent' ) );
			echo '</div>';

			echo '<div class="crw-cards">';
			$this->card( number_format_i18n( $consent['total'] ), __( 'Consent choices (30d)', 'cartconsent' ) );
			$this->card( $consent['accept_rate'] . '%', __( 'Banner accept rate (30d)', 'cartconsent' ), $consent['accept_rate'] >= 50 ? 'good' : '' );
			$this->card( $optin . '%', __( 'Checkout opt-in rate', 'cartconsent' ) );
			$this->card( number_format_i18n( class_exists( 'CRW_Consent' ) ? CRW_Consent::count() : 0 ), __( 'Consent records (all time)', 'cartconsent' ) );
			echo '</div>';

			echo '<p class="description" style="margin:2px 0 0">' . esc_html__( 'This is everything the free plugin observes on its own: abandoned carts counted (not captured), and every consent choice recorded. Connect Consent Resolve to unlock cart recovery — capturing consented shoppers, winning carts back, and naming anonymous visitors.', 'cartconsent' ) . '</p>';
		}


		// Estimated lost revenue — measured anonymous carts × assumed resolution
		// rate × measured recovery rate. Clearly an estimate, never a stat.
		$est = class_exists( 'CRW_Estimates' ) ? CRW_Estimates::estimate() : null;
		if ( $est && $est['recoverable_cents'] > 0 ) {
			echo '<div class="crw-card crw-est"><span class="crw-est-badge">' . esc_html__( 'Estimate', 'cartconsent' ) . '</span>';
			echo '<div class="crw-est-value">' . esc_html( $this->money( $est['recoverable_cents'] ) ) . '</div>';
			echo '<div class="crw-est-label">' . esc_html__( 'Estimated lost revenue (30d) — what visitor resolution could have won back', 'cartconsent' ) . '</div>';
			echo '<div class="crw-est-math">' . esc_html( sprintf(
				/* translators: 1: count 2: money 3: resolution rate 4: recovery rate */
				__( 'Based on %1$s anonymous carts worth %2$s × %3$d%% assumed resolution rate × %4$d%% recovery rate.', 'cartconsent' ),
				number_format_i18n( $est['anon_count'] ),
				$this->money( $est['anon_cents'] ),
				(int) round( $est['res_rate'] * 100 ),
				(int) round( $est['rec_rate'] * 100 )
			) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=crw-analytics#lost' ) ) . '">' . esc_html__( 'See the math', 'cartconsent' ) . '</a></div>';
			echo '</div>';
		}

		// 2) Status — below the stats.
		$this->status_strip( $active );

		// 3) Icon navigation with next steps.
		$this->nav_grid( $active );

		echo '</div>';
	}

	/**
	 * Status strip: connection, banner, credits, then operational checks.
	 *
	 * @param bool $active Module active.
	 */
	private function status_strip( $active ) {
		$credits = $active ? CRW_Connection::credits() : null;
		if ( null === $credits ) {
			$credit_item = array( null, $active ? __( 'Credits: n/a', 'cartconsent' ) : __( 'Credits: connect to add resolution', 'cartconsent' ) );
		} else {
			$credit_item = array( $credits > 0, sprintf( /* translators: %s number */ __( 'Credits available: %s', 'cartconsent' ), number_format_i18n( $credits ) ) );
		}
		$items = array(
			array( (bool) CRW_Options::get( 'banner.enabled', true ), $active ? __( 'Cookie banner active (Consent Resolve hosted)', 'cartconsent' ) : __( 'Cookie banner active (built-in, free)', 'cartconsent' ) ),
			array( $active ? true : null, $active ? __( 'Connected to Consent Resolve', 'cartconsent' ) : __( 'Not connected — free plan', 'cartconsent' ) ),
			$credit_item,
			array( (bool) CRW_Options::get( 'capture.enabled', true ), __( 'Capture active', 'cartconsent' ) ),
			array( (bool) wp_next_scheduled( CRW_Install::CRON_HOOK ), __( 'Recovery queue scheduled', 'cartconsent' ) ),
			array( '' !== trim( (string) CRW_Options::get( 'emails.from_email', '' ) ) || (bool) get_option( 'admin_email' ), __( 'Sender address set', 'cartconsent' ) ),
			array( '' !== trim( (string) get_option( 'woocommerce_store_address', '' ) ), __( 'Store address (for CAN-SPAM)', 'cartconsent' ) ),
			array( CRW_Crypto::available(), __( 'Email encryption available', 'cartconsent' ) ),
		);
		echo '<h2 class="crw-section-title">' . esc_html__( 'Status', 'cartconsent' ) . '</h2><div class="crw-health">';
		foreach ( $items as $it ) {
			$color = null === $it[0] ? '#8a8a8a' : ( $it[0] ? '#1d7f43' : '#a3282a' );
			$mark  = null === $it[0] ? '&#9675;' : ( $it[0] ? '&#10003;' : '&#9679;' );
			printf( '<div class="crw-health-item"><span style="color:%s">%s</span> %s</div>', $color, $mark, esc_html( $it[1] ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}

	/**
	 * Icon-based navigation to the rest of the plugin, each tile carrying its
	 * next step. Consent Records + Privacy Requests link out to the hosted
	 * Consent Resolve dashboard.
	 *
	 * @param bool $active Module active.
	 */
	private function nav_grid( $active ) {
		$open       = (int) ( CRW_Carts_Store::funnel()['open'] ?? 0 );
		$rec30      = number_format_i18n( CRW_Events::count( 'recovery_clicked', 30 ) );
		$sequences  = count( array_filter( CRW_Options::sequences(), static function ( $s ) { return ! empty( $s['enabled'] ) && ! empty( $s['steps'] ); } ) );
		$setup_done = (bool) get_option( 'crw_setup_complete' );
		$hosted     = CRW_Hosted::dashboard_url();

		$tiles = array(
			array( 'dashicons-cart', __( 'Cart Recovery', 'cartconsent' ), admin_url( 'admin.php?page=crw-carts' ), false,
				$open > 0 ? sprintf( /* translators: %s count */ _n( '%s cart in progress', '%s carts in progress', $open, 'cartconsent' ), number_format_i18n( $open ) ) : __( 'No open carts right now', 'cartconsent' ), $open > 0 ? 'go' : '' ),
			array( 'dashicons-chart-bar', __( 'Recovery Analytics', 'cartconsent' ), admin_url( 'admin.php?page=crw-analytics' ), false,
				sprintf( /* translators: %s count */ __( '%s recovery clicks in 30 days', 'cartconsent' ), $rec30 ), '' ),
			array( 'dashicons-admin-settings', __( 'Recovery Settings', 'cartconsent' ), admin_url( 'admin.php?page=crw-settings' ), false,
				$sequences > 0 ? sprintf( /* translators: %s count */ _n( '%s sequence live', '%s sequences live', $sequences, 'cartconsent' ), number_format_i18n( $sequences ) ) : __( 'Set up your first sequence', 'cartconsent' ), $sequences > 0 ? '' : 'todo' ),
			array( 'dashicons-admin-network', __( 'Connection', 'cartconsent' ), admin_url( 'admin.php?page=crw-connection' ), false,
				$active ? __( 'Connected', 'cartconsent' ) : __( 'Optional — hosted banner + resolution', 'cartconsent' ), $active ? 'go' : '' ),
			array( 'dashicons-welcome-learn-more', __( 'Setup Wizard', 'cartconsent' ), admin_url( 'admin.php?page=crw-wizard' ), false,
				$setup_done ? __( 'Completed', 'cartconsent' ) : __( 'Run the 3-step setup', 'cartconsent' ), $setup_done ? 'go' : 'todo' ),
			array( 'dashicons-shield-alt', __( 'Consent Records', 'cartconsent' ), $hosted, true,
				__( 'View in Consent Resolve', 'cartconsent' ), '' ),
			array( 'dashicons-lock', __( 'Privacy Requests', 'cartconsent' ), $hosted, true,
				__( 'View in Consent Resolve', 'cartconsent' ), '' ),
			array( 'dashicons-dashboard', __( 'Consent Resolve', 'cartconsent' ), $hosted, true,
				__( 'Open your dashboard', 'cartconsent' ), '' ),
		);

		echo '<h2 class="crw-section-title">' . esc_html__( 'Everything else', 'cartconsent' ) . '</h2><div class="crw-nav-grid">';
		foreach ( $tiles as $t ) {
			list( $icon, $label, $url, $ext, $step, $tone ) = $t;
			printf(
				'<a class="crw-nav-tile" href="%s"%s><span class="dashicons %s" aria-hidden="true"></span><span class="crw-nav-label">%s%s</span><span class="crw-nav-step %s">%s</span></a>',
				esc_url( $url ),
				$ext ? ' target="_blank" rel="noopener"' : '',
				esc_attr( $icon ),
				esc_html( $label ),
				$ext ? ' <span aria-hidden="true">&#8599;</span>' : '',
				esc_attr( $tone ? 'is-' . $tone : '' ),
				esc_html( $step )
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
		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Abandoned Carts', 'cartconsent' ) . '</h1>';

		echo '<ul class="subsubsub"><li><a href="' . esc_url( admin_url( 'admin.php?page=crw-carts' ) ) . '"' . ( '' === $status ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'cartconsent' ) . '</a> | </li>';
		foreach ( array( 'abandoned' => __( 'In progress', 'cartconsent' ), 'recovered' => __( 'Recovered', 'cartconsent' ), 'lost' => __( 'Lost', 'cartconsent' ) ) as $s => $label ) {
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=crw-carts&status=' . $s ) ) . '"' . ( $status === $s ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a> | </li>';
		}
		echo '</ul>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Shopper', 'cartconsent' ) . '</th><th>' . esc_html__( 'Items', 'cartconsent' ) . '</th><th>' . esc_html__( 'Value', 'cartconsent' ) . '</th><th>' . esc_html__( 'Consent', 'cartconsent' ) . '</th><th>' . esc_html__( 'Emails', 'cartconsent' ) . '</th><th>' . esc_html__( 'Status', 'cartconsent' ) . '</th><th>' . esc_html__( 'Updated', 'cartconsent' ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No abandoned carts yet.', 'cartconsent' ) . '</td></tr>';
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
			'abandoned'    => __( 'In sequence', 'cartconsent' ),
			'recovered'    => __( 'Recovered', 'cartconsent' ),
			'lost'         => __( 'Lost', 'cartconsent' ),
			'unsubscribed' => __( 'Unsubscribed', 'cartconsent' ),
			'active'       => __( 'Active', 'cartconsent' ),
		);
		return $map[ $status ] ?? $status;
	}

	/* ------------------------------------------------------------- analytics */

	/**
	 * Recovery analytics: channels, per-sequence performance, and A/B splits.
	 */
	public function page_analytics() {
		global $wpdb;
		$carts_t  = $wpdb->prefix . 'crw_carts';
		$events_t = $wpdb->prefix . 'crw_events';
		$since    = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		// --- 30-day KPIs.
		$rec30 = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(recovered_cents),0) AS cents FROM {$carts_t} WHERE status='recovered' AND updated_at >= %s", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$sent30   = CRW_Events::count( 'email_sent', 30 );
		$clicks30 = CRW_Events::count( 'recovery_clicked', 30 );
		$ctr      = $sent30 > 0 ? round( $clicks30 / $sent30 * 100 ) : 0;
		$avg      = (int) $rec30['n'] > 0 ? (int) round( (int) $rec30['cents'] / (int) $rec30['n'] ) : 0;

		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Recovery Analytics', 'cartconsent' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Last 30 days unless noted.', 'cartconsent' ) . '</p>';

		echo '<div class="crw-cards">';
		$this->card( $this->money( (int) $rec30['cents'] ), __( 'Revenue recovered (30d)', 'cartconsent' ), 'good' );
		$this->card( number_format_i18n( (int) $rec30['n'] ), __( 'Carts recovered (30d)', 'cartconsent' ) );
		$this->card( $this->money( $avg ), __( 'Avg recovered order', 'cartconsent' ) );
		$this->card( $ctr . '%', __( 'Click rate (clicks / sends)', 'cartconsent' ), $ctr >= 15 ? 'good' : '' );
		echo '</div>';

		// --- Daily recovered revenue, 30-day bar chart (inline SVG, no libs).
		$daily = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(updated_at) AS d, SUM(recovered_cents) AS cents FROM {$carts_t} WHERE status='recovered' AND updated_at >= %s GROUP BY DATE(updated_at)", $since ), OBJECT_K ); // phpcs:ignore WordPress.DB
		$max   = 0;
		$days  = array();
		for ( $i = 29; $i >= 0; $i-- ) {
			$key          = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$cents        = isset( $daily[ $key ] ) ? (int) $daily[ $key ]->cents : 0;
			$days[ $key ] = $cents;
			$max          = max( $max, $cents );
		}
		echo '<div class="crw-card"><h2 class="crw-card-title">' . esc_html__( 'Recovered revenue by day', 'cartconsent' ) . '</h2>';
		if ( 0 === $max ) {
			echo '<p class="description">' . esc_html__( 'No recovered revenue in the last 30 days yet — this chart fills in as sequences win carts back.', 'cartconsent' ) . '</p>';
		} else {
			$w = 30 * 24;
			echo '<svg class="crw-chart" viewBox="0 0 ' . (int) $w . ' 140" role="img" aria-label="' . esc_attr__( 'Daily recovered revenue, last 30 days', 'cartconsent' ) . '" preserveAspectRatio="none">';
			$x = 0;
			foreach ( $days as $key => $cents ) {
				$h = $max > 0 ? max( 2, (int) round( $cents / $max * 120 ) ) : 2;
				$y = 130 - $h;
				printf(
					'<rect x="%d" y="%d" width="18" height="%d" rx="2" fill="%s"><title>%s — %s</title></rect>',
					(int) $x + 3, (int) $y, (int) $h,
					$cents > 0 ? '#7f54b3' : '#e4e0ea',
					esc_html( mysql2date( get_option( 'date_format' ), $key . ' 00:00:00' ) ),
					esc_html( $this->money( $cents ) )
				);
				$x += 24;
			}
			echo '<line x1="0" y1="130" x2="' . (int) $w . '" y2="130" stroke="#dcdcde" stroke-width="1"/>';
			echo '</svg>';
			echo '<p class="description">' . esc_html( sprintf( /* translators: %s money */ __( 'Best day: %s.', 'cartconsent' ), $this->money( max( $days ) ) ) ) . '</p>';
		}
		echo '</div>';

		// --- Funnel: captured → emailed → clicked → recovered (lifetime).
		$fun = $wpdb->get_row( "SELECT
			SUM(status IN ('abandoned','lost','recovered','unsubscribed','active')) AS captured,
			SUM(CASE WHEN emails_sent > 0 AND status IN ('abandoned','lost','recovered','unsubscribed') THEN 1 ELSE 0 END) AS emailed,
			SUM(status='recovered') AS recovered
			FROM {$carts_t}", ARRAY_A ); // phpcs:ignore WordPress.DB
		$clicked = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT cart_id) FROM {$events_t} WHERE type='recovery_clicked'" ); // phpcs:ignore WordPress.DB
		$stages  = array(
			array( __( 'Carts captured', 'cartconsent' ), (int) ( $fun['captured'] ?? 0 ) ),
			array( __( 'Entered a sequence (emailed)', 'cartconsent' ), (int) ( $fun['emailed'] ?? 0 ) ),
			array( __( 'Clicked a recovery link', 'cartconsent' ), $clicked ),
			array( __( 'Recovered', 'cartconsent' ), (int) ( $fun['recovered'] ?? 0 ) ),
		);
		$top = max( 1, $stages[0][1] );
		echo '<div class="crw-card"><h2 class="crw-card-title">' . esc_html__( 'Where shoppers drop off', 'cartconsent' ) . '</h2><div class="crw-funnel">';
		foreach ( $stages as $i => $s ) {
			$pct  = (int) round( $s[1] / $top * 100 );
			$conv = $i > 0 && $stages[ $i - 1 ][1] > 0 ? (int) round( $s[1] / $stages[ $i - 1 ][1] * 100 ) : 100;
			printf(
				'<div class="crw-funnel-row"><span class="crw-funnel-label">%s</span><span class="crw-funnel-bar"><span style="width:%d%%"></span></span><span class="crw-funnel-n">%s</span><span class="crw-funnel-pct">%s</span></div>',
				esc_html( $s[0] ), max( 2, $pct ), esc_html( number_format_i18n( $s[1] ) ),
				$i > 0 ? esc_html( $conv . '%' ) : '&nbsp;'
			);
		}
		echo '</div><p class="description">' . esc_html__( 'The percentage on each row is conversion from the previous stage. A weak "clicked" row usually means subject lines; a weak "recovered" row usually means the offer or checkout friction.', 'cartconsent' ) . '</p></div>';

		// --- Estimated lost revenue (labeled, with its math shown).
		$est = class_exists( 'CRW_Estimates' ) ? CRW_Estimates::estimate() : null;
		echo '<div class="crw-card crw-est" id="lost" style="scroll-margin-top:60px"><span class="crw-est-badge">' . esc_html__( 'Estimate', 'cartconsent' ) . '</span>';
		echo '<h2 class="crw-card-title">' . esc_html__( 'What visitor resolution would have caught', 'cartconsent' ) . '</h2>';
		if ( ! $est ) {
			echo '<p class="description">' . esc_html__( 'Watching your store for anonymous guest carts — figures appear here within a day of normal traffic.', 'cartconsent' ) . '</p>';
		} else {
			echo '<div class="crw-cards" style="margin-top:4px">';
			$this->card( number_format_i18n( $est['anon_count'] ), __( 'Anonymous carts observed (30d)', 'cartconsent' ) );
			$this->card( $this->money( $est['anon_cents'] ), __( 'Their combined value', 'cartconsent' ) );
			$this->card( number_format_i18n( $est['resolvable'] ), sprintf( /* translators: %d rate */ __( 'Est. resolvable at %d%%', 'cartconsent' ), (int) round( $est['res_rate'] * 100 ) ) );
			$this->card( $this->money( $est['recoverable_cents'] ), __( 'Est. recoverable revenue', 'cartconsent' ), 'good' );
			echo '</div>';
			echo '<p class="description">' . esc_html( sprintf(
				/* translators: 1: money 2: resolution rate 3: recovery rate */
				__( 'Math: %1$s in anonymous carts × %2$d%% assumed resolution rate (editable in Settings → Retargeting & Data) × %3$d%% recovery rate', 'cartconsent' ),
				$this->money( $est['anon_cents'] ),
				(int) round( $est['res_rate'] * 100 ),
				(int) round( $est['rec_rate'] * 100 )
			) ) . ( $est['rec_measured'] ? ' ' . esc_html__( '(your store\'s measured rate).', 'cartconsent' ) : ' ' . esc_html__( '(a conservative default until your store has measured recoveries).', 'cartconsent' ) ) . ' ' . esc_html__( 'Anonymous carts are counted from guest sessions on your own store — no personal data is read or stored.', 'cartconsent' ) . '</p>';
			if ( ! CRW_Hosted::active() ) {
				echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=crw-connection' ) ) . '">' . esc_html__( 'Connect Consent Resolve to start resolving', 'cartconsent' ) . '</a></p>';
			} elseif ( ! CRW_Connection::credits() ) {
				echo '<p><a class="button button-primary" href="' . esc_url( CRW_Hosted::dashboard_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Add a resolution plan', 'cartconsent' ) . ' &#8599;</a></p>';
			}
		}
		echo '</div>';

		// --- Channels.
		echo '<h2 class="crw-section-title">' . esc_html__( 'Channels (30d)', 'cartconsent' ) . '</h2><div class="crw-cards">';
		$this->card( number_format_i18n( $sent30 ), __( 'Recovery emails', 'cartconsent' ) );
		$this->card( number_format_i18n( CRW_Events::count( 'push_sent', 30 ) ), __( 'Web pushes', 'cartconsent' ) );
		$this->card( number_format_i18n( CRW_Events::count( 'popup_capture', 30 ) ), __( 'Popup captures', 'cartconsent' ) );
		$this->card( number_format_i18n( $clicks30 ), __( 'Recovery clicks', 'cartconsent' ) );
		echo '</div>';

		// Per-sequence.
		echo '<h2>' . esc_html__( 'By sequence', 'cartconsent' ) . '</h2>';
		$stats = CRW_Carts_Store::sequence_stats();
		$names = array();
		foreach ( CRW_Options::sequences() as $seq ) {
			$names[ (string) $seq['id'] ] = (string) ( $seq['name'] ?? $seq['id'] );
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Sequence', 'cartconsent' ) . '</th><th>' . esc_html__( 'Carts', 'cartconsent' ) . '</th><th>' . esc_html__( 'Recovered', 'cartconsent' ) . '</th><th>' . esc_html__( 'Rate', 'cartconsent' ) . '</th><th>' . esc_html__( 'Revenue', 'cartconsent' ) . '</th></tr></thead><tbody>';
		if ( empty( $stats ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No data yet.', 'cartconsent' ) . '</td></tr>';
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
				$ab_out .= '<tr><td colspan="3"><strong>' . esc_html( ( $seq['name'] ?? $seq['id'] ) . ' — ' . sprintf( /* translators: %d step */ __( 'step %d', 'cartconsent' ), $sti + 1 ) ) . '</strong></td></tr>';
				foreach ( $subs as $vi => $subject ) {
					$sends  = CRW_Events::count_label( 'email_sent', (string) $seq['id'] . ':' . $sti . ':' . $vi );
					$ab_out .= '<tr><td style="padding-left:24px">' . esc_html( 'A' === chr( 65 + $vi ) || $vi < 26 ? chr( 65 + $vi ) : ( $vi + 1 ) ) . '</td><td>' . esc_html( wp_trim_words( $subject, 12 ) ) . '</td><td>' . esc_html( number_format_i18n( $sends ) ) . ' ' . esc_html__( 'sent', 'cartconsent' ) . '</td></tr>';
				}
			}
		}
		if ( $has_ab ) {
			echo '<h2>' . esc_html__( 'A/B subject tests', 'cartconsent' ) . '</h2>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Variant', 'cartconsent' ) . '</th><th>' . esc_html__( 'Subject', 'cartconsent' ) . '</th><th>' . esc_html__( 'Sends', 'cartconsent' ) . '</th></tr></thead><tbody>' . $ab_out . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput
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

		echo '<div class="wrap crw-wrap"><h1>' . esc_html__( 'Cart Recovery — Settings', 'cartconsent' ) . '</h1>';
		$this->flash();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'crw_save_settings' );
		echo '<input type="hidden" name="action" value="crw_save_settings">';

		// Tab navigation — same form, sections shown one group at a time.
		$tabs = array(
			'capture'  => __( 'Capture & Consent', 'cartconsent' ),
			'emails'   => __( 'Emails & Sequences', 'cartconsent' ),
			'channels' => __( 'Coupon, Popup & Push', 'cartconsent' ),
			'data'     => __( 'Retargeting & Data', 'cartconsent' ),
		);
		echo '<nav class="crw-tabs" role="tablist">';
		foreach ( $tabs as $key => $label ) {
			printf( '<button type="button" class="crw-tab-btn" role="tab" data-tab="%s">%s</button>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</nav>';

		echo '<div class="crw-tab-panel" data-panel="capture">';
		// Capture.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Capture', 'cartconsent' ) . '</h2>';
		$this->toggle( 'capture[enabled]', __( 'Capture abandoning shoppers', 'cartconsent' ), $capture['enabled'] );
		echo '<p class="description">' . esc_html__( 'We capture only on a deliberate action (a submitted checkout or a completed email field) — never by watching keystrokes.', 'cartconsent' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Consider a cart abandoned after (minutes of inactivity)', 'cartconsent' ) . ' <input type="number" min="5" name="capture[abandon_after_minutes]" value="' . esc_attr( (int) $capture['abandon_after_minutes'] ) . '" class="small-text"></label></p>';
		echo '</div>';

		// Consent.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Consent', 'cartconsent' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Who may we email?', 'cartconsent' ) . '<br><select name="consent[basis]">';
		foreach ( array(
			'jurisdiction' => __( 'Region-aware (recommended)', 'cartconsent' ),
			'optin_only'   => __( 'Only explicit opt-ins (strictest)', 'cartconsent' ),
			'all_unsub'    => __( 'Everyone, with unsubscribe (most aggressive)', 'cartconsent' ),
		) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '" ' . selected( $consent['basis'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'When location is unknown, treat visitors as', 'cartconsent' ) . ' <select name="consent[fallback_model]">';
		foreach ( array( 'opt_out' => __( 'US-style opt-out (soft opt-in allowed)', 'cartconsent' ), 'opt_in' => __( 'EU-style opt-in (require checkbox)', 'cartconsent' ) ) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '" ' . selected( $consent['fallback_model'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select><br><span class="description">' . esc_html__( 'Each visitor\'s region is detected automatically from your CDN\'s geo headers.', 'cartconsent' ) . '</span></p>';
		$this->toggle( 'consent[checkout_checkbox]', __( 'Show a marketing-consent checkbox at checkout', 'cartconsent' ), $consent['checkout_checkbox'] );
		echo '<p><label>' . esc_html__( 'Checkbox label', 'cartconsent' ) . '<br><input type="text" name="consent[checkbox_label]" value="' . esc_attr( $consent['checkbox_label'] ) . '" class="large-text"></label></p>';
		$this->toggle( 'consent[honor_gpc]', __( 'Honor Global Privacy Control (suppress soft opt-ins + audiences)', 'cartconsent' ), $consent['honor_gpc'] );
		echo '</div>';

		echo '</div><div class="crw-tab-panel" data-panel="emails">';

		// Emails.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Emails', 'cartconsent' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'From name', 'cartconsent' ) . '<br><input type="text" name="emails[from_name]" value="' . esc_attr( $emails['from_name'] ) . '" class="regular-text" placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'From address', 'cartconsent' ) . '<br><input type="email" name="emails[from_email]" value="' . esc_attr( $emails['from_email'] ) . '" class="regular-text" placeholder="' . esc_attr( get_option( 'admin_email' ) ) . '"></label><br><span class="description">' . esc_html__( 'Use an address on a domain with SPF/DKIM set up for best deliverability.', 'cartconsent' ) . '</span></p>';
		echo '<p><label>' . esc_html__( 'Reply-to (optional)', 'cartconsent' ) . '<br><input type="email" name="emails[reply_to]" value="' . esc_attr( $emails['reply_to'] ) . '" class="regular-text"></label></p>';
		echo '</div>';

		// Sequences (multi-sequence + segmentation + A/B).
		echo '<div class="crw-card"><h2>' . esc_html__( 'Recovery sequences', 'cartconsent' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each cart enters the first matching sequence (by segment). Merge tags: {first_name} {store_name} {cart_items} {cart_total} {recovery_url} {coupon} {coupon_code}. Put multiple subject lines (one per line) to A/B-test them.', 'cartconsent' ) . '</p>';
		echo '<div id="crw-sequences">';
		$seqs = CRW_Options::sequences();
		foreach ( $seqs as $si => $seq ) {
			$this->render_sequence( $si, $seq );
		}
		echo '</div>';
		echo '<p><button type="button" class="button" id="crw-add-seq">+ ' . esc_html__( 'Add sequence', 'cartconsent' ) . '</button></p>';
		$this->sequences_js( count( $seqs ) );
		echo '</div>';

		echo '</div><div class="crw-tab-panel" data-panel="channels">';

		// Coupon.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Recovery coupon', 'cartconsent' ) . '</h2>';
		$this->toggle( 'coupon[enabled]', __( 'Offer a single-use coupon (locked to the shopper)', 'cartconsent' ), $coupon['enabled'] );
		echo '<p><label>' . esc_html__( 'Type', 'cartconsent' ) . ' <select name="coupon[type]"><option value="percent" ' . selected( $coupon['type'], 'percent', false ) . '>' . esc_html__( 'Percent', 'cartconsent' ) . '</option><option value="fixed_cart" ' . selected( $coupon['type'], 'fixed_cart', false ) . '>' . esc_html__( 'Fixed cart', 'cartconsent' ) . '</option></select></label> ';
		echo '<label>' . esc_html__( 'Amount', 'cartconsent' ) . ' <input type="number" min="1" step="0.01" name="coupon[amount]" value="' . esc_attr( $coupon['amount'] ) . '" class="small-text"></label> ';
		echo '<label>' . esc_html__( 'Expires (days)', 'cartconsent' ) . ' <input type="number" min="1" name="coupon[expiry_days]" value="' . esc_attr( (int) $coupon['expiry_days'] ) . '" class="small-text"></label></p>';
		echo '</div>';

		// Channels — web push.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Web push channel', 'cartconsent' ) . '</h2>';
		$this->toggle( 'push[enabled]', __( 'Also recover carts by browser push notification', 'cartconsent' ), CRW_Options::get( 'push.enabled', false ) );
		if ( class_exists( 'CRW_Push_Crypto' ) && ! CRW_Push_Crypto::available() ) {
			echo '<p class="description" style="color:#a3282a">' . esc_html__( 'Your server is missing the OpenSSL EC support web push needs — contact your host.', 'cartconsent' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Shoppers are asked (with a dismissible prompt at checkout) to allow notifications. Those who opt in get a push alongside each recovery email. Self-hosted — no third-party account or cost.', 'cartconsent' ) . '</p>';
		}
		echo '</div>';

		// Cart-saver popup.
		$pop = CRW_Options::get( 'popup' );
		echo '<div class="crw-card"><h2>' . esc_html__( 'Cart-saver popup', 'cartconsent' ) . '</h2>';
		$this->toggle( 'popup[enabled]', __( 'Show an exit-intent popup that saves the cart by email', 'cartconsent' ), $pop['enabled'] );
		echo '<p class="description">' . esc_html__( 'Spam-safe: the shopper ticks a consent box, and we only start reminders after they confirm via a double-opt-in email.', 'cartconsent' ) . '</p>';
		echo '<p><label>' . esc_html__( 'Trigger', 'cartconsent' ) . ' <select name="popup[trigger]"><option value="exit" ' . selected( $pop['trigger'], 'exit', false ) . '>' . esc_html__( 'Exit intent', 'cartconsent' ) . '</option><option value="timer" ' . selected( $pop['trigger'], 'timer', false ) . '>' . esc_html__( 'After a delay', 'cartconsent' ) . '</option></select></label> ';
		echo '<label>' . esc_html__( 'Delay (seconds)', 'cartconsent' ) . ' <input type="number" min="3" name="popup[delay_seconds]" value="' . esc_attr( (int) $pop['delay_seconds'] ) . '" class="small-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Title', 'cartconsent' ) . '<br><input type="text" name="popup[title]" value="' . esc_attr( $pop['title'] ) . '" class="regular-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Message', 'cartconsent' ) . '<br><textarea name="popup[message]" rows="2" class="large-text">' . esc_textarea( $pop['message'] ) . '</textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Consent label', 'cartconsent' ) . '<br><input type="text" name="popup[consent_label]" value="' . esc_attr( $pop['consent_label'] ) . '" class="large-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Button', 'cartconsent' ) . '<br><input type="text" name="popup[button]" value="' . esc_attr( $pop['button'] ) . '" class="regular-text"></label></p>';
		echo '</div>';

		echo '</div><div class="crw-tab-panel" data-panel="data">';

		// Retargeting.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Retargeting', 'cartconsent' ) . '</h2>';
		$this->toggle( 'tracking[consent_mode]', __( 'Set Google Consent Mode v2 defaults', 'cartconsent' ), $tracking['consent_mode'] );
		echo '<p><label>' . esc_html__( 'Meta Pixel ID', 'cartconsent' ) . '<br><input type="text" name="tracking[meta_pixel_id]" value="' . esc_attr( $tracking['meta_pixel_id'] ) . '" class="regular-text"></label></p>';
		echo '<p><label>' . esc_html__( 'GA4 Measurement ID', 'cartconsent' ) . '<br><input type="text" name="tracking[ga4_id]" value="' . esc_attr( $tracking['ga4_id'] ) . '" class="regular-text"></label></p>';
		echo '<p class="description">' . esc_html__( 'Pixels load only after marketing consent is granted. Add-to-cart, checkout, and purchase events fire behind the same gate.', 'cartconsent' ) . '</p>';
		$this->toggle( 'audiences[enabled]', __( 'Build a consent-filtered retargeting audience of abandoners (requires Consent Resolve)', 'cartconsent' ), $audiences['enabled'] );
		echo '<p class="description">' . esc_html__( 'Only explicit opt-ins with no Do-Not-Sell/Share signal are added; unsubscribes and erasures are removed automatically.', 'cartconsent' ) . '</p>';
		echo '</div>';

		// Retention.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Data retention', 'cartconsent' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Delete abandoned carts after (days)', 'cartconsent' ) . ' <input type="number" min="1" name="retention_days" value="' . esc_attr( (int) CRW_Options::get( 'retention_days', 60 ) ) . '" class="small-text"></label><br><span class="description">' . esc_html__( 'Captures with no lawful basis to email are dropped within 24 hours automatically (data minimization).', 'cartconsent' ) . '</span></p>';
		echo '</div>';

		// Lost-revenue estimate assumption.
		echo '<div class="crw-card"><h2>' . esc_html__( 'Lost-revenue estimate', 'cartconsent' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Assumed visitor-resolution rate (%)', 'cartconsent' ) . ' <input type="number" min="1" max="100" name="estimates[resolution_rate]" value="' . esc_attr( (int) CRW_Options::get( 'estimates.resolution_rate', 20 ) ) . '" class="small-text"></label><br><span class="description">' . esc_html__( 'Used only for the clearly-labeled "estimated lost revenue" figures on the Dashboard and Analytics screens: anonymous carts observed × this rate × your measured recovery rate. It never mixes into measured revenue.', 'cartconsent' ) . '</span></p>';
		echo '</div>';

		echo '</div>'; // last tab panel

		// Sticky save bar — visible from every tab.
		echo '<div class="crw-savebar"><button class="button button-primary button-hero">' . esc_html__( 'Save settings', 'cartconsent' ) . '</button> <span class="description">' . esc_html__( 'Saves every tab at once.', 'cartconsent' ) . '</span></div>';
		echo '</form>';

		// Tabs: show one panel at a time; remember the choice in the URL hash.
		echo '<script>(function(){
			var btns = document.querySelectorAll(".crw-tab-btn");
			var panels = document.querySelectorAll(".crw-tab-panel");
			function show(key){
				panels.forEach(function(p){ p.style.display = p.dataset.panel === key ? "" : "none"; });
				btns.forEach(function(b){ b.classList.toggle("is-active", b.dataset.tab === key); b.setAttribute("aria-selected", b.dataset.tab === key ? "true" : "false"); });
				if (history.replaceState) { history.replaceState(null, "", "#" + key); }
			}
			btns.forEach(function(b){ b.addEventListener("click", function(){ show(b.dataset.tab); }); });
			var initial = (location.hash || "#capture").slice(1);
			var valid = Array.prototype.some.call(btns, function(b){ return b.dataset.tab === initial; });
			show(valid ? initial : "capture");
		})();</script>';
		echo '</div>';
	}

	/* ------------------------------------------------------------- save + ui */

	/**
	 * Persist settings.
	 */
	public function save() {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'crw_save_settings' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'cartconsent' ) );
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
		$s['estimates']['resolution_rate'] = max( 1, min( 100, (int) ( $in['estimates']['resolution_rate'] ?? 20 ) ) );

		CRW_Options::save( $s );
		wp_safe_redirect( add_query_arg( array( 'page' => 'crw-settings', 'crw_msg' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Banner consent activity, last 30 days (grants vs rejects + accept rate).
	 */
	private function consent_stats_30d() {
		global $wpdb;
		$t     = $wpdb->prefix . 'crw_consent_records';
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(event_type='grant') AS grants, SUM(event_type IN ('reject','withdraw')) AS rejects FROM {$t} WHERE created_at >= %s", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$grants  = (int) ( $row['grants'] ?? 0 );
		$rejects = (int) ( $row['rejects'] ?? 0 );
		$decided = $grants + $rejects;
		return array(
			'total'       => (int) ( $row['total'] ?? 0 ),
			'accept_rate' => $decided > 0 ? (int) round( $grants / $decided * 100 ) : 0,
		);
	}

	/**
	 * Share of captured carts with an explicit checkout opt-in.
	 */
	private function optin_rate() {
		global $wpdb;
		$t   = $wpdb->prefix . 'crw_carts';
		$row = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(consent_basis='optin') AS optin FROM {$t} WHERE consent_basis IN ('optin','legitimate')", ARRAY_A ); // phpcs:ignore WordPress.DB
		$total = (int) ( $row['total'] ?? 0 );
		return $total > 0 ? (int) round( (int) $row['optin'] / $total * 100 ) : 0;
	}

	/**
	 * Store pulse from WooCommerce's own analytics table (fail-soft to 0).
	 */
	private function store_30d() {
		global $wpdb;
		$t = $wpdb->prefix . 'wc_order_stats';
		if ( $t !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) { // phpcs:ignore WordPress.DB
			return array( 'orders' => 0 );
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		return array(
			'orders' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE parent_id = 0 AND date_created >= %s", $since ) ), // phpcs:ignore WordPress.DB
		);
	}

	private function flash() {
		$m = isset( $_GET['crw_msg'] ) ? sanitize_key( wp_unslash( $_GET['crw_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'saved' === $m ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'cartconsent' ) . '</p></div>';
		} elseif ( 'connected' === $m ) {
			echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Superpowers enabled!', 'cartconsent' ) . '</strong> ' . esc_html__( 'You are now seeing the connected view — hosted banner, full analytics, and visitor resolution ready.', 'cartconsent' ) . '</p></div>';
		} elseif ( 'free' === $m ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Back on the free view — everything below is what the free plugin captures on its own.', 'cartconsent' ) . '</p></div>';
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
		echo '<p><label><strong>' . esc_html__( 'Sequence name', 'cartconsent' ) . '</strong> <input type="text" name="seq[' . esc_attr( $si ) . '][name]" value="' . esc_attr( $seq['name'] ?? '' ) . '" placeholder="' . esc_attr__( 'e.g. High-value carts', 'cartconsent' ) . '"></label> ';
		echo '<label style="margin-left:14px"><input type="checkbox" name="seq[' . esc_attr( $si ) . '][enabled]" value="1" ' . checked( ! empty( $seq['enabled'] ), true, false ) . '> ' . esc_html__( 'Enabled', 'cartconsent' ) . '</label> ';
		echo '<button type="button" class="button-link crw-del-seq" style="color:#a3282a;margin-left:14px">' . esc_html__( 'Remove', 'cartconsent' ) . '</button></p>';

		echo '<details><summary style="cursor:pointer">' . esc_html__( 'Segment — who enters this sequence', 'cartconsent' ) . '</summary><div style="padding:8px 0">';
		echo '<p><label>' . esc_html__( 'Cart total between', 'cartconsent' ) . ' <input type="number" step="0.01" name="seq[' . esc_attr( $si ) . '][segment][min_total]" value="' . esc_attr( $val( $seg['min_total'] ?? '' ) ) . '" class="small-text" placeholder="' . esc_attr__( 'min', 'cartconsent' ) . '"> ' . esc_html__( 'and', 'cartconsent' ) . ' <input type="number" step="0.01" name="seq[' . esc_attr( $si ) . '][segment][max_total]" value="' . esc_attr( $val( $seg['max_total'] ?? '' ) ) . '" class="small-text" placeholder="' . esc_attr__( 'max', 'cartconsent' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Product IDs (comma-separated)', 'cartconsent' ) . '<br><input type="text" name="seq[' . esc_attr( $si ) . '][segment][products]" value="' . esc_attr( $val( $seg['products'] ?? '' ) ) . '" class="regular-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Category slugs or IDs', 'cartconsent' ) . '<br><input type="text" name="seq[' . esc_attr( $si ) . '][segment][categories]" value="' . esc_attr( $val( $seg['categories'] ?? '' ) ) . '" class="regular-text"></label></p>';
		echo '<p><label>' . esc_html__( 'Countries (2-letter, comma-separated)', 'cartconsent' ) . '<br><input type="text" name="seq[' . esc_attr( $si ) . '][segment][countries]" value="' . esc_attr( $val( $seg['countries'] ?? '' ) ) . '" class="regular-text" placeholder="US, CA"></label></p>';
		echo '<p class="description">' . esc_html__( 'Leave everything blank for a catch-all sequence. Carts enter the first matching enabled sequence.', 'cartconsent' ) . '</p></div></details>';

		echo '<div class="crw-steps" data-si="' . esc_attr( $si ) . '">';
		foreach ( array_values( (array) ( $seq['steps'] ?? array() ) ) as $sti => $step ) {
			$this->render_step( $si, $sti, $step );
		}
		echo '</div>';
		echo '<p><button type="button" class="button button-small crw-add-step" data-si="' . esc_attr( $si ) . '">+ ' . esc_html__( 'Add step', 'cartconsent' ) . '</button></p>';
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
		echo '<p><label>' . esc_html__( 'Send after (minutes)', 'cartconsent' ) . ' <input type="number" min="1" name="' . $base . '[delay_minutes]" value="' . esc_attr( (int) ( $step['delay_minutes'] ?? 60 ) ) . '" class="small-text"></label> ';
		echo '<label style="margin-left:14px"><input type="checkbox" name="' . $base . '[coupon]" value="1" ' . checked( ! empty( $step['coupon'] ), true, false ) . '> ' . esc_html__( 'Coupon', 'cartconsent' ) . '</label> ';
		echo '<button type="button" class="button-link crw-del-step" style="color:#a3282a;margin-left:10px">' . esc_html__( 'Remove', 'cartconsent' ) . '</button></p>';
		echo '<p><label>' . esc_html__( 'Subject lines (one per line = A/B test)', 'cartconsent' ) . '<br><textarea name="' . $base . '[subjects]" rows="2" class="large-text">' . esc_textarea( implode( "\n", array_map( 'strval', $subjects ) ) ) . '</textarea></label></p>';
		echo '<p><textarea name="' . $base . '[body]" rows="5" class="large-text" placeholder="' . esc_attr__( 'Message body', 'cartconsent' ) . '">' . esc_textarea( (string) ( $step['body'] ?? '' ) ) . '</textarea></p></div>';
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

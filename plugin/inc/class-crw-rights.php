<?php
/**
 * Data-subject rights: a public request form ([crw_dsar_form]) plus an admin
 * queue with deadline tracking. Deletion / do-not-sell requests also record a
 * consent withdrawal and propagate an ad-audience removal.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * DSAR intake + queue.
 */
class CRW_Rights {

	const CPT           = 'crw_dsar';
	const DEADLINE_DAYS = 45;

	/**
	 * Hook up.
	 */
	public function register() {
		add_action( 'init', array( $this, 'cpt' ) );
		add_shortcode( 'crw_dsar_form', array( $this, 'form' ) );
		add_action( 'admin_post_nopriv_crw_dsar', array( $this, 'submit' ) );
		add_action( 'admin_post_crw_dsar', array( $this, 'submit' ) );
	}

	/**
	 * Private request CPT.
	 */
	public function cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'          => array( 'name' => __( 'Privacy Requests', 'consent-resolve-woo' ) ),
				'public'          => false,
				'show_ui'         => false,
				'capability_type' => 'post',
				'supports'        => array( 'title' ),
			)
		);
	}

	/**
	 * Public intake form.
	 */
	public function form() {
		if ( isset( $_GET['crw_dsar'] ) && 'ok' === $_GET['crw_dsar'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '<div class="crw-dsar-ok"><p>' . esc_html__( 'Thank you. Your request has been received and we will respond within the time required by law.', 'consent-resolve-woo' ) . '</p></div>';
		}
		ob_start();
		?>
		<form class="crw-dsar-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'crw_dsar' ); ?>
			<input type="hidden" name="action" value="crw_dsar">
			<h3><?php esc_html_e( 'Submit a privacy request', 'consent-resolve-woo' ); ?></h3>
			<p><label><?php esc_html_e( 'Your name', 'consent-resolve-woo' ); ?><br>
				<input type="text" name="crw_name" required></label></p>
			<p><label><?php esc_html_e( 'Your email', 'consent-resolve-woo' ); ?><br>
				<input type="email" name="crw_email" required></label></p>
			<p><label><?php esc_html_e( 'Request type', 'consent-resolve-woo' ); ?><br>
				<select name="crw_type" required>
					<option value="access"><?php esc_html_e( 'Access my data', 'consent-resolve-woo' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete my data', 'consent-resolve-woo' ); ?></option>
					<option value="correct"><?php esc_html_e( 'Correct my data', 'consent-resolve-woo' ); ?></option>
					<option value="opt_out"><?php esc_html_e( 'Do not sell or share my data', 'consent-resolve-woo' ); ?></option>
				</select></label></p>
			<p><label><?php esc_html_e( 'Details (optional)', 'consent-resolve-woo' ); ?><br>
				<textarea name="crw_details" rows="3"></textarea></label></p>
			<p><button type="submit"><?php esc_html_e( 'Submit request', 'consent-resolve-woo' ); ?></button></p>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Handle a submission.
	 */
	public function submit() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'crw_dsar' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'consent-resolve-woo' ) );
		}
		// Public endpoint — throttle so it can't be scripted (a logged-out nonce
		// is shared, not per-user).
		if ( ! CRW_Rate::ok( CRW_Rate::ip_key( 'dsar' ), 5, HOUR_IN_SECONDS ) ) {
			wp_die( esc_html__( 'Too many requests. Please try again later.', 'consent-resolve-woo' ) );
		}
		$name    = isset( $_POST['crw_name'] ) ? sanitize_text_field( wp_unslash( $_POST['crw_name'] ) ) : '';
		$email   = isset( $_POST['crw_email'] ) ? sanitize_email( wp_unslash( $_POST['crw_email'] ) ) : '';
		$type    = isset( $_POST['crw_type'] ) ? sanitize_key( wp_unslash( $_POST['crw_type'] ) ) : 'access';
		$details = isset( $_POST['crw_details'] ) ? sanitize_textarea_field( wp_unslash( $_POST['crw_details'] ) ) : '';

		if ( ! $email || ! in_array( $type, array( 'access', 'delete', 'correct', 'opt_out' ), true ) ) {
			wp_die( esc_html__( 'Please provide a valid email and request type.', 'consent-resolve-woo' ) );
		}

		$id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => sprintf( '%s — %s', $email, $type ),
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			$deadline = gmdate( 'Y-m-d', time() + self::DEADLINE_DAYS * DAY_IN_SECONDS );
			update_post_meta( $id, 'crw_name', $name );
			update_post_meta( $id, 'crw_email', $email );
			update_post_meta( $id, 'crw_type', $type );
			update_post_meta( $id, 'crw_details', $details );
			update_post_meta( $id, 'crw_status', 'new' );
			update_post_meta( $id, 'crw_deadline', $deadline );

			// NOTE: we deliberately do NOT suppress / propagate an opt-out here.
			// The submitter's ownership of the email is unverified, so acting now
			// would let anyone erase/opt-out an arbitrary address and forge the
			// consent log. The withdrawal is applied only when an admin verifies
			// and completes the request (see CRW_Cmp_Admin::dsar_done).

			wp_mail(
				get_option( 'admin_email' ),
				sprintf( /* translators: %s type */ __( 'New privacy request: %s', 'consent-resolve-woo' ), $type ),
				sprintf( "%s (%s) requested: %s\n\n%s\n\nRespond by %s.", $name, $email, $type, $details, $deadline )
			);
		}
		$back = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'crw_dsar', 'ok', $back ) );
		exit;
	}

	/**
	 * Queue for the admin, newest first.
	 */
	public static function queue() {
		$posts = get_posts(
			array(
				'post_type'      => self::CPT,
				'posts_per_page' => 200,
				'post_status'    => 'publish',
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'       => $p->ID,
				'email'    => get_post_meta( $p->ID, 'crw_email', true ),
				'name'     => get_post_meta( $p->ID, 'crw_name', true ),
				'type'     => get_post_meta( $p->ID, 'crw_type', true ),
				'status'   => get_post_meta( $p->ID, 'crw_status', true ),
				'deadline' => get_post_meta( $p->ID, 'crw_deadline', true ),
				'created'  => $p->post_date,
			);
		}
		return $out;
	}
}

<?php
/**
 * REST API — crw/v1. Consent record writes from the built-in banner + a
 * chain-verify endpoint for the Consent Records screen.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consent REST endpoints.
 */
class CRW_Rest {

	const NS = 'crw/v1';

	/**
	 * Register routes.
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Route table.
	 */
	public function routes() {
		register_rest_route(
			self::NS,
			'/record',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record' ),
				'permission_callback' => array( $this, 'can_record' ),
				'args'                => array(
					'categories' => array( 'required' => true, 'type' => 'object' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/verify',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'verify' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);
	}

	/**
	 * A logged-out visitor may record their own consent; the nonce blocks CSRF.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function can_record( $req ) {
		return (bool) wp_verify_nonce( (string) $req->get_header( 'X-WP-Nonce' ), 'wp_rest' );
	}

	/**
	 * Admin gate.
	 */
	public function can_manage() {
		return current_user_can( 'crw_manage' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Write a hash-chained consent record.
	 *
	 * @param WP_REST_Request $req Request.
	 */
	public function record( $req ) {
		// The nonce is shared across logged-out visitors, so throttle per-IP to
		// stop a scripted flood of consent-log writes.
		if ( ! CRW_Rate::ok( CRW_Rate::ip_key( 'record' ), 60, HOUR_IN_SECONDS ) ) {
			return new WP_REST_Response( array( 'ok' => false ), 429 );
		}
		$cats   = (array) $req->get_param( 'categories' );
		$method = sanitize_key( (string) $req->get_param( 'method' ) );
		$event  = sanitize_key( (string) $req->get_param( 'event' ) );
		$vid    = sanitize_text_field( (string) $req->get_param( 'vid' ) );

		$clean = array();
		foreach ( CRW_Regions::categories() as $key => $_m ) {
			$clean[ $key ] = ( 'functional' === $key ) ? true : ! empty( $cats[ $key ] );
		}
		$hash = CRW_Consent::record(
			array(
				'event_type' => $event ? $event : 'update',
				'method'     => $method ? $method : 'banner',
				'categories' => $clean,
				'visitor_id' => $vid,
			)
		);
		return new WP_REST_Response( array( 'ok' => (bool) $hash, 'hash' => $hash ), $hash ? 201 : 500 );
	}

	/**
	 * Verify the record chain is intact.
	 */
	public function verify() {
		$r = CRW_Consent::verify_chain();
		return new WP_REST_Response( array( 'intact' => ( true === $r ), 'broken_at' => ( true === $r ) ? null : $r ), 200 );
	}
}

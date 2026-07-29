<?php
/**
 * Bundled script-blocking services library (shared snapshot with Consent
 * Resolve core). Feeds the client blocking engine.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads and exposes the curated services list.
 */
class CRW_Services {

	/**
	 * Memoized list.
	 *
	 * @var array|null
	 */
	protected static $list = null;

	/**
	 * All services as an array of maps.
	 */
	public static function all() {
		if ( null !== self::$list ) {
			return self::$list;
		}
		$path = CRW_DIR . 'data/services.json';
		$raw  = is_readable( $path ) ? file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = json_decode( $raw, true );
		$list = ( is_array( $data ) && ! empty( $data['services'] ) ) ? $data['services'] : array();
		/** Allow add-ons to register custom services. */
		self::$list = apply_filters( 'crw_services', $list );
		return self::$list;
	}

	/**
	 * Lean map for the client blocking engine.
	 */
	public static function client_rules() {
		$rules = array();
		foreach ( self::all() as $s ) {
			$rules[] = array(
				'p' => isset( $s['patterns'] ) ? array_values( $s['patterns'] ) : array(),
				'c' => isset( $s['category'] ) ? $s['category'] : 'marketing',
				'm' => isset( $s['method'] ) ? $s['method'] : 'script',
				's' => isset( $s['slug'] ) ? $s['slug'] : '',
				'n' => isset( $s['name'] ) ? $s['name'] : '',
			);
		}
		return $rules;
	}

	/**
	 * Group services by category (for the cookie policy table).
	 */
	public static function by_category() {
		$out = array();
		foreach ( self::all() as $s ) {
			$cat           = isset( $s['category'] ) ? $s['category'] : 'marketing';
			$out[ $cat ][] = $s;
		}
		return $out;
	}
}

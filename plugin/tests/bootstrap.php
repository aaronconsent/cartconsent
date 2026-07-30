<?php
/**
 * Standalone test bootstrap — runs the plugin's pure logic with no WordPress.
 * Run:  php tests/run.php  (PHP 8.1+ with sodium).
 *
 * @package ConsentResolveWoo
 */

if ( defined( 'CRW_TEST_BOOT' ) ) {
	return;
}
define( 'CRW_TEST_BOOT', 1 );
error_reporting( E_ALL );

define( 'ABSPATH', '/tmp/' );
if ( ! defined( 'LOGGED_IN_KEY' ) ) { define( 'LOGGED_IN_KEY', 'crw-test-key-1234567890abcdefghij' ); }
if ( ! defined( 'LOGGED_IN_SALT' ) ) { define( 'LOGGED_IN_SALT', 'crw-test-salt-0987654321zyxwvuts' ); }
if ( ! defined( 'NONCE_SALT' ) ) { define( 'NONCE_SALT', 'crw-test-nonce-salt-abcxyz123456' ); }
define( 'CRW_VERSION', 'test' );
if ( ! defined( 'CRW_DIR' ) ) { define( 'CRW_DIR', dirname( __DIR__ ) . '/' ); }
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) { define( 'MINUTE_IN_SECONDS', 60 ); }
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }
if ( ! defined( 'DAY_IN_SECONDS' ) ) { define( 'DAY_IN_SECONDS', 86400 ); }

$GLOBALS['crw_store'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['crw_store'] ) ? $GLOBALS['crw_store'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['crw_store'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['crw_store'][ $k ] ); return true; }
function __( $s, $d = '' ) { return $s; }
function sanitize_email( $e ) { return trim( (string) $e ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/\s+/', ' ', (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function wp_generate_password( $len = 12, $special = true ) { return substr( str_repeat( 'abcdef0123456789', 8 ), 0, (int) $len ); }
function home_url( $path = '/' ) { return 'https://shop.test' . $path; }
function get_woocommerce_currency_symbol( $c = '' ) { return '$'; }
function wp_json_encode( $d, $o = 0, $depth = 512 ) { return json_encode( $d, $o, $depth ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function add_query_arg( ...$a ) {
	if ( is_array( $a[0] ) ) { $args = $a[0]; $url = (string) ( $a[1] ?? '' ); }
	else { $args = array( $a[0] => $a[1] ); $url = (string) ( $a[2] ?? '' ); }
	$sep = ( false !== strpos( $url, '?' ) ) ? '&' : '?';
	return $url . $sep . http_build_query( $args );
}

$inc = dirname( __DIR__ ) . '/inc/';
require $inc . 'class-crw-options.php';
require $inc . 'class-crw-crypto.php';
require $inc . 'class-crw-regions.php';
require $inc . 'class-crw-consent.php';
require $inc . 'class-crw-recovery-consent.php';
require $inc . 'class-crw-events.php';
require $inc . 'class-crw-carts-store.php';
require $inc . 'class-crw-segments.php';
require $inc . 'class-crw-push-crypto.php';
require $inc . 'class-crw-push-store.php';
require $inc . 'class-crw-estimates.php';
require $inc . 'class-crw-unsubscribe.php';
require $inc . 'class-crw-popup.php';
require $inc . 'class-crw-recovery.php';
require $inc . 'class-crw-mailer.php';

$GLOBALS['crw_pass'] = 0;
$GLOBALS['crw_fail'] = 0;
function crw_group( $name ) { echo "\n" . $name . "\n"; }
function crw_check( $label, $cond ) {
	if ( $cond ) { $GLOBALS['crw_pass']++; echo "  ok   $label\n"; }
	else { $GLOBALS['crw_fail']++; echo "  FAIL $label\n"; }
}

/** Reset settings to defaults with optional overrides, busting the cache. */
function crw_set( array $overrides = array() ) {
	$s = CRW_Options::defaults();
	foreach ( $overrides as $path => $val ) {
		$keys = explode( '.', $path );
		$ref  = &$s;
		foreach ( $keys as $i => $k ) {
			if ( $i === count( $keys ) - 1 ) { $ref[ $k ] = $val; }
			else { $ref = &$ref[ $k ]; }
		}
		unset( $ref );
	}
	CRW_Options::save( $s );
	unset( $_SERVER['HTTP_SEC_GPC'] );
}

<?php
/**
 * Uninstall — remove all plugin data. Abandoned-cart records contain shopper
 * emails, so a clean uninstall drops them entirely (data minimization).
 *
 * @package ConsentResolveWoo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'crw_carts', 'crw_suppression', 'crw_events', 'crw_consent_records', 'crw_push' ) as $t ) {
	$table = $wpdb->prefix . $t;
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

foreach ( array( 'crw_settings', 'crw_db_version', 'crw_unsub_secret', 'crw_crypto_secret', 'crw_vapid', 'crw_purged_today', 'crw_setup_complete' ) as $opt ) {
	delete_option( $opt );
}
delete_transient( 'crw_purged_today' );

foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		$role->remove_cap( 'crw_manage' );
	}
}

wp_clear_scheduled_hook( 'crw_process_queue' );

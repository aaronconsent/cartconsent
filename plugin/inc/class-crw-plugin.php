<?php
/**
 * Plugin orchestrator — wires modules and runs upgrades.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boots the plugin.
 */
class CRW_Plugin {

	/**
	 * Wire everything up.
	 */
	public function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		$this->maybe_upgrade();

		// Built-in consent surface (banner / records / privacy) — self-contained.
		( new CRW_Consent() )->register();
		( new CRW_Frontend() )->register();
		( new CRW_Rest() )->register();
		( new CRW_Rights() )->register();

		// Cart recovery.
		( new CRW_Capture() )->register();
		( new CRW_Recovery() )->register();
		( new CRW_Unsubscribe() )->register();
		( new CRW_Scheduler() )->register();
		( new CRW_Tracking() )->register();
		( new CRW_Push() )->register();
		( new CRW_Popup() )->register();
		( new CRW_Privacy() )->register();

		if ( is_admin() ) {
			( new CRW_Admin() )->register();
			( new CRW_Cmp_Admin() )->register();
			( new CRW_Wizard() )->register();
		}
	}

	/**
	 * Translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'cartconsent', false, dirname( CRW_BASENAME ) . '/languages' );
	}

	/**
	 * Run table upgrades + heal the cron schedule when the version changes.
	 */
	protected function maybe_upgrade() {
		if ( (int) get_option( 'crw_db_version' ) !== CRW_Install::DB_VERSION ) {
			CRW_Install::create_tables();
			CRW_Install::add_caps();
			update_option( 'crw_db_version', CRW_Install::DB_VERSION );
		}
	}
}

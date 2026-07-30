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

		// Consent surface: the hosted Consent Resolve javascript + cookie banner,
		// exclusively — activated by the API key. The formerly-bundled local
		// banner/records/privacy modules (CRW_Frontend/CRW_Consent/CRW_Rest/
		// CRW_Rights) are retired; those surfaces live in the Consent Resolve
		// dashboard now.
		( new CRW_Hosted() )->register();

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

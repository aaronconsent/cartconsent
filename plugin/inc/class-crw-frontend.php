<?php
/**
 * Front-end consent delivery: Google Consent Mode v2 defaults, the inline
 * blocking engine, and the banner UI. Uses the same audited engine assets as
 * Consent Resolve core. Stands down automatically if the core plugin is active
 * so a store never shows two banners.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Banner + blocking + consent-mode.
 */
class CRW_Frontend {

	/**
	 * Hook up (only when our banner should run).
	 */
	public function register() {
		// Reopen-preferences shortcode (always available while our banner runs).
		add_shortcode( 'crw_manage_consent', array( $this, 'manage_shortcode' ) );
		// Defer option-dependent wiring to init (avoids pre-init translated defaults).
		add_action( 'init', array( $this, 'wire' ) );
	}

	/**
	 * Option-dependent wiring (runs on init).
	 */
	public function wire() {
		if ( ! CRW_Options::get( 'banner.enabled', true ) ) {
			return;
		}
		// If Consent Resolve core is active it owns consent + blocking end-to-end.
		if ( class_exists( 'CR_Frontend' ) || class_exists( 'CR_Consent' ) ) {
			return;
		}
		add_action( 'wp_head', array( $this, 'head' ), 0 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_footer', array( $this, 'banner_root' ) );
		add_filter( 'script_loader_tag', array( $this, 'gate_enqueued_script' ), 10, 3 );
	}

	/**
	 * Config + Consent Mode defaults + inline blocking engine in <head> pri 0.
	 */
	public function head() {
		if ( is_admin() ) {
			return;
		}
		$profile = CRW_Regions::profile();
		$config  = array(
			'model'       => $profile['model'],
			'mode'        => $profile['mode'],
			'doNotSell'   => ! empty( $profile['do_not_sell'] ),
			'grants'      => isset( $profile['default_grant'] ) ? $profile['default_grant'] : array( 'functional' ),
			'signals'     => CRW_Options::get( 'signals' ),
			'rules'       => CRW_Services::client_rules(),
			'iframePh'    => (bool) CRW_Options::get( 'blocking.iframe_placeholders', true ),
			'consentMode' => (bool) CRW_Options::get( 'consent_mode', true ),
			'uet'         => (bool) CRW_Options::get( 'uet_consent', true ),
			'cookie'      => CRW_Consent::COOKIE,
			'vid'         => CRW_Consent::visitor_id(),
			'rest'        => esc_url_raw( rest_url( 'crw/v1/record' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'i18n'        => array(
				'loadContent' => __( 'This content is blocked until you accept marketing cookies.', 'cartconsent' ),
				'loadBtn'     => __( 'Load content', 'cartconsent' ),
			),
		);

		echo "\n<!-- CartConsent -->\n";
		echo '<script id="cr-config">window.CRData=' . wp_json_encode( $config ) . ';</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( $config['consentMode'] ) {
			echo '<script id="cr-consent-mode">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("consent","default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",functionality_storage:"granted",security_storage:"granted",wait_for_update:500});</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( $config['uet'] ) {
			echo '<script id="cr-uet-consent">window.uetq=window.uetq||[];window.uetq.push("consent","default",{ad_storage:"denied"});</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$engine = @file_get_contents( CRW_DIR . 'assets/js/blocking.js' ); // phpcs:ignore
		if ( $engine ) {
			echo '<script id="cr-blocking">' . $engine . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * Banner assets (deferred).
	 */
	public function assets() {
		if ( is_admin() || ! CRW_Regions::profile()['banner'] ) {
			return;
		}
		wp_enqueue_style( 'crw-banner', CRW_URL . 'assets/css/banner.css', array(), CRW_VERSION );
		wp_add_inline_style( 'crw-banner', $this->style_vars() );
		wp_enqueue_script( 'crw-banner', CRW_URL . 'assets/js/banner.js', array(), CRW_VERSION, true );
		wp_localize_script( 'crw-banner', 'CRBanner', $this->banner_config() );
	}

	/**
	 * CSS variables from the style settings.
	 */
	protected function style_vars() {
		$s   = CRW_Options::get( 'style' );
		$css = ':root{'
			. '--cr-accent:' . esc_attr( $s['accent'] ) . ';'
			. '--cr-text:' . esc_attr( $s['text'] ) . ';'
			. '--cr-surface:' . esc_attr( $s['surface'] ) . ';'
			. '--cr-radius:' . (int) $s['radius'] . 'px;'
			. ( ! empty( $s['font'] ) ? '--cr-font:' . esc_attr( $s['font'] ) . ';' : '' )
			. '}';
		if ( ! empty( $s['custom_css'] ) ) {
			$css .= wp_strip_all_tags( $s['custom_css'] );
		}
		return $css;
	}

	/**
	 * Banner UI config.
	 */
	protected function banner_config() {
		$b = CRW_Options::get( 'banner' );
		return array(
			'layout'     => $b['layout'],
			'position'   => $b['position'],
			'title'      => $b['title'],
			'message'    => $b['message'],
			'accept'     => $b['accept_label'],
			'reject'     => $b['reject_label'],
			'prefs'      => $b['prefs_label'],
			'save'       => $b['save_label'],
			'logo'       => $b['logo'],
			'categories' => CRW_Regions::categories(),
			'doNotSell'  => (bool) ( CRW_Options::get( 'do_not_sell', true ) && ! empty( CRW_Regions::profile()['do_not_sell'] ) ),
			'dnsLabel'   => __( 'Do Not Sell or Share My Personal Information', 'cartconsent' ),
			'poweredBy'  => false,
		);
	}

	/**
	 * [crw_manage_consent] — a button that reopens the preference center.
	 *
	 * @param array $atts Attributes.
	 */
	public function manage_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'label' => __( 'Manage cookie preferences', 'cartconsent' ) ), $atts );
		return '<button type="button" class="crw-manage-consent" data-cr-open>' . esc_html( $atts['label'] ) . '</button>';
	}

	/**
	 * The banner mount point.
	 */
	public function banner_root() {
		if ( is_admin() || ! CRW_Regions::profile()['banner'] ) {
			return;
		}
		echo '<div id="cr-root" aria-live="polite"></div>' . "\n";
	}

	/**
	 * Neutralize known third-party enqueued scripts lacking consent.
	 *
	 * @param string $tag    Tag.
	 * @param string $handle Handle.
	 * @param string $src    Source.
	 */
	public function gate_enqueued_script( $tag, $handle, $src ) {
		if ( is_admin() || empty( $src ) ) {
			return $tag;
		}
		foreach ( CRW_Services::all() as $service ) {
			$cat = isset( $service['category'] ) ? $service['category'] : 'marketing';
			if ( 'functional' === $cat || CRW_Consent::allows( $cat ) ) {
				continue;
			}
			foreach ( (array) $service['patterns'] as $pattern ) {
				if ( false !== strpos( $src, $pattern ) ) {
					$tag = str_replace(
						array( " src='", ' src="', ' type="text/javascript"' ),
						array( " data-cr-category='" . esc_attr( $cat ) . "' data-cr-src='", ' data-cr-category="' . esc_attr( $cat ) . '" data-cr-src="', '' ),
						$tag
					);
					$tag = str_replace( '<script ', '<script type="text/plain" ', $tag );
					return $tag;
				}
			}
		}
		return $tag;
	}
}

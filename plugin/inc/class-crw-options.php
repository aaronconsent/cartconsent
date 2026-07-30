<?php
/**
 * Settings — dot-path accessor over a single option row.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes crw_settings.
 */
class CRW_Options {

	const KEY = 'crw_settings';

	/**
	 * Memoized settings.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Defaults. The email sequence ships pre-written so a store owner gets a
	 * working recovery flow out of the box.
	 */
	public static function defaults() {
		return array(
			'capture'   => array(
				'enabled'               => true,
				'abandon_after_minutes' => 30,   // Inactivity before a cart counts as abandoned.
				'guests'                => true,  // Capture guest carts (email typed at checkout).
			),
			'consent'   => array(
				// optin_only  = only shoppers who tick the box are emailed (strictest).
				// jurisdiction = opt-out regions get a soft opt-in (email + unsubscribe);
				//                opt-in regions (EU/UK) require the box. (Default.)
				// all_unsub   = email everyone who left an address, always with unsubscribe.
				'basis'                   => 'jurisdiction',
				// Region model to assume when no geo signal is available (Consent
				// Resolve core not installed). 'opt_out' permits a US-style soft
				// opt-in; set 'opt_in' if you sell into the EU/UK without geo.
				'fallback_model'          => 'opt_out',
				'checkout_checkbox'       => true,
				'checkbox_label'          => __( 'Email me a reminder about my cart and occasional offers. Unsubscribe anytime.', 'cartconsent' ),
				'checkbox_default_checked' => false,
				'honor_gpc'               => true,
			),
			'emails'    => array(
				'from_name'  => '',
				'from_email' => '',
				'reply_to'   => '',
				'sequences'  => self::default_sequences(),
			),
			'coupon'    => array(
				'enabled'     => false,
				'type'        => 'percent',   // percent | fixed_cart
				'amount'      => 10,
				'expiry_days' => 7,
				'prefix'      => 'COMEBACK',
			),
			'tracking'  => array(
				'consent_mode' => true,
				'meta_pixel_id' => '',
				'ga4_id'       => '',
				'events'       => true,
			),
			'audiences' => array(
				'enabled' => false, // Push consented abandoners to a retargeting audience via the Consent Resolve edge.
			),
			'push'      => array(
				'enabled' => false, // Web-push recovery channel (browser notifications).
			),
			'popup'     => array(
				'enabled'       => false,
				'trigger'       => 'exit', // exit | timer.
				'delay_seconds' => 20,
				'title'         => __( 'Wait — save your cart', 'cartconsent' ),
				'message'       => __( 'Enter your email and we’ll hold your cart and send you a link to finish later.', 'cartconsent' ),
				'consent_label' => __( 'Email me a reminder about my cart. Unsubscribe anytime.', 'cartconsent' ),
				'button'        => __( 'Save my cart', 'cartconsent' ),
			),
			'retention_days' => 60,
			// --- Lost-revenue estimate (assumption is merchant-editable) ---------
			'estimates' => array( 'resolution_rate' => 20 ),

			// --- Built-in consent banner (works standalone; stands down if
			//     Consent Resolve core is active) --------------------------------
			'banner'    => array(
				'enabled'      => true,
				'auto_region'  => true,   // Detect region from CDN geo headers.
				'region'       => 'us',   // Manual fallback / when auto is off.
				'layout'       => 'box',  // bar-top | bar-bottom | box | modal | corner.
				'position'     => 'bottom-left',
				'title'        => __( 'We value your privacy', 'cartconsent' ),
				'message'      => __( 'We use cookies to improve your experience, analyze traffic, and for marketing. Accept all, reject non-essential, or choose what to allow.', 'cartconsent' ),
				'accept_label' => __( 'Accept all', 'cartconsent' ),
				'reject_label' => __( 'Reject non-essential', 'cartconsent' ),
				'prefs_label'  => __( 'Preferences', 'cartconsent' ),
				'save_label'   => __( 'Save choices', 'cartconsent' ),
				'logo'         => '',
			),
			'style'     => array(
				'accent'     => '#1F6FEB',
				'text'       => '#14161D',
				'surface'    => '#FFFFFF',
				'radius'     => 14,
				'font'       => '',
				'custom_css' => '',
			),
			'signals'   => array(
				'gpc' => true,
				'dnt' => true,
			),
			'consent_mode' => true,
			'uet_consent'  => true,
			'do_not_sell'  => true,
			'blocking'     => array(
				'iframe_placeholders' => true,
			),

			// --- Connection: the plugin's own Consent Resolve account (edge) ----
			'connection' => array(
				'site_id' => '',
				'api_key' => '',
			),
		);
	}

	/**
	 * The bundled sequences. Ships one catch-all "Default" flow; store owners
	 * can add more, each targeting a segment.
	 */
	public static function default_sequences() {
		return array(
			array(
				'id'      => 'default',
				'name'    => __( 'Default', 'cartconsent' ),
				'enabled' => true,
				'segment' => array(), // Empty = matches every cart.
				'steps'   => self::default_steps(),
			),
		);
	}

	/**
	 * The bundled 3-step steps (delays are minutes after abandonment). Each step
	 * carries one or more subject lines — extra lines are A/B-tested.
	 */
	public static function default_steps() {
		return array(
			array(
				'delay_minutes' => 60,
				'subjects'      => array( __( 'You left something in your cart at {store_name}', 'cartconsent' ) ),
				'body'          => __( "Hi {first_name},\n\nYou left these items in your cart:\n\n{cart_items}\n\nYour cart is still saved — pick up right where you left off:\n{recovery_url}\n\nQuestions? Just reply to this email.", 'cartconsent' ),
				'coupon'        => false,
			),
			array(
				'delay_minutes' => 1440,
				'subjects'      => array( __( 'Still thinking it over, {first_name}?', 'cartconsent' ) ),
				'body'          => __( "Hi {first_name},\n\nYour cart at {store_name} is still here:\n\n{cart_items}\n\nTotal: {cart_total}\n\nComplete your order in one click:\n{recovery_url}", 'cartconsent' ),
				'coupon'        => false,
			),
			array(
				'delay_minutes' => 4320,
				'subjects'      => array( __( 'Last chance for your cart at {store_name}', 'cartconsent' ) ),
				'body'          => __( "Hi {first_name},\n\nThis is a friendly last reminder — your cart is still saved but won't be held much longer:\n\n{cart_items}\n\nTotal: {cart_total}\n\nComplete your order here:\n{recovery_url}", 'cartconsent' ),
				'coupon'        => false,
			),
		);
	}

	/**
	 * The active sequences (migrating any legacy single-sequence config).
	 */
	public static function sequences() {
		$em = self::get( 'emails', array() );
		if ( isset( $em['sequences'] ) && is_array( $em['sequences'] ) && ! empty( $em['sequences'] ) ) {
			return array_values( $em['sequences'] );
		}
		if ( isset( $em['sequence'] ) && is_array( $em['sequence'] ) && ! empty( $em['sequence'] ) ) {
			$steps = array();
			foreach ( $em['sequence'] as $s ) {
				$steps[] = array(
					'delay_minutes' => (int) ( $s['delay_minutes'] ?? 60 ),
					'subjects'      => isset( $s['subjects'] ) ? (array) $s['subjects'] : array( (string) ( $s['subject'] ?? '' ) ),
					'body'          => (string) ( $s['body'] ?? '' ),
					'coupon'        => ! empty( $s['coupon'] ),
				);
			}
			return array( array( 'id' => 'default', 'name' => 'Default', 'enabled' => true, 'segment' => array(), 'steps' => $steps ) );
		}
		return self::default_sequences();
	}

	/**
	 * A sequence by id, or the first, or null.
	 *
	 * @param string $id Sequence id.
	 */
	public static function sequence( $id ) {
		foreach ( self::sequences() as $seq ) {
			if ( (string) ( $seq['id'] ?? '' ) === (string) $id ) {
				return $seq;
			}
		}
		return null;
	}

	/**
	 * Full settings, merged over defaults.
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::KEY, array() );
			self::$cache = self::deep_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	/**
	 * Dot-path getter: CRW_Options::get('consent.basis').
	 *
	 * @param string $path    Dot path.
	 * @param mixed  $default Fallback.
	 */
	public static function get( $path, $default = null ) {
		$node = self::all();
		foreach ( explode( '.', $path ) as $key ) {
			if ( is_array( $node ) && array_key_exists( $key, $node ) ) {
				$node = $node[ $key ];
			} else {
				return $default;
			}
		}
		return $node;
	}

	/**
	 * Persist the full settings array + bust the request cache.
	 *
	 * @param array $settings Settings.
	 */
	public static function save( array $settings ) {
		update_option( self::KEY, $settings, false );
		self::$cache = null;
	}

	/**
	 * Seed defaults on activation if nothing is stored yet.
	 */
	public static function seed() {
		if ( false === get_option( self::KEY, false ) ) {
			update_option( self::KEY, self::defaults(), false );
		}
	}

	/**
	 * Recursive array merge where saved scalars win and nested arrays merge.
	 * The email sequence (a list) is replaced wholesale when present.
	 *
	 * @param array $base     Defaults.
	 * @param array $override Saved.
	 */
	protected static function deep_merge( array $base, array $override ) {
		foreach ( $override as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && ! self::is_list( $v ) ) {
				$base[ $k ] = self::deep_merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	/**
	 * Whether an array is a sequential list (so it should replace, not merge).
	 *
	 * @param array $a Array.
	 */
	protected static function is_list( array $a ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $a );
		}
		return array_keys( $a ) === range( 0, count( $a ) - 1 );
	}
}

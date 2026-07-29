<?php
/**
 * Composes and sends recovery emails over the site's own mail transport
 * (wp_mail → whatever SMTP the store uses). Every message carries the CAN-SPAM
 * essentials: honest From, the store's physical address, and a one-click
 * unsubscribe. Uses str_ireplace for merges so shopper/product values with
 * $1 / backslashes are inserted literally.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recovery email sender.
 */
class CRW_Mailer {

	/**
	 * Send a specific step of a sequence for a cart row. If the step defines more
	 * than one subject line, they are A/B-tested (deterministic split by cart id)
	 * and the chosen variant is logged for reporting.
	 *
	 * @param array      $row      Cart row.
	 * @param array|null $sequence The sequence (with steps + id).
	 * @param int        $step     Zero-based step index.
	 * @return bool Whether the mail was accepted.
	 */
	public static function send_step( array $row, $sequence, $step ) {
		$steps = is_array( $sequence ) ? array_values( (array) ( $sequence['steps'] ?? array() ) ) : array();
		if ( ! isset( $steps[ $step ] ) ) {
			return false;
		}
		$email = CRW_Carts_Store::email( $row );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}
		if ( CRW_Carts_Store::is_suppressed( CRW_Crypto::email_hash( $email ) ) ) {
			return false;
		}

		$def      = $steps[ $step ];
		$subjects = isset( $def['subjects'] ) && is_array( $def['subjects'] ) && $def['subjects']
			? array_values( array_filter( $def['subjects'] ) )
			: array( (string) ( $def['subject'] ?? '' ) );
		if ( empty( $subjects ) ) {
			$subjects = array( '' );
		}
		$variant = count( $subjects ) > 1 ? ( (int) $row['id'] ) % count( $subjects ) : 0;

		$tokens  = self::tokens( $row, $email, ! empty( $def['coupon'] ) );
		$subject = self::merge( (string) $subjects[ $variant ], $tokens );
		$body    = self::merge( (string) ( $def['body'] ?? '' ), $tokens );
		$body    = rtrim( $body ) . "\n\n" . self::footer( $email );

		$sent = wp_mail( $email, $subject, $body, self::headers( $email ) );
		if ( $sent ) {
			$label = (string) ( $sequence['id'] ?? 'default' ) . ':' . (int) $step . ':' . (int) $variant;
			CRW_Events::log( (int) $row['id'], 'email_sent', 0, $label );
		}
		return (bool) $sent;
	}

	/**
	 * Send the double-opt-in confirmation email for a popup capture.
	 *
	 * @param array  $row   Pending cart row.
	 * @param string $email Shopper email.
	 * @return bool
	 */
	public static function send_confirmation( $row, $email ) {
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}
		if ( CRW_Carts_Store::is_suppressed( CRW_Crypto::email_hash( $email ) ) ) {
			return false;
		}
		$store   = self::store_name();
		$link    = CRW_Popup::confirm_link( $email );
		$subject = sprintf( /* translators: %s store */ __( 'Confirm your cart reminder at %s', 'cartconsent' ), $store );
		$body    = sprintf(
			/* translators: 1: store 2: confirm link */
			__( "You asked us to hold your cart at %1\$s and send you a reminder.\n\nConfirm here and we'll do the rest:\n%2\$s\n\nIf this wasn't you, just ignore this email — nothing else will be sent.", 'cartconsent' ),
			$store,
			$link
		);
		$body .= "\n\n" . self::footer( $email );
		return (bool) wp_mail( $email, $subject, $body, self::headers( $email ) );
	}

	/**
	 * Merge-tag values for a cart.
	 *
	 * @param array  $row       Cart row.
	 * @param string $email     Decrypted email.
	 * @param bool   $with_coupon Whether this step offers a coupon.
	 */
	protected static function tokens( array $row, $email, $with_coupon ) {
		$first = trim( (string) ( $row['first_name'] ?? '' ) );
		$code  = '';
		$label = '';
		if ( $with_coupon ) {
			$code  = CRW_Recovery::mint_coupon( $row, $email );
			$label = '' !== $code ? CRW_Recovery::coupon_label() : '';
		}
		return array(
			'{first_name}'  => '' !== $first ? $first : __( 'there', 'cartconsent' ),
			'{store_name}'  => self::store_name(),
			'{cart_items}'  => self::items_text( $row ),
			'{cart_total}'  => self::money( (int) ( $row['total_cents'] ?? 0 ), (string) ( $row['currency'] ?? 'USD' ) ),
			'{recovery_url}' => CRW_Recovery::url( $row ),
			'{coupon}'      => $label,
			'{coupon_code}' => $code,
		);
	}

	/**
	 * Replace {tags} case-insensitively (literal replacement), then strip any
	 * unresolved tags so nothing leaks.
	 *
	 * @param string $text   Template.
	 * @param array  $tokens Values keyed by {tag}.
	 */
	protected static function merge( $text, array $tokens ) {
		$text = str_ireplace( array_keys( $tokens ), array_values( $tokens ), (string) $text );
		return (string) preg_replace( '/\{[a-z_]+\}/i', '', $text );
	}

	/**
	 * A plain-text list of cart items.
	 *
	 * @param array $row Cart row.
	 */
	protected static function items_text( array $row ) {
		$lines = array();
		foreach ( CRW_Carts_Store::items( $row ) as $item ) {
			$name = trim( (string) ( $item['name'] ?? '' ) );
			$qty  = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			if ( '' !== $name ) {
				$lines[] = '• ' . $qty . '× ' . $name;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Format integer cents as a currency string.
	 *
	 * @param int    $cents    Cents.
	 * @param string $currency ISO code.
	 */
	protected static function money( $cents, $currency ) {
		$symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol( $currency ) : '$';
		return html_entity_decode( $symbol, ENT_QUOTES, 'UTF-8' ) . number_format( max( 0, (int) $cents ) / 100, 2 );
	}

	/**
	 * The store display name.
	 */
	protected static function store_name() {
		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Email headers: honest From/Reply-To + one-click List-Unsubscribe.
	 *
	 * @param string $email Recipient.
	 */
	protected static function headers( $email ) {
		$from_name  = trim( (string) CRW_Options::get( 'emails.from_name', '' ) );
		$from_name  = '' !== $from_name ? $from_name : self::store_name();
		$from_email = sanitize_email( (string) CRW_Options::get( 'emails.from_email', '' ) );
		if ( '' === $from_email || ! is_email( $from_email ) ) {
			$from_email = (string) get_option( 'admin_email' );
		}
		$reply = sanitize_email( (string) CRW_Options::get( 'emails.reply_to', '' ) );
		$reply = ( '' !== $reply && is_email( $reply ) ) ? $reply : $from_email;
		$unsub = CRW_Unsubscribe::link( $email );

		$from_name = str_replace( array( '"', "\r", "\n" ), '', $from_name );
		return array(
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: "%s" <%s>', $from_name, $from_email ),
			'Reply-To: ' . $reply,
			sprintf( 'List-Unsubscribe: <%s>, <mailto:%s?subject=unsubscribe>', $unsub, $from_email ),
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		);
	}

	/**
	 * CAN-SPAM footer: store identity, physical address (from WooCommerce), and
	 * the unsubscribe link.
	 *
	 * @param string $email Recipient.
	 */
	protected static function footer( $email ) {
		$lines = array( '--', self::store_name() );
		$addr  = self::store_address();
		if ( '' !== $addr ) {
			$lines[] = $addr;
		}
		$lines[] = '';
		$lines[] = __( 'Not interested? Unsubscribe:', 'cartconsent' ) . ' ' . CRW_Unsubscribe::link( $email );
		return implode( "\n", $lines );
	}

	/**
	 * The store's physical address, single line, from WooCommerce settings.
	 */
	protected static function store_address() {
		$parts = array_filter(
			array(
				get_option( 'woocommerce_store_address' ),
				get_option( 'woocommerce_store_address_2' ),
				get_option( 'woocommerce_store_city' ),
				get_option( 'woocommerce_store_postcode' ),
			)
		);
		return trim( implode( ', ', array_map( 'sanitize_text_field', $parts ) ) );
	}
}

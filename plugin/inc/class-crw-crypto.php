<?php
/**
 * Symmetric encryption for shopper emails at rest. libsodium secretbox with a
 * 32-byte key derived (HKDF-SHA256) from a long-lived plugin secret.
 *
 * By default that secret lives in wp_options (crw_crypto_secret) — chosen over a
 * salt-derived key because rotating WordPress salts (common on migration / "log
 * out everywhere") would otherwise make every stored email undecryptable and
 * break opt-out matching. This protects against an APPLICATION-LAYER or single-
 * table leak of wp_carts (email_enc is useless without the key). It does NOT
 * protect against a full database dump that also captures wp_options.
 *
 * For full-dump protection, define CONSENT_RESOLVE_WOO_CRYPTO_SECRET (a 64+ char
 * random string) in wp-config.php — the key is then derived from that constant
 * and never touches the database. (Changing the secret later makes already-
 * stored emails undecryptable, so set it before go-live.)
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypt/decrypt helper.
 */
class CRW_Crypto {

	/**
	 * Whether libsodium is available (PHP 7.2+ ships it in core).
	 */
	public static function available() {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * The plugin's own long-lived secret, used for both the encryption key and
	 * the dedupe/suppression hash. Prefers a wp-config constant (kept out of the
	 * database — stronger against a full dump); otherwise a once-generated,
	 * stored option (survives WordPress salt rotation, which a salt-derived key
	 * would not). See the class docblock for the threat-model tradeoff.
	 */
	protected static function secret() {
		if ( defined( 'CONSENT_RESOLVE_WOO_CRYPTO_SECRET' ) && is_string( CONSENT_RESOLVE_WOO_CRYPTO_SECRET ) && strlen( CONSENT_RESOLVE_WOO_CRYPTO_SECRET ) >= 64 ) {
			return CONSENT_RESOLVE_WOO_CRYPTO_SECRET;
		}
		$s = get_option( 'crw_crypto_secret' );
		if ( ! is_string( $s ) || strlen( $s ) < 64 ) {
			$s = wp_generate_password( 96, false );
			update_option( 'crw_crypto_secret', $s, false );
		}
		return $s;
	}

	/**
	 * 32-byte encryption key derived from the plugin secret via HKDF.
	 */
	protected static function key() {
		return hash_hkdf( 'sha256', self::secret(), 32, 'crw-email-v1' );
	}

	/**
	 * Encrypt a string → base64(nonce||ciphertext), or '' on failure.
	 *
	 * @param string $plaintext Value.
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext || ! self::available() ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
		return base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Decrypt a value from encrypt(); returns '' if it can't be recovered.
	 *
	 * @param string $stored base64(nonce||ciphertext).
	 */
	public static function decrypt( $stored ) {
		if ( '' === (string) $stored || ! self::available() ) {
			return '';
		}
		$raw = base64_decode( (string) $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return '';
		}
		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
		return false === $plain ? '' : $plain;
	}

	/**
	 * Salted, one-way hash of a normalized email — the dedupe + suppression key.
	 *
	 * @param string $email Email.
	 */
	public static function email_hash( $email ) {
		$norm = strtolower( trim( (string) $email ) );
		if ( '' === $norm ) {
			return '';
		}
		return hash_hmac( 'sha256', $norm, self::secret() );
	}

	/**
	 * Mask an email for display: jo***@example.com.
	 *
	 * @param string $email Email.
	 */
	public static function mask( $email ) {
		$email = (string) $email;
		if ( false === strpos( $email, '@' ) ) {
			return '';
		}
		list( $u, $d ) = explode( '@', $email, 2 );
		$keep = max( 1, min( 2, strlen( $u ) ) );
		return substr( $u, 0, $keep ) . '***@' . $d;
	}
}

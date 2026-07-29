<?php
/**
 * Web Push encryption (RFC 8291, aes128gcm content encoding per RFC 8188) and
 * VAPID (RFC 8292) — pure PHP via openssl. No Composer dependency.
 *
 * @package ConsentResolveWoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Web Push crypto primitives.
 */
class CRW_Push_Crypto {

	/** Whether the required openssl EC support is available. */
	public static function available() {
		return function_exists( 'openssl_pkey_new' ) && function_exists( 'openssl_pkey_derive' ) && function_exists( 'hash_hkdf' );
	}

	/* -------------------------------------------------------------- base64url */

	public static function b64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	public static function b64url_decode( $data ) {
		$data .= str_repeat( '=', ( 4 - strlen( $data ) % 4 ) % 4 );
		return base64_decode( strtr( $data, '-_', '+/' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/* ------------------------------------------------------------ EC helpers */

	/**
	 * Generate a P-256 keypair. Returns [ 'private_pem', 'public' (65-byte raw
	 * uncompressed point) ].
	 */
	public static function generate_keypair() {
		$res = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		if ( ! $res ) {
			return null;
		}
		openssl_pkey_export( $res, $pem );
		$details = openssl_pkey_get_details( $res );
		$public  = "\x04" . str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT );
		return array( 'private_pem' => $pem, 'public' => $public );
	}

	/**
	 * A private key resource from PEM.
	 *
	 * @param string $pem PEM.
	 */
	protected static function priv( $pem ) {
		return openssl_pkey_get_private( $pem );
	}

	/**
	 * A public key resource from a raw 65-byte uncompressed point (via a DER
	 * SubjectPublicKeyInfo wrapper).
	 *
	 * @param string $raw 65-byte point.
	 */
	protected static function pub_from_raw( $raw ) {
		// DER prefix for an id-ecPublicKey / prime256v1 SPKI, then the bit string.
		$der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $raw;
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		return openssl_pkey_get_public( $pem );
	}

	/**
	 * ECDH shared secret between a private PEM and a raw public point.
	 *
	 * @param string $private_pem Server/local private key PEM.
	 * @param string $peer_raw    Peer 65-byte public point.
	 */
	protected static function ecdh( $private_pem, $peer_raw ) {
		$priv = self::priv( $private_pem );
		$peer = self::pub_from_raw( $peer_raw );
		if ( ! $priv || ! $peer ) {
			return '';
		}
		return (string) openssl_pkey_derive( $peer, $priv, 32 );
	}

	/* --------------------------------------------------------- RFC 8291 encrypt */

	/**
	 * Encrypt a payload for a subscription. Returns [ 'body', 'headers' ] ready
	 * to POST, or null on failure.
	 *
	 * @param string $p256dh_b64 Subscription public key (base64url).
	 * @param string $auth_b64   Subscription auth secret (base64url).
	 * @param string $payload    Plaintext.
	 */
	public static function encrypt( $p256dh_b64, $auth_b64, $payload ) {
		if ( ! self::available() ) {
			return null;
		}
		$ua_public = self::b64url_decode( $p256dh_b64 );
		$auth      = self::b64url_decode( $auth_b64 );
		if ( 65 !== strlen( $ua_public ) || 16 !== strlen( $auth ) ) {
			return null;
		}
		$server = self::generate_keypair();
		if ( ! $server ) {
			return null;
		}
		$shared = self::ecdh( $server['private_pem'], $ua_public );
		if ( 32 !== strlen( $shared ) ) {
			return null;
		}
		$salt = random_bytes( 16 );

		// RFC 8291 §3.4: derive the input keying material.
		$key_info = 'WebPush: info' . "\0" . $ua_public . $server['public'];
		$ikm      = hash_hkdf( 'sha256', $shared, 32, $key_info, $auth );

		// RFC 8188: content-encryption key + nonce.
		$cek   = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
		$nonce = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );

		// Single record: payload || 0x02 delimiter (last record).
		$plaintext = $payload . "\x02";
		$tag       = '';
		$cipher    = openssl_encrypt( $plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $cipher ) {
			return null;
		}
		$rs     = 4096;
		$header = $salt . pack( 'N', $rs ) . pack( 'C', 65 ) . $server['public'];
		$body   = $header . $cipher . $tag;

		return array(
			'body'    => $body,
			'headers' => array(
				'Content-Encoding' => 'aes128gcm',
				'Content-Type'     => 'application/octet-stream',
			),
		);
	}

	/**
	 * Decrypt an aes128gcm body with a receiver private key — used for the
	 * round-trip self-test (proves the key derivation is correct).
	 *
	 * @param string $body            aes128gcm body.
	 * @param string $recv_private_pem Receiver private key PEM.
	 * @param string $auth            Raw auth secret (16 bytes).
	 * @param string $recv_public_raw Receiver public point (65 bytes).
	 */
	public static function decrypt( $body, $recv_private_pem, $auth, $recv_public_raw ) {
		$salt      = substr( $body, 0, 16 );
		$idlen     = ord( substr( $body, 20, 1 ) );
		$as_public = substr( $body, 21, $idlen );
		$cipher    = substr( $body, 21 + $idlen );

		$shared   = self::ecdh( $recv_private_pem, $as_public );
		$key_info = 'WebPush: info' . "\0" . $recv_public_raw . $as_public;
		$ikm      = hash_hkdf( 'sha256', $shared, 32, $key_info, $auth );
		$cek      = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
		$nonce    = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );

		$tag       = substr( $cipher, -16 );
		$ct        = substr( $cipher, 0, -16 );
		$plaintext = openssl_decrypt( $ct, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $plaintext ) {
			return false;
		}
		return rtrim( $plaintext, "\x02" ); // Strip the record delimiter.
	}

	/* --------------------------------------------------------------- VAPID */

	/**
	 * Build the VAPID Authorization + Crypto-Key headers for an endpoint.
	 *
	 * @param string $endpoint         Push endpoint URL.
	 * @param string $vapid_private_pem VAPID private key PEM.
	 * @param string $vapid_public_raw VAPID public point (65 bytes).
	 * @param string $subject          mailto: or https: contact.
	 */
	public static function vapid_headers( $endpoint, $vapid_private_pem, $vapid_public_raw, $subject ) {
		$parts = wp_parse_url( $endpoint );
		$aud   = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );

		$header  = self::b64url_encode( (string) wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$body    = self::b64url_encode( (string) wp_json_encode( array( 'aud' => $aud, 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => $subject ) ) );
		$signing = $header . '.' . $body;

		$der = '';
		if ( ! openssl_sign( $signing, $der, self::priv( $vapid_private_pem ), OPENSSL_ALGO_SHA256 ) ) {
			return array();
		}
		$jwt = $signing . '.' . self::b64url_encode( self::der_to_raw_sig( $der ) );
		return array(
			'Authorization' => 'vapid t=' . $jwt . ', k=' . self::b64url_encode( $vapid_public_raw ),
		);
	}

	/**
	 * Convert an ECDSA DER signature to the raw 64-byte r||s JOSE form.
	 *
	 * @param string $der DER signature.
	 */
	protected static function der_to_raw_sig( $der ) {
		$offset = 4; // 0x30 len 0x02 rlen.
		$rlen   = ord( $der[3] );
		$r      = substr( $der, $offset, $rlen );
		$soff   = $offset + $rlen + 2;
		$slen   = ord( $der[ $offset + $rlen + 1 ] );
		$s      = substr( $der, $soff, $slen );
		$r      = str_pad( ltrim( $r, "\0" ), 32, "\0", STR_PAD_LEFT );
		$s      = str_pad( ltrim( $s, "\0" ), 32, "\0", STR_PAD_LEFT );
		return $r . $s;
	}
}

<?php
/**
 * Web Push crypto — RFC 8291 encrypt/decrypt round-trip + VAPID JWT signature.
 * (Live delivery to a browser push service is verified separately on a device.)
 *
 * @package ConsentResolveWoo
 */

require_once __DIR__ . '/bootstrap.php';
$X = 'CRW_Push_Crypto';

crw_group( 'Web Push: availability + keys' );
crw_check( 'openssl EC support available', $X::available() );
if ( ! $X::available() ) {
	return;
}
$kp = $X::generate_keypair();
crw_check( 'keypair generated', is_array( $kp ) && ! empty( $kp['private_pem'] ) );
crw_check( 'public key is 65-byte uncompressed point', 65 === strlen( $kp['public'] ) && "\x04" === $kp['public'][0] );

crw_group( 'Web Push: base64url' );
crw_check( 'b64url round-trips binary', "\x00\xffhi" === $X::b64url_decode( $X::b64url_encode( "\x00\xffhi" ) ) );

crw_group( 'Web Push: RFC 8291 encrypt/decrypt round-trip' );
// Mock a browser subscription: a receiver keypair + a random auth secret.
$recv       = $X::generate_keypair();
$auth_raw   = random_bytes( 16 );
$p256dh_b64 = $X::b64url_encode( $recv['public'] );
$auth_b64   = $X::b64url_encode( $auth_raw );

$payload = wp_json_encode( array( 'title' => 'You left something behind', 'url' => 'https://shop.test/checkout' ) );
$enc     = $X::encrypt( $p256dh_b64, $auth_b64, $payload );
crw_check( 'encrypt returns a body + aes128gcm header', is_array( $enc ) && ! empty( $enc['body'] ) && 'aes128gcm' === $enc['headers']['Content-Encoding'] );
crw_check( 'body carries the 16-byte salt + server key', strlen( $enc['body'] ) > 21 + 65 && 65 === ord( substr( $enc['body'], 20, 1 ) ) );

$decrypted = $X::decrypt( $enc['body'], $recv['private_pem'], $auth_raw, $recv['public'] );
crw_check( 'decrypts back to the exact payload (crypto correct)', $decrypted === $payload );
crw_check( 'wrong auth secret fails to decrypt', $payload !== $X::decrypt( $enc['body'], $recv['private_pem'], random_bytes( 16 ), $recv['public'] ) );

crw_group( 'Web Push: VAPID JWT' );
$vapid   = $X::generate_keypair();
$headers = $X::vapid_headers( 'https://fcm.googleapis.com/fcm/send/abc123', $vapid['private_pem'], $vapid['public'], 'mailto:owner@shop.test' );
crw_check( 'Authorization header built (vapid t=…, k=…)', isset( $headers['Authorization'] ) && 0 === strpos( $headers['Authorization'], 'vapid t=' ) && false !== strpos( $headers['Authorization'], ', k=' ) );

// Verify the JWT signature with the VAPID public key.
$auth_val = $headers['Authorization'];
preg_match( '/t=([^,]+)/', $auth_val, $m );
$jwt   = $m[1];
$parts = explode( '.', $jwt );
crw_check( 'JWT has 3 parts', 3 === count( $parts ) );
$signing = $parts[0] . '.' . $parts[1];
$raw_sig = $X::b64url_decode( $parts[2] );
crw_check( 'JWT signature is 64 raw bytes (r||s)', 64 === strlen( $raw_sig ) );
// Convert raw r||s back to DER for openssl_verify.
$r   = ltrim( substr( $raw_sig, 0, 32 ), "\0" ); if ( '' === $r || ord( $r[0] ) > 0x7f ) { $r = "\0" . $r; }
$s   = ltrim( substr( $raw_sig, 32 ), "\0" ); if ( '' === $s || ord( $s[0] ) > 0x7f ) { $s = "\0" . $s; }
$der = "\x30" . chr( 4 + strlen( $r ) + strlen( $s ) ) . "\x02" . chr( strlen( $r ) ) . $r . "\x02" . chr( strlen( $s ) ) . $s;
$pub_pem_der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $vapid['public'];
$pub_pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $pub_pem_der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
$ok = openssl_verify( $signing, $der, openssl_pkey_get_public( $pub_pem ), OPENSSL_ALGO_SHA256 );
crw_check( 'VAPID JWT signature verifies against the public key', 1 === $ok );

crw_group( 'Web Push: endpoint SSRF allowlist' );
crw_check( 'FCM host allowed', CRW_Push_Store::endpoint_allowed( 'https://fcm.googleapis.com/fcm/send/abc' ) );
crw_check( 'Mozilla push host allowed', CRW_Push_Store::endpoint_allowed( 'https://updates.push.services.mozilla.com/wpush/v2/xyz' ) );
crw_check( 'WNS host allowed', CRW_Push_Store::endpoint_allowed( 'https://db5p.notify.windows.com/w/?token=q' ) );
crw_check( 'Apple push host allowed', CRW_Push_Store::endpoint_allowed( 'https://api.push.apple.com/3/device/abc' ) );
crw_check( 'internal IP rejected', ! CRW_Push_Store::endpoint_allowed( 'https://169.254.169.254/latest/meta-data/' ) );
crw_check( 'localhost rejected', ! CRW_Push_Store::endpoint_allowed( 'https://127.0.0.1/admin' ) );
crw_check( 'http scheme rejected', ! CRW_Push_Store::endpoint_allowed( 'http://fcm.googleapis.com/x' ) );
crw_check( 'suffix-spoof host rejected', ! CRW_Push_Store::endpoint_allowed( 'https://fcm.googleapis.com.evil.test/x' ) );
crw_check( 'lookalike mozilla rejected', ! CRW_Push_Store::endpoint_allowed( 'https://evilpush.services.mozilla.com.attacker.test/x' ) );

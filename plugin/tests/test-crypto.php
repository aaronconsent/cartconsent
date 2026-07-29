<?php
/**
 * Email encryption at rest + hashing/masking.
 *
 * @package ConsentResolveWoo
 */

require_once __DIR__ . '/bootstrap.php';
$C = 'CRW_Crypto';

crw_group( 'Crypto: at-rest email encryption' );
crw_check( 'libsodium available', $C::available() );
if ( $C::available() ) {
	$enc = $C::encrypt( 'Jane@Example.com' );
	crw_check( 'ciphertext hides the plaintext', '' !== $enc && false === strpos( $enc, 'Jane' ) );
	crw_check( 'decrypt round-trips exactly', 'Jane@Example.com' === $C::decrypt( $enc ) );
	crw_check( 'nonce randomizes each ciphertext', $C::encrypt( 'a@b.com' ) !== $C::encrypt( 'a@b.com' ) );
	crw_check( 'both still decrypt', 'a@b.com' === $C::decrypt( $C::encrypt( 'a@b.com' ) ) );
	crw_check( 'garbage input → empty (no fatal)', '' === $C::decrypt( 'not-valid-base64-@@@' ) );
	crw_check( 'empty input → empty', '' === $C::encrypt( '' ) && '' === $C::decrypt( '' ) );
}

crw_group( 'Crypto: hashing + masking' );
crw_check( 'hash normalized (case/space)', $C::email_hash( 'A@B.com' ) === $C::email_hash( ' a@b.com ' ) );
crw_check( 'hash differs per email', $C::email_hash( 'a@b.com' ) !== $C::email_hash( 'c@d.com' ) );
crw_check( 'hash is 64-char hex', 1 === preg_match( '/^[0-9a-f]{64}$/', $C::email_hash( 'a@b.com' ) ) );
crw_check( 'empty email → empty hash', '' === $C::email_hash( '' ) );
crw_check( 'mask hides the local part', 'ja***@example.com' === $C::mask( 'jane@example.com' ) );
crw_check( 'mask of non-email → empty', '' === $C::mask( 'not-an-email' ) );

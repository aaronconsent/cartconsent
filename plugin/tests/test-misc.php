<?php
/**
 * Unsubscribe tokens, mailer merge, options defaults.
 *
 * @package ConsentResolveWoo
 */

require_once __DIR__ . '/bootstrap.php';

crw_group( 'Unsubscribe: signed one-click links' );
$U  = 'CRW_Unsubscribe';
$t1 = $U::token( 'jane@example.com' );
$t2 = $U::token( 'JANE@example.com ' );
$t3 = $U::token( 'other@example.com' );
crw_check( 'token normalized (case/space)', $t1 === $t2 );
crw_check( 'token differs per email', $t1 !== $t3 );
crw_check( 'token is 64-hex', 1 === preg_match( '/^[0-9a-f]{64}$/', $t1 ) );
crw_check( 'link carries the token', false !== strpos( $U::link( 'jane@example.com' ), $t1 ) );
crw_check( 'link carries NO raw email (privacy)', false === strpos( $U::link( 'jane@example.com' ), 'jane@example.com' ) );
crw_check( 'link carries the one-way email hash', false !== strpos( $U::link( 'jane@example.com' ), CRW_Crypto::email_hash( 'jane@example.com' ) ) );

crw_group( 'Mailer: literal merge (no $-backreference mangling)' );
$m = new ReflectionMethod( 'CRW_Mailer', 'merge' );
$m->setAccessible( true );
$out = $m->invoke( null, 'Hi {first_name}, from {store_name}', array( '{first_name}' => 'Sam', '{store_name}' => 'Save $5 Shop \\ Co' ) );
crw_check( '{first_name} merged', false !== strpos( $out, 'Sam' ) );
crw_check( 'literal $5 + backslash preserved', false !== strpos( $out, 'Save $5 Shop \\ Co' ) );
$out2 = $m->invoke( null, 'Hello {unknown_tag}!', array( '{first_name}' => 'x' ) );
crw_check( 'unresolved tag is stripped', false === strpos( $out2, '{unknown_tag}' ) );

crw_group( 'Options: defaults + deep merge' );
crw_set();
crw_check( 'default basis = jurisdiction', 'jurisdiction' === CRW_Options::get( 'consent.basis' ) );
crw_check( 'ships one sequence with 3 steps', 1 === count( CRW_Options::sequences() ) && 3 === count( CRW_Options::sequences()[0]['steps'] ) );
crw_check( 'default sequence is a catch-all', array() === CRW_Options::sequences()[0]['segment'] );
crw_check( 'capture on by default', true === CRW_Options::get( 'capture.enabled' ) );
crw_set( array( 'capture.abandon_after_minutes' => 45 ) );
crw_check( 'override applies', 45 === CRW_Options::get( 'capture.abandon_after_minutes' ) );
crw_check( 'sibling default survives', 'jurisdiction' === CRW_Options::get( 'consent.basis' ) );
crw_check( 'unknown path → default', 'fallback' === CRW_Options::get( 'nope.nope', 'fallback' ) );

crw_group( 'Popup: double-opt-in confirm token' );
$P  = 'CRW_Popup';
$h1 = CRW_Crypto::email_hash( 'jane@example.com' );
crw_check( 'confirm token is deterministic per hash', $P::token( $h1 ) === $P::token( $h1 ) );
crw_check( 'confirm token differs from unsubscribe token', $P::token( $h1 ) !== CRW_Unsubscribe::token( 'jane@example.com' ) );
crw_check( 'confirm link carries the hash, not the raw email', false === strpos( $P::confirm_link( 'jane@example.com' ), 'jane@example.com' ) && false !== strpos( $P::confirm_link( 'jane@example.com' ), $h1 ) );

crw_group( 'Recovery: coupon label' );
crw_set( array( 'coupon.type' => 'percent', 'coupon.amount' => 10 ) );
crw_check( 'percent label', '10%' === CRW_Recovery::coupon_label() );
crw_set( array( 'coupon.type' => 'fixed_cart', 'coupon.amount' => 15 ) );
crw_check( 'fixed label uses currency symbol', '$15' === CRW_Recovery::coupon_label() );

crw_group( 'Lost-revenue estimate math' );
$e = CRW_Estimates::estimate_from( 200, 900000, 0.20, 0.28 );
crw_check( 'resolvable = anon x res rate (200 x 0.20 = 40)', 40 === $e['resolvable'] );
crw_check( 'recoverable = value x res x rec (9000 x .2 x .28 = $504)', 50400 === $e['recoverable_cents'] );
crw_check( 'factors echoed back for the UI', 0.20 === $e['res_rate'] && 0.28 === $e['rec_rate'] && 200 === $e['anon_count'] );
$z = CRW_Estimates::estimate_from( 0, 0, 0.20, 0.10 );
crw_check( 'zero pool -> zero estimate', 0 === $z['resolvable'] && 0 === $z['recoverable_cents'] );
$r = CRW_Estimates::estimate_from( 10, 12345, 0.20, 0.10, false );
crw_check( 'fallback recovery rate flagged unmeasured', false === $r['rec_measured'] );

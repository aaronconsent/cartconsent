<?php
/**
 * The consent decision table — the product's core logic.
 *
 * @package ConsentResolveWoo
 */

require_once __DIR__ . '/bootstrap.php';
$C = 'CRW_Recovery_Consent';

crw_group( 'Consent: jurisdiction basis, US (opt-out) region' );
crw_set( array( 'consent.basis' => 'jurisdiction', 'banner.region' => 'us' ) );
crw_check( 'US · no opt-in · no GPC → soft opt-in', 'legitimate' === $C::resolve_basis( false, false ) );
crw_check( 'US · opt-in → optin', 'optin' === $C::resolve_basis( true, false ) );
crw_check( 'US · no opt-in · GPC → none (GPC suppresses soft opt-in)', 'none' === $C::resolve_basis( false, true ) );
crw_check( 'US · opt-in · GPC → optin (explicit survives GPC for email)', 'optin' === $C::resolve_basis( true, true ) );
crw_check( 'soft opt-in may email', $C::can_email( 'legitimate' ) );
crw_check( 'none may not email', ! $C::can_email( 'none' ) );

crw_group( 'Consent: jurisdiction basis, EU (opt-in) region' );
crw_set( array( 'consent.basis' => 'jurisdiction', 'banner.region' => 'eu' ) );
crw_check( 'EU · no opt-in → none (explicit required)', 'none' === $C::resolve_basis( false, false ) );
crw_check( 'EU · opt-in → optin', 'optin' === $C::resolve_basis( true, false ) );

crw_group( 'Consent: strict + aggressive modes' );
crw_set( array( 'consent.basis' => 'optin_only' ) );
crw_check( 'optin_only · no opt-in → none', 'none' === $C::resolve_basis( false, false ) );
crw_check( 'optin_only · opt-in → optin', 'optin' === $C::resolve_basis( true, false ) );
crw_set( array( 'consent.basis' => 'all_unsub' ) );
crw_check( 'all_unsub · no opt-in → soft opt-in', 'legitimate' === $C::resolve_basis( false, false ) );
crw_check( 'all_unsub · GPC → none', 'none' === $C::resolve_basis( false, true ) );

crw_group( 'Consent: audiences need explicit opt-in AND no GPC' );
crw_check( 'optin · no GPC → audience OK', $C::can_audience( 'optin', false ) );
crw_check( 'optin · GPC → no audience', ! $C::can_audience( 'optin', true ) );
crw_check( 'soft opt-in → never audience', ! $C::can_audience( 'legitimate', false ) );

crw_group( 'Consent: GPC signal + region fallback' );
crw_set();
crw_check( 'no Sec-GPC header → gpc() false', ! $C::gpc() );
$_SERVER['HTTP_SEC_GPC'] = '1';
crw_check( 'Sec-GPC:1 → gpc() true', $C::gpc() );
crw_set( array( 'consent.honor_gpc' => false ) );
$_SERVER['HTTP_SEC_GPC'] = '1';
crw_check( 'honor_gpc off → gpc() false', ! $C::gpc() );
crw_set( array( 'banner.region' => 'us' ) );
crw_check( 'region_model → opt_out (US region)', 'opt_out' === $C::region_model() );
crw_set( array( 'banner.region' => 'eu' ) );
crw_check( 'region_model → opt_in (EU region)', 'opt_in' === $C::region_model() );

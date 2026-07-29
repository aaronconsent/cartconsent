<?php
/**
 * Segmentation — which cart matches which sequence.
 *
 * @package ConsentResolveWoo
 */

require_once __DIR__ . '/bootstrap.php';
$S = 'CRW_Segments';

$row = array(
	'total_cents' => 5000, // $50
	'region'      => 'US',
	'items'       => json_encode( array( array( 'product_id' => 42 ), array( 'product_id' => 7 ) ) ),
);

crw_group( 'Segments: matching' );
crw_check( 'empty segment matches everything', $S::matches( $row, array() ) );
crw_check( 'min_total above cart → no match', ! $S::matches( $row, array( 'min_total' => 100 ) ) );
crw_check( 'min_total below cart → match', $S::matches( $row, array( 'min_total' => 20 ) ) );
crw_check( 'max_total below cart → no match', ! $S::matches( $row, array( 'max_total' => 40 ) ) );
crw_check( 'within range → match', $S::matches( $row, array( 'min_total' => 20, 'max_total' => 80 ) ) );

crw_group( 'Segments: country + product' );
crw_check( 'country US in [US] → match', $S::matches( $row, array( 'countries' => 'US, CA' ) ) );
crw_check( 'country US not in [DE] → no match', ! $S::matches( $row, array( 'countries' => 'DE' ) ) );
crw_check( 'no region + country filter → no match', ! $S::matches( array( 'total_cents' => 100, 'region' => '', 'items' => '[]' ), array( 'countries' => 'US' ) ) );
crw_check( 'product 42 present → match', $S::matches( $row, array( 'products' => '42' ) ) );
crw_check( 'product 99 absent → no match', ! $S::matches( $row, array( 'products' => '99' ) ) );
crw_check( 'product 7 or 99 (one present) → match', $S::matches( $row, array( 'products' => '99, 7' ) ) );

crw_group( 'Segments: sequence routing' );
crw_set();
crw_check( 'default catch-all sequence matches', 'default' === $S::first_matching( $row ) );

crw_group( 'Segments: stepless sequences are skipped (regression)' );
// An enabled but stepless sequence must NOT capture a cart and dead-end it as
// 'lost' — it should fall through to a later matching sequence that has steps.
crw_set( array( 'emails.sequences' => array(
	array( 'id' => 'empty', 'name' => 'Empty', 'enabled' => true, 'segment' => array(), 'steps' => array() ),
	array( 'id' => 'real',  'name' => 'Real',  'enabled' => true, 'segment' => array(), 'steps' => array( array( 'delay_minutes' => 60, 'subjects' => array( 'x' ), 'body' => 'b' ) ) ),
) ) );
crw_check( 'stepless enabled sequence skipped → falls through to real one', 'real' === $S::first_matching( $row ) );

crw_set( array( 'emails.sequences' => array(
	array( 'id' => 'empty', 'name' => 'Empty', 'enabled' => true, 'segment' => array(), 'steps' => array() ),
) ) );
crw_check( 'only a stepless sequence → no match (cart not dead-ended)', '' === $S::first_matching( $row ) );
crw_set();

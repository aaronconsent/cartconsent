<?php
/**
 * Test runner. Usage:  php tests/run.php
 *
 * @package ConsentResolveWoo
 */

require __DIR__ . '/bootstrap.php';

foreach ( glob( __DIR__ . '/test-*.php' ) as $file ) {
	require $file;
}

echo "\n" . $GLOBALS['crw_pass'] . ' passed, ' . $GLOBALS['crw_fail'] . " failed\n";
exit( $GLOBALS['crw_fail'] ? 1 : 0 );

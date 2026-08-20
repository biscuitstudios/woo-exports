<?php
/**
 * Diff two baseline.php snapshots. Exits non-zero if any range moved.
 *
 *   php tests/integration/compare.php before.json after.json
 *
 * Standalone: does not boot WordPress, so it runs anywhere.
 *
 * @package WooExports
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$before_path = $argv[1] ?? '';
$after_path  = $argv[2] ?? '';

foreach ( [ $before_path, $after_path ] as $path ) {
	if ( '' === $path || ! is_readable( $path ) ) {
		fwrite( STDERR, "Usage: php compare.php <before.json> <after.json>\n" );
		exit( 2 );
	}
}

$before = json_decode( (string) file_get_contents( $before_path ), true );
$after  = json_decode( (string) file_get_contents( $after_path ), true );

if ( ! is_array( $before ) || ! is_array( $after ) ) {
	fwrite( STDERR, "One of the snapshots is not valid JSON.\n" );
	exit( 2 );
}

$changes = 0;

foreach ( $before['ranges'] ?? [] as $range => $was ) {
	$now = $after['ranges'][ $range ] ?? null;

	if ( null === $now ) {
		printf( "  MISSING  %s is absent from the after snapshot\n", $range );
		$changes++;
		continue;
	}

	if ( $was['count'] === $now['count'] && $was['labels'] === $now['labels'] ) {
		continue;
	}

	$changes++;
	printf( "  CHANGED  %s: %d -> %d\n", $range, $was['count'], $now['count'] );
	printf( "           was %s .. %s\n", $was['window'][0], $was['window'][1] );
	printf( "           now %s .. %s\n", $now['window'][0], $now['window'][1] );

	foreach ( array_diff( $was['labels'], $now['labels'] ) as $gone ) {
		printf( "           dropped: %s\n", $gone );
	}
	foreach ( array_diff( $now['labels'], $was['labels'] ) as $added ) {
		printf( "           added:   %s\n", $added );
	}
}

foreach ( array_diff_key( $after['ranges'] ?? [], $before['ranges'] ?? [] ) as $range => $_ ) {
	printf( "  NEW      %s is not in the before snapshot\n", $range );
	$changes++;
}

if ( 0 === $changes ) {
	printf( "  CLEAN    all %d ranges identical\n", count( $before['ranges'] ?? [] ) );
	exit( 0 );
}

printf( "\n  %d range(s) changed.\n", $changes );
exit( 1 );

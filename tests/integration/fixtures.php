<?php
/**
 * Boundary-order fixtures for the day-start work.
 *
 * Creates throwaway orders at the instants where a day-precision and a
 * second-precision date query actually differ: either side of midnight, either
 * side of a 19:00 cutoff, either side of month, year and week rollovers, and
 * either side of both 2026 daylight-saving transitions in America/New_York.
 *
 * Real order data almost never lands on 23:59:59 or exactly midnight, which is
 * why this is a purpose-built fixture rather than a production replica. Nothing
 * else in the suite can catch a regression to whole-day precision.
 *
 * Every order carries _wooex_fixture = 1 so teardown is exact.
 *
 *   php tests/integration/fixtures.php seed
 *   php tests/integration/fixtures.php teardown
 *   php tests/integration/fixtures.php count
 *
 * @package WooExports
 */

require_once __DIR__ . '/bootstrap.php';

const WOOEX_IT_FIXTURE_META = '_wooex_fixture';

/** Site-local wall clock to a UTC timestamp. */
function wooex_it_ts( string $wall ): int {
	return ( new DateTimeImmutable( $wall, wp_timezone() ) )->getTimestamp();
}

/**
 * The fixture set: [ site-local wall clock, status, label ].
 *
 * Labels are stored on the order so a failing baseline diff names the boundary
 * that moved rather than printing an order ID nobody can place.
 *
 * The dates are fixed rather than relative to today. That keeps the set
 * reproducible, and the ranges that matter most (today, yesterday) are covered
 * by verify-cutoff.php, which pins the clock explicitly.
 */
function wooex_it_fixture_spec(): array {
	return [
		// Plain midnight rollover.
		[ '2026-08-18 23:59:58', 'completed',  'midnight -2s' ],
		[ '2026-08-18 23:59:59', 'completed',  'midnight -1s' ],
		[ '2026-08-19 00:00:00', 'completed',  'midnight +0s' ],
		[ '2026-08-19 00:00:01', 'processing', 'midnight +1s' ],

		// The 19:00 cutoff, the boundary this feature introduces.
		[ '2026-08-19 18:59:58', 'completed',  'cutoff Aug19 -2s' ],
		[ '2026-08-19 18:59:59', 'completed',  'cutoff Aug19 -1s' ],
		[ '2026-08-19 19:00:00', 'completed',  'cutoff Aug19 +0s' ],
		[ '2026-08-19 19:00:01', 'processing', 'cutoff Aug19 +1s' ],
		[ '2026-08-20 18:59:59', 'completed',  'cutoff Aug20 -1s' ],
		[ '2026-08-20 19:00:00', 'completed',  'cutoff Aug20 +0s' ],

		// Month rollover.
		[ '2026-07-31 23:59:59', 'completed',  'month end Jul' ],
		[ '2026-08-01 00:00:00', 'completed',  'month start Aug' ],
		[ '2026-07-15 12:00:00', 'processing', 'mid last_month' ],

		// Year rollover.
		[ '2025-12-31 23:59:59', 'completed',  'year end 2025' ],
		[ '2026-01-01 00:00:00', 'completed',  'year start 2026' ],

		// Week rollover.
		[ '2026-08-15 23:59:59', 'completed',  'week end Sat' ],
		[ '2026-08-16 00:00:00', 'completed',  'week start Sun' ],
		[ '2026-08-17 12:00:00', 'processing', 'mid this_week' ],

		// DST spring forward: 2026-03-08, 02:00 EST jumps to 03:00 EDT.
		[ '2026-03-07 19:00:00', 'completed',  'DST spring, cutoff before' ],
		[ '2026-03-08 01:59:59', 'completed',  'DST spring, last EST second' ],
		[ '2026-03-08 03:00:00', 'completed',  'DST spring, first EDT second' ],
		[ '2026-03-08 19:00:00', 'completed',  'DST spring, cutoff after' ],

		// DST fall back: 2026-11-01, 02:00 EDT returns to 01:00 EST.
		// 01:30 occurs twice; PHP resolves the ambiguity to the first (EDT).
		[ '2026-10-31 19:00:00', 'completed',  'DST fall, cutoff before' ],
		[ '2026-11-01 00:59:59', 'completed',  'DST fall, before repeat hour' ],
		[ '2026-11-01 01:30:00', 'completed',  'DST fall, ambiguous hour' ],
		[ '2026-11-01 19:00:00', 'completed',  'DST fall, cutoff after' ],

		// Inside the fixed custom-range window used by baseline.php.
		[ '2026-05-13 12:00:00', 'completed',  'mid custom_week' ],

		// Statuses outside the default filter, to prove status filtering is
		// unaffected by anything the date work changes.
		[ '2026-08-19 12:00:00', 'failed',     'excluded status: failed' ],
		[ '2026-08-19 13:00:00', 'cancelled',  'excluded status: cancelled' ],
	];
}

/**
 * Fixture order IDs.
 *
 * Deliberately does NOT use wc_get_orders()'s meta_query. That argument is
 * silently ignored on WooCommerce 11.0.1 legacy post storage: it does not
 * filter and does not error, so every order comes back. An earlier version of
 * this script used it and reported 9 pre-existing real orders as fixtures. Had
 * the teardown trusted that result alone it would have deleted them.
 *
 * Read every order and check the meta directly. Slower, correct.
 */
function wooex_it_fixture_ids(): array {
	$out = [];
	foreach ( wc_get_orders( [ 'limit' => -1, 'return' => 'ids', 'status' => 'any' ] ) as $id ) {
		$order = wc_get_order( $id );
		if ( $order && '1' === (string) $order->get_meta( WOOEX_IT_FIXTURE_META ) ) {
			$out[] = $id;
		}
	}
	return $out;
}

$command = $argv[1] ?? 'count';

if ( 'count' === $command ) {
	$ids = wooex_it_fixture_ids();
	wooex_it_emit( [ 'fixture_orders' => count( $ids ), 'ids' => $ids ] );
}

if ( 'teardown' === $command ) {
	wooex_it_require_dev_site();

	$deleted = 0;
	foreach ( wooex_it_fixture_ids() as $id ) {
		$order = wc_get_order( $id );
		// Re-check immediately before deleting. The list above is already
		// scoped, and this still earns its place: it is the check that caught
		// the meta_query bug, and it is all that stands between a bad query and
		// somebody's real orders.
		if ( ! $order || '1' !== (string) $order->get_meta( WOOEX_IT_FIXTURE_META ) ) {
			continue;
		}
		$order->delete( true );
		$deleted++;
	}

	wooex_it_emit( [ 'deleted' => $deleted, 'remaining' => count( wooex_it_fixture_ids() ) ] );
}

if ( 'seed' !== $command ) {
	fwrite( STDERR, "Usage: php tests/integration/fixtures.php [seed|teardown|count]\n" );
	exit( 1 );
}

wooex_it_require_dev_site();

$existing = wooex_it_fixture_ids();
if ( ! empty( $existing ) ) {
	fwrite( STDERR, sprintf( "Refusing to seed: %d fixture orders already present. Run teardown first.\n", count( $existing ) ) );
	exit( 1 );
}

$created = [];
foreach ( wooex_it_fixture_spec() as [ $wall, $status, $label ] ) {
	$order = wc_create_order();
	$order->set_date_created( wooex_it_ts( $wall ) );
	$order->set_status( $status );
	$order->set_total( 16.34 );
	$order->update_meta_data( WOOEX_IT_FIXTURE_META, '1' );
	$order->update_meta_data( '_wooex_fixture_label', $label );
	$order->update_meta_data( '_wooex_fixture_wall', $wall );
	$order->save();

	$created[] = [
		'id'     => $order->get_id(),
		'label'  => $label,
		'wanted' => $wall,
		// Read back what WooCommerce actually stored. set_date_created() going
		// in is not proof of what came out, and the DST rows are exactly where
		// that could bite.
		'stored' => $order->get_date_created()
			? $order->get_date_created()->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s T' )
			: null,
		'status' => $order->get_status(),
	];
}

$mismatched = array_values(
	array_filter( $created, static fn( $r ) => $r['wanted'] !== substr( (string) $r['stored'], 0, 19 ) )
);

wooex_it_emit(
	[
		'created'    => count( $created ),
		'timezone'   => wp_timezone_string(),
		'mismatched' => $mismatched,
		'orders'     => $created,
	],
	empty( $mismatched ) ? 0 : 1
);

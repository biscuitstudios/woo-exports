<?php
/**
 * End-to-end proof that a day-start boundary survives the real WooCommerce
 * query.
 *
 * The unit tests prove resolve_dates() computes the right instants. They cannot
 * prove WooCommerce honours them. Before 0.10.0 the time component of a
 * `date_created` range was silently discarded and the query ran at whole-day
 * precision, so a correct resolver still produced a wrong export. That is the
 * failure this script exists to catch, and it can only be caught against a
 * database.
 *
 * The fixtures sit one second either side of the boundary, so a regression to
 * day precision cannot produce the expected set by luck.
 *
 *   php tests/integration/verify-cutoff.php
 *
 * Read-only. Exits non-zero if any case fails.
 *
 * @package WooExports
 */

require_once __DIR__ . '/bootstrap.php';

$statuses = [ 'wc-completed', 'wc-processing' ];

// Pinned so the expected sets are fixed. This is the instant the client's
// nightly 9pm export would run.
$now = ( new DateTimeImmutable( '2026-08-20 21:00:00', wp_timezone() ) )->getTimestamp();

$cases = [
	// The report this feature was built for.
	'yesterday @ 19:00' => [
		'filters'  => [ 'date_range' => 'yesterday', 'day_start' => '19:00' ],
		'expected' => [ 'cutoff Aug19 +0s', 'cutoff Aug19 +1s', 'cutoff Aug20 -1s' ],
	],
	// The same range with the default boundary, for contrast.
	'yesterday @ 00:00' => [
		'filters'  => [ 'date_range' => 'yesterday', 'day_start' => '00:00' ],
		'expected' => [ 'cutoff Aug19 +0s', 'cutoff Aug19 +1s', 'cutoff Aug19 -1s', 'cutoff Aug19 -2s', 'midnight +0s', 'midnight +1s' ],
	],
	'today @ 19:00' => [
		'filters'  => [ 'date_range' => 'today', 'day_start' => '19:00' ],
		'expected' => [ 'cutoff Aug20 +0s' ],
	],
	// A one-off pull of the same window, which is how a missed night is re-run.
	'custom Aug19 @ 19:00' => [
		'filters'  => [ 'date_range' => 'custom', 'date_from' => '2026-08-19', 'date_to' => '2026-08-19', 'day_start' => '19:00' ],
		'expected' => [ 'cutoff Aug19 +0s', 'cutoff Aug19 +1s', 'cutoff Aug20 -1s' ],
	],
	// Calendar periods must ignore the boundary entirely: July is July.
	'last_month @ 19:00' => [
		'filters'  => [ 'date_range' => 'last_month', 'day_start' => '19:00' ],
		'expected' => [ 'mid last_month', 'month end Jul' ],
	],
	'last_month @ 00:00' => [
		'filters'  => [ 'date_range' => 'last_month', 'day_start' => '00:00' ],
		'expected' => [ 'mid last_month', 'month end Jul' ],
	],
];

$results  = [];
$failures = 0;

foreach ( $cases as $label => $case ) {
	$filters = $case['filters'] + [ 'statuses' => $statuses ];
	$dates   = Wooex_Data_Orders::resolve_dates( $filters, $now );

	$args = [ 'limit' => -1, 'return' => 'ids', 'status' => $statuses ];
	$date_created = Wooex_Data_Orders::date_created_arg( $dates );
	if ( null !== $date_created ) {
		$args['date_created'] = $date_created;
	}

	$got = [];
	foreach ( wc_get_orders( $args ) as $id ) {
		$order = wc_get_order( $id );
		if ( ! $order ) {
			continue;
		}
		$l     = (string) $order->get_meta( '_wooex_fixture_label' );
		$got[] = '' !== $l ? $l : ( 'pre-existing #' . $id );
	}
	sort( $got );

	$expected = $case['expected'];
	sort( $expected );

	$pass = ( $got === $expected );
	if ( ! $pass ) {
		$failures++;
	}

	$results[ $label ] = [
		'pass'         => $pass,
		'date_created' => $date_created,
		'window'       => [
			wp_date( 'Y-m-d H:i:s T', (int) $dates['from'] ),
			wp_date( 'Y-m-d H:i:s T', (int) $dates['to'] ),
		],
		'expected'     => $expected,
		'got'          => $got,
	];
}

wooex_it_emit(
	[
		'pinned_now' => wp_date( 'Y-m-d H:i:s T', $now ),
		'hpos'       => class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
		'failures'   => $failures,
		'cases'      => $results,
	],
	$failures > 0 ? 1 : 0
);

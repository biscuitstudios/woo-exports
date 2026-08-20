<?php
/**
 * Capture what every date range actually returns, as a comparable snapshot.
 *
 * Run once before a change to the date logic and once after, then diff with
 * compare.php. Any difference at day_start 00:00 is a regression.
 *
 *   php tests/integration/baseline.php > before.json
 *
 * Read-only. Meaningless without fixtures.php having been seeded first.
 *
 * @package WooExports
 */

require_once __DIR__ . '/bootstrap.php';

$statuses = [ 'wc-completed', 'wc-processing' ];

/**
 * Run one query and record what came back.
 *
 * Records fixture LABELS, not just a count. A count alone cannot distinguish
 * "the window moved by a second" from "a different order happened to fall in",
 * and naming the boundary is the entire reason the fixtures sit on exact
 * seconds.
 */
function wooex_it_snapshot( array $filters, array $statuses ): array {
	$dates = Wooex_Data_Orders::resolve_dates( $filters );

	$args = [ 'limit' => -1, 'return' => 'ids', 'status' => $statuses ];
	$date_created = Wooex_Data_Orders::date_created_arg( $dates );
	if ( null !== $date_created ) {
		$args['date_created'] = $date_created;
	}

	$labels = [];
	foreach ( wc_get_orders( $args ) as $id ) {
		$order = wc_get_order( $id );
		if ( ! $order ) {
			continue;
		}
		$label    = (string) $order->get_meta( '_wooex_fixture_label' );
		$labels[] = '' !== $label ? $label : ( 'pre-existing #' . $id );
	}
	sort( $labels );

	return [
		'window' => [
			is_int( $dates['from'] ) ? wp_date( 'Y-m-d H:i:s T', $dates['from'] ) : '',
			is_int( $dates['to'] ) ? wp_date( 'Y-m-d H:i:s T', $dates['to'] ) : '',
		],
		'count'  => count( $labels ),
		'labels' => $labels,
	];
}

$out = [
	'site_timezone' => wp_timezone_string(),
	'start_of_week' => (int) get_option( 'start_of_week' ),
	'wc_version'    => defined( 'WC_VERSION' ) ? WC_VERSION : '?',
	'hpos'          => class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
		&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
	'total_orders'  => count( wc_get_orders( [ 'limit' => -1, 'return' => 'ids', 'status' => 'any' ] ) ),
	'ranges'        => [],
];

foreach ( [ 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year', 'all_time' ] as $range ) {
	$out['ranges'][ $range ] = wooex_it_snapshot( [ 'date_range' => $range ], $statuses );
}

foreach ( [
	'custom_week'    => [ '2026-05-10', '2026-05-16' ],
	'custom_single'  => [ '2026-08-19', '2026-08-19' ],
	'custom_two_day' => [ '2026-08-19', '2026-08-20' ],
] as $label => [ $from, $to ] ) {
	$out['ranges'][ $label ] = wooex_it_snapshot(
		[ 'date_range' => 'custom', 'date_from' => $from, 'date_to' => $to ],
		$statuses
	);
}

wooex_it_emit( $out );

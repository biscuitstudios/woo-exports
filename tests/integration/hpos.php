<?php
/**
 * Toggle HPOS, so the baseline can cover both order-query paths.
 *
 * The date-precision behaviour under test lives in two different places:
 *   legacy -> WC_Data_Store_WP::parse_date_for_wp_query()
 *   HPOS   -> OrdersTableQuery::date_to_date_query_arg()
 * They agree today. Nothing guarantees they keep agreeing after a change, so
 * the baseline has to run against both.
 *
 *   php tests/integration/hpos.php status
 *   php tests/integration/hpos.php on
 *   php tests/integration/hpos.php off
 *
 * `off` restores all three options, not just the authoritative one. Leaving any
 * of them flipped quietly changes how the site behaves afterwards.
 *
 * @package WooExports
 */

require_once __DIR__ . '/bootstrap.php';

use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! class_exists( DataSynchronizer::class ) ) {
	fwrite( STDERR, "This WooCommerce version has no HPOS DataSynchronizer.\n" );
	exit( 1 );
}

function wooex_it_hpos_status(): array {
	$sync = wc_get_container()->get( DataSynchronizer::class );
	return [
		'feature_enabled' => get_option( 'woocommerce_feature_custom_order_tables_enabled' ),
		'authoritative'   => get_option( 'woocommerce_custom_orders_table_enabled' ),
		'sync_enabled'    => get_option( DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION ),
		'tables_exist'    => $sync->check_orders_table_exists(),
		'pending_sync'    => $sync->get_sync_status(),
		'hpos_in_use'     => OrderUtil::custom_orders_table_usage_is_enabled(),
	];
}

$command = $argv[1] ?? 'status';

if ( 'status' === $command ) {
	wooex_it_emit( wooex_it_hpos_status() );
}

wooex_it_require_dev_site();

if ( 'off' === $command ) {
	update_option( 'woocommerce_custom_orders_table_enabled', 'no' );
	update_option( DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, 'no' );
	update_option( 'woocommerce_feature_custom_order_tables_enabled', 'no' );
	wooex_it_emit( [ 'switched' => 'off', 'status' => wooex_it_hpos_status() ] );
}

if ( 'on' !== $command ) {
	fwrite( STDERR, "Usage: php tests/integration/hpos.php [status|on|off]\n" );
	exit( 1 );
}

$sync = wc_get_container()->get( DataSynchronizer::class );

update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );

if ( ! $sync->check_orders_table_exists() ) {
	$sync->create_database_tables();
}
if ( ! $sync->check_orders_table_exists() ) {
	fwrite( STDERR, "HPOS tables could not be created.\n" );
	exit( 1 );
}

// Drain the sync queue in-process rather than waiting on Action Scheduler.
// Bounded, so a bad state cannot spin forever.
$rounds = 0;
while ( $rounds++ < 200 ) {
	$batch = $sync->get_next_batch_to_process( 50 );
	if ( empty( $batch ) ) {
		break;
	}
	$sync->process_batch( $batch );
}

$pending = $sync->get_sync_status();
if ( ! empty( $pending['current_pending_count'] ) ) {
	fwrite( STDERR, 'Sync did not drain: ' . json_encode( $pending ) . "\n" );
	exit( 1 );
}

// Only make HPOS authoritative once every order is actually in the new tables.
update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
update_option( DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, 'yes' );

wooex_it_emit( [ 'switched' => 'on', 'rounds' => $rounds, 'status' => wooex_it_hpos_status() ] );

<?php
/**
 * WooCommerce customers query layer with order history aggregates.
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wooex_Data_Customers {

	public static function get( array $filters, ?int $now = null ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$dates = Wooex_Data_Orders::resolve_dates( $filters, $now );

		$order_args = [
			'limit'  => -1,
			'return' => 'objects',
		];
		$date_created = Wooex_Data_Orders::date_created_arg( $dates );
		if ( null !== $date_created ) {
			$order_args['date_created'] = $date_created;
		}

		$statuses = Wooex_Data_Orders::normalize_statuses( $filters['statuses'] ?? [] );
		if ( ! empty( $statuses ) ) {
			$order_args['status'] = $statuses;
		}

		$customer_ids = Wooex_Data_Products::clean_ids( $filters['customer_ids'] ?? [] );
		if ( ! empty( $customer_ids ) ) {
			$order_args['customer_id'] = $customer_ids;
		}

		$orders = wc_get_orders( $order_args );

		$customers = [];
		$guests    = [];

		foreach ( $orders as $order ) {
			$cid = (int) $order->get_customer_id();

			if ( $cid > 0 ) {
				$customers[ $cid ] = true;
				continue;
			}

			$email = $order->get_billing_email();
			if ( ! $email ) {
				continue;
			}

			if ( ! isset( $guests[ $email ] ) ) {
				$guests[ $email ] = self::format_guest_row( $order, $email );
			} else {
				self::merge_guest_order( $guests[ $email ], $order );
			}
		}

		$rows = [];

		foreach ( array_keys( $customers ) as $cid ) {
			$row = self::format_customer_row( $cid );
			if ( $row ) {
				$rows[] = $row;
			}
		}

		$d_fmt = get_option( 'date_format' );
		foreach ( $guests as $row ) {
			$row['First Order Date'] = ! empty( $row['_first_ts'] ) ? wp_date( $d_fmt, (int) $row['_first_ts'] ) : '';
			$row['Last Order Date']  = ! empty( $row['_last_ts'] ) ? wp_date( $d_fmt, (int) $row['_last_ts'] ) : '';
			unset( $row['_first_ts'], $row['_last_ts'] );
			$rows[] = $row;
		}

		return $rows;
	}

	private static function format_customer_row( int $cid ): ?array {
		$user = get_user_by( 'id', $cid );
		if ( ! $user ) {
			return null;
		}

		$customer = new WC_Customer( $cid );

		$total_orders = function_exists( 'wc_get_customer_order_count' )
			? (int) wc_get_customer_order_count( $cid )
			: (int) $customer->get_order_count();

		$total_spent = function_exists( 'wc_get_customer_total_spent' )
			? (float) wc_get_customer_total_spent( $cid )
			: (float) $customer->get_total_spent();

		$avg = $total_orders > 0 ? ( $total_spent / $total_orders ) : 0;

		[ $first_date, $last_date ] = self::customer_date_range( $cid );

		return [
			'Customer ID'        => $cid,
			'First Name'         => $customer->get_first_name() ?: $user->first_name,
			'Last Name'          => $customer->get_last_name() ?: $user->last_name,
			'Email'              => $user->user_email,
			'Phone'              => $customer->get_billing_phone(),
			'City'               => $customer->get_billing_city(),
			'State'              => $customer->get_billing_state(),
			'Country'            => $customer->get_billing_country(),
			'Registration Date'  => $user->user_registered ? wp_date( get_option( 'date_format' ), strtotime( $user->user_registered . ' UTC' ) ) : '',
			'Total Orders'       => $total_orders,
			'Total Spent'        => wc_format_decimal( $total_spent, 2 ),
			'Average Order Value' => wc_format_decimal( $avg, 2 ),
			'First Order Date'   => $first_date,
			'Last Order Date'    => $last_date,
			'Customer Note'      => '',
		];
	}

	private static function customer_date_range( int $cid ): array {
		$orders = wc_get_orders(
			[
				'customer_id' => $cid,
				'limit'       => -1,
				'orderby'     => 'date',
				'order'       => 'ASC',
				'return'      => 'objects',
			]
		);

		if ( empty( $orders ) ) {
			return [ '', '' ];
		}

		$first = $orders[0]->get_date_created();
		$last  = end( $orders )->get_date_created();
		$d_fmt = get_option( 'date_format' );

		return [
			$first ? $first->date_i18n( $d_fmt ) : '',
			$last ? $last->date_i18n( $d_fmt ) : '',
		];
	}

	private static function format_guest_row( $order, string $email ): array {
		$created = $order->get_date_created();
		$ts      = $created ? $created->getTimestamp() : 0;
		$total   = (float) $order->get_total();

		return [
			'Customer ID'         => 0,
			'First Name'          => $order->get_billing_first_name(),
			'Last Name'           => $order->get_billing_last_name(),
			'Email'               => $email,
			'Phone'               => $order->get_billing_phone(),
			'City'                => $order->get_billing_city(),
			'State'               => $order->get_billing_state(),
			'Country'             => $order->get_billing_country(),
			'Registration Date'   => '',
			'Total Orders'        => 1,
			'Total Spent'         => wc_format_decimal( $total, 2 ),
			'Average Order Value' => wc_format_decimal( $total, 2 ),
			'First Order Date'    => '',
			'Last Order Date'     => '',
			'Customer Note'       => $order->get_customer_note(),
			'_first_ts'           => $ts,
			'_last_ts'            => $ts,
		];
	}

	private static function merge_guest_order( array &$row, $order ): void {
		$created = $order->get_date_created();
		$ts      = $created ? $created->getTimestamp() : 0;
		$total   = (float) $order->get_total();

		$row['Total Orders'] = (int) $row['Total Orders'] + 1;

		$running = (float) $row['Total Spent'] + $total;
		$row['Total Spent']         = wc_format_decimal( $running, 2 );
		$row['Average Order Value'] = wc_format_decimal( $running / $row['Total Orders'], 2 );

		if ( $ts > 0 ) {
			if ( empty( $row['_first_ts'] ) || $ts < $row['_first_ts'] ) {
				$row['_first_ts'] = $ts;
			}
			if ( empty( $row['_last_ts'] ) || $ts > $row['_last_ts'] ) {
				$row['_last_ts'] = $ts;
			}
		}
	}
}

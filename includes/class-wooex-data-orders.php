<?php
/**
 * WooCommerce orders query layer (HPOS-compatible).
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wooex_Data_Orders {

	public static function get( array $filters ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$args = self::build_query_args( $filters );
		if ( false === $args ) {
			return [];
		}

		// Stream by ID rather than materialising every WC_Order at once.
		// `return => 'objects'` would build the full $orders array in memory
		// before we touch it; with thousands of orders this OOMs PHP. Fetching
		// just IDs (lightweight ints) and loading one WC_Order per iteration
		// lets PHP garbage-collect each order before the next is hydrated.
		$args['return'] = 'ids';
		$order_ids      = wc_get_orders( $args );

		$rows    = [];
		$skipped = 0;
		foreach ( (array) $order_ids as $order_id ) {
			try {
				$order = wc_get_order( $order_id );
				// wc_get_orders() returns both WC_Order and OrderRefund objects.
				// OrderRefund extends WC_Abstract_Order but has no billing /
				// shipping methods — calling them throws. Filter to real orders.
				if ( ! $order || ! ( $order instanceof \WC_Order ) ) {
					$skipped++;
					continue;
				}
				$rows[] = self::format_row( $order );
				unset( $order ); // WC_Order has circular refs; help the GC release it.
			} catch ( \Throwable $e ) {
				$skipped++;
				error_log(
					sprintf(
						'[WooExports] Skipped order %s in row formatting: %s in %s:%d',
						$order_id,
						$e->getMessage(),
						$e->getFile(),
						$e->getLine()
					)
				);
			}
		}

		if ( $skipped > 0 ) {
			error_log( sprintf( '[WooExports] Orders query: %d order(s) skipped due to per-order errors. Total rows: %d.', $skipped, count( $rows ) ) );
		}

		return $rows;
	}

	/**
	 * Cheap "count + first N rows" loader for the preview pane. Uses
	 * wc_get_orders' built-in pagination so the database does a single
	 * COUNT(*) plus a LIMIT N fetch — peak memory stays at N WC_Order
	 * objects regardless of how many orders match. Use this instead of
	 * `get()` whenever you don't actually need every row.
	 *
	 * @return array{rows: array<int,array<string,mixed>>, total: int}
	 */
	public static function get_paginated( array $filters, int $limit ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [ 'rows' => [], 'total' => 0 ];
		}

		$args = self::build_query_args( $filters );
		if ( false === $args ) {
			return [ 'rows' => [], 'total' => 0 ];
		}

		$args['paginate'] = true;
		$args['limit']    = max( 1, $limit );
		$args['return']   = 'objects';

		$result = wc_get_orders( $args );
		// `paginate => true` returns a stdClass with ->orders, ->total, ->max_num_pages.
		// On failure or no match it can return an empty array — normalize both.
		$orders = is_object( $result ) ? (array) ( $result->orders ?? [] ) : [];
		$total  = is_object( $result ) ? (int) ( $result->total ?? 0 ) : 0;

		$rows = [];
		foreach ( $orders as $order ) {
			if ( ! ( $order instanceof \WC_Order ) ) {
				continue; // skip refunds, as in get()
			}
			try {
				$rows[] = self::format_row( $order );
			} catch ( \Throwable $e ) {
				error_log(
					sprintf(
						'[WooExports] Skipped order %d in preview: %s in %s:%d',
						$order->get_id(),
						$e->getMessage(),
						$e->getFile(),
						$e->getLine()
					)
				);
			}
		}

		return [ 'rows' => $rows, 'total' => $total ];
	}

	/**
	 * Builds the wc_get_orders args from $filters. Shared by both `get()` and
	 * `get_paginated()` so the two paths apply identical filtering. Returns
	 * false if a filter implies an empty result (no orders match the post__in
	 * intersection), in which case the caller should short-circuit.
	 *
	 * @return array<string,mixed>|false
	 */
	private static function build_query_args( array $filters ) {
		$dates = self::resolve_dates( $filters );

		$args = [
			'limit'   => -1,
			'orderby' => 'date',
			'order'   => 'DESC',
		];

		$args['status'] = self::normalize_statuses( $filters['statuses'] ?? [] );

		if ( ! empty( $dates['from'] ) && ! empty( $dates['to'] ) ) {
			$args['date_created'] = $dates['from'] . '...' . $dates['to'];
		}

		$customer_ids = Wooex_Data_Products::clean_ids( $filters['customer_ids'] ?? [] );
		if ( ! empty( $customer_ids ) ) {
			$args['customer_id'] = $customer_ids;
		}

		$parent_post_ids = Wooex_Data_Products::clean_ids( $filters['parent_post_ids'] ?? [] );
		if ( ! empty( $parent_post_ids ) ) {
			$attendee_order_ids = Wooex_Data_Attendees::order_ids_for_parents( $parent_post_ids );
			if ( empty( $attendee_order_ids ) ) {
				return false;
			}
			$args['post__in'] = $attendee_order_ids;
		}

		$product_filter = self::resolve_product_filter( $filters );
		if ( null !== $product_filter ) {
			$product_order_ids = self::order_ids_with_products( $product_filter );
			if ( empty( $product_order_ids ) ) {
				return false;
			}
			$args['post__in'] = isset( $args['post__in'] )
				? array_values( array_intersect( $args['post__in'], $product_order_ids ) )
				: $product_order_ids;
			if ( empty( $args['post__in'] ) ) {
				return false;
			}
		}

		return $args;
	}

	/**
	 * Return order IDs whose line items reference any of the given product IDs
	 * (matched against either the product ID or variation ID stored on the item).
	 */
	public static function order_ids_with_products( array $product_ids ): array {
		global $wpdb;

		$product_ids = array_values( array_unique( array_map( 'intval', $product_ids ) ) );
		$product_ids = array_filter( $product_ids );
		if ( empty( $product_ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		$sql = $wpdb->prepare(
			"SELECT DISTINCT i.order_id
			 FROM {$wpdb->prefix}woocommerce_order_items i
			 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta im
			   ON im.order_item_id = i.order_item_id
			 WHERE i.order_item_type = 'line_item'
			   AND im.meta_key IN ('_product_id', '_variation_id')
			   AND im.meta_value IN ($placeholders)",
			$product_ids
		);

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	public static function normalize_statuses( $statuses ): array {
		if ( ! is_array( $statuses ) || empty( $statuses ) ) {
			return [];
		}
		return array_values(
			array_map(
				static fn( $s ) => str_starts_with( (string) $s, 'wc-' ) ? substr( (string) $s, 3 ) : (string) $s,
				$statuses
			)
		);
	}

	public static function resolve_product_filter( array $filters ): ?array {
		$direct_ids = Wooex_Data_Products::clean_ids( $filters['product_ids'] ?? [] );
		$cat_ids    = Wooex_Data_Products::clean_ids( $filters['product_cat_ids'] ?? [] );
		$tag_ids    = Wooex_Data_Products::clean_ids( $filters['product_tag_ids'] ?? [] );

		if ( empty( $direct_ids ) && empty( $cat_ids ) && empty( $tag_ids ) ) {
			return null;
		}

		$ids = $direct_ids;

		if ( ! empty( $cat_ids ) || ! empty( $tag_ids ) ) {
			$ids = array_merge( $ids, Wooex_Data_Products::product_ids_in_taxonomy( $cat_ids, $tag_ids ) );
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	public static function order_contains_any_product( $order, array $product_ids ): bool {
		if ( empty( $product_ids ) ) {
			return false;
		}

		$wanted = array_flip( $product_ids );

		foreach ( $order->get_items() as $item ) {
			$pid = (int) $item->get_product_id();
			if ( isset( $wanted[ $pid ] ) ) {
				return true;
			}
			$vid = (int) $item->get_variation_id();
			if ( $vid > 0 && isset( $wanted[ $vid ] ) ) {
				return true;
			}
		}

		return false;
	}

	private static function format_row( $order ): array {
		$created  = $order->get_date_created();
		$dt_fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$items    = [];

		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_quantity() . ' x ' . $item->get_name();
		}

		// Donation + processing-fee meta added by the sibling
		// woo-donation-cover-processing-fee plugin. Blank when the order
		// predates the plugin or the customer didn't opt in — deliberately
		// NOT "0.00", so downstream SUM() cleanly ignores non-participants.
		$donation      = $order->get_meta( '_woo_cover_fee_donation' );
		$processing_fee = $order->get_meta( '_woo_cover_fee_processing' );

		return [
			'Order ID'           => $order->get_id(),
			'Order Date'         => $created ? $created->date_i18n( $dt_fmt ) : '',
			'Order Status'       => wc_get_order_status_name( $order->get_status() ),
			'Customer Name'      => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'Customer Email'     => $order->get_billing_email(),
			'Billing Phone'      => $order->get_billing_phone(),
			'Billing Address'    => self::format_address( $order, 'billing' ),
			'Shipping Name'      => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'Shipping Company'   => $order->get_shipping_company(),
			'Shipping Address 1' => $order->get_shipping_address_1(),
			'Shipping Address 2' => $order->get_shipping_address_2(),
			'Shipping City'      => $order->get_shipping_city(),
			'Shipping State'     => $order->get_shipping_state(),
			'Shipping Postcode'  => $order->get_shipping_postcode(),
			'Shipping Country'   => $order->get_shipping_country(),
			'Items'              => implode( ' | ', $items ),
			'Subtotal'           => wc_format_decimal( $order->get_subtotal(), 2 ),
			'Discount'           => wc_format_decimal( $order->get_discount_total(), 2 ),
			'Shipping Total'     => wc_format_decimal( $order->get_shipping_total(), 2 ),
			'Tax'                => wc_format_decimal( $order->get_total_tax(), 2 ),
			'Donation'           => ( '' !== $donation       && is_numeric( $donation ) )       ? wc_format_decimal( $donation, 2 )       : '',
			'Processing Fee'     => ( '' !== $processing_fee && is_numeric( $processing_fee ) ) ? wc_format_decimal( $processing_fee, 2 ) : '',
			'Order Total'        => wc_format_decimal( $order->get_total(), 2 ),
			'Payment Method'     => $order->get_payment_method_title(),
			'Transaction ID'     => $order->get_transaction_id(),
			'Customer Note'      => $order->get_customer_note(),
		];
	}

	private static function format_address( $order, string $type = 'billing' ): string {
		$prefix = 'shipping' === $type ? 'get_shipping_' : 'get_billing_';
		$parts  = array_filter(
			[
				$order->{$prefix . 'address_1'}(),
				$order->{$prefix . 'address_2'}(),
				$order->{$prefix . 'city'}(),
				$order->{$prefix . 'state'}(),
				$order->{$prefix . 'postcode'}(),
				$order->{$prefix . 'country'}(),
			]
		);
		return implode( ', ', $parts );
	}

	public static function resolve_dates( array $filters ): array {
		$tz    = wp_timezone();
		$range = $filters['date_range'] ?? 'today';
		$today = new DateTimeImmutable( 'today', $tz );

		switch ( $range ) {
			case 'today':
				$from = $today;
				$to   = $today;
				break;

			case 'yesterday':
				$from = $today->modify( '-1 day' );
				$to   = $today->modify( '-1 day' );
				break;

			case 'this_week':
				[ $from, $to ] = self::week_range( $today, 0 );
				break;

			case 'last_week':
				[ $from, $to ] = self::week_range( $today, -1 );
				break;

			case 'this_month':
				$from = $today->modify( 'first day of this month' );
				$to   = $today;
				break;

			case 'last_month':
				$from = $today->modify( 'first day of last month' );
				$to   = $today->modify( 'last day of last month' );
				break;

			case 'this_year':
				$from = new DateTimeImmutable( $today->format( 'Y' ) . '-01-01', $tz );
				$to   = $today;
				break;

			case 'last_year':
				$year = (int) $today->format( 'Y' ) - 1;
				$from = new DateTimeImmutable( "{$year}-01-01", $tz );
				$to   = new DateTimeImmutable( "{$year}-12-31", $tz );
				break;

			case 'all_time':
				return [ 'from' => '', 'to' => '' ];

			case 'custom':
				$from_raw = (string) ( $filters['date_from'] ?? '' );
				$to_raw   = (string) ( $filters['date_to'] ?? '' );

				if ( '' === $from_raw || '' === $to_raw ) {
					return [ 'from' => '', 'to' => '' ];
				}

				$from = DateTimeImmutable::createFromFormat( 'Y-m-d', $from_raw, $tz ) ?: $today;
				$to   = DateTimeImmutable::createFromFormat( 'Y-m-d', $to_raw, $tz ) ?: $today;
				break;

			default:
				$from = $today;
				$to   = $today;
				break;
		}

		return [
			'from' => $from->format( 'Y-m-d' ) . ' 00:00:00',
			'to'   => $to->format( 'Y-m-d' ) . ' 23:59:59',
		];
	}

	/**
	 * Returns [start, end] DateTimeImmutable for a week relative to today,
	 * honouring WordPress's start_of_week option (0=Sun … 6=Sat).
	 * $offset_weeks: 0 = current week, -1 = last week.
	 */
	private static function week_range( DateTimeImmutable $today, int $offset_weeks ): array {
		$start_of_week = (int) get_option( 'start_of_week', 1 );
		$today_dow     = (int) $today->format( 'w' ); // 0 (Sun) – 6 (Sat)
		$diff_days     = ( $today_dow - $start_of_week + 7 ) % 7;

		$start = $today->modify( "-{$diff_days} days" );
		if ( 0 !== $offset_weeks ) {
			$start = $start->modify( ( $offset_weeks * 7 ) . ' days' );
		}
		$end = $start->modify( '+6 days' );

		return [ $start, $end ];
	}
}

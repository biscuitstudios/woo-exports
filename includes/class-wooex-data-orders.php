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

	/**
	 * Validation pattern for an HH:MM wall-clock time. Shared with the admin
	 * sanitiser so the filter's `day_start` and the schedule's `time` cannot
	 * drift apart on what they accept.
	 */
	public const TIME_PATTERN = '/^([0-1]?\d|2[0-3]):([0-5]\d)$/';

	/**
	 * The date ranges that honour `day_start`. Everything else is a named
	 * calendar period and runs midnight to midnight.
	 *
	 * The switch in resolve_dates() is the real logic; this list exists so the
	 * admin view and the JS can hide the field for ranges it does not affect
	 * without restating the rule. DayStartTest::test_day_start_ranges_constant_
	 * matches_behaviour() drives every range through the resolver and fails if
	 * the two ever disagree, so this cannot rot into a lie.
	 */
	public const DAY_START_RANGES = [ 'today', 'yesterday', 'custom' ];

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

		$date_created = self::date_created_arg( $dates );
		if ( null !== $date_created ) {
			$args['date_created'] = $date_created;
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

	/**
	 * Resolve a report's filters to an inclusive [from, to] window.
	 *
	 * Returns UNIX timestamps, not wall-clock strings. That is deliberate and
	 * load-bearing: `wc_get_orders( [ 'date_created' => 'A...B' ] )` throws away
	 * the time component when both sides are date strings and silently drops to
	 * whole-day precision. Passing numerics is the only way to get second
	 * precision, on both the legacy CPT store and HPOS.
	 *
	 * @see \WC_Data_Store_WP::parse_date_for_wp_query()
	 * @see \Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableQuery::date_to_date_query_arg()
	 *
	 * A day does not have to begin at midnight. The `day_start` filter moves the
	 * boundary, so a store reconciling against a payment processor's settlement
	 * cutoff can define its day as, say, 19:00 to 19:00.
	 *
	 * `day_start` applies to the ranges that are counted in DAYS — Today,
	 * Yesterday and Custom — and to nothing else. Week, month and year are named
	 * calendar periods and keep midnight boundaries whatever the setting is.
	 * Shifting them would make "Last Month (July)" return 19 hours of August,
	 * contradicting the label the user picked it by. Nobody sets a settlement
	 * cutoff in order to redefine what July means.
	 *
	 * At the default 00:00 every range resolves exactly as it always has.
	 *
	 * @param array    $filters Report filters.
	 * @param int|null $now     Override the clock. Tests only; production passes null.
	 * @return array{from:int|string,to:int|string} Empty strings mean "no bound".
	 */
	public static function resolve_dates( array $filters, ?int $now = null ): array {
		$tz    = wp_timezone();
		$range = $filters['date_range'] ?? 'today';

		$now_dt = ( null === $now )
			? new DateTimeImmutable( 'now', $tz )
			: ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );

		[ $hh, $mm ] = self::parse_day_start( $filters['day_start'] ?? '00:00' );

		// Two reference points, and which one a range uses is the whole design.
		//
		// $anchor is the start of the day currently in progress under this
		// report's boundary. Before today's boundary has passed we are still
		// inside the day that opened yesterday evening.
		//
		// $calendar_today is plain midnight, untouched by day_start.
		$candidate      = $now_dt->setTime( $hh, $mm, 0 );
		$anchor         = ( $now_dt < $candidate ) ? $candidate->modify( '-1 day' ) : $candidate;
		$calendar_today = $now_dt->setTime( 0, 0, 0 );

		// Each branch names the FIRST and LAST day in the window by its start
		// instant. The window is closed at the far end, computed below.
		switch ( $range ) {
			// --- Counted in days. These follow day_start. ---
			case 'today':
				$from_day = $anchor;
				$to_day   = $anchor;
				break;

			case 'yesterday':
				$from_day = $anchor->modify( '-1 day' );
				$to_day   = $from_day;
				break;

			// --- Named calendar periods. These ignore day_start entirely, so
			// "Last Month (July)" is July and nothing else. ---
			case 'this_week':
				[ $from_day, $to_day ] = self::week_range( $calendar_today, 0 );
				break;

			case 'last_week':
				[ $from_day, $to_day ] = self::week_range( $calendar_today, -1 );
				break;

			case 'this_month':
				$from_day = $calendar_today->modify( 'first day of this month' );
				$to_day   = $calendar_today;
				break;

			case 'last_month':
				$from_day = $calendar_today->modify( 'first day of last month' );
				$to_day   = $calendar_today->modify( 'last day of last month' );
				break;

			case 'this_year':
				$from_day = $calendar_today->setDate( (int) $calendar_today->format( 'Y' ), 1, 1 );
				$to_day   = $calendar_today;
				break;

			case 'last_year':
				$year     = (int) $calendar_today->format( 'Y' ) - 1;
				$from_day = $calendar_today->setDate( $year, 1, 1 );
				$to_day   = $calendar_today->setDate( $year, 12, 31 );
				break;

			case 'all_time':
				return [ 'from' => '', 'to' => '' ];

			case 'custom':
				$from_raw = (string) ( $filters['date_from'] ?? '' );
				$to_raw   = (string) ( $filters['date_to'] ?? '' );

				if ( '' === $from_raw || '' === $to_raw ) {
					return [ 'from' => '', 'to' => '' ];
				}

				$from_day = self::day_from_ymd( $from_raw, $hh, $mm, $tz ) ?? $anchor;
				$to_day   = self::day_from_ymd( $to_raw, $hh, $mm, $tz ) ?? $anchor;
				break;

			default:
				$from_day = $anchor;
				$to_day   = $anchor;
				break;
		}

		// The window closes one second before the following day opens. One
		// formula serves both kinds of range: $to_day carries the boundary time
		// for day-counted ranges and midnight for calendar ones, so this yields
		// 18:59:59 in the first case and 23:59:59 in the second.
		//
		// `modify('+1 day')` holds wall-clock time across a DST transition, so a
		// 19:00 boundary stays 19:00 on the 23- and 25-hour days.
		$end = $to_day->modify( '+1 day' )->modify( '-1 second' );

		return [
			'from' => $from_day->getTimestamp(),
			'to'   => $end->getTimestamp(),
		];
	}

	/**
	 * True when this report's day boundary is something other than midnight.
	 * Callers use it to decide whether a window can be described by dates alone.
	 */
	public static function has_custom_day_start( array $filters ): bool {
		return [ 0, 0 ] !== self::parse_day_start( $filters['day_start'] ?? '00:00' );
	}

	/**
	 * Parse an HH:MM day-start into [hour, minute]. Anything malformed falls
	 * back to midnight, which is the behaviour the plugin had before day_start
	 * existed — a bad value degrades to the old default rather than to an
	 * arbitrary window.
	 */
	public static function parse_day_start( $raw ): array {
		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $raw || ! preg_match( self::TIME_PATTERN, $raw ) ) {
			return [ 0, 0 ];
		}
		[ $hh, $mm ] = array_map( 'intval', explode( ':', $raw ) );
		return [ $hh, $mm ];
	}

	/**
	 * Build the `date_created` argument for wc_get_orders() from a resolved
	 * window, or null when the window is unbounded (All Time).
	 *
	 * Both sides must reach WooCommerce as numerics. If either is a date string
	 * the query silently degrades to whole-day precision and the day_start
	 * boundary is discarded. Shared with Wooex_Data_Customers so the two query
	 * paths cannot drift on that.
	 *
	 * @param array{from:int|string,to:int|string} $dates
	 */
	public static function date_created_arg( array $dates ): ?string {
		$from = $dates['from'] ?? '';
		$to   = $dates['to'] ?? '';

		if ( ! is_int( $from ) || ! is_int( $to ) ) {
			return null;
		}
		return $from . '...' . $to;
	}

	/**
	 * A 'Y-m-d' custom-range date as the instant that day begins under this
	 * report's boundary.
	 * Returns null if the string is not a real date, so the caller can decide
	 * the fallback rather than silently receiving today.
	 */
	private static function day_from_ymd( string $ymd, int $hh, int $mm, DateTimeZone $tz ): ?DateTimeImmutable {
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $ymd, $tz );
		if ( ! $dt ) {
			return null;
		}
		return $dt->setTime( $hh, $mm, 0 );
	}

	/**
	 * Returns [first day, last day] of a week relative to $today, honouring
	 * WordPress's start_of_week option (0=Sun … 6=Sat).
	 *
	 * Always called with plain midnight. A week is a calendar week and does not
	 * move with day_start. $offset_weeks: 0 = current week, -1 = last week.
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

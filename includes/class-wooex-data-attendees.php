<?php
/**
 * Attendee query layer (Event Tickets Plus, WooCommerce-backed tickets).
 * Powers the "Attendees" report type and provides the parent-post-IDs lookup
 * used by other report types (e.g. Orders by Event Ticket).
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wooex_Data_Attendees {

	private const MIN_ETP_VERSION = '6.9.2';

	private const META_ORDER_ID    = '_tribe_wooticket_order';
	private const META_EVENT_ID    = '_tribe_wooticket_event';
	private const META_PRODUCT_ID  = '_tribe_wooticket_product';
	private const META_FULL_NAME   = '_tribe_tickets_full_name';
	private const META_EMAIL       = '_tribe_tickets_email';
	private const META_CHECKED_IN  = '_tribe_wooticket_checkedin';
	private const META_SECURITY    = '_tribe_wooticket_security_code';

	public static function is_available(): bool {
		if ( ! class_exists( 'Tribe__Tickets_Plus__Main' ) ) {
			return false;
		}

		$version = self::detected_version();

		if ( '' === $version || version_compare( $version, self::MIN_ETP_VERSION, '<' ) ) {
			error_log( '[WooExports] Event Tickets Plus ' . ( $version ?: 'unknown' ) . ' found — ' . self::MIN_ETP_VERSION . '+ required. Events report type disabled.' );
			return false;
		}

		return true;
	}

	/**
	 * Returns the distinct WC order IDs for attendees whose parent post (event/page/CPT)
	 * is in $parent_post_ids. Used by Wooex_Data_Orders for the "by Event Ticket" filter.
	 */
	public static function order_ids_for_parents( array $parent_post_ids ): array {
		$parent_post_ids = Wooex_Data_Products::clean_ids( $parent_post_ids );
		if ( empty( $parent_post_ids ) || ! self::is_available() ) {
			return [];
		}

		$attendees = get_posts(
			[
				'post_type'      => 'tribe_wooticket',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => self::META_ORDER_ID,
						'compare' => 'EXISTS',
					],
					[
						'key'     => self::META_EVENT_ID,
						'value'   => $parent_post_ids,
						'compare' => 'IN',
					],
				],
			]
		);

		if ( empty( $attendees ) ) {
			return [];
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $attendees ), '%d' ) );
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND post_id IN ($placeholders)",
				array_merge( [ self::META_ORDER_ID ], $attendees )
			)
		);

		return array_values( array_unique( array_map( 'intval', (array) $rows ) ) );
	}

	public static function detected_version(): string {
		if ( defined( 'EVENT_TICKETS_PLUS_VERSION' ) ) {
			return (string) EVENT_TICKETS_PLUS_VERSION;
		}

		if ( class_exists( 'Tribe__Tickets_Plus__Main' ) && defined( 'Tribe__Tickets_Plus__Main::VERSION' ) ) {
			return (string) Tribe__Tickets_Plus__Main::VERSION;
		}

		return '';
	}

	public static array $last_debug = [];

	public static function get( array $filters ): array {
		self::$last_debug = [];

		if ( ! self::is_available() ) {
			self::$last_debug['available'] = false;
			return [];
		}

		$meta_query = [ 'relation' => 'AND' ];
		$meta_query[] = [
			'key'     => self::META_ORDER_ID,
			'compare' => 'EXISTS',
		];

		$parent_post_ids = Wooex_Data_Products::clean_ids( $filters['parent_post_ids'] ?? [] );
		if ( ! empty( $parent_post_ids ) ) {
			$meta_query[] = [
				'key'     => self::META_EVENT_ID,
				'value'   => $parent_post_ids,
				'compare' => 'IN',
			];
		}

		$product_filter = Wooex_Data_Orders::resolve_product_filter( $filters );
		if ( null !== $product_filter ) {
			if ( empty( $product_filter ) ) {
				self::$last_debug['product_filter_empty'] = true;
				return [];
			}
			$meta_query[] = [
				'key'     => self::META_PRODUCT_ID,
				'value'   => $product_filter,
				'compare' => 'IN',
			];
		}

		self::$last_debug['meta_query']      = $meta_query;
		self::$last_debug['parent_post_ids'] = $parent_post_ids;
		self::$last_debug['product_filter']  = $product_filter;

		$attendees = get_posts(
			[
				'post_type'      => 'tribe_wooticket',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
			]
		);

		self::$last_debug['attendees_from_meta_query'] = count( $attendees );

		if ( empty( $attendees ) ) {
			return [];
		}

		$dates         = Wooex_Data_Orders::resolve_dates( $filters );
		$status_filter = Wooex_Data_Orders::normalize_statuses( $filters['statuses'] ?? [] );
		$customer_ids  = Wooex_Data_Products::clean_ids( $filters['customer_ids'] ?? [] );
		$customer_set  = ! empty( $customer_ids ) ? array_flip( $customer_ids ) : null;

		$from_ts = ! empty( $dates['from'] ) ? strtotime( $dates['from'] ) : null;
		$to_ts   = ! empty( $dates['to'] ) ? strtotime( $dates['to'] ) : null;

		self::$last_debug['from_ts']    = $from_ts;
		self::$last_debug['to_ts']      = $to_ts;
		self::$last_debug['statuses']   = $status_filter;

		$rejected = [
			'no_order_id'       => 0,
			'order_not_found'   => 0,
			'date_out_of_range' => 0,
			'wrong_status'      => 0,
			'wrong_customer'    => 0,
		];

		$rows = [];

		foreach ( $attendees as $attendee_id ) {
			$order_id = (int) get_post_meta( $attendee_id, self::META_ORDER_ID, true );
			if ( $order_id <= 0 ) {
				$rejected['no_order_id']++;
				continue;
			}

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				$rejected['order_not_found']++;
				continue;
			}

			$created    = $order->get_date_created();
			$created_ts = $created ? $created->getTimestamp() : null;

			if ( null !== $from_ts && null !== $to_ts && $created_ts ) {
				if ( $created_ts < $from_ts || $created_ts > $to_ts ) {
					$rejected['date_out_of_range']++;
					continue;
				}
			}

			if ( ! empty( $status_filter ) && ! in_array( $order->get_status(), $status_filter, true ) ) {
				$rejected['wrong_status']++;
				continue;
			}

			if ( null !== $customer_set && ! isset( $customer_set[ (int) $order->get_customer_id() ] ) ) {
				$rejected['wrong_customer']++;
				continue;
			}

			$rows[] = self::format_row( $attendee_id, $order, $created );
		}

		self::$last_debug['rejected'] = $rejected;
		self::$last_debug['kept']     = count( $rows );

		return $rows;
	}

	private static function format_row( int $attendee_id, $order, $created ): array {
		$event_id   = (int) get_post_meta( $attendee_id, self::META_EVENT_ID, true );
		$event_name = $event_id ? get_the_title( $event_id ) : '';

		$product_id  = (int) get_post_meta( $attendee_id, self::META_PRODUCT_ID, true );
		$product     = $product_id ? wc_get_product( $product_id ) : null;
		$ticket_type = $product ? $product->get_name() : 'General';

		if ( $ticket_type === $event_name ) {
			$ticket_type = 'General Admission';
		}

		$venue_id = $event_id ? (int) get_post_meta( $event_id, '_EventVenueID', true ) : 0;
		$venue    = $venue_id ? get_the_title( $venue_id ) : '';

		$checked_in = (string) get_post_meta( $attendee_id, self::META_CHECKED_IN, true );
		$security   = (string) get_post_meta( $attendee_id, self::META_SECURITY, true );

		$dt_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return [
			'Attendee Name'                       => (string) get_post_meta( $attendee_id, self::META_FULL_NAME, true ),
			'Attendee Email'                      => (string) get_post_meta( $attendee_id, self::META_EMAIL, true ),
			'Event Name'                          => $event_name,
			'Ticket Type'                         => $ticket_type,
			'Venue'                               => $venue,
			'Checked In'                          => '1' === $checked_in ? 'Yes' : 'No',
			'Security Code'                       => $security,
			'Order ID'                            => $order->get_id(),
			'Order Date'                          => $created ? $created->date_i18n( $dt_fmt ) : '',
			'Order Status'                        => wc_get_order_status_name( $order->get_status() ),
			'Order Total (after Purchase Rules)'  => wc_format_decimal( $order->get_total(), 2 ),
		];
	}

}

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

	/**
	 * Attendee Information. One meta key, two completely different shapes:
	 *
	 *  - on a `tribe_wooticket` attendee post it holds the ANSWERS, a flat
	 *    slug => value map, e.g. [ 'full-name' => 'Lucy Fry' ];
	 *  - on the ticket PRODUCT it holds the FIELD DEFINITIONS, a list of
	 *    [ 'slug' => 'full-name', 'label' => 'Full Name', 'type' => 'text',
	 *      'required' => 'on', ... ].
	 *
	 * Reading one where you meant the other returns an array either way and
	 * fails silently, so both call sites below say which they want.
	 */
	private const META_ATTENDEE_INFO = '_tribe_tickets_meta';

	/**
	 * Attendee Information slugs that carry the attendee's own name and email.
	 *
	 * Matched EXACTLY against the normalised slug, never as a substring. That is
	 * the whole negative guard: a fieldset's "Emergency Contact Name" or
	 * "Company Name" slugs to `emergency-contact-name` / `company-name` and is
	 * correctly ignored, where a `str_contains( $slug, 'name' )` would export it
	 * as the attendee. A site whose fieldset uses a slug not listed here adds it
	 * through the `wooex_attendee_name_slugs` / `wooex_attendee_email_slugs`
	 * filters rather than by editing this file.
	 */
	public const DEFAULT_NAME_SLUGS = [
		'full-name',
		'fullname',
		'name',
		'attendee-name',
		'attendee-full-name',
		'guest-name',
		'ticket-holder',
		'ticket-holder-name',
	];

	/** Checked only when no single-field name matched. Both halves must be present. */
	public const DEFAULT_NAME_PAIRS = [
		[ 'first-name', 'last-name' ],
		[ 'given-name', 'family-name' ],
	];

	public const DEFAULT_EMAIL_SLUGS = [
		'email',
		'e-mail',
		'attendee-email',
		'email-address',
	];

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

	public static function get( array $filters, ?int $now = null ): array {
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

		$dates         = Wooex_Data_Orders::resolve_dates( $filters, $now );
		$status_filter = Wooex_Data_Orders::normalize_statuses( $filters['statuses'] ?? [] );
		$customer_ids  = Wooex_Data_Products::clean_ids( $filters['customer_ids'] ?? [] );
		$customer_set  = ! empty( $customer_ids ) ? array_flip( $customer_ids ) : null;

		// resolve_dates() returns UNIX timestamps, which is what $created_ts is
		// too, so these compare directly.
		//
		// This used to be strtotime() over the wall-clock strings the old
		// resolve_dates() returned. WordPress forces PHP's default timezone to
		// UTC, so those strings — wall-clock in SITE time — were parsed as UTC
		// and every attendee window was shifted by the site's offset. Five hours
		// on an Eastern site. Attendee row counts change on this release as a
		// result, and the new numbers are the correct ones.
		$from_ts = is_int( $dates['from'] ?? null ) ? $dates['from'] : null;
		$to_ts   = is_int( $dates['to'] ?? null ) ? $dates['to'] : null;

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

	// =====================================================================
	// Attendee Information (per-ticket answers)
	// =====================================================================

	/**
	 * Picks the attendee's own name and email out of the Attendee Information
	 * answers, falling back to '' when the fieldset has no such field.
	 *
	 * Why this exists: `_tribe_tickets_full_name` and `_tribe_tickets_email` are
	 * NOT the attendee. Event Tickets Plus stamps both from the WooCommerce
	 * billing details at ticket generation, so every ticket in a multi-ticket
	 * order carries the PURCHASER's name and email. Verified on a three-ticket
	 * order: all three attendee posts held "Jason Butler", while the per-ticket
	 * answers held Lucy Fry, Edie Butler and August Butler. Up to 0.15.1 this
	 * class read the purchaser keys, so an order for a family exported the buyer
	 * three times.
	 *
	 * Pure on purpose — no meta reads, no filters — so the suite can exercise
	 * the matching without WordPress.
	 *
	 * @param array $values      Attendee post's answers, slug => value.
	 * @param array $definitions Ticket product's field definitions. Optional: it
	 *                           supplies field ORDER and the structural
	 *                           type === 'email' signal. When it is empty (the
	 *                           ticket product was deleted, or the fieldset was
	 *                           edited after the sale) the answers' own key
	 *                           order is used instead, which still resolves.
	 * `collects_name` / `collects_email` say whether the fieldset ASKS for that
	 * field, which is a different question from whether this attendee answered
	 * it. An optional field left blank must export blank; only a field nobody was
	 * ever asked for falls back to the purchaser. Collapsing the two puts the
	 * buyer's email against someone else's name, which is the bug this class was
	 * fixed for, one column across.
	 *
	 * @return array{name:string,email:string,name_slug:string,email_slug:string,collects_name:bool,collects_email:bool}
	 */
	public static function resolve_attendee_identity(
		array $values,
		array $definitions = [],
		?array $name_slugs = null,
		?array $email_slugs = null
	): array {
		$name_slugs  = $name_slugs ?? self::DEFAULT_NAME_SLUGS;
		$email_slugs = $email_slugs ?? self::DEFAULT_EMAIL_SLUGS;

		// slug => trimmed string, keyed by normalised slug. Blank answers are
		// dropped here rather than tested for later: an optional field left
		// empty must fall through to the next candidate, not win and export a
		// blank cell. Jason's fieldset has Full Name required and Email not, so
		// the half-filled row is the normal case, not the edge case.
		$answers = [];
		foreach ( $values as $slug => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				continue; // checkbox groups and the like are not a name.
			}
			$clean = trim( (string) $value );
			if ( '' !== $clean ) {
				$answers[ self::normalize_slug( (string) $slug ) ] = $clean;
			}
		}

		// Raw keys, blanks included. What the fieldset ASKS for is read from here
		// and from the definitions, never from $answers, which has had the blanks
		// dropped and so cannot tell "not asked" from "asked and skipped".
		$asked = [];
		foreach ( array_keys( $values ) as $slug ) {
			$asked[] = self::normalize_slug( (string) $slug );
		}
		foreach ( $definitions as $def ) {
			if ( is_array( $def ) && '' !== ( $def['slug'] ?? '' ) ) {
				$asked[] = self::normalize_slug( (string) $def['slug'] );
			}
		}

		$collects_name = (bool) array_intersect( $asked, $name_slugs );
		if ( ! $collects_name ) {
			foreach ( self::DEFAULT_NAME_PAIRS as $pair ) {
				if ( array_intersect( $asked, $pair ) ) {
					$collects_name = true;
					break;
				}
			}
		}

		$collects_email = (bool) array_intersect( $asked, $email_slugs );
		foreach ( $definitions as $def ) {
			if ( is_array( $def ) && ( $def['type'] ?? '' ) === 'email' ) {
				$collects_email = true;
				break;
			}
		}

		$blank = [
			'name'           => '',
			'email'          => '',
			'name_slug'      => '',
			'email_slug'     => '',
			'collects_name'  => $collects_name,
			'collects_email' => $collects_email,
		];

		if ( empty( $answers ) ) {
			return $blank;
		}

		$ordered = self::ordered_slugs( $answers, $definitions );

		$name      = '';
		$name_slug = '';
		foreach ( $ordered as $slug ) {
			if ( in_array( $slug, $name_slugs, true ) ) {
				$name      = $answers[ $slug ];
				$name_slug = $slug;
				break;
			}
		}

		// Only if no single field held the whole name. A fieldset that splits it
		// is common enough to handle, and both halves must be answered — a lone
		// "Sarah" with no surname field is still a better name than the buyer's.
		if ( '' === $name ) {
			foreach ( self::DEFAULT_NAME_PAIRS as [ $first, $last ] ) {
				if ( isset( $answers[ $first ] ) || isset( $answers[ $last ] ) ) {
					$name      = trim( ( $answers[ $first ] ?? '' ) . ' ' . ( $answers[ $last ] ?? '' ) );
					$name_slug = $first . '+' . $last;
					break;
				}
			}
		}

		// Email leads on the field's declared type, which is structural rather
		// than a guess about wording, and only falls back to the slug list.
		$email      = '';
		$email_slug = '';
		foreach ( $definitions as $def ) {
			if ( ! is_array( $def ) || ( $def['type'] ?? '' ) !== 'email' ) {
				continue;
			}
			$slug = self::normalize_slug( (string) ( $def['slug'] ?? '' ) );
			if ( '' !== $slug && isset( $answers[ $slug ] ) ) {
				$email      = $answers[ $slug ];
				$email_slug = $slug;
				break;
			}
		}

		if ( '' === $email ) {
			foreach ( $ordered as $slug ) {
				if ( in_array( $slug, $email_slugs, true ) && $slug !== $name_slug ) {
					$email      = $answers[ $slug ];
					$email_slug = $slug;
					break;
				}
			}
		}

		return [
			'name'           => $name,
			'email'          => $email,
			'name_slug'      => $name_slug,
			'email_slug'     => $email_slug,
			'collects_name'  => $collects_name,
			'collects_email' => $collects_email,
		];
	}

	/**
	 * The answered slugs in the order the form declared them, with any answer the
	 * definitions do not mention appended in its stored order. Field order is the
	 * tie-breaker when a fieldset somehow holds two candidates, so it comes from
	 * the form the client filled in rather than from the order of our own list.
	 */
	private static function ordered_slugs( array $answers, array $definitions ): array {
		$ordered = [];

		foreach ( $definitions as $def ) {
			if ( ! is_array( $def ) ) {
				continue;
			}
			$slug = self::normalize_slug( (string) ( $def['slug'] ?? '' ) );
			if ( '' !== $slug && isset( $answers[ $slug ] ) && ! in_array( $slug, $ordered, true ) ) {
				$ordered[] = $slug;
			}
		}

		foreach ( array_keys( $answers ) as $slug ) {
			if ( ! in_array( $slug, $ordered, true ) ) {
				$ordered[] = $slug;
			}
		}

		return $ordered;
	}

	/** `Full_Name` and `Full Name` and `full-name` are the same field. */
	private static function normalize_slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		$slug = preg_replace( '/[\s_]+/', '-', $slug );
		return trim( (string) $slug, '-' );
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

		// Same meta key on two post types, two different shapes. See META_ATTENDEE_INFO.
		$answers     = get_post_meta( $attendee_id, self::META_ATTENDEE_INFO, true );
		$definitions = $product_id ? get_post_meta( $product_id, self::META_ATTENDEE_INFO, true ) : [];

		$identity = self::resolve_attendee_identity(
			is_array( $answers ) ? $answers : [],
			is_array( $definitions ) ? $definitions : [],
			(array) apply_filters( 'wooex_attendee_name_slugs', self::DEFAULT_NAME_SLUGS ),
			(array) apply_filters( 'wooex_attendee_email_slugs', self::DEFAULT_EMAIL_SLUGS )
		);

		// Who paid. Read once, used twice: for the two reference columns, and as
		// the fallback below. The ORDER's billing details are the live source —
		// `_tribe_tickets_full_name` is a copy Event Tickets Plus took when the
		// ticket was generated and goes stale if billing is corrected afterwards,
		// so it is the fallback rather than the source.
		$purchaser_name = trim(
			$order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
		);
		if ( '' === $purchaser_name ) {
			$purchaser_name = (string) get_post_meta( $attendee_id, self::META_FULL_NAME, true );
		}

		$purchaser_email = (string) $order->get_billing_email();
		if ( '' === $purchaser_email ) {
			$purchaser_email = (string) get_post_meta( $attendee_id, self::META_EMAIL, true );
		}

		// The purchaser fills in only where the fieldset never asked. A ticket
		// sold before Attendee Information was switched on has no answers at all
		// and the buyer is the only name there is; a ticket whose optional Email
		// was skipped exports blank, because "blank" is the true answer and the
		// buyer's address against someone else's name is the bug one column over.
		// Counted so a report falling back across the board shows up in the debug
		// output rather than just looking plausible.
		$name  = $identity['name'];
		$email = $identity['email'];

		if ( '' === $name && ! $identity['collects_name'] ) {
			$name = $purchaser_name;
			self::$last_debug['name_from_purchaser'] = ( self::$last_debug['name_from_purchaser'] ?? 0 ) + 1;
		}
		if ( '' === $email && ! $identity['collects_email'] ) {
			$email = $purchaser_email;
			self::$last_debug['email_from_purchaser'] = ( self::$last_debug['email_from_purchaser'] ?? 0 ) + 1;
		}

		$checked_in = (string) get_post_meta( $attendee_id, self::META_CHECKED_IN, true );
		$security   = (string) get_post_meta( $attendee_id, self::META_SECURITY, true );

		$dt_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return [
			'Attendee Name'                       => $name,
			'Attendee Email'                      => $email,
			'Event Name'                          => $event_name,
			'Ticket Type'                         => $ticket_type,
			'Venue'                               => $venue,
			'Checked In'                          => '1' === $checked_in ? 'Yes' : 'No',
			'Security Code'                       => $security,
			// Who bought the ticket, which on a multi-ticket order is nobody in
			// the two columns above. Placed here so the attendee's own details
			// lead the row and the purchaser reads as the reference it is.
			'Purchaser Name'                      => $purchaser_name,
			'Purchaser Email'                     => $purchaser_email,
			'Order ID'                            => $order->get_id(),
			'Order Date'                          => $created ? $created->date_i18n( $dt_fmt ) : '',
			'Order Status'                        => wc_get_order_status_name( $order->get_status() ),
			'Order Total (after Purchase Rules)'  => wc_format_decimal( $order->get_total(), 2 ),
		];
	}

}

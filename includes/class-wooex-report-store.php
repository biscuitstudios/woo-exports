<?php
/**
 * Report configuration store. Reads/writes the wooex_reports option.
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wooex_Report_Store {

	private const OPTION_KEY = 'wooex_reports';

	public static function all(): array {
		$reports = get_option( self::OPTION_KEY, [] );
		return is_array( $reports ) ? array_values( $reports ) : [];
	}

	public static function all_active(): array {
		return array_values( array_filter( self::all(), static fn( $r ) => empty( $r['trashed_at'] ) ) );
	}

	public static function all_trashed(): array {
		return array_values( array_filter( self::all(), static fn( $r ) => ! empty( $r['trashed_at'] ) ) );
	}

	public static function trash( string $id ): bool {
		$report = self::get( $id );
		if ( ! $report ) {
			return false;
		}
		$report['trashed_at'] = time();
		$report['active']     = false; // a trashed report never fires
		$report['next_run']   = null;
		return self::save( $report );
	}

	public static function restore( string $id ): bool {
		$report = self::get( $id );
		if ( ! $report ) {
			return false;
		}
		$report['trashed_at'] = null;
		return self::save( $report );
	}

	public static function get( string $id ): ?array {
		foreach ( self::all() as $report ) {
			if ( isset( $report['id'] ) && $report['id'] === $id ) {
				return $report;
			}
		}
		return null;
	}

	public static function save( array $report ): bool {
		if ( empty( $report['id'] ) ) {
			$report['id'] = self::generate_id();
		}

		if ( empty( $report['created_at'] ) ) {
			$report['created_at'] = time();
		}

		$report = self::apply_defaults( $report );

		$reports = self::all();
		$found   = false;

		foreach ( $reports as $i => $existing ) {
			if ( $existing['id'] === $report['id'] ) {
				$reports[ $i ] = $report;
				$found         = true;
				break;
			}
		}

		if ( ! $found ) {
			$reports[] = $report;
		}

		return update_option( self::OPTION_KEY, array_values( $reports ) );
	}

	public static function delete( string $id ): bool {
		$reports  = self::all();
		$filtered = array_values(
			array_filter(
				$reports,
				static fn( $r ) => ( $r['id'] ?? '' ) !== $id
			)
		);

		if ( count( $filtered ) === count( $reports ) ) {
			return false;
		}

		return update_option( self::OPTION_KEY, $filtered );
	}

	public static function generate_id(): string {
		return substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	/**
	 * Public so the suite exercises the entry point `save()` actually calls,
	 * rather than the helper underneath it. Testing `fill_defaults()` directly
	 * left this method free to go back to `array_replace_recursive()` with every
	 * test still green, which is the bug it was written to stop.
	 */
	public static function apply_defaults( array $report ): array {
		return self::fill_defaults( self::defaults(), $report );
	}

	/** The shape every saved report is filled out to. Public so the suite can assert against it. */
	public static function defaults(): array {
		return [
			'id'               => '',
			'name'             => '',
			'type'             => 'orders',
			'format'           => 'xlsx',
			'filters'          => [
				'date_range'      => 'today',
				'date_from'       => '',
				'date_to'         => '',
				// Midnight reproduces the pre-0.10.0 windows exactly, so
				// fill_defaults() backfills saved reports with the old
				// behaviour and no migration is needed.
				'day_start'       => '00:00',
				'statuses'        => [ 'wc-completed', 'wc-processing' ],
				'customer_ids'    => [],
				'product_ids'     => [],
				'product_cat_ids' => [],
				'product_tag_ids' => [],
				'parent_post_ids' => [],
			],
			'schedule'         => [
				'frequency'    => 'daily',
				'time'         => '06:00',
				'day'          => 'monday',
				'day_of_month' => 1,
			],
			'recipients'       => [],
			'active'           => true,
			'last_run'         => null,
			'last_run_status'  => null,
			'last_run_message' => null,
			'next_run'         => null,
			'trashed_at'       => null,
			'created_at'       => time(),
		];
	}

	/**
	 * Fills in the keys a saved report does not have, and nothing else.
	 *
	 * This was array_replace_recursive() until 0.16.0, which merges
	 * numerically-indexed arrays slot by slot rather than treating them as one
	 * value. A report saved with statuses => ['wc-completed'] took index 0 from
	 * the report and kept index 1, 'wc-processing', from the default — so
	 * removing Processing and saving put Processing straight back, and any
	 * single-status report silently exported two. Every list in the defaults is
	 * one choice the user made, so a key present in $report wins outright and
	 * only a missing key falls back. An explicitly empty list stays empty, which
	 * the query layer already reads as "every status".
	 *
	 * Recursion is by shape, not by key name: an associative default is merged
	 * key by key, a list default is atomic.
	 */
	public static function fill_defaults( array $defaults, array $report ): array {
		$out = $report;

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $out ) ) {
				$out[ $key ] = $default;
				continue;
			}

			if ( ! self::is_map( $default ) ) {
				continue; // scalar, null, or list — the saved value is the whole value.
			}

			// A map default against a non-array saved value means the stored
			// option is malformed; the default is the only usable shape.
			$out[ $key ] = is_array( $out[ $key ] )
				? self::fill_defaults( $default, $out[ $key ] )
				: $default;
		}

		return $out;
	}

	/** True for an associative array only. [] and lists are values, not shapes to merge into. */
	private static function is_map( $value ): bool {
		return is_array( $value ) && [] !== $value && ! array_is_list( $value );
	}

}

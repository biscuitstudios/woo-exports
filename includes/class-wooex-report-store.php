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

	private static function apply_defaults( array $report ): array {
		return array_replace_recursive(
			[
				'id'               => '',
				'name'             => '',
				'type'             => 'orders',
				'format'           => 'xlsx',
				'filters'          => [
					'date_range'      => 'today',
					'date_from'       => '',
					'date_to'         => '',
					// Midnight reproduces the pre-0.10.0 windows exactly, so
					// array_replace_recursive backfills saved reports with the
					// old behaviour and no migration is needed.
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
			],
			$report
		);
	}
}

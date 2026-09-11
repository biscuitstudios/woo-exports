<?php
/**
 * Default-filling on save.
 *
 * The bug this covers: `apply_defaults()` used `array_replace_recursive()`, which
 * merges numerically-indexed arrays slot by slot instead of treating them as one
 * value. The `statuses` default is a two-item list, so a report saved with one
 * status took index 0 from the report and kept index 1 from the default. Removing
 * Processing in the builder and saving put Processing straight back, on screen and
 * in the export.
 *
 * Every assertion here is about a LIST staying exactly what the user chose. The
 * maps still have to merge, so that is asserted alongside — fixing one by
 * breaking the other is the obvious wrong fix.
 *
 * @package WooExports
 */

namespace WooExports\Tests;

// Direct web access exits cleanly; under PHPUnit tests/bootstrap.php
// defines ABSPATH before this file loads.
if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;
use Wooex_Report_Store;

final class ReportDefaultsTest extends TestCase {

	/** Deliberately the method `save()` calls, not the helper beneath it. */
	private static function fill( array $report ): array {
		return Wooex_Report_Store::apply_defaults( $report );
	}

	// -----------------------------------------------------------------
	// The reported bug
	// -----------------------------------------------------------------

	public function test_removing_processing_from_a_saved_report_sticks(): void {
		$filled = self::fill( [ 'id' => 'abc', 'filters' => [ 'statuses' => [ 'wc-completed' ] ] ] );

		$this->assertSame( [ 'wc-completed' ], $filled['filters']['statuses'] );
	}

	/** The mirror image: dropping Completed used to leave it behind at index 1. */
	public function test_removing_completed_from_a_saved_report_sticks(): void {
		$filled = self::fill( [ 'id' => 'abc', 'filters' => [ 'statuses' => [ 'wc-processing' ] ] ] );

		$this->assertSame( [ 'wc-processing' ], $filled['filters']['statuses'] );
	}

	public function test_a_status_list_is_kept_whole_whatever_its_length(): void {
		foreach (
			[
				[ 'wc-on-hold' ],
				[ 'wc-refunded', 'wc-cancelled' ],
				[ 'wc-completed', 'wc-processing', 'wc-on-hold', 'wc-refunded' ],
			] as $statuses
		) {
			$filled = self::fill( [ 'filters' => [ 'statuses' => $statuses ] ] );

			$this->assertSame( $statuses, $filled['filters']['statuses'] );
		}
	}

	/**
	 * Deselecting every status is a real state now that it survives a save.
	 * `Wooex_Data_Orders::normalize_statuses()` reads an empty list as no status
	 * filter at all, so this is the difference between "every status" and "the
	 * two defaults" and it must not be quietly refilled.
	 */
	public function test_an_explicitly_empty_status_list_stays_empty(): void {
		$filled = self::fill( [ 'filters' => [ 'statuses' => [] ] ] );

		$this->assertSame( [], $filled['filters']['statuses'] );
	}

	// -----------------------------------------------------------------
	// What the defaults still have to do
	// -----------------------------------------------------------------

	public function test_a_report_with_no_statuses_key_still_gets_the_default_pair(): void {
		$filled = self::fill( [ 'id' => 'abc', 'filters' => [ 'date_range' => 'this_week' ] ] );

		$this->assertSame( [ 'wc-completed', 'wc-processing' ], $filled['filters']['statuses'] );
	}

	/** The 0.10.0 backfill: a report saved before day_start existed reads as midnight. */
	public function test_a_missing_key_inside_filters_is_backfilled_without_losing_the_rest(): void {
		$filled = self::fill(
			[ 'filters' => [ 'date_range' => 'last_month', 'statuses' => [ 'wc-completed' ] ] ]
		);

		$this->assertSame( '00:00', $filled['filters']['day_start'] );
		$this->assertSame( 'last_month', $filled['filters']['date_range'] );
		$this->assertSame( [ 'wc-completed' ], $filled['filters']['statuses'] );
		$this->assertSame( [], $filled['filters']['product_ids'] );
	}

	public function test_saved_scalars_win_over_defaults(): void {
		$filled = self::fill( [ 'type' => 'attendees', 'format' => 'csv', 'active' => false ] );

		$this->assertSame( 'attendees', $filled['type'] );
		$this->assertSame( 'csv', $filled['format'] );
		$this->assertFalse( $filled['active'] );
	}

	/** A falsy-but-set value is still the user's value. */
	public function test_a_null_last_run_is_not_treated_as_missing(): void {
		$filled = self::fill( [ 'last_run' => null ] );

		$this->assertNull( $filled['last_run'] );
		$this->assertArrayHasKey( 'last_run', $filled );
	}

	// -----------------------------------------------------------------
	// The same bug class on the other lists
	// -----------------------------------------------------------------

	public function test_recipients_and_id_lists_are_kept_whole(): void {
		$filled = self::fill(
			[
				'recipients' => [ 'lucy@biscuitstudios.com' ],
				'filters'    => [ 'product_ids' => [ 1493 ], 'customer_ids' => [ 3 ] ],
			]
		);

		$this->assertSame( [ 'lucy@biscuitstudios.com' ], $filled['recipients'] );
		$this->assertSame( [ 1493 ], $filled['filters']['product_ids'] );
		$this->assertSame( [ 3 ], $filled['filters']['customer_ids'] );
	}

	/** Schedule days come from checkboxes and are a list, so they carry the same risk. */
	public function test_a_schedule_day_list_is_kept_whole(): void {
		$filled = self::fill( [ 'schedule' => [ 'frequency' => 'weekly', 'days' => [ 'sunday' ] ] ] );

		$this->assertSame( [ 'sunday' ], $filled['schedule']['days'] );
		$this->assertSame( 'weekly', $filled['schedule']['frequency'] );
		$this->assertSame( '06:00', $filled['schedule']['time'], 'Unset schedule keys still backfill.' );
	}

	// -----------------------------------------------------------------
	// Malformed stored options
	// -----------------------------------------------------------------

	public function test_a_corrupt_filters_value_falls_back_to_the_whole_default_map(): void {
		$filled = self::fill( [ 'id' => 'abc', 'filters' => 'not-an-array' ] );

		$this->assertSame( Wooex_Report_Store::defaults()['filters'], $filled['filters'] );
	}

	public function test_unknown_saved_keys_are_carried_through(): void {
		$filled = self::fill( [ 'id' => 'abc', 'some_future_key' => 'kept' ] );

		$this->assertSame( 'kept', $filled['some_future_key'] );
	}
}

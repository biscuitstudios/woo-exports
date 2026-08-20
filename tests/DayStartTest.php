<?php
/**
 * The day-start boundary: a "day" that begins at something other than midnight.
 *
 * Added in 0.10.0 so a store can reconcile against a payment processor's
 * settlement cutoff. The companion ResolveDatesTest.php proves the default
 * 00:00 still behaves exactly as it did before this existed; everything here is
 * about the non-midnight case.
 *
 * Every test pins the clock through resolve_dates()' $now parameter. Without
 * that the anchor logic is untestable, because "which day are we in"
 * depends on the time of day the suite happens to run.
 */

namespace WooExports\Tests;

if ( ! defined( 'ABSPATH' ) ) exit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Wooex_Data_Orders;
use Wooex_Mailer;

final class DayStartTest extends TestCase {

	private const TZ = 'America/New_York';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wooex_test_tz']      = self::TZ;
		$GLOBALS['_wooex_test_options'] = [
			'start_of_week' => 0,
			'date_format'   => 'F j, Y',
			'time_format'   => 'g:i a',
		];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wooex_test_tz'], $GLOBALS['_wooex_test_options'] );
		parent::tearDown();
	}

	/** Site-local wall clock to a UTC timestamp. */
	private function ts( string $wall ): int {
		return ( new DateTimeImmutable( $wall, wp_timezone() ) )->getTimestamp();
	}

	/** resolve_dates() output as site-local wall clock, for readable assertions. */
	private function window( array $filters, string $now ): array {
		$r = Wooex_Data_Orders::resolve_dates( $filters, $this->ts( $now ) );
		return array_map(
			fn( $v ) => is_int( $v )
				? ( new DateTimeImmutable( '@' . $v ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' )
				: '',
			[ $r['from'], $r['to'] ]
		);
	}

	// -----------------------------------------------------------------------
	// The case this feature was built for.
	// -----------------------------------------------------------------------

	/**
	 * The client's report: orders from 7:00pm yesterday to 6:59pm today,
	 * emailed nightly at 9:00pm.
	 */
	public function test_yesterday_at_1900_is_the_settlement_window(): void {
		$this->assertSame(
			[ '2026-08-19 19:00:00', '2026-08-20 18:59:59' ],
			$this->window(
				[ 'date_range' => 'yesterday', 'day_start' => '19:00' ],
				'2026-08-20 21:00:00'
			)
		);
	}

	/**
	 * The same window whether the scheduled run fires on time at 9pm or limps in
	 * at 6am the next morning after a missed cron. This is the property that
	 * makes a cutoff-anchored day better than "the last 24 hours": a late run
	 * reports the same period rather than a shifted one.
	 */
	public function test_window_is_stable_across_a_late_run(): void {
		$on_time = $this->window( [ 'date_range' => 'yesterday', 'day_start' => '19:00' ], '2026-08-20 21:00:00' );
		$late    = $this->window( [ 'date_range' => 'yesterday', 'day_start' => '19:00' ], '2026-08-21 06:00:00' );

		$this->assertSame( $on_time, $late );
		$this->assertSame( [ '2026-08-19 19:00:00', '2026-08-20 18:59:59' ], $late );
	}

	/**
	 * Before the boundary has passed, we are still inside the day that
	 * opened yesterday evening. 6pm on the 20th is still the 19th's day.
	 */
	public function test_anchor_rolls_only_once_the_boundary_passes(): void {
		$before = $this->window( [ 'date_range' => 'today', 'day_start' => '19:00' ], '2026-08-20 18:59:59' );
		$after  = $this->window( [ 'date_range' => 'today', 'day_start' => '19:00' ], '2026-08-20 19:00:00' );

		$this->assertSame( [ '2026-08-19 19:00:00', '2026-08-20 18:59:59' ], $before );
		$this->assertSame( [ '2026-08-20 19:00:00', '2026-08-21 18:59:59' ], $after );
	}

	public function test_windows_abut_without_gap_or_overlap(): void {
		[ , $y_end ]   = $this->window( [ 'date_range' => 'yesterday', 'day_start' => '19:00' ], '2026-08-20 21:00:00' );
		[ $t_start, ] = $this->window( [ 'date_range' => 'today', 'day_start' => '19:00' ], '2026-08-20 21:00:00' );

		$this->assertSame(
			1,
			strtotime( $t_start . ' America/New_York' ) - strtotime( $y_end . ' America/New_York' ),
			'Consecutive windows must be exactly one second apart: no order can fall in both or neither.'
		);
	}

	// -----------------------------------------------------------------------
	// Daylight saving. The boundary is a wall-clock time, so it must hold at
	// 19:00 across both transitions even though those days are 23 and 25 hours.
	// -----------------------------------------------------------------------

	public function test_boundary_holds_at_1900_across_spring_forward(): void {
		// 2026-03-08: 02:00 EST becomes 03:00 EDT. The day that opened
		// on the 7th at 19:00 EST is 23 hours long.
		$this->assertSame(
			[ '2026-03-07 19:00:00', '2026-03-08 18:59:59' ],
			$this->window( [ 'date_range' => 'yesterday', 'day_start' => '19:00' ], '2026-03-08 21:00:00' )
		);
	}

	public function test_boundary_holds_at_1900_across_fall_back(): void {
		// 2026-11-01: 02:00 EDT returns to 01:00 EST. That day is 25
		// hours long and still runs 19:00 to 18:59:59.
		$this->assertSame(
			[ '2026-10-31 19:00:00', '2026-11-01 18:59:59' ],
			$this->window( [ 'date_range' => 'yesterday', 'day_start' => '19:00' ], '2026-11-01 21:00:00' )
		);
	}

	public function test_dst_days_are_23_and_25_hours_long(): void {
		$spring = Wooex_Data_Orders::resolve_dates(
			[ 'date_range' => 'yesterday', 'day_start' => '19:00' ],
			$this->ts( '2026-03-08 21:00:00' )
		);
		$fall = Wooex_Data_Orders::resolve_dates(
			[ 'date_range' => 'yesterday', 'day_start' => '19:00' ],
			$this->ts( '2026-11-01 21:00:00' )
		);

		// +1 because the window is inclusive and ends one second early.
		$this->assertSame( 23 * 3600, $spring['to'] - $spring['from'] + 1, 'spring-forward day' );
		$this->assertSame( 25 * 3600, $fall['to'] - $fall['from'] + 1, 'fall-back day' );
	}

	// -----------------------------------------------------------------------
	// The boundary shifts every range, not just Today and Yesterday.
	// -----------------------------------------------------------------------

	/**
	 * Wooex_Data_Orders::DAY_START_RANGES must describe what the resolver
	 * actually does, for every range, in both directions.
	 *
	 * The constant exists so the admin view and the JS can hide the control for
	 * ranges it does not affect. A constant that drifts from the switch it
	 * describes would hide a control that still works, or show one that does
	 * nothing, and nothing else would catch it.
	 */
	public function test_day_start_ranges_constant_matches_behaviour(): void {
		$now        = '2026-08-20 21:00:00';
		$all_ranges = [
			'today', 'yesterday', 'this_week', 'last_week', 'this_month',
			'last_month', 'this_year', 'last_year', 'all_time', 'custom',
		];

		foreach ( $all_ranges as $range ) {
			$base = [ 'date_range' => $range, 'date_from' => '2026-08-19', 'date_to' => '2026-08-19' ];

			$at_midnight = $this->window( $base + [ 'day_start' => '00:00' ], $now );
			$at_seven    = $this->window( $base + [ 'day_start' => '19:00' ], $now );

			$declared = in_array( $range, Wooex_Data_Orders::DAY_START_RANGES, true );
			$observed = ( $at_midnight !== $at_seven );

			$this->assertSame(
				$declared,
				$observed,
				$declared
					? "DAY_START_RANGES lists '$range', but the resolver ignores day_start for it."
					: "DAY_START_RANGES omits '$range', but the resolver shifts it anyway."
			);
		}
	}

	/**
	 * Week, month and year IGNORE the boundary completely.
	 *
	 * They are named calendar periods, and the dropdown labels them by that name
	 * — "Last Month (July)", "Last Year (2025)". Shifting them would return 19
	 * hours of August in a window the user picked as July, so the label and the
	 * data would disagree. Nobody sets a settlement cutoff in order to redefine
	 * what July means.
	 *
	 * Asserted as an exact equality against the same range at 00:00, so this
	 * cannot drift: if a future change starts shifting these, it fails here.
	 */
	public function test_week_month_and_year_ignore_the_boundary(): void {
		$now = '2026-08-20 21:00:00'; // Thursday; start_of_week is Sunday.

		foreach ( [ 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year' ] as $range ) {
			$this->assertSame(
				$this->window( [ 'date_range' => $range, 'day_start' => '00:00' ], $now ),
				$this->window( [ 'date_range' => $range, 'day_start' => '19:00' ], $now ),
				"$range must resolve identically whatever day_start says"
			);
		}
	}

	/**
	 * The concrete windows, spelled out. The point of writing these literally is
	 * that July ends on July 31 and 2025 ends on December 31, which is the whole
	 * substance of this design decision.
	 */
	public function test_calendar_periods_end_where_their_labels_say(): void {
		$now = '2026-08-20 21:00:00';
		$ds  = [ 'day_start' => '19:00' ];

		$this->assertSame(
			[ '2026-08-16 00:00:00', '2026-08-22 23:59:59' ],
			$this->window( $ds + [ 'date_range' => 'this_week' ], $now ),
			'this_week'
		);
		$this->assertSame(
			[ '2026-08-09 00:00:00', '2026-08-15 23:59:59' ],
			$this->window( $ds + [ 'date_range' => 'last_week' ], $now ),
			'last_week'
		);
		$this->assertSame(
			[ '2026-08-01 00:00:00', '2026-08-20 23:59:59' ],
			$this->window( $ds + [ 'date_range' => 'this_month' ], $now ),
			'this_month'
		);
		$this->assertSame(
			[ '2026-07-01 00:00:00', '2026-07-31 23:59:59' ],
			$this->window( $ds + [ 'date_range' => 'last_month' ], $now ),
			'Last Month (July) must end on July 31, not spill into August'
		);
		$this->assertSame(
			[ '2026-01-01 00:00:00', '2026-08-20 23:59:59' ],
			$this->window( $ds + [ 'date_range' => 'this_year' ], $now ),
			'this_year'
		);
		$this->assertSame(
			[ '2025-01-01 00:00:00', '2025-12-31 23:59:59' ],
			$this->window( $ds + [ 'date_range' => 'last_year' ], $now ),
			'Last Year (2025) must end on December 31, not spill into 2026'
		);
	}

	/**
	 * Calendar periods still tile with each other, as they always did. The
	 * boundary changing nothing here is exactly what keeps that true.
	 */
	public function test_consecutive_calendar_periods_abut(): void {
		$now = '2026-08-20 21:00:00';
		$ds  = [ 'day_start' => '19:00' ];

		[ , $last_week_end ]  = $this->window( $ds + [ 'date_range' => 'last_week' ], $now );
		[ $this_week_start, ] = $this->window( $ds + [ 'date_range' => 'this_week' ], $now );
		$this->assertSame( '2026-08-15 23:59:59', $last_week_end );
		$this->assertSame( '2026-08-16 00:00:00', $this_week_start );

		[ , $last_month_end ]  = $this->window( $ds + [ 'date_range' => 'last_month' ], $now );
		[ $this_month_start, ] = $this->window( $ds + [ 'date_range' => 'this_month' ], $now );
		$this->assertSame( '2026-07-31 23:59:59', $last_month_end );
		$this->assertSame( '2026-08-01 00:00:00', $this_month_start );
	}

	/**
	 * A single-day custom range picks up the same boundary, so the client's
	 * window can also be pulled as a one-off without doing the arithmetic.
	 */
	public function test_custom_single_day_spans_the_boundary(): void {
		$this->assertSame(
			[ '2026-08-19 19:00:00', '2026-08-20 18:59:59' ],
			$this->window(
				[ 'date_range' => 'custom', 'date_from' => '2026-08-19', 'date_to' => '2026-08-19', 'day_start' => '19:00' ],
				'2026-08-25 12:00:00'
			)
		);
	}

	public function test_all_time_ignores_the_boundary(): void {
		$r = Wooex_Data_Orders::resolve_dates(
			[ 'date_range' => 'all_time', 'day_start' => '19:00' ],
			$this->ts( '2026-08-20 21:00:00' )
		);
		$this->assertSame( [ 'from' => '', 'to' => '' ], $r );
	}

	// -----------------------------------------------------------------------
	// Bad input degrades to the old behaviour rather than to an odd window.
	// -----------------------------------------------------------------------

	/**
	 * @dataProvider malformedDayStarts
	 */
	public function test_malformed_day_start_falls_back_to_midnight( $raw ): void {
		$this->assertSame(
			[ '2026-08-19 00:00:00', '2026-08-19 23:59:59' ],
			$this->window(
				[ 'date_range' => 'yesterday', 'day_start' => $raw ],
				'2026-08-20 21:00:00'
			),
			'Malformed day_start must behave as 00:00, not as an arbitrary window.'
		);
	}

	public static function malformedDayStarts(): array {
		return [
			'empty'          => [ '' ],
			'hour only'      => [ '19' ],
			'out of range'   => [ '24:00' ],
			'bad minutes'    => [ '19:60' ],
			'garbage'        => [ 'seven pm' ],
			'null'           => [ null ],
			'array'          => [ [ '19:00' ] ],
			'sql-ish'        => [ "19:00' OR 1=1" ],
		];
	}

	public function test_missing_day_start_key_behaves_as_midnight(): void {
		$this->assertSame(
			$this->window( [ 'date_range' => 'yesterday', 'day_start' => '00:00' ], '2026-08-20 21:00:00' ),
			$this->window( [ 'date_range' => 'yesterday' ], '2026-08-20 21:00:00' )
		);
	}

	// -----------------------------------------------------------------------
	// The emailed "Date range:" line has to describe the window honestly.
	// -----------------------------------------------------------------------

	public function test_email_range_shows_times_when_boundary_is_not_midnight(): void {
		$out = Wooex_Mailer::format_range_for_filters(
			[ 'date_range' => 'yesterday', 'day_start' => '19:00' ],
			$this->ts( '2026-08-20 21:00:00' )
		);
		$this->assertSame( 'August 19, 2026 7:00 pm – August 20, 2026 6:59 pm', $out );
	}

	public function test_email_range_stays_date_only_at_midnight(): void {
		$out = Wooex_Mailer::format_range_for_filters(
			[ 'date_range' => 'yesterday', 'day_start' => '00:00' ],
			$this->ts( '2026-08-20 21:00:00' )
		);
		$this->assertSame( 'August 19, 2026', $out, 'Midnight boundary must not gain times.' );
	}

	/**
	 * The single-day collapse is suppressed when a boundary is set. A 19:00 day
	 * spans two calendar dates, so collapsing it to one would understate the
	 * window by several hours in both directions.
	 */
	public function test_email_range_does_not_collapse_a_shifted_single_day(): void {
		$out = Wooex_Mailer::format_range_for_filters(
			[ 'date_range' => 'custom', 'date_from' => '2026-08-19', 'date_to' => '2026-08-19', 'day_start' => '19:00' ],
			$this->ts( '2026-08-25 12:00:00' )
		);
		$this->assertStringContainsString( '–', $out );
		$this->assertStringContainsString( 'August 19, 2026 7:00 pm', $out );
		$this->assertStringContainsString( 'August 20, 2026 6:59 pm', $out );
	}
}

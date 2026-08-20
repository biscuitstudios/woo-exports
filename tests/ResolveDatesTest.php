<?php
/**
 * Regression guard for Wooex_Data_Orders::resolve_dates().
 *
 * Written BEFORE the day-start ("day starts at HH:MM") feature landed, and
 * before resolve_dates() switched its return type from wall-clock strings to
 * integer timestamps. Its whole job is to prove that both of those changes are
 * behaviour-preserving when `day_start` is absent or '00:00'.
 *
 * Two rules this file follows deliberately:
 *
 *  1. Expected values are re-derived here from first principles, not snapshotted
 *     from a previous run. A golden file keyed on "now" goes stale overnight.
 *
 *  2. Assertions are made against a CANONICAL form (see canonical()), so the
 *     exact same assertions run against the old string return and the new
 *     timestamp return. If this file needs editing to survive the refactor,
 *     the refactor changed behaviour and that is the thing to look at.
 *
 * This guard pins CURRENT behaviour. It is not a claim that current behaviour
 * is correct — see test_this_week_runs_to_end_of_week_not_today() for a live
 * inconsistency it locks in on purpose.
 */

namespace WooExports\Tests;

if ( ! defined( 'ABSPATH' ) ) exit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Wooex_Data_Orders;

final class ResolveDatesTest extends TestCase {

	/** Timezones either side of UTC, plus one with a non-hour offset. */
	private const TIMEZONES = [ 'UTC', 'America/New_York', 'America/Los_Angeles', 'Asia/Tokyo', 'Australia/Adelaide' ];

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wooex_test_options'] = [ 'start_of_week' => 1 ];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wooex_test_tz'], $GLOBALS['_wooex_test_options'] );
		parent::tearDown();
	}

	private function useTimezone( string $tz ): void {
		$GLOBALS['_wooex_test_tz'] = $tz;
	}

	/**
	 * Normalises whatever resolve_dates() returns into 'Y-m-d H:i:s' wall-clock
	 * in the site timezone.
	 *
	 * Pre-refactor the values ARE that string. Post-refactor they are integer
	 * timestamps. Both collapse to the same canonical form here, which is what
	 * lets one set of assertions straddle the change.
	 */
	private function canonical( $value ): string {
		if ( '' === $value || null === $value ) {
			return '';
		}
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return ( new DateTimeImmutable( '@' . (int) $value ) )
				->setTimezone( wp_timezone() )
				->format( 'Y-m-d H:i:s' );
		}
		return (string) $value;
	}

	/**
	 * Runs resolve_dates() and returns [from, to] canonicalised.
	 *
	 * Guards against the once-a-day flake where the clock crosses midnight
	 * between the test computing its expectation and resolve_dates() computing
	 * its own — the two would disagree by a day for reasons unrelated to the
	 * code under test.
	 */
	private function resolve( array $filters ): array {
		$before = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
		$out    = Wooex_Data_Orders::resolve_dates( $filters );
		$after  = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );

		if ( $before !== $after ) {
			$this->markTestSkipped( 'Clock crossed midnight mid-test; expectation and result are a day apart for reasons unrelated to the code under test.' );
		}

		return [ $this->canonical( $out['from'] ?? '' ), $this->canonical( $out['to'] ?? '' ) ];
	}

	private function today(): DateTimeImmutable {
		return new DateTimeImmutable( 'today', wp_timezone() );
	}

	private function dayStart( DateTimeImmutable $d ): string {
		return $d->format( 'Y-m-d' ) . ' 00:00:00';
	}

	private function dayEnd( DateTimeImmutable $d ): string {
		return $d->format( 'Y-m-d' ) . ' 23:59:59';
	}

	/**
	 * Independent restatement of Wooex_Data_Orders::week_range(), which is
	 * private. Deliberately a copy: the guard's job is "did the refactor move
	 * this", not "was this ever right".
	 */
	private function weekRange( DateTimeImmutable $today, int $offset_weeks ): array {
		$start_of_week = (int) get_option( 'start_of_week', 1 );
		$diff_days     = ( (int) $today->format( 'w' ) - $start_of_week + 7 ) % 7;

		$start = $today->modify( "-{$diff_days} days" );
		if ( 0 !== $offset_weeks ) {
			$start = $start->modify( ( $offset_weeks * 7 ) . ' days' );
		}
		return [ $start, $start->modify( '+6 days' ) ];
	}

	// -----------------------------------------------------------------------
	// Every range, every timezone, no day_start key at all (the saved shape of
	// every report that exists today).
	// -----------------------------------------------------------------------

	public function test_today(): void {
		foreach ( self::TIMEZONES as $tz ) {
			$this->useTimezone( $tz );
			$t = $this->today();
			$this->assertSame(
				[ $this->dayStart( $t ), $this->dayEnd( $t ) ],
				$this->resolve( [ 'date_range' => 'today' ] ),
				"today in $tz"
			);
		}
	}

	public function test_yesterday(): void {
		foreach ( self::TIMEZONES as $tz ) {
			$this->useTimezone( $tz );
			$y = $this->today()->modify( '-1 day' );
			$this->assertSame(
				[ $this->dayStart( $y ), $this->dayEnd( $y ) ],
				$this->resolve( [ 'date_range' => 'yesterday' ] ),
				"yesterday in $tz"
			);
		}
	}

	public function test_this_week_and_last_week_across_start_of_week_settings(): void {
		foreach ( [ 0, 1, 6 ] as $sow ) {
			$GLOBALS['_wooex_test_options']['start_of_week'] = $sow;
			foreach ( self::TIMEZONES as $tz ) {
				$this->useTimezone( $tz );
				$t = $this->today();

				[ $ts, $te ] = $this->weekRange( $t, 0 );
				$this->assertSame(
					[ $this->dayStart( $ts ), $this->dayEnd( $te ) ],
					$this->resolve( [ 'date_range' => 'this_week' ] ),
					"this_week in $tz, start_of_week=$sow"
				);

				[ $ls, $le ] = $this->weekRange( $t, -1 );
				$this->assertSame(
					[ $this->dayStart( $ls ), $this->dayEnd( $le ) ],
					$this->resolve( [ 'date_range' => 'last_week' ] ),
					"last_week in $tz, start_of_week=$sow"
				);
			}
		}
	}

	public function test_this_month_ends_today_not_end_of_month(): void {
		foreach ( self::TIMEZONES as $tz ) {
			$this->useTimezone( $tz );
			$t = $this->today();
			$this->assertSame(
				[ $this->dayStart( $t->modify( 'first day of this month' ) ), $this->dayEnd( $t ) ],
				$this->resolve( [ 'date_range' => 'this_month' ] ),
				"this_month in $tz"
			);
		}
	}

	public function test_last_month(): void {
		foreach ( self::TIMEZONES as $tz ) {
			$this->useTimezone( $tz );
			$t = $this->today();
			$this->assertSame(
				[
					$this->dayStart( $t->modify( 'first day of last month' ) ),
					$this->dayEnd( $t->modify( 'last day of last month' ) ),
				],
				$this->resolve( [ 'date_range' => 'last_month' ] ),
				"last_month in $tz"
			);
		}
	}

	public function test_this_year_ends_today_not_end_of_year(): void {
		foreach ( self::TIMEZONES as $tz ) {
			$this->useTimezone( $tz );
			$t = $this->today();
			$this->assertSame(
				[ $t->format( 'Y' ) . '-01-01 00:00:00', $this->dayEnd( $t ) ],
				$this->resolve( [ 'date_range' => 'this_year' ] ),
				"this_year in $tz"
			);
		}
	}

	public function test_last_year(): void {
		foreach ( self::TIMEZONES as $tz ) {
			$this->useTimezone( $tz );
			$year = (int) $this->today()->format( 'Y' ) - 1;
			$this->assertSame(
				[ "{$year}-01-01 00:00:00", "{$year}-12-31 23:59:59" ],
				$this->resolve( [ 'date_range' => 'last_year' ] ),
				"last_year in $tz"
			);
		}
	}

	public function test_all_time_returns_empty_both_ends(): void {
		foreach ( self::TIMEZONES as $tz ) {
			$this->useTimezone( $tz );
			$this->assertSame( [ '', '' ], $this->resolve( [ 'date_range' => 'all_time' ] ), "all_time in $tz" );
		}
	}

	public function test_custom_multi_day(): void {
		foreach ( self::TIMEZONES as $tz ) {
			$this->useTimezone( $tz );
			$this->assertSame(
				[ '2026-05-10 00:00:00', '2026-05-16 23:59:59' ],
				$this->resolve( [ 'date_range' => 'custom', 'date_from' => '2026-05-10', 'date_to' => '2026-05-16' ] ),
				"custom multi-day in $tz"
			);
		}
	}

	public function test_custom_single_day(): void {
		$this->useTimezone( 'America/New_York' );
		$this->assertSame(
			[ '2026-05-15 00:00:00', '2026-05-15 23:59:59' ],
			$this->resolve( [ 'date_range' => 'custom', 'date_from' => '2026-05-15', 'date_to' => '2026-05-15' ] )
		);
	}

	public function test_custom_with_either_date_missing_falls_back_to_all_time(): void {
		$this->useTimezone( 'America/New_York' );
		foreach ( [ [ '', '2026-05-16' ], [ '2026-05-10', '' ], [ '', '' ] ] as [ $from, $to ] ) {
			$this->assertSame(
				[ '', '' ],
				$this->resolve( [ 'date_range' => 'custom', 'date_from' => $from, 'date_to' => $to ] ),
				"custom with from='$from' to='$to'"
			);
		}
	}

	public function test_unknown_and_missing_range_fall_back_to_today(): void {
		$this->useTimezone( 'America/New_York' );
		$t        = $this->today();
		$expected = [ $this->dayStart( $t ), $this->dayEnd( $t ) ];

		$this->assertSame( $expected, $this->resolve( [ 'date_range' => 'not_a_range' ] ), 'unknown range' );
		$this->assertSame( $expected, $this->resolve( [] ), 'missing date_range key' );
	}

	// -----------------------------------------------------------------------
	// Behaviour this guard locks in on purpose, so a later change has to be a
	// decision rather than an accident.
	// -----------------------------------------------------------------------

	/**
	 * this_week runs to the END of the current week, which is in the future,
	 * while this_month and this_year stop at today. That is inconsistent, it is
	 * what the plugin does today, and the day-start work is not the place to
	 * change it.
	 */
	public function test_this_week_runs_to_end_of_week_not_today(): void {
		$GLOBALS['_wooex_test_options']['start_of_week'] = 1;
		$this->useTimezone( 'America/New_York' );

		$t             = $this->today();
		$days_into_week = ( (int) $t->format( 'w' ) - 1 + 7 ) % 7;
		if ( 6 === $days_into_week ) {
			$this->markTestSkipped( 'Today is the last day of the week; the future-end behaviour is unobservable.' );
		}

		[ , $to ]    = $this->resolve( [ 'date_range' => 'this_week' ] );
		[ , $wk_end ] = $this->weekRange( $t, 0 );

		$this->assertSame( $this->dayEnd( $wk_end ), $to );
		$this->assertGreaterThanOrEqual( $this->dayEnd( $t ), $to, 'this_week end should not stop at today' );
	}

	/**
	 * Every window is inclusive of both endpoints and ends on :59, never on the
	 * next midnight. The day-start work changes what the endpoints ARE; it must
	 * not change this shape, or ranges start double-counting the boundary order.
	 */
	public function test_all_bounded_ranges_are_inclusive_and_end_on_59(): void {
		$this->useTimezone( 'America/New_York' );
		$ranges = [ 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year' ];

		foreach ( $ranges as $range ) {
			[ $from, $to ] = $this->resolve( [ 'date_range' => $range ] );
			$this->assertStringEndsWith( ' 00:00:00', $from, "$range from" );
			$this->assertStringEndsWith( ' 23:59:59', $to, "$range to" );
			$this->assertLessThan( $to, $from, "$range from must precede to" );
		}
	}
}

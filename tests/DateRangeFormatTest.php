<?php
/**
 * Smoke tests for the human-readable date-range string used in scheduled-export
 * emails. Two specific bugs are pinned here:
 *
 *  1. Timezone-shift via strtotime() — WordPress sets PHP's default timezone to
 *     UTC, so a "2026-05-10 00:00:00" string from resolve_dates() (which is
 *     wall-clock in *site* timezone) gets parsed as UTC, then re-formatted in
 *     site TZ — shifting the display back one day for sites east of UTC.
 *
 *  2. Single-day ranges (Today, Yesterday, single-day custom) showing as
 *     "May 20, 2026 – May 20, 2026" instead of "May 20, 2026".
 */

namespace WooExports\Tests;

// Direct web access exits cleanly; under PHPUnit tests/bootstrap.php
// defines ABSPATH before this file loads.
if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;
use Wooex_Mailer;

final class DateRangeFormatTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_wooex_test_options'] = [
			'date_format'   => 'F j, Y',
			'start_of_week' => 0, // Sunday — the configuration the bug reproduces on
		];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wooex_test_tz'], $GLOBALS['_wooex_test_options'] );
		parent::tearDown();
	}

	private function setSiteTimezone( string $tz ): void {
		$GLOBALS['_wooex_test_tz'] = $tz;
	}

	public function test_today_collapses_to_single_date(): void {
		$this->setSiteTimezone( 'America/New_York' );
		$out = Wooex_Mailer::format_range_for_filters( [ 'date_range' => 'today' ] );
		$this->assertStringNotContainsString( '–', $out, 'Single-day "today" range should not render an en-dash' );
		$this->assertMatchesRegularExpression( '/^[A-Z][a-z]+ \d{1,2}, \d{4}$/', $out );
	}

	public function test_yesterday_collapses_to_single_date(): void {
		$this->setSiteTimezone( 'America/New_York' );
		$out = Wooex_Mailer::format_range_for_filters( [ 'date_range' => 'yesterday' ] );
		$this->assertStringNotContainsString( '–', $out, 'Single-day "yesterday" range should not render an en-dash' );
		$this->assertMatchesRegularExpression( '/^[A-Z][a-z]+ \d{1,2}, \d{4}$/', $out );
	}

	public function test_custom_same_day_collapses(): void {
		$this->setSiteTimezone( 'America/New_York' );
		$out = Wooex_Mailer::format_range_for_filters( [
			'date_range' => 'custom',
			'date_from'  => '2026-05-15',
			'date_to'    => '2026-05-15',
		] );
		$this->assertSame( 'May 15, 2026', $out );
	}

	public function test_last_week_is_seven_days(): void {
		$this->setSiteTimezone( 'America/New_York' );
		$out = Wooex_Mailer::format_range_for_filters( [ 'date_range' => 'last_week' ] );

		// Must render as "From – To" with both halves on the same day-of-week
		// 7 days apart (depending on start_of_week, but a full week either way).
		$this->assertMatchesRegularExpression(
			'/^[A-Z][a-z]+ \d{1,2}, \d{4} – [A-Z][a-z]+ \d{1,2}, \d{4}$/',
			$out
		);
		[ $from, $to ] = array_map( 'trim', explode( '–', $out ) );
		$from_ts = strtotime( $from );
		$to_ts   = strtotime( $to );
		$this->assertSame(
			6 * 86400, // 6 day gaps = 7 days inclusive
			$to_ts - $from_ts,
			"Last Week should span exactly 7 days inclusive, got: $out"
		);
	}

	public function test_timezone_does_not_shift_display_dates(): void {
		// The original bug only manifested east of UTC. Custom range is the
		// cleanest test because the input dates are explicit.
		foreach ( [ 'UTC', 'America/New_York', 'Asia/Tokyo', 'Australia/Sydney' ] as $tz ) {
			$this->setSiteTimezone( $tz );
			$out = Wooex_Mailer::format_range_for_filters( [
				'date_range' => 'custom',
				'date_from'  => '2026-05-10',
				'date_to'    => '2026-05-16',
			] );
			$this->assertSame(
				'May 10, 2026 – May 16, 2026',
				$out,
				"Date range should render identically regardless of site timezone (failed for $tz)"
			);
		}
	}

	public function test_all_time_returns_empty(): void {
		$out = Wooex_Mailer::format_range_for_filters( [ 'date_range' => 'all_time' ] );
		$this->assertSame( '', $out, 'All Time has no defined range — must return empty so the email omits the line' );
	}

	public function test_custom_with_missing_dates_returns_empty(): void {
		$out = Wooex_Mailer::format_range_for_filters( [
			'date_range' => 'custom',
			'date_from'  => '',
			'date_to'    => '',
		] );
		$this->assertSame( '', $out );
	}
}

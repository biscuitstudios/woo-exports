<?php
/**
 * Smoke tests for Wooex_Scheduler::next_run_timestamp.
 *
 * Timezone math is the most likely place for this code to regress — WordPress
 * force-sets PHP's default timezone to UTC, so anything reading `time()` and
 * formatting against a local-wall-clock schedule has to be careful. These tests
 * lock in the contract: the scheduler produces the right moment in time
 * regardless of the site timezone.
 */

namespace WooExports\Tests;

// Direct web access exits cleanly; under PHPUnit tests/bootstrap.php
// defines ABSPATH before this file loads.
if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;
use Wooex_Scheduler;

final class SchedulerTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['_wooex_test_tz'] );
		parent::tearDown();
	}

	private function setSiteTimezone( string $tz ): void {
		$GLOBALS['_wooex_test_tz'] = $tz;
	}

	public function test_daily_returns_next_occurrence_in_site_tz(): void {
		$this->setSiteTimezone( 'America/New_York' );

		$report = [
			'schedule' => [ 'frequency' => 'daily', 'time' => '09:00' ],
		];
		$next   = Wooex_Scheduler::next_run_timestamp( $report );

		$this->assertNotNull( $next );
		$dt = ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( new \DateTimeZone( 'America/New_York' ) );
		$this->assertSame( '09:00', $dt->format( 'H:i' ), 'Daily schedule should land at 09:00 in the site timezone' );
		$this->assertGreaterThan( time(), $next, 'Next run must be in the future' );
	}

	public function test_invalid_time_falls_back_to_default(): void {
		$report = [
			'schedule' => [ 'frequency' => 'daily', 'time' => 'totally-invalid' ],
		];
		$next   = Wooex_Scheduler::next_run_timestamp( $report );

		$this->assertNotNull( $next );
		// Default fallback is 06:00.
		$dt = ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() );
		$this->assertSame( '06:00', $dt->format( 'H:i' ) );
	}

	public function test_weekly_picks_nearest_selected_day(): void {
		$this->setSiteTimezone( 'UTC' );

		$report = [
			'schedule' => [
				'frequency' => 'weekly',
				'time'      => '12:00',
				'days'      => [ 'monday', 'thursday' ],
			],
		];
		$next   = Wooex_Scheduler::next_run_timestamp( $report );

		$this->assertNotNull( $next );
		$day = ( new \DateTimeImmutable( '@' . $next ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'l' );
		$this->assertContains( $day, [ 'Monday', 'Thursday' ], "Next weekly run should be Mon or Thu, got $day" );
	}

	public function test_weekly_supports_legacy_single_day_field(): void {
		$report = [
			'schedule' => [
				'frequency' => 'weekly',
				'time'      => '08:00',
				'day'       => 'wednesday', // legacy single-day field
			],
		];
		$next   = Wooex_Scheduler::next_run_timestamp( $report );

		$this->assertNotNull( $next );
		$day = ( new \DateTimeImmutable( '@' . $next ) )->format( 'l' );
		$this->assertSame( 'Wednesday', $day );
	}

	public function test_monthly_clamps_dom_to_28(): void {
		$report = [
			'schedule' => [ 'frequency' => 'monthly', 'time' => '03:00', 'day_of_month' => 99 ],
		];
		$next   = Wooex_Scheduler::next_run_timestamp( $report );

		$this->assertNotNull( $next );
		$dom = (int) ( new \DateTimeImmutable( '@' . $next ) )->format( 'j' );
		$this->assertSame( 28, $dom, 'Day-of-month should be clamped to 28 to dodge Feb edge cases' );
	}

	public function test_unknown_frequency_returns_null(): void {
		$this->assertNull(
			Wooex_Scheduler::next_run_timestamp( [ 'schedule' => [ 'frequency' => 'hourly' ] ] )
		);
	}
}

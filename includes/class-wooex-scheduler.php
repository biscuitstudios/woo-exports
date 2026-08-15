<?php
/**
 * Action Scheduler integration. One-shot rescheduling pattern (no recurring actions).
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wooex_Scheduler {

	public const HOOK  = 'wooex_run_report';
	public const GROUP = 'woo-exports';

	public function init(): void {
		add_action( self::HOOK, [ $this, 'run_report' ], 10, 1 );
	}

	/**
	 * Action Scheduler callback. Executes a report end-to-end and queues the next run.
	 */
	public function run_report( string $report_id ): void {
		$report = Wooex_Report_Store::get( $report_id );
		if ( ! $report ) {
			error_log( '[WooExports] Scheduler: report not found: ' . $report_id );
			return;
		}

		if ( empty( $report['active'] ) ) {
			error_log( '[WooExports] Scheduler: report inactive, skipping: ' . $report_id );
			return;
		}

		$file = Wooex_Exporter::run( $report );
		$rows = (int) Wooex_Exporter::$last_row_count;

		if ( false === $file ) {
			$report['last_run']         = time();
			$report['last_run_status']  = 'failed';
			$report['last_run_message'] = 'Export failed — see PHP error log for [WooExports] entries.';
			Wooex_Report_Store::save( $report );
			$this->schedule( $report );
			return;
		}

		$sent = Wooex_Mailer::send( $report, $file );

		$report['last_run']         = time();
		$report['last_run_status']  = $sent ? 'success' : 'failed';
		$report['last_run_message'] = $sent
			? sprintf(
				'%s row(s) emailed to %d recipient(s).',
				number_format_i18n( $rows ),
				count( (array) ( $report['recipients'] ?? [] ) )
			)
			: 'Email send failed — file retained on disk for retry.';

		Wooex_Report_Store::save( $report );
		$this->schedule( $report );
	}

	public function schedule_all(): void {
		foreach ( Wooex_Report_Store::all() as $report ) {
			if ( ! empty( $report['active'] ) ) {
				$this->schedule( $report );
			}
		}
	}

	/**
	 * Compute the next run, queue an Action Scheduler single action, and persist next_run.
	 */
	public function schedule( array $report ): bool {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return false;
		}
		if ( empty( $report['id'] ) ) {
			return false;
		}

		$this->unschedule( $report['id'] );

		$next = self::next_run_timestamp( $report );
		if ( null === $next ) {
			error_log( '[WooExports] Scheduler: could not compute next_run for report ' . $report['id'] );
			return false;
		}

		as_schedule_single_action( $next, self::HOOK, [ $report['id'] ], self::GROUP );

		$report['next_run'] = $next;
		Wooex_Report_Store::save( $report );

		return true;
	}

	public function unschedule( string $report_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [ $report_id ], self::GROUP );
		}
	}

	/**
	 * Resolves the next run timestamp in the site timezone based on the schedule sub-array.
	 * Returns null on malformed schedule input.
	 */
	public static function next_run_timestamp( array $report ): ?int {
		$schedule = (array) ( $report['schedule'] ?? [] );
		$tz       = wp_timezone();

		$time = (string) ( $schedule['time'] ?? '06:00' );
		if ( ! preg_match( '/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $time ) ) {
			$time = '06:00';
		}
		[ $hh, $mm ] = array_map( 'intval', explode( ':', $time ) );

		try {
			switch ( $schedule['frequency'] ?? 'daily' ) {
				case 'daily':
					$dt = new DateTimeImmutable( 'now', $tz );
					$dt = $dt->setTime( $hh, $mm, 0 );
					if ( $dt->getTimestamp() <= time() ) {
						$dt = $dt->modify( '+1 day' );
					}
					break;

				case 'weekly':
					$valid = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
					$days  = (array) ( $schedule['days'] ?? [] );
					if ( empty( $days ) && ! empty( $schedule['day'] ) ) {
						$days = [ $schedule['day'] ];
					}
					$days = array_values( array_intersect( $valid, array_map( 'strtolower', array_map( 'strval', $days ) ) ) );
					if ( empty( $days ) ) {
						$days = [ 'monday' ];
					}

					$candidates = [];
					foreach ( $days as $day ) {
						$now = new DateTimeImmutable( 'now', $tz );
						if ( strtolower( $now->format( 'l' ) ) === $day ) {
							$today = $now->setTime( $hh, $mm, 0 );
							if ( $today->getTimestamp() > time() ) {
								$candidates[] = $today;
								continue;
							}
						}
						$candidates[] = ( new DateTimeImmutable( 'next ' . $day, $tz ) )->setTime( $hh, $mm, 0 );
					}

					usort( $candidates, static fn( $a, $b ) => $a->getTimestamp() <=> $b->getTimestamp() );
					$dt = $candidates[0];
					break;

				case 'monthly':
					$dom = (int) ( $schedule['day_of_month'] ?? 1 );
					$dom = max( 1, min( 28, $dom ) );

					$now = new DateTimeImmutable( 'now', $tz );
					$dt  = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'm' ), $dom )
						->setTime( $hh, $mm, 0 );
					if ( $dt->getTimestamp() <= time() ) {
						$dt = $dt->modify( '+1 month' );
					}
					break;

				default:
					return null;
			}
			return $dt->getTimestamp();
		} catch ( \Throwable $e ) {
			error_log( '[WooExports] Scheduler: next_run_timestamp failed: ' . $e->getMessage() );
			return null;
		}
	}
}

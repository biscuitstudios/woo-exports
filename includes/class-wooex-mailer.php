<?php
/**
 * Sends a generated export as an email attachment.
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wooex_Mailer {

	public static function send( array $report, string $file_path ): bool {
		if ( ! is_string( $file_path ) || '' === $file_path || ! file_exists( $file_path ) ) {
			error_log( '[WooExports] Mailer: file not found: ' . (string) $file_path );
			return false;
		}

		$recipients = self::clean_recipients( $report['recipients'] ?? [] );
		if ( empty( $recipients ) ) {
			error_log( '[WooExports] Mailer: no valid recipients for report ' . ( $report['id'] ?? '?' ) );
			return false;
		}

		$site_name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$report_name = (string) ( $report['name'] ?? ucfirst( (string) ( $report['type'] ?? 'Export' ) ) );
		$subject     = sprintf( '[%s] %s — %s', $site_name, $report_name, wp_date( 'F j, Y' ) );

		$body    = self::build_body( $report, $site_name, $report_name );
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];

		$sent = wp_mail( $recipients, $subject, $body, $headers, [ $file_path ] );

		// Delete in both paths. There's no retry mechanism, so keeping a failed-
		// send file on disk only enlarges the exposure window without giving us
		// anything to retry from. The error log captures the failure for diagnosis.
		@unlink( $file_path );

		if ( $sent ) {
			return true;
		}

		error_log(
			'[WooExports] Email failed for report ' . ( $report['id'] ?? '?' )
			. ' — file discarded.'
		);
		return false;
	}

	private static function clean_recipients( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,;]+/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}

		$out = [];
		foreach ( $raw as $email ) {
			$clean = sanitize_email( (string) $email );
			if ( $clean && is_email( $clean ) ) {
				$out[] = $clean;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function build_body( array $report, string $site_name, string $report_name ): string {
		$rows_count = (int) Wooex_Exporter::$last_row_count;
		$range_str  = self::format_range_for_filters( (array) ( $report['filters'] ?? [] ) );

		$rows = [];
		$rows[] = '<p>Hi,</p>';
		$rows[] = sprintf(
			'<p>Your <strong>%s</strong> export from %s is attached.</p>',
			esc_html( $report_name ),
			esc_html( $site_name )
		);
		if ( $range_str ) {
			$rows[] = sprintf( '<p><strong>Date range:</strong> %s</p>', esc_html( $range_str ) );
		}
		$rows[] = sprintf( '<p><strong>Rows:</strong> %s</p>', number_format_i18n( $rows_count ) );
		$rows[] = '<p style="color:#777;font-size:12px;margin-top:24px;">— Sent automatically by Woo Exports.</p>';

		return implode( "\n", $rows );
	}

	/**
	 * Builds the human-readable date range from a report's filters. Returns ''
	 * for All Time (no range) or any malformed/missing dates.
	 *
	 * Single-day ranges (Today, Yesterday, a custom range where from == to)
	 * collapse to a single date — e.g. "May 20, 2026" instead of
	 * "May 20, 2026 – May 20, 2026".
	 *
	 * Public + static so the test suite can hit it without instantiating WP.
	 */
	public static function format_range_for_filters( array $filters, ?int $now = null ): string {
		$resolved = Wooex_Data_Orders::resolve_dates( $filters, $now );

		$from_ts = $resolved['from'] ?? '';
		$to_ts   = $resolved['to'] ?? '';
		if ( ! is_int( $from_ts ) || ! is_int( $to_ts ) ) {
			return '';
		}

		$date_fmt = (string) get_option( 'date_format', 'F j, Y' );

		// resolve_dates() returns timestamps, so wp_date() renders them in the
		// site timezone directly. The predecessor of this code parsed wall-clock
		// strings with strtotime(), which WordPress evaluates as UTC, shifting
		// every displayed date by the site's offset.
		if ( ! Wooex_Data_Orders::has_custom_day_start( $filters ) ) {
			$from_str = wp_date( $date_fmt, $from_ts );
			$to_str   = wp_date( $date_fmt, $to_ts );

			return ( $from_str === $to_str ) ? $from_str : ( $from_str . ' – ' . $to_str );
		}

		// A day that starts at, say, 19:00 spans two calendar dates, so the
		// dates alone would misdescribe the window by several hours in both
		// directions. Show times, and never collapse to a single date.
		$time_fmt = (string) get_option( 'time_format', 'g:i a' );
		$stamp    = $date_fmt . ' ' . $time_fmt;

		return wp_date( $stamp, $from_ts ) . ' – ' . wp_date( $stamp, $to_ts );
	}
}

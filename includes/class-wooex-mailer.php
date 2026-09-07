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

	/**
	 * Builds the HTML email body.
	 *
	 * Visual language follows the Spill notification email: a neutral gray
	 * page, a single white card with a large bold headline, and quiet gray
	 * supporting text. Tables and inline styles throughout, because Outlook
	 * ignores <style> blocks, float and flexbox. Widths are fixed in px for
	 * the same reason, with max-width for the phone clients that honor it.
	 */
	private static function build_body( array $report, string $site_name, string $report_name ): string {
		$rows_count = (int) Wooex_Exporter::$last_row_count;
		$range_str  = self::format_range_for_filters( (array) ( $report['filters'] ?? [] ) );

		$font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";

		// Meta lines under the headline. Date range is omitted for All Time,
		// which has no range to state.
		$meta = [];
		if ( $range_str ) {
			$meta['Date range'] = $range_str;
		}
		// Name what was counted — "Attendees", not "Rows". The type is the
		// report's own, so the label matches the file that is attached.
		$count_label          = Wooex_Exporter::type_label( (string) ( $report['type'] ?? '' ) );
		$meta[ $count_label ] = number_format_i18n( $rows_count );

		$meta_html = '';
		foreach ( $meta as $label => $value ) {
			$meta_html .= sprintf(
				'<tr>
					<td style="padding:0 0 8px 0;font-family:%1$s;font-size:14px;line-height:20px;color:#8a8a8a;white-space:nowrap;" width="110">%2$s</td>
					<td style="padding:0 0 8px 0;font-family:%1$s;font-size:14px;line-height:20px;color:#333333;font-weight:600;">%3$s</td>
				</tr>',
				$font,
				esc_html( $label ),
				esc_html( $value )
			);
		}

		return sprintf(
			'<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>%2$s</title>
</head>
<body style="margin:0;padding:0;background-color:#ebebeb;">
<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0" style="background-color:#ebebeb;">
	<tr>
		<td align="center" style="padding:40px 20px;">
			<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:560px;max-width:100%%;">

				<!-- Wordmark -->
				<tr>
					<td style="padding:0 0 28px 0;font-family:%1$s;font-size:26px;line-height:32px;font-weight:800;letter-spacing:-0.5px;color:#111111;">%2$s</td>
				</tr>

				<!-- Card -->
				<tr>
					<td style="background-color:#ffffff;border-radius:14px;padding:40px;">
						<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" border="0">
							<tr>
								<td style="padding:0 0 20px 0;font-family:%1$s;font-size:24px;line-height:32px;font-weight:700;letter-spacing:-0.3px;color:#111111;">Your %3$s export is attached.</td>
							</tr>
							<tr>
								<td style="padding:0 0 24px 0;border-bottom:1px solid #ececec;font-family:%1$s;font-size:15px;line-height:22px;color:#555555;">Generated from %2$s.</td>
							</tr>
							<tr>
								<td style="padding:24px 0 0 0;">
									<table role="presentation" cellpadding="0" cellspacing="0" border="0">%4$s</table>
								</td>
							</tr>
						</table>
					</td>
				</tr>

				<!-- Footer -->
				<tr>
					<td style="padding:24px 4px 0 4px;font-family:%1$s;font-size:13px;line-height:19px;color:#8a8a8a;">Sent automatically by Woo Exports.</td>
				</tr>

			</table>
		</td>
	</tr>
</table>
</body>
</html>',
			$font,
			esc_html( $site_name ),
			esc_html( $report_name ),
			$meta_html
		);
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

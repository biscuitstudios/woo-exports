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

	/**
	 * The card's widest rendered width, in px.
	 *
	 * Not a fixed width any more. The shell is `width:100%` with this as a
	 * `max-width`, so the layout shrinks to whatever viewport it lands in, and
	 * an Outlook-only conditional table pins it to this number for the one
	 * engine that neither reflows nor honours max-width.
	 *
	 * 560 was too narrow for a boundary-shifted range, which prints two full
	 * date-and-time stamps either side of a dash and wrapped mid-date.
	 */
	private const CARD_WIDTH = 680;

	/**
	 * Viewport below which the meta rows stack, label above value.
	 *
	 * Where the two-column form actually runs out of room: the content box is
	 * the viewport less 40px of shell padding and 80px of card padding, and it
	 * has to hold a ~88px label column plus a range carrying two full
	 * date-and-time stamps at ~350px. That puts the real floor near 560, so 620
	 * leaves a margin. Erring toward stacking is the safe direction, because a
	 * stacked row at a width that could have held two columns still looks
	 * deliberate, and a crowded two-column row does not.
	 */
	private const STACK_BREAKPOINT = 620;

	/**
	 * Viewport below which the padding and type scale come down too.
	 *
	 * Separate from STACK_BREAKPOINT on purpose. Stacking is a layout
	 * necessity that starts mattering around tablet width; 40px of card
	 * padding and a 24px headline only become wrong on a phone. Sharing one
	 * number made a 619px viewport render the phone treatment, which fit fine
	 * but read as cramped for no reason.
	 */
	private const COMPACT_BREAKPOINT = 480;

	/**
	 * Every colour the email uses, stated once per scheme.
	 *
	 * The keys are the tokens the template carries. build_body() runs one
	 * strtr() pass over its finished output with both maps merged, which fills
	 * the `{...}` tokens in the inline styles from PALETTE and the `{dark-...}`
	 * tokens in the dark-mode block from PALETTE_DARK. Same seven roles either
	 * way, so a colour can only be changed in one place and the two schemes
	 * cannot drift into describing different designs.
	 *
	 * Substituted after sprintf, never before, so a value can never be read as
	 * a format specifier. Hex colours contain no `%` today; doing it in this
	 * order means it would not matter if one ever did.
	 *
	 * The two maps share no key prefix (`{p...}` against `{d...}`), so strtr
	 * cannot partially match one token against the other.
	 */
	private const PALETTE = [
		'{page}'    => '#ebebeb',
		'{card}'    => '#ffffff',
		'{heading}' => '#111111',
		'{lede}'    => '#555555',
		'{value}'   => '#333333',
		'{quiet}'   => '#767676',
		'{rule}'    => '#ececec',
	];

	/**
	 * The same seven roles for a reader whose machine is set to dark.
	 *
	 * Not an inversion of the light palette. A literal inversion gives pure
	 * white text on pure black, which is harsh to read and loses the card as a
	 * distinct surface. These were chosen against measured WCAG contrast rather
	 * than by eye, and they hold the light design's two structural
	 * relationships: the card sits 1.24:1 above the ground (light is 1.19:1)
	 * and the hairline sits 1.22:1 above the card (light is 1.18:1).
	 *
	 * Every text pair clears AA. `{quiet}` is 5.38:1 on the card here, where
	 * the light palette's equivalent is 3.45:1 and fails. That is a
	 * pre-existing problem in the light design, not something introduced by
	 * choosing these, and it is logged as item 11 in `Suggested fixes.md`
	 * rather than fixed quietly. `tests/SendTest.php` measures both and carries
	 * the light failures as named exceptions, so they cannot get worse
	 * unnoticed and cannot be forgotten.
	 */
	private const PALETTE_DARK = [
		'{dark-page}'    => '#121212',
		'{dark-card}'    => '#262626',
		'{dark-heading}' => '#f5f5f5',
		'{dark-lede}'    => '#b9b9b9',
		'{dark-value}'   => '#e6e6e6',
		'{dark-quiet}'   => '#9a9a9a',
		'{dark-rule}'    => '#343434',
	];

	/**
	 * Both maps merged, for the single substitution pass.
	 *
	 * A method rather than a third constant so the two palettes stay the only
	 * places a colour is written down.
	 */
	private static function colour_tokens(): array {
		return self::PALETTE + self::PALETTE_DARK;
	}

	/**
	 * Send a generated export file.
	 *
	 * @param array    $report              Report config.
	 * @param string   $file_path           Absolute path to the generated file.
	 * @param string[] $recipients_override Send here instead of the report's own
	 *                                      recipients. Used by the manual send,
	 *                                      where the addresses are typed at the
	 *                                      time rather than saved on the report.
	 * @param bool     $manual              Whether this was a person pressing a
	 *                                      button. Only changes the footer line.
	 */
	public static function send( array $report, string $file_path, array $recipients_override = [], bool $manual = false ): bool {
		if ( ! is_string( $file_path ) || '' === $file_path || ! file_exists( $file_path ) ) {
			error_log( '[WooExports] Mailer: file not found: ' . (string) $file_path );
			return false;
		}

		// An empty override falls back to the report's own list rather than
		// sending nowhere, so a caller passing [] behaves exactly as before.
		$recipients = self::clean_recipients(
			! empty( $recipients_override ) ? $recipients_override : ( $report['recipients'] ?? [] )
		);
		if ( empty( $recipients ) ) {
			error_log( '[WooExports] Mailer: no valid recipients for report ' . ( $report['id'] ?? '?' ) );
			return false;
		}

		// Nothing here checks the row count. A zero-row export is a legitimate
		// thing to send — it is the confirmation that a window had no orders in
		// it — so do not add a "skip if empty" guard above this line.

		$site_name   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$report_name = (string) ( $report['name'] ?? ucfirst( (string) ( $report['type'] ?? 'Export' ) ) );
		$subject     = sprintf( '[%s] %s — %s', $site_name, $report_name, wp_date( 'F j, Y' ) );

		$body    = self::build_body( $report, $site_name, $report_name, $manual );
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
	 * The headline sentence.
	 *
	 * A report named "Nightly Order Export" produced "Your Nightly Order Export
	 * export is attached." The template appended a word the name already ended
	 * with, and names in the wild carry that word often, because the thing being
	 * named is an export. So the check is on the name rather than on the
	 * template, and the appended word is dropped when the name already says it.
	 *
	 * The negative guard is the part worth reading: anchoring on `\b...$` means
	 * "Exported", "Reporter" and "Airport" are not treated as matches, which a
	 * bare `strpos` would have got wrong in all three cases.
	 *
	 * Public + static so the test suite can hit it without instantiating WP.
	 */
	public static function headline( string $report_name ): string {
		$name = trim( $report_name );
		if ( '' === $name ) {
			return 'Your export is attached.';
		}
		if ( preg_match( '/\b(?:exports?|reports?)$/i', $name ) ) {
			return sprintf( 'Your %s is attached.', $name );
		}
		return sprintf( 'Your %s export is attached.', $name );
	}

	/**
	 * Wraps each side of a date range in a nowrap span, so a range too long for
	 * the card breaks at the dash rather than in the middle of a date.
	 *
	 * Splits on the same ' – ' that format_range_for_filters() joins with. A
	 * single-date range has nothing to split, and a separator that ever stopped
	 * matching would simply return the whole value in one span — wrong-looking
	 * at worst, never broken.
	 *
	 * Returns escaped HTML, not a plain string.
	 */
	private static function nowrap_range( string $value ): string {
		$parts = explode( ' – ', $value );
		foreach ( $parts as $i => $part ) {
			$parts[ $i ] = '<span style="white-space:nowrap;">' . esc_html( $part ) . '</span>';
		}
		return implode( ' – ', $parts );
	}

	/**
	 * The <head> stylesheet.
	 *
	 * Built as its own string rather than inlined into build_body()'s sprintf
	 * format, because it is full of `%` and every one of them would have to be
	 * doubled. That is a bug waiting to happen for no gain.
	 *
	 * Everything here is an enhancement over the inline styles, never the only
	 * source of a rule. A client that drops <style> entirely still gets the
	 * full-width design and its correct colours, because both are inline too.
	 *
	 * Outlook desktop applies none of the media queries, which is correct: it
	 * has a fixed reading pane, does not reflow, and build_body() pins it to
	 * the full card width with an mso-only conditional table. It also has no
	 * dark mode of its own on the Word engine, so the block below is moot
	 * there.
	 *
	 * Carries {palette} tokens; build_body() substitutes them.
	 */
	private static function head_styles(): string {
		return '
	/* Both schemes, following the reader'."'".'s machine. Jason'."'".'s call on
	   September 8, 2026, reversing a same-day decision to lock it to light.

	   `light dark` is doing real work and is not a no-op. It says this document
	   supplies both schemes, which is what stops an engine applying its own
	   forced darkening on top of the one below. Without it a client is entitled
	   to invert the design as well, and you get both at once.

	   `supported-color-schemes` is not standard CSS. It is Apple'."'".'s, from
	   their own Mail dark-mode guidance, and an unrecognised property is simply
	   dropped everywhere else. */
	:root, body {
		color-scheme: light dark;
		supported-color-schemes: light dark;
	}

	/* Stop the clients that inflate type on their own. */
	body { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
	table { border-collapse: collapse; }

	/* Dark mode. The inline styles carry the light scheme, so this block is the
	   whole of the dark one and every colour in the design has to be restated
	   here. A role missed here inherits its light value and you get, say, black
	   text on a dark card. tests/SendTest.php asserts that every element
	   carrying a colour inline is also named below.

	   The !important is load-bearing. Without it these lose to the inline light
	   styles they are overriding. */
	@media (prefers-color-scheme: dark) {
		/* Every element inside the card names the card colour, and every
		   element on the ground names the ground colour. Grouped rather than
		   listed per element because a client that repaints `table, td`
		   wholesale reaches through anything left without a background of its
		   own, however carefully the classed elements are restated. That is
		   also why the light scheme sets a background on all of them: the two
		   schemes have the same gap and the same fix. Found on September 8,
		   2026 by simulating exactly that rule, not by reading the CSS. */
		body,
		.wooex-bg,
		.wooex-shell,
		.wooex-frame,
		.wooex-wordmark { background-color: {dark-page} !important; }

		.wooex-card,
		.wooex-card-inner,
		.wooex-headline,
		.wooex-lede,
		.wooex-meta-wrap,
		.wooex-meta-table,
		.wooex-meta-label,
		.wooex-meta-value,
		.wooex-footer     { background-color: {dark-card} !important; }

		.wooex-wordmark   { color: {dark-heading} !important; }
		.wooex-headline   { color: {dark-heading} !important; }
		.wooex-lede       { color: {dark-lede} !important; border-bottom-color: {dark-rule} !important; }
		.wooex-meta-label { color: {dark-quiet} !important; }
		.wooex-meta-value { color: {dark-value} !important; }
		.wooex-footer     { color: {dark-quiet} !important; border-top-color: {dark-rule} !important; }

		/* The same rules again, one level more specific.
		   `!important` settles importance, not order: against a client'."'".'s
		   injected rule that is also `!important` and also one class deep, the
		   later one wins, and ours is not the later one. A descendant selector
		   wins on specificity instead, whatever the order.
		   Both forms are stated because the plain ones above are the version a
		   renderer with no descendant-selector support still applies, and
		   losing the dark scheme entirely would be worse than losing a tie. */
		body .wooex-bg,
		body .wooex-shell,
		body .wooex-frame,
		body .wooex-wordmark { background-color: {dark-page} !important; }

		body .wooex-card,
		body .wooex-card-inner,
		body .wooex-headline,
		body .wooex-lede,
		body .wooex-meta-wrap,
		body .wooex-meta-table,
		body .wooex-meta-label,
		body .wooex-meta-value,
		body .wooex-footer     { background-color: {dark-card} !important; }

		body .wooex-wordmark   { color: {dark-heading} !important; }
		body .wooex-headline   { color: {dark-heading} !important; }
		body .wooex-lede       { color: {dark-lede} !important; border-bottom-color: {dark-rule} !important; }
		body .wooex-meta-label { color: {dark-quiet} !important; }
		body .wooex-meta-value { color: {dark-value} !important; }
		body .wooex-footer     { color: {dark-quiet} !important; border-top-color: {dark-rule} !important; }
	}

	/* Stack every meta row, label above value. The two-column form is what was
	   forcing a two-stamp range to break in the middle of a date. */
	@media only screen and (max-width: ' . self::STACK_BREAKPOINT . 'px) {
		.wooex-meta-label,
		.wooex-meta-value {
			display: block !important;
			width: 100% !important;
			padding: 0 !important;
		}
		.wooex-meta-label { padding-bottom: 2px !important; }
		.wooex-meta-value { padding-bottom: 14px !important; }
		/* The stacked rows leave 14px rather than 8px below the last one, so
		   the wrapper gives back the difference and the gap above the footer
		   rule stays at 24px. */
		.wooex-meta-wrap  { padding-bottom: 10px !important; }
	}

	/* Phone treatment. 40px of card padding leaves 240px of content on a 320px
	   screen, which is where the padding stops being generous and starts being
	   the problem. */
	@media only screen and (max-width: ' . self::COMPACT_BREAKPOINT . 'px) {
		.wooex-shell    { padding: 20px 10px !important; }
		.wooex-card     { padding: 24px !important; border-radius: 10px !important; }
		.wooex-wordmark { font-size: 20px !important; line-height: 26px !important; padding-bottom: 18px !important; }
		.wooex-headline { font-size: 20px !important; line-height: 27px !important; }
		.wooex-lede     { font-size: 14px !important; line-height: 21px !important; }
		.wooex-footer   { font-size: 12px !important; padding-top: 18px !important; }
	}
';
	}

	/**
	 * Builds the HTML email body.
	 *
	 * Visual language follows the Spill notification email: a neutral gray
	 * page, a single white card with a large bold headline, and quiet gray
	 * supporting text. Tables and inline styles throughout, because Outlook
	 * ignores float and flexbox, and any client may drop the <style> block.
	 *
	 * Responsive in three layers, weakest client first:
	 *
	 * 1. The shell is `width:100%` capped by a `max-width`, so it fits any
	 *    viewport without a media query. This is the layer everything else is
	 *    an improvement on, and the only one Gmail's stricter modes need.
	 * 2. An `[if mso]` conditional table pins Outlook desktop to the full card
	 *    width. That engine ignores max-width, so without this it would render
	 *    the fluid table at whatever width its reading pane happens to be.
	 * 3. head_styles() reduces the padding and type scale below the breakpoint
	 *    and stacks the meta rows.
	 *
	 * Light or dark, following the reader's machine. The inline styles here are
	 * the light scheme and a `prefers-color-scheme: dark` block in
	 * head_styles() is the dark one, so a client that drops <style> stays
	 * light rather than breaking. Colours come from PALETTE and PALETTE_DARK,
	 * one entry per role per scheme. See head_styles() for what that reaches
	 * and what it does not.
	 */
	private static function build_body( array $report, string $site_name, string $report_name, bool $manual = false ): string {
		$rows_count = (int) Wooex_Exporter::$last_row_count;
		$range_str  = self::format_range_for_filters( (array) ( $report['filters'] ?? [] ) );

		$font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";

		// Meta lines under the headline. Values are pre-escaped HTML rather than
		// plain strings, because the date range carries nowrap spans. Date range
		// is omitted for All Time, which has no range to state.
		$meta = [];
		if ( $range_str ) {
			$meta['Date range'] = self::nowrap_range( $range_str );
		}
		// Name what was counted — "Attendees", not "Rows". The type is the
		// report's own, so the label matches the file that is attached.
		$count_label          = Wooex_Exporter::type_label( (string) ( $report['type'] ?? '' ) );
		$meta[ $count_label ] = esc_html( number_format_i18n( $rows_count ) );

		// The label column has no width attribute. It sizes to the widest label
		// and the padding-right holds the gap, which is one fewer number to keep
		// in step with the card width and gives the value cell everything left
		// over. Both cells become full-width blocks below the breakpoint.
		$meta_html = '';
		foreach ( $meta as $label => $value_html ) {
			$meta_html .= sprintf(
				'<tr>
					<td class="wooex-meta-label" style="background-color:{card};padding:0 16px 8px 0;font-family:%1$s;font-size:14px;line-height:20px;color:{quiet};white-space:nowrap;vertical-align:top;">%2$s</td>
					<td class="wooex-meta-value" style="background-color:{card};padding:0 0 8px 0;font-family:%1$s;font-size:14px;line-height:20px;color:{value};font-weight:600;">%3$s</td>
				</tr>',
				$font,
				esc_html( $label ),
				$value_html
			);
		}

		$footer = $manual
			? 'Sent on request by Woo Exports.'
			: 'Sent automatically by Woo Exports.';

		$html = sprintf(
			'<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<title>%2$s</title>
<style>%7$s</style>
</head>
<body style="margin:0;padding:0;background-color:{page};">
<table role="presentation" class="wooex-bg" width="100%%" cellpadding="0" cellspacing="0" border="0" style="background-color:{page};">
	<tr>
		<td align="center" class="wooex-shell" style="padding:40px 20px;background-color:{page};">
			<!--[if mso]><table role="presentation" width="%6$d" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
			<table role="presentation" class="wooex-frame" width="100%%" cellpadding="0" cellspacing="0" border="0" style="width:100%%;max-width:%6$dpx;margin:0 auto;background-color:{page};">

				<!-- Wordmark -->
				<tr>
					<td class="wooex-wordmark" style="background-color:{page};padding:0 0 28px 0;font-family:%1$s;font-size:26px;line-height:32px;font-weight:800;letter-spacing:-0.5px;color:{heading};">%2$s</td>
				</tr>

				<!-- Card -->
				<tr>
					<td class="wooex-card" style="background-color:{card};border-radius:14px;padding:40px;">
						<table role="presentation" class="wooex-card-inner" width="100%%" cellpadding="0" cellspacing="0" border="0" style="background-color:{card};">
							<tr>
								<td class="wooex-headline" style="background-color:{card};padding:0 0 20px 0;font-family:%1$s;font-size:24px;line-height:32px;font-weight:700;letter-spacing:-0.3px;color:{heading};">%3$s</td>
							</tr>
							<tr>
								<td class="wooex-lede" style="background-color:{card};padding:0 0 24px 0;border-bottom:1px solid {rule};font-family:%1$s;font-size:15px;line-height:22px;color:{lede};">Generated from %2$s.</td>
							</tr>
							<tr>
								<td class="wooex-meta-wrap" style="background-color:{card};padding:24px 0 16px 0;">
									<table role="presentation" class="wooex-meta-table" width="100%%" cellpadding="0" cellspacing="0" border="0" style="background-color:{card};">%4$s</table>
								</td>
							</tr>

							<!-- Footer, inside the card since September 8, 2026.
							     The 16px above pairs with the last meta row own
							     8px to give the same 24px the lede leaves above
							     its own divider, so both rules sit in the same
							     rhythm. -->
							<tr>
								<td class="wooex-footer" style="background-color:{card};padding:24px 0 0 0;border-top:1px solid {rule};font-family:%1$s;font-size:13px;line-height:19px;color:{quiet};">%5$s</td>
							</tr>
						</table>
					</td>
				</tr>

			</table>
			<!--[if mso]></td></tr></table><![endif]-->
		</td>
	</tr>
</table>
</body>
</html>',
			$font,
			esc_html( $site_name ),
			esc_html( self::headline( $report_name ) ),
			$meta_html,
			esc_html( $footer ),
			self::CARD_WIDTH,
			self::head_styles()
		);

		// One substitution pass over the finished document, so the inline
		// styles and head_styles()' forced-light block cannot disagree about a
		// colour. After sprintf, never before: see PALETTE.
		return strtr( $html, self::colour_tokens() );
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

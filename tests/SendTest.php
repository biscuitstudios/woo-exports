<?php
/**
 * The send path: recipient resolution, the manual footer, and the rule that a
 * zero-row export still goes out.
 *
 * That last one is the point of the manual send — the email is the evidence
 * that a window had no orders in it — so it is asserted here rather than left
 * to be re-derived by whoever next reads the mailer.
 *
 * wp_mail is stubbed in tests/bootstrap.php and records its arguments.
 */

namespace WooExports\Tests;

if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;
use Wooex_Mailer;
use Wooex_Exporter;
use ReflectionClass;

final class SendTest extends TestCase {

	private string $file = '';

	protected function setUp(): void {
		$GLOBALS['_wooex_test_mail']        = [];
		$GLOBALS['_wooex_test_mail_result'] = true;
		$GLOBALS['_wooex_test_tz']          = 'America/New_York';
		$GLOBALS['_wooex_test_options']     = [
			'date_format'   => 'F j, Y',
			'time_format'   => 'g:i a',
			'bloginfo_name' => 'Test Site',
		];

		$this->file = (string) tempnam( sys_get_temp_dir(), 'wooex-send-test-' );
		file_put_contents( $this->file, 'x' );

		Wooex_Exporter::$last_row_count = 0;
	}

	protected function tearDown(): void {
		if ( $this->file && file_exists( $this->file ) ) {
			unlink( $this->file );
		}
		unset( $GLOBALS['_wooex_test_mail'], $GLOBALS['_wooex_test_mail_result'] );
	}

	private function report( array $over = [] ): array {
		return array_replace(
			[
				'id'         => 'abc123',
				'name'       => 'Nightly Order Export',
				'type'       => 'orders',
				'format'     => 'xlsx',
				'filters'    => [ 'date_range' => 'today', 'day_start' => '00:00' ],
				'recipients' => [ 'saved@example.com' ],
			],
			$over
		);
	}

	private function lastMail(): array {
		$all = $GLOBALS['_wooex_test_mail'] ?? [];
		$this->assertNotEmpty( $all, 'wp_mail was not called.' );
		return $all[ count( $all ) - 1 ];
	}

	// =====================================================================
	// Recipients
	// =====================================================================

	public function test_uses_the_reports_own_recipients_by_default(): void {
		$this->assertTrue( Wooex_Mailer::send( $this->report(), $this->file ) );
		$this->assertSame( [ 'saved@example.com' ], $this->lastMail()['to'] );
	}

	public function test_override_replaces_the_saved_recipients(): void {
		$this->assertTrue(
			Wooex_Mailer::send( $this->report(), $this->file, [ 'typed@example.com' ] )
		);
		$this->assertSame( [ 'typed@example.com' ], $this->lastMail()['to'] );
	}

	/**
	 * An empty override is not "send nowhere" — it is "no override given", so
	 * the scheduled path keeps working unchanged when it passes nothing.
	 */
	public function test_empty_override_falls_back_to_the_saved_recipients(): void {
		$this->assertTrue( Wooex_Mailer::send( $this->report(), $this->file, [] ) );
		$this->assertSame( [ 'saved@example.com' ], $this->lastMail()['to'] );
	}

	public function test_override_is_cleaned_and_deduplicated(): void {
		Wooex_Mailer::send(
			$this->report(),
			$this->file,
			[ ' a@example.com ', 'not-an-email', 'a@example.com', 'b@example.com' ]
		);
		$this->assertSame( [ 'a@example.com', 'b@example.com' ], $this->lastMail()['to'] );
	}

	public function test_an_override_of_only_junk_sends_nothing(): void {
		// Deliberately does NOT fall back to the saved list. A typed address
		// that failed validation means the person meant somewhere specific, and
		// quietly mailing the client instead is the worst available outcome.
		$this->assertFalse(
			Wooex_Mailer::send( $this->report(), $this->file, [ 'not-an-email' ] )
		);
		$this->assertSame( [], $GLOBALS['_wooex_test_mail'] );
	}

	public function test_a_missing_file_sends_nothing(): void {
		$this->assertFalse( Wooex_Mailer::send( $this->report(), $this->file . '-gone' ) );
		$this->assertSame( [], $GLOBALS['_wooex_test_mail'] );
	}

	// =====================================================================
	// Zero rows
	// =====================================================================

	public function test_a_zero_row_export_still_sends(): void {
		Wooex_Exporter::$last_row_count = 0;

		$this->assertTrue(
			Wooex_Mailer::send( $this->report(), $this->file, [ 'client@example.com' ], true )
		);

		$mail = $this->lastMail();
		$this->assertSame( [ 'client@example.com' ], $mail['to'] );
		// The count line is present and reads zero, rather than the block being
		// omitted because there was nothing to report.
		$this->assertStringContainsString( 'Orders', $mail['message'] );
		$this->assertStringContainsString( '>0<', $mail['message'] );
	}

	public function test_the_file_is_attached_and_then_removed(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$this->assertSame( [ $this->file ], $this->lastMail()['attachments'] );
		$this->assertFileDoesNotExist( $this->file );
	}

	// =====================================================================
	// Body
	// =====================================================================

	public function test_manual_and_scheduled_footers_differ(): void {
		Wooex_Mailer::send( $this->report(), $this->file, [], true );
		$this->assertStringContainsString( 'Sent on request by Woo Exports.', $this->lastMail()['message'] );

		file_put_contents( $this->file, 'x' );
		Wooex_Mailer::send( $this->report(), $this->file );
		$this->assertStringContainsString( 'Sent automatically by Woo Exports.', $this->lastMail()['message'] );
	}

	public function test_the_headline_does_not_double_the_word_export(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];
		$this->assertStringContainsString( 'Your Nightly Order Export is attached.', $msg );
		$this->assertStringNotContainsString( 'Export export', $msg );
	}

	// =====================================================================
	// Responsive layout
	// =====================================================================

	/**
	 * Layer 1, the one every client gets: the shell is fluid and capped, so it
	 * fits any viewport with no media query and no conditional comment. A
	 * client that strips <style> still gets a working layout off this alone.
	 *
	 * Asserted on the rendered markup rather than on the constant, so changing
	 * CARD_WIDTH without threading it through fails here.
	 */
	public function test_the_shell_is_fluid_and_capped(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];

		$this->assertStringContainsString( 'style="width:100%;max-width:680px', $msg );
		// The regression the width itself guards: 560px wrapped a
		// boundary-shifted range mid-date.
		$this->assertStringContainsString( 'max-width:680px', $msg );
		$this->assertStringNotContainsString( 'style="width:680px', $msg );
	}

	/**
	 * Layer 2: Outlook desktop ignores max-width and does not reflow, so it is
	 * pinned to the full width by a conditional table. Both halves of the
	 * conditional have to be there or every other client sees a stray table.
	 */
	public function test_outlook_is_pinned_to_the_full_width(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];

		$this->assertStringContainsString( '<!--[if mso]><table role="presentation" width="680"', $msg );
		$this->assertStringContainsString( '<!--[if mso]></td></tr></table><![endif]-->', $msg );

		// One matched pair: each conditional opens and closes its own comment,
		// so both markers appear twice. An odd count means a half-written
		// conditional, which shows every other client a stray table.
		$this->assertSame( 2, substr_count( $msg, '<!--[if mso]>' ) );
		$this->assertSame( 2, substr_count( $msg, '<![endif]-->' ) );
	}

	/**
	 * Layer 3: the two media queries, and the classes they target.
	 *
	 * The coupling worth a test is that the selectors in head_styles() and the
	 * class attributes in the markup are the same strings. Nothing errors when
	 * they drift — the email just silently stops being responsive — so both
	 * sides are asserted here.
	 */
	public function test_both_breakpoints_are_present(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];

		$this->assertStringContainsString( '@media only screen and (max-width: 620px)', $msg );
		$this->assertStringContainsString( '@media only screen and (max-width: 480px)', $msg );
	}

	/**
	 * Both directions at once: a class on an element that no rule targets, and
	 * a rule targeting a class no element carries.
	 *
	 * Derived from the document rather than compared against a list written
	 * here, so adding a class to the template without styling it fails without
	 * anyone remembering to update this test. Neither kind of drift errors at
	 * runtime. The email just silently stops being responsive, or stops
	 * holding its colours in dark mode.
	 */
	public function test_every_class_is_both_styled_and_used(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];

		preg_match_all( '/class="(wooex-[a-z-]+)"/', $msg, $used_m );
		preg_match_all( '/\.(wooex-[a-z-]+)/', $msg, $styled_m );

		$used   = array_values( array_unique( $used_m[1] ) );
		$styled = array_values( array_unique( $styled_m[1] ) );
		sort( $used );
		sort( $styled );

		$this->assertNotEmpty( $used, 'No wooex- classes found in the markup at all.' );
		$this->assertSame( $styled, $used );
	}

	// =====================================================================
	// Light and dark
	// =====================================================================

	public function test_the_email_declares_both_schemes(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];

		$this->assertStringContainsString( '<meta name="color-scheme" content="light dark">', $msg );
		$this->assertStringContainsString( '<meta name="supported-color-schemes" content="light dark">', $msg );
		$this->assertStringContainsString( 'color-scheme: light dark;', $msg );
		$this->assertStringContainsString( '@media (prefers-color-scheme: dark)', $msg );

		// `light only` was a same-day decision, reversed. If it comes back the
		// dark block below it becomes dead code in the clients that matter
		// most, so the two cannot both be present.
		$this->assertStringNotContainsString( 'light only', $msg );
	}

	/**
	 * Returns the dark block as class => property => selector forms that set it.
	 *
	 * Parsing rather than substring-matching, because the question the tests
	 * need answered is "does the dark scheme override this element's colour",
	 * and a class merely appearing somewhere in the block does not answer it.
	 *
	 * The forms are tracked separately, `plain` for `.wooex-x` and
	 * `descendant` for `body .wooex-x`, because the design states every dark
	 * rule twice on purpose and each form is there for a different reason. A
	 * check that accepted either would pass with one of them deleted, which is
	 * exactly what it did on the first attempt.
	 */
	private function darkRules( string $msg ): array {
		$block = substr( $msg, (int) strpos( $msg, '@media (prefers-color-scheme: dark)' ) );
		$block = substr( $block, 0, (int) strpos( $block, '@media only screen' ) );
		// Comments carry commas and would be read as selectors.
		$block = (string) preg_replace( '#/\*.*?\*/#s', '', $block );
		// Drop the `@media (...) {` opener so the inner rules parse cleanly.
		$block = substr( $block, (int) strpos( $block, '{' ) + 1 );

		$map = [];
		preg_match_all( '/([^{}]+)\{([^}]*)\}/', $block, $rules, PREG_SET_ORDER );
		foreach ( $rules as $rule ) {
			foreach ( explode( ',', $rule[1] ) as $selector ) {
				// Anchored at both ends, so only the two intended shapes count
				// and a stray fragment of comment text cannot register.
				if ( ! preg_match( '/^(body\s+)?\.(wooex-[a-z-]+)$/', trim( $selector ), $c ) ) {
					continue;
				}
				$form = '' !== $c[1] ? 'descendant' : 'plain';

				foreach ( [ 'background-color', 'color', 'border-bottom-color', 'border-top-color' ] as $prop ) {
					// The leading boundary stops `color` matching inside
					// `background-color` and the two border longhands.
					if ( preg_match( '/(?:^|;|\s)' . $prop . ':\s*(#[0-9a-f]{3,6})/i', $rule[2], $v ) ) {
						$map[ $c[2] ][ $prop ][ $form ] = strtolower( $v[1] );
					}
				}
			}
		}
		return $map;
	}

	/**
	 * The failure this exists for: a role restated in the light scheme and
	 * forgotten in the dark one keeps its light value, which is how you get
	 * near-black text on a dark card. Nothing errors. It just looks broken for
	 * every reader whose machine is set to dark, and nobody on a light machine
	 * ever sees it.
	 *
	 * Derived from the document, so adding a colour to an element without
	 * giving it a dark counterpart fails here without anyone updating a list.
	 */
	public function test_every_colour_set_inline_is_also_set_for_dark(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];

		$dark = $this->darkRules( $msg );
		$this->assertNotEmpty( $dark, 'The dark block parsed to nothing.' );

		// Inline shorthand => the longhand the dark block has to override.
		$needs = [
			'background-color' => 'background-color',
			'color'            => 'color',
			'border-bottom'    => 'border-bottom-color',
			'border-top'       => 'border-top-color',
		];

		preg_match_all(
			'/class="(wooex-[a-z-]+)"[^>]*style="([^"]*)"/',
			$msg,
			$elements,
			PREG_SET_ORDER
		);
		$this->assertNotEmpty( $elements );

		$checked = 0;
		foreach ( $elements as $el ) {
			[ , $class, $style ] = $el;
			foreach ( $needs as $inline => $required ) {
				if ( ! preg_match( '/(?:^|;|\s)' . $inline . '\s*:/', $style ) ) {
					continue;
				}
				$this->assertArrayHasKey(
					$class,
					$dark,
					$class . ' carries colours inline but the dark block never names it.'
				);
				$this->assertArrayHasKey(
					$required,
					$dark[ $class ],
					$class . ' sets ' . $inline . ' inline but the dark block does not override ' . $required . '.'
				);
				// Both selector forms, per the note on darkRules().
				foreach ( [ 'plain', 'descendant' ] as $form ) {
					$this->assertArrayHasKey(
						$form,
						$dark[ $class ][ $required ],
						sprintf(
							'The dark %s override for %s is missing its %s selector form.',
							$required,
							$class,
							$form
						)
					);
				}
				$checked++;
			}
		}
		$this->assertGreaterThan( 10, $checked, 'Too few colour declarations found to be checking anything.' );
	}

	public function test_the_dark_block_uses_only_dark_palette_colours(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];

		$block = substr( $msg, (int) strpos( $msg, '@media (prefers-color-scheme: dark)' ) );
		$block = substr( $block, 0, (int) strpos( $block, '@media only screen' ) );

		preg_match_all( '/#[0-9a-f]{3,6}\b/i', $block, $hexes );
		$this->assertNotEmpty( $hexes[0] );

		$ref   = new ReflectionClass( Wooex_Mailer::class );
		$dark  = array_values( $ref->getConstant( 'PALETTE_DARK' ) );
		$light = array_values( $ref->getConstant( 'PALETTE' ) );

		foreach ( array_unique( array_map( 'strtolower', $hexes[0] ) ) as $hex ) {
			$this->assertContains( $hex, $dark, $hex . ' is in the dark block but is not a dark-palette colour.' );
			$this->assertNotContains( $hex, $light, $hex . ' is a light-palette colour leaking into the dark block.' );
		}
	}

	/**
	 * Every foreground/background pair the email actually renders, measured
	 * against WCAG AA in both schemes.
	 *
	 * The pairs are derived from the document rather than listed here. An
	 * earlier version carried a hardcoded list plus two named exceptions for
	 * the light `quiet` grey, which failed at 3.45:1 on the card and 2.90:1 on
	 * the page ground. Both were fixed on September 8, 2026 by moving to
	 * `#767676` and moving the footer onto the card, so there are no exceptions
	 * left and none should be added without a reason recorded here.
	 *
	 * Deriving matters more than the list did: a hardcoded `quiet/page` pair
	 * would now be testing a combination the design no longer contains, and
	 * would have had to be deleted by hand, taking the guard with it. This way
	 * moving an element onto a different ground re-measures it automatically.
	 *
	 * Only elements that set both a colour and a background are measured. In
	 * this template that is every element carrying text, and the structural
	 * cells that set a background alone render none.
	 */
	public function test_every_rendered_text_pair_clears_aa(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];

		$checked = 0;

		// --- Light: read straight off the inline styles. ---
		preg_match_all(
			'/class="(wooex-[a-z-]+)"[^>]*style="([^"]*)"/',
			$msg,
			$elements,
			PREG_SET_ORDER
		);
		$this->assertNotEmpty( $elements );

		foreach ( $elements as [ , $class, $style ] ) {
			$fg = self::declaredColour( $style, 'color' );
			$bg = self::declaredColour( $style, 'background-color' );
			if ( null === $fg || null === $bg ) {
				continue;
			}
			$this->assertAA( $fg, $bg, 'light ' . $class );
			$checked++;
		}

		// --- Dark: read off the parsed block. ---
		foreach ( $this->darkRules( $msg ) as $class => $props ) {
			$fg = $props['color']['plain'] ?? null;
			$bg = $props['background-color']['plain'] ?? null;
			if ( null === $fg || null === $bg ) {
				continue;
			}
			$this->assertAA( $fg, $bg, 'dark ' . $class );
			$checked++;
		}

		// Both schemes, and more than a couple of elements each, or this test
		// is passing because it found nothing to measure.
		$this->assertGreaterThan( 8, $checked, 'Too few text pairs found to be checking anything.' );
	}

	/**
	 * Surface relationships, which are deliberately subtle and so are not held
	 * to a text threshold. The card has to read as a surface above the ground
	 * and the hairline as a line on the card.
	 */
	public function test_the_surfaces_stay_distinguishable_in_both_schemes(): void {
		$ref = new ReflectionClass( Wooex_Mailer::class );

		foreach ( [ 'PALETTE' => '', 'PALETTE_DARK' => 'dark-' ] as $const => $prefix ) {
			$palette = $ref->getConstant( $const );
			foreach ( [ [ 'card', 'page' ], [ 'rule', 'card' ] ] as [ $a, $b ] ) {
				$ratio = self::contrast(
					$palette[ '{' . $prefix . $a . '}' ],
					$palette[ '{' . $prefix . $b . '}' ]
				);
				$this->assertGreaterThan(
					1.1,
					$ratio,
					sprintf( '%s: %s is indistinguishable from %s at %.2f:1.', $const, $a, $b, $ratio )
				);
			}
		}
	}

	/**
	 * Pulls one colour out of an inline style attribute.
	 *
	 * The leading boundary is what stops a request for `color` matching the
	 * `color` inside `background-color`, `border-top-color` and friends.
	 */
	private static function declaredColour( string $style, string $property ): ?string {
		$found = preg_match(
			'/(?:^|;|\s)' . preg_quote( $property, '/' ) . ':\s*(#[0-9a-f]{3,6})/i',
			$style,
			$m
		);
		return $found ? strtolower( $m[1] ) : null;
	}

	private function assertAA( string $fg, string $bg, string $label ): void {
		$ratio = self::contrast( $fg, $bg );
		$this->assertGreaterThanOrEqual(
			4.5,
			$ratio,
			sprintf( '%s: %s on %s is %.2f:1, below WCAG AA.', $label, $fg, $bg, $ratio )
		);
	}

	/**
	 * WCAG relative luminance, per the definition rather than an approximation,
	 * so the numbers here are the same ones a checker would report.
	 */
	private static function luminance( string $hex ): float {
		$hex = ltrim( $hex, '#' );
		$out = 0.0;
		foreach ( [ [ 0, 0.2126 ], [ 2, 0.7152 ], [ 4, 0.0722 ] ] as [ $offset, $weight ] ) {
			$c    = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$lin  = $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			$out += $weight * $lin;
		}
		return $out;
	}

	private static function contrast( string $a, string $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	public function test_no_palette_token_survives_substitution(): void {
		Wooex_Mailer::send( $this->report(), $this->file );

		// A mistyped token ships a literal "{card}" into a style attribute.
		// Every client ignores it silently and that element goes unpainted.
		$this->assertDoesNotMatchRegularExpression(
			'/\{[a-z]+\}/',
			$this->lastMail()['message']
		);
	}

	public function test_every_palette_colour_reaches_the_document(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];

		$ref = new ReflectionClass( Wooex_Mailer::class );
		foreach ( [ 'PALETTE', 'PALETTE_DARK' ] as $const ) {
			$palette = $ref->getConstant( $const );
			$this->assertNotEmpty( $palette );

			foreach ( $palette as $token => $hex ) {
				// A role defined but never used is either a dead entry or,
				// worse, one whose token is misspelt at the point of use.
				$this->assertStringContainsString(
					$hex,
					$msg,
					$token . ' (' . $hex . ') never reaches the document.'
				);
			}
		}
	}

	public function test_the_two_palettes_cover_the_same_roles(): void {
		$ref  = new ReflectionClass( Wooex_Mailer::class );
		$roles = static fn( array $p, string $prefix ): array => array_map(
			static fn( string $t ): string => substr( $t, strlen( '{' . $prefix ) , -1 ),
			array_keys( $p )
		);

		$light = $roles( $ref->getConstant( 'PALETTE' ), '' );
		$dark  = $roles( $ref->getConstant( 'PALETTE_DARK' ), 'dark-' );
		sort( $light );
		sort( $dark );

		// A role in one scheme and not the other is a colour that silently
		// falls back to its light value in dark mode.
		$this->assertSame( $light, $dark );
	}

	/**
	 * The stacked form is what lets a two-stamp range break at the dash instead
	 * of mid-date, so the nowrap spans have to survive into it.
	 */
	public function test_the_range_keeps_its_nowrap_spans_in_the_stacked_form(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];

		$this->assertStringContainsString( 'class="wooex-meta-value"', $msg );
		$this->assertStringContainsString( '<span style="white-space:nowrap;">', $msg );
	}

	/**
	 * The label column sizes to its content. A width attribute here would be a
	 * second number to keep in step with the card width, and it is what left
	 * the value cell too narrow at 560px.
	 */
	public function test_the_label_column_has_no_fixed_width(): void {
		Wooex_Mailer::send( $this->report(), $this->file );
		$msg = $this->lastMail()['message'];

		$this->assertStringContainsString( 'class="wooex-meta-label"', $msg );
		$this->assertDoesNotMatchRegularExpression(
			'/class="wooex-meta-label"[^>]*\swidth=/',
			$msg
		);
	}
}

<?php
/**
 * Smoke tests for Wooex_Mailer's recipient parsing.
 *
 * `clean_recipients` is private, so we reach it via reflection. The behavior
 * matters because the recipient list comes from a free-text textarea — users
 * paste comma/newline/semicolon-separated emails with arbitrary whitespace,
 * and we drop invalid entries silently.
 */

namespace WooExports\Tests;

// Direct web access exits cleanly; under PHPUnit tests/bootstrap.php
// defines ABSPATH before this file loads.
if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;
use Wooex_Mailer;
use ReflectionMethod;

final class MailerTest extends TestCase {

	private function clean( $raw ): array {
		$m = new ReflectionMethod( Wooex_Mailer::class, 'clean_recipients' );
		$m->setAccessible( true );
		return $m->invoke( null, $raw );
	}

	public function test_parses_newline_separated_string(): void {
		$out = $this->clean( "alice@example.com\nbob@example.com" );
		$this->assertSame( [ 'alice@example.com', 'bob@example.com' ], $out );
	}

	public function test_parses_comma_and_semicolon_separated_string(): void {
		$out = $this->clean( 'alice@example.com, bob@example.com; carol@example.com' );
		$this->assertSame(
			[ 'alice@example.com', 'bob@example.com', 'carol@example.com' ],
			$out
		);
	}

	public function test_drops_invalid_entries(): void {
		$out = $this->clean( "alice@example.com\nnot-an-email\n@nope\nbob@example.com" );
		$this->assertSame( [ 'alice@example.com', 'bob@example.com' ], $out );
	}

	public function test_deduplicates(): void {
		$out = $this->clean( "alice@example.com\nalice@example.com\nbob@example.com" );
		$this->assertSame( [ 'alice@example.com', 'bob@example.com' ], $out );
	}

	public function test_accepts_array_input(): void {
		$out = $this->clean( [ ' alice@example.com ', 'bob@example.com', '' ] );
		$this->assertSame( [ 'alice@example.com', 'bob@example.com' ], $out );
	}

	public function test_empty_input_returns_empty_array(): void {
		$this->assertSame( [], $this->clean( '' ) );
		$this->assertSame( [], $this->clean( null ) );
		$this->assertSame( [], $this->clean( [] ) );
	}

	// =====================================================================
	// headline() — the appended-word fix
	// =====================================================================

	public function test_headline_appends_export_to_a_plain_name(): void {
		$this->assertSame(
			'Your Nightly Orders export is attached.',
			Wooex_Mailer::headline( 'Nightly Orders' )
		);
	}

	public function test_headline_does_not_double_the_word_export(): void {
		// The bug this exists for: "Your Nightly Order Export export is
		// attached." Both the singular and plural forms, and both cases.
		$this->assertSame(
			'Your Nightly Order Export is attached.',
			Wooex_Mailer::headline( 'Nightly Order Export' )
		);
		$this->assertSame(
			'Your Weekly Exports is attached.',
			Wooex_Mailer::headline( 'Weekly Exports' )
		);
		$this->assertSame(
			'Your donations report is attached.',
			Wooex_Mailer::headline( 'donations report' )
		);
		$this->assertSame(
			'Your Board Reports is attached.',
			Wooex_Mailer::headline( 'Board Reports' )
		);
	}

	/**
	 * The negative guard. A word that merely ends in the same letters is not a
	 * match, which is exactly what a `strpos`-shaped check would get wrong.
	 */
	public function test_headline_negative_guard(): void {
		$this->assertSame(
			'Your Atlanta Airport export is attached.',
			Wooex_Mailer::headline( 'Atlanta Airport' )
		);
		$this->assertSame(
			'Your Orders Exported export is attached.',
			Wooex_Mailer::headline( 'Orders Exported' )
		);
		$this->assertSame(
			'Your Volunteer Reporter export is attached.',
			Wooex_Mailer::headline( 'Volunteer Reporter' )
		);
	}

	public function test_headline_handles_empty_and_whitespace_names(): void {
		$this->assertSame( 'Your export is attached.', Wooex_Mailer::headline( '' ) );
		$this->assertSame( 'Your export is attached.', Wooex_Mailer::headline( '   ' ) );
	}

	public function test_headline_trailing_whitespace_still_matches(): void {
		$this->assertSame(
			'Your Nightly Export is attached.',
			Wooex_Mailer::headline( 'Nightly Export  ' )
		);
	}

	// =====================================================================
	// nowrap_range() — where a long range is allowed to break
	// =====================================================================

	private function nowrap( string $value ): string {
		$m = new ReflectionMethod( Wooex_Mailer::class, 'nowrap_range' );
		$m->setAccessible( true );
		return $m->invoke( null, $value );
	}

	public function test_nowrap_range_splits_on_the_en_dash(): void {
		$out = $this->nowrap( 'September 6, 2026 7:00 pm – September 7, 2026 6:59 pm' );

		// Two spans, so the range can only break at the dash between them and
		// never inside a date.
		$this->assertSame( 2, substr_count( $out, '<span' ) );
		$this->assertStringContainsString(
			'<span style="white-space:nowrap;">September 6, 2026 7:00 pm</span>',
			$out
		);
		$this->assertStringContainsString(
			'<span style="white-space:nowrap;">September 7, 2026 6:59 pm</span>',
			$out
		);
		$this->assertStringContainsString( '</span> – <span', $out );
	}

	public function test_nowrap_range_wraps_a_single_date_in_one_span(): void {
		$out = $this->nowrap( 'September 7, 2026' );
		$this->assertSame( 1, substr_count( $out, '<span' ) );
		$this->assertStringContainsString( 'September 7, 2026', $out );
	}

	public function test_nowrap_range_escapes_its_input(): void {
		$out = $this->nowrap( '<b>x</b>' );
		$this->assertStringNotContainsString( '<b>', $out );
		$this->assertStringContainsString( '&lt;b&gt;', $out );
	}
}

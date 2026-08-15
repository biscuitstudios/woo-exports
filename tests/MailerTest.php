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
}

<?php
/**
 * Empty-cell fill.
 *
 * A client reading an export cannot tell an empty cell from a lost one, so every
 * blank is filled with an en dash. Two things about that are easy to get wrong
 * and are pinned here: a zero is a value and must survive, and the placeholder
 * must not be a plain hyphen, because `safe_cell()` quote-prefixes anything
 * opening with `-` as CSV-injection defence and would ship `'-` to the client.
 *
 * @package WooExports
 */

namespace WooExports\Tests;

// Direct web access exits cleanly; under PHPUnit tests/bootstrap.php
// defines ABSPATH before this file loads.
if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;
use Wooex_Exporter;

final class BlankPlaceholderTest extends TestCase {

	private const DASH = "\u{2013}";

	// -----------------------------------------------------------------
	// What gets filled
	// -----------------------------------------------------------------

	public function test_an_empty_string_is_filled(): void {
		$filled = Wooex_Exporter::with_blank_placeholder( [ [ 'Attendee Email' => '' ] ] );

		$this->assertSame( self::DASH, $filled[0]['Attendee Email'] );
	}

	public function test_a_whitespace_only_cell_is_filled(): void {
		$filled = Wooex_Exporter::with_blank_placeholder( [ [ 'Venue' => "  \t " ] ] );

		$this->assertSame( self::DASH, $filled[0]['Venue'] );
	}

	public function test_a_null_cell_is_filled(): void {
		$filled = Wooex_Exporter::with_blank_placeholder( [ [ 'Venue' => null ] ] );

		$this->assertSame( self::DASH, $filled[0]['Venue'] );
	}

	// -----------------------------------------------------------------
	// What must survive untouched
	// -----------------------------------------------------------------

	/**
	 * The cover-fee columns export a real 0.00 for a participating order that
	 * happened to cover nothing, and blank for an order that never participated.
	 * Filling the zero would collapse a distinction the Orders export exists to
	 * make.
	 */
	public function test_every_shape_of_zero_survives(): void {
		$filled = Wooex_Exporter::with_blank_placeholder(
			[
				[
					'int'     => 0,
					'string'  => '0',
					'money'   => '0.00',
					'float'   => 0.0,
					'negative' => '-1.50',
				],
			]
		);

		$this->assertSame( 0, $filled[0]['int'] );
		$this->assertSame( '0', $filled[0]['string'] );
		$this->assertSame( '0.00', $filled[0]['money'] );
		$this->assertSame( 0.0, $filled[0]['float'] );
		$this->assertSame( '-1.50', $filled[0]['negative'] );
	}

	public function test_ordinary_values_are_untouched(): void {
		$row    = [ 'Attendee Name' => 'Lucy Fry', 'Checked In' => 'No', 'Order ID' => 1498 ];
		$filled = Wooex_Exporter::with_blank_placeholder( [ $row ] );

		$this->assertSame( $row, $filled[0] );
	}

	// -----------------------------------------------------------------
	// The placeholder itself
	// -----------------------------------------------------------------

	/**
	 * The regression guard. `safe_cell()` prefixes a leading `-` with a quote, so
	 * a hyphen placeholder reaches the client as `'-` in both CSV and XLSX. If
	 * anyone "simplifies" the constant to an ASCII hyphen, this fails.
	 */
	public function test_the_placeholder_does_not_trip_the_csv_injection_guard(): void {
		$first = Wooex_Exporter::BLANK_PLACEHOLDER[0];

		$this->assertNotSame( '-', $first );
		$this->assertNotContains(
			$first,
			[ '=', '+', '-', '@', "\t", "\r" ],
			'A placeholder starting with one of these is quote-prefixed before it reaches the client.'
		);
	}

	public function test_the_placeholder_is_an_en_dash(): void {
		$this->assertSame( self::DASH, Wooex_Exporter::BLANK_PLACEHOLDER );
	}

	// -----------------------------------------------------------------
	// Shape
	// -----------------------------------------------------------------

	public function test_column_order_and_keys_are_preserved(): void {
		$filled = Wooex_Exporter::with_blank_placeholder(
			[ [ 'A' => 'x', 'B' => '', 'C' => 'z' ] ]
		);

		$this->assertSame( [ 'A', 'B', 'C' ], array_keys( $filled[0] ) );
	}

	public function test_an_empty_result_set_stays_empty(): void {
		$this->assertSame( [], Wooex_Exporter::with_blank_placeholder( [] ) );
	}

	public function test_every_row_is_covered_not_just_the_first(): void {
		$filled = Wooex_Exporter::with_blank_placeholder(
			[ [ 'Email' => 'lucy@biscuitstudios.com' ], [ 'Email' => '' ], [ 'Email' => '' ] ]
		);

		$this->assertSame( 'lucy@biscuitstudios.com', $filled[0]['Email'] );
		$this->assertSame( self::DASH, $filled[1]['Email'] );
		$this->assertSame( self::DASH, $filled[2]['Email'] );
	}
}

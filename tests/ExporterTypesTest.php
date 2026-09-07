<?php
/**
 * Tests for the export-type label accessors on Wooex_Exporter.
 *
 * `TYPE_LABELS` is the single source of truth for what export types exist and
 * what they are called. Five call sites read it: the list table's type filter
 * and Type column, the builder's type dropdown, three request validators in
 * `Wooex_Admin`, the email's count label, and the Preview Export sentence.
 * Each of those held its own copy of the list until September 7, 2026, so the
 * value of these tests is holding the shape of the const steady now that
 * everything depends on it.
 *
 * Two properties matter beyond the obvious lookups. Declaration order is the
 * order both type dropdowns render in, so it is asserted rather than left to
 * chance. And the "Records" fallback is what a report row with a blank or
 * unrecognized type falls back to in the email, which is the only path here
 * that a user would ever see fail.
 */

namespace WooExports\Tests;

// Direct web access exits cleanly; under PHPUnit tests/bootstrap.php
// defines ABSPATH before this file loads.
if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;
use Wooex_Exporter;

final class ExporterTypesTest extends TestCase {

	/** Declaration order is dropdown render order, so it is part of the contract. */
	public function test_types_returns_the_four_slugs_in_render_order(): void {
		$this->assertSame(
			[ 'products', 'orders', 'customers', 'attendees' ],
			Wooex_Exporter::types()
		);
	}

	public function test_type_options_maps_every_slug_to_its_plural_label(): void {
		$this->assertSame(
			[
				'products'  => 'Products',
				'orders'    => 'Orders',
				'customers' => 'Customers',
				'attendees' => 'Attendees',
			],
			Wooex_Exporter::type_options()
		);
	}

	/**
	 * The dropdowns loop type_options() while the validators check types(). If
	 * those two ever disagreed, a type could render as an option and then be
	 * rejected on submit.
	 */
	public function test_type_options_keys_match_types(): void {
		$this->assertSame(
			Wooex_Exporter::types(),
			array_keys( Wooex_Exporter::type_options() )
		);
	}

	public function test_type_label_returns_plural_by_default(): void {
		$this->assertSame( 'Products', Wooex_Exporter::type_label( 'products' ) );
		$this->assertSame( 'Orders', Wooex_Exporter::type_label( 'orders' ) );
		$this->assertSame( 'Customers', Wooex_Exporter::type_label( 'customers' ) );
		$this->assertSame( 'Attendees', Wooex_Exporter::type_label( 'attendees' ) );
	}

	public function test_type_label_returns_singular_when_asked(): void {
		$this->assertSame( 'Product', Wooex_Exporter::type_label( 'products', false ) );
		$this->assertSame( 'Order', Wooex_Exporter::type_label( 'orders', false ) );
		$this->assertSame( 'Customer', Wooex_Exporter::type_label( 'customers', false ) );
		$this->assertSame( 'Attendee', Wooex_Exporter::type_label( 'attendees', false ) );
	}

	/**
	 * A legacy or corrupt report row with no usable type still has to produce a
	 * readable email. Blank is the case that actually reaches this in practice,
	 * via `$report['type'] ?? ''` in the mailer.
	 */
	public function test_unknown_type_falls_back_to_records(): void {
		$this->assertSame( 'Records', Wooex_Exporter::type_label( '' ) );
		$this->assertSame( 'Records', Wooex_Exporter::type_label( 'bogus' ) );
		$this->assertSame( 'Record', Wooex_Exporter::type_label( '', false ) );
		$this->assertSame( 'Record', Wooex_Exporter::type_label( 'bogus', false ) );
	}

	/**
	 * Guards a future entry added with one form, or with the pair the wrong way
	 * round — neither of which the lookups above would catch on their own.
	 */
	public function test_every_entry_is_a_distinct_singular_plural_pair(): void {
		foreach ( Wooex_Exporter::TYPE_LABELS as $slug => $pair ) {
			$this->assertIsArray( $pair, "$slug: expected a [singular, plural] pair" );
			$this->assertCount( 2, $pair, "$slug: expected exactly two forms" );
			$this->assertNotSame( $pair[0], $pair[1], "$slug: singular and plural are identical" );
			$this->assertSame(
				$pair[0] . 's',
				$pair[1],
				"$slug: plural is not the singular plus 's' — if that is intended, drop this assertion"
			);
		}
	}
}

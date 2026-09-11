<?php
/**
 * Attendee Information resolution.
 *
 * The bug this covers: `_tribe_tickets_full_name` and `_tribe_tickets_email` are
 * stamped from the WooCommerce billing details, so every ticket in a multi-ticket
 * order carries the PURCHASER. A three-ticket order on the dev site held
 * "Jason Butler" on all three attendee posts while the Attendee Information
 * answers held Lucy Fry, Edie Butler and August Butler. The fixtures below are
 * that order's real `_tribe_tickets_meta` values, unserialized.
 *
 * `resolve_attendee_identity()` is pure, so everything here runs without
 * WordPress. The purchaser fallback itself lives in `format_row()`, which needs
 * meta reads; what is asserted here is the '' that triggers it.
 *
 * @package WooExports
 */

namespace WooExports\Tests;

// Direct web access exits cleanly; under PHPUnit tests/bootstrap.php
// defines ABSPATH before this file loads.
if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;
use Wooex_Data_Attendees;

final class AttendeeIdentityTest extends TestCase {

	/** The "Basic Attendee Info" fieldset on the dev site's Fall Ramble ticket. */
	private const DEFINITIONS = [
		[ 'id' => 0, 'type' => 'text',  'required' => 'on', 'label' => 'Full Name', 'slug' => 'full-name' ],
		[ 'id' => 0, 'type' => 'email', 'required' => '',   'label' => 'Email',     'slug' => 'email' ],
	];

	private static function resolve( array $values, array $definitions = self::DEFINITIONS ): array {
		return Wooex_Data_Attendees::resolve_attendee_identity( $values, $definitions );
	}

	// -----------------------------------------------------------------
	// The reported bug
	// -----------------------------------------------------------------

	public function test_each_ticket_in_one_order_resolves_to_its_own_attendee(): void {
		$order = [
			'FR-4-PHJWAN' => [ 'full-name' => 'Lucy Fry', 'email' => 'lucy@biscuitstudios.com' ],
			'FR-5-4IGT9S' => [ 'full-name' => 'Edie Butler' ],
			'FR-6-Z79M8S' => [ 'full-name' => 'August Butler', 'email' => 'august@biscuitstudios.com' ],
		];

		$names = [];
		foreach ( $order as $answers ) {
			$names[] = self::resolve( $answers )['name'];
		}

		$this->assertSame( [ 'Lucy Fry', 'Edie Butler', 'August Butler' ], $names );
	}

	/** Email is not required on this fieldset, so a ticket can answer the name only. */
	public function test_an_unanswered_optional_field_resolves_empty_rather_than_borrowing(): void {
		$identity = self::resolve( [ 'full-name' => 'Edie Butler' ] );

		$this->assertSame( 'Edie Butler', $identity['name'] );
		$this->assertSame( '', $identity['email'], 'A missing answer must not be filled from another field.' );
	}

	/** A field present but left blank is the same as absent; '' is what triggers the fallback. */
	public function test_a_blank_answer_does_not_win(): void {
		$identity = self::resolve( [ 'full-name' => '   ', 'email' => 'edie@example.com' ] );

		$this->assertSame( '', $identity['name'] );
		$this->assertSame( 'edie@example.com', $identity['email'] );
	}

	public function test_no_attendee_information_at_all_resolves_empty(): void {
		$identity = Wooex_Data_Attendees::resolve_attendee_identity( [], [] );

		$this->assertSame( '', $identity['name'] );
		$this->assertSame( '', $identity['email'] );
	}

	// -----------------------------------------------------------------
	// Blank answer vs. field never asked
	//
	// These two look identical in the export — an empty cell — and mean opposite
	// things. Only the second may borrow the purchaser's details.
	// -----------------------------------------------------------------

	public function test_a_skipped_optional_field_counts_as_collected(): void {
		// Jason's fieldset asks for Email and does not require it. Edie's ticket
		// left it blank, so the export must show blank rather than the buyer.
		$identity = self::resolve( [ 'full-name' => 'Edie Butler' ] );

		$this->assertTrue( $identity['collects_email'], 'The fieldset asks for Email, so a blank is the attendee answering nothing.' );
		$this->assertSame( '', $identity['email'] );
	}

	public function test_a_ticket_with_no_fieldset_collects_nothing(): void {
		// Attendee Information off. The purchaser is the only data ETP holds.
		$identity = Wooex_Data_Attendees::resolve_attendee_identity( [], [] );

		$this->assertFalse( $identity['collects_name'] );
		$this->assertFalse( $identity['collects_email'] );
	}

	public function test_a_field_present_but_blank_still_counts_as_collected(): void {
		// The answers map keeps the key with an empty value, which is the only
		// signal left when the ticket product has been deleted.
		$identity = self::resolve( [ 'full-name' => '', 'email' => '' ], [] );

		$this->assertTrue( $identity['collects_name'] );
		$this->assertTrue( $identity['collects_email'] );
	}

	public function test_a_fieldset_that_asks_for_a_name_but_no_email(): void {
		$definitions = [ [ 'type' => 'text', 'label' => 'Full Name', 'slug' => 'full-name' ] ];
		$identity    = self::resolve( [ 'full-name' => 'Lucy Fry' ], $definitions );

		$this->assertTrue( $identity['collects_name'] );
		$this->assertFalse( $identity['collects_email'], 'Nobody was asked for an email, so the purchaser may fill in.' );
	}

	/** Collection is read from the form, not from this attendee's answers. */
	public function test_the_definitions_alone_establish_collection(): void {
		$identity = self::resolve( [], self::DEFINITIONS );

		$this->assertTrue( $identity['collects_name'] );
		$this->assertTrue( $identity['collects_email'] );
	}

	// -----------------------------------------------------------------
	// Matching rules
	// -----------------------------------------------------------------

	/**
	 * The negative guard. These all contain "name" and none of them is the
	 * attendee, so a substring match would export the wrong person.
	 */
	public function test_a_name_shaped_label_that_is_not_the_attendee_is_ignored(): void {
		$definitions = [
			[ 'type' => 'text', 'label' => 'Emergency Contact Name', 'slug' => 'emergency-contact-name' ],
			[ 'type' => 'text', 'label' => 'Company Name',           'slug' => 'company-name' ],
			[ 'type' => 'text', 'label' => 'Parent Name',            'slug' => 'parent-name' ],
		];
		$values = [
			'emergency-contact-name' => 'Nina Fry',
			'company-name'           => 'Biscuit Studios',
			'parent-name'            => 'Jason Butler',
		];

		$this->assertSame( '', self::resolve( $values, $definitions )['name'] );
	}

	public function test_underscores_and_spaces_and_case_match_the_same_field(): void {
		foreach ( [ 'Full_Name', 'FULL NAME', 'full-name', ' Full-Name ' ] as $slug ) {
			$this->assertSame(
				'Lucy Fry',
				self::resolve( [ $slug => 'Lucy Fry' ], [] )['name'],
				"Slug '$slug' should resolve."
			);
		}
	}

	public function test_a_split_name_fieldset_is_joined(): void {
		$definitions = [
			[ 'type' => 'text', 'label' => 'First Name', 'slug' => 'first-name' ],
			[ 'type' => 'text', 'label' => 'Last Name',  'slug' => 'last-name' ],
		];

		$this->assertSame(
			'Edie Butler',
			self::resolve( [ 'first-name' => 'Edie', 'last-name' => 'Butler' ], $definitions )['name']
		);
	}

	public function test_half_a_split_name_still_beats_the_purchaser(): void {
		$this->assertSame( 'Edie', self::resolve( [ 'first-name' => 'Edie' ], [] )['name'] );
	}

	/** A single-field name wins over a split pair, so a fieldset with both does not double up. */
	public function test_a_whole_name_field_is_preferred_to_a_split_pair(): void {
		$identity = self::resolve(
			[ 'full-name' => 'Edie Butler', 'first-name' => 'Edie', 'last-name' => 'Butler' ],
			[]
		);

		$this->assertSame( 'Edie Butler', $identity['name'] );
		$this->assertSame( 'full-name', $identity['name_slug'] );
	}

	// -----------------------------------------------------------------
	// Email
	// -----------------------------------------------------------------

	/** The declared type is structural, so an oddly-named email field still resolves. */
	public function test_email_resolves_from_the_field_type_when_the_slug_is_unknown(): void {
		$definitions = [
			[ 'type' => 'text',  'label' => 'Full Name',      'slug' => 'full-name' ],
			[ 'type' => 'email', 'label' => 'Where to reach', 'slug' => 'where-to-reach' ],
		];
		$values = [ 'full-name' => 'Lucy Fry', 'where-to-reach' => 'lucy@biscuitstudios.com' ];

		$identity = self::resolve( $values, $definitions );

		$this->assertSame( 'lucy@biscuitstudios.com', $identity['email'] );
		$this->assertSame( 'where-to-reach', $identity['email_slug'] );
	}

	/** Definitions go missing when the ticket product is deleted. The answers still resolve. */
	public function test_it_resolves_with_no_definitions_at_all(): void {
		$identity = self::resolve(
			[ 'full-name' => 'Lucy Fry', 'email' => 'lucy@biscuitstudios.com' ],
			[]
		);

		$this->assertSame( 'Lucy Fry', $identity['name'] );
		$this->assertSame( 'lucy@biscuitstudios.com', $identity['email'] );
	}

	public function test_a_field_cannot_supply_both_the_name_and_the_email(): void {
		// A fieldset whose only field is slugged 'email' but answered with a name
		// must not also be read as the name, and vice versa.
		$identity = self::resolve( [ 'name' => 'lucy@biscuitstudios.com' ], [] );

		$this->assertSame( 'lucy@biscuitstudios.com', $identity['name'] );
		$this->assertSame( '', $identity['email'] );
	}

	// -----------------------------------------------------------------
	// Shapes that must not fatal
	// -----------------------------------------------------------------

	public function test_a_checkbox_group_answer_is_skipped_rather_than_stringified(): void {
		$identity = self::resolve(
			[ 'dietary' => [ 'vegan', 'gluten-free' ], 'full-name' => 'Lucy Fry' ],
			[]
		);

		$this->assertSame( 'Lucy Fry', $identity['name'] );
	}

	public function test_a_site_can_add_its_own_slug(): void {
		$identity = Wooex_Data_Attendees::resolve_attendee_identity(
			[ 'rambler' => 'Lucy Fry' ],
			[],
			[ 'rambler' ],
			Wooex_Data_Attendees::DEFAULT_EMAIL_SLUGS
		);

		$this->assertSame( 'Lucy Fry', $identity['name'] );
	}
}

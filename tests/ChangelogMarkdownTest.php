<?php
/**
 * Covers the renderer behind the "View version X details" modal.
 *
 * The modal is the only place a client ever reads a changelog, and nothing
 * about it fails loudly: bad markup is swallowed by core's wp_kses() pass and
 * reads as a formatting quirk rather than a bug. So the shape of the output is
 * asserted here rather than eyeballed on a site.
 *
 * The allowlist test is the load-bearing one. Core filters every section
 * through wp_kses() against $plugins_allowedtags in
 * wp-admin/includes/plugin-install.php, which permits no attribute except a
 * href, and silently drops anything else. A style attribute on a <pre> was
 * exactly how the previous renderer failed.
 */

namespace WooExports\Tests;

if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;
use Wooex_Updater;

final class ChangelogMarkdownTest extends TestCase {

	private static function render( string $markdown ): string {
		return Wooex_Updater::render_markdown( $markdown );
	}

	public function test_bullets_become_one_list_item_each(): void {
		$html = self::render( "* First thing\n* Second thing" );

		$this->assertSame( '<ul><li>First thing</li><li>Second thing</li></ul>', $html );
	}

	public function test_indented_lines_continue_their_bullet(): void {
		// The changelog wraps at 78 columns, so nearly every real entry runs to
		// several lines. Breaking them into separate list items or paragraphs
		// would cut sentences in half.
		$html = self::render( "* Fix: a thing that needed\n  more than one line to say\n* Second thing" );

		$this->assertSame(
			'<ul><li>Fix: a thing that needed more than one line to say</li><li>Second thing</li></ul>',
			$html
		);
	}

	public function test_a_blank_line_between_bullets_keeps_one_list(): void {
		$html = self::render( "* First thing\n\n* Second thing" );

		$this->assertSame( '<ul><li>First thing</li><li>Second thing</li></ul>', $html );
		$this->assertSame( 1, substr_count( $html, '<ul>' ) );
	}

	public function test_prose_after_a_list_closes_the_list(): void {
		$html = self::render( "* First thing\nA closing note." );

		$this->assertSame( '<ul><li>First thing</li></ul><p>A closing note.</p>', $html );
	}

	public function test_headings_become_h4(): void {
		// GitHub appends its own "## What's Changed" under our notes.
		$html = self::render( "## What's Changed\n* A thing" );

		$this->assertStringStartsWith( '<h4>What&#039;s Changed</h4>', $html );
	}

	public function test_markup_in_the_body_is_escaped_not_rendered(): void {
		// A release body is text we wrote, but it reaches the site over the
		// network and lands in an admin screen. It is never trusted.
		$html = self::render( '* <script>alert(1)</script> and <b>bold</b>' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_code_spans_and_bold(): void {
		$html = self::render( '* The `wooex_thing` filter is **new**' );

		$this->assertSame( '<ul><li>The <code>wooex_thing</code> filter is <strong>new</strong></li></ul>', $html );
	}

	public function test_asterisks_inside_a_code_span_survive(): void {
		// Bold run before code spans would eat these.
		$html = self::render( '* Pass `**` to match everything' );

		$this->assertStringContainsString( '<code>**</code>', $html );
		$this->assertStringNotContainsString( '<strong>', $html );
	}

	public function test_markdown_links(): void {
		$html = self::render( '* See [the notes](https://example.com/notes)' );

		$this->assertStringContainsString( '<a href="https://example.com/notes">the notes</a>', $html );
	}

	public function test_bare_urls_are_linked_without_their_trailing_stop(): void {
		$html = self::render( '* See https://example.com/notes.' );

		$this->assertStringContainsString( '<a href="https://example.com/notes">https://example.com/notes</a>.', $html );
	}

	public function test_a_url_is_linked_once_not_twice(): void {
		// The link pass runs once over text holding no <a> yet, so a URL
		// written as a Markdown link must not also match the bare-URL branch.
		$html = self::render( '* See [notes](https://example.com/notes)' );

		$this->assertSame( 1, substr_count( $html, '<a href=' ) );
	}

	public function test_empty_input_renders_nothing(): void {
		$this->assertSame( '', self::render( '' ) );
	}

	public function test_output_uses_only_tags_core_allows(): void {
		// Anything outside this set is dropped by wp_kses() at display time,
		// and href is the only attribute on the list that we set.
		$allowed = [ 'p', 'ul', 'li', 'strong', 'em', 'code', 'h4', 'a' ];

		$html = self::render( self::realChangelogSection() );

		preg_match_all( '/<([a-z0-9]+)([^>]*)>/i', $html, $m, PREG_SET_ORDER );

		$this->assertNotEmpty( $m, 'The fixture rendered no markup at all.' );

		foreach ( $m as $tag ) {
			$this->assertContains( strtolower( $tag[1] ), $allowed, "Tag <{$tag[1]}> is not on core's allowlist." );

			$attrs = trim( $tag[2] );

			if ( '' !== $attrs ) {
				$this->assertMatchesRegularExpression( '/^href="[^"]*"$/', $attrs, "Attribute \"{$attrs}\" would be stripped by wp_kses()." );
			}
		}
	}

	public function test_the_real_changelog_renders_as_a_list(): void {
		// A positive control. Every assertion above uses input written for the
		// test, which proves the renderer handles what the test imagines. This
		// one runs the plugin's own shipped changelog through it.
		$section = self::realChangelogSection();

		$this->assertNotSame( '', trim( $section ), 'No changelog section was found in readme.txt.' );

		$bullets = preg_match_all( '/^\* /m', $section );
		$html    = self::render( $section );

		$this->assertSame( $bullets, substr_count( $html, '<li>' ) );
		$this->assertStringNotContainsString( '<p>', $html, 'A wrapped line was read as prose instead of a continuation.' );
	}

	/**
	 * The newest section of the shipped readme.txt changelog, which is exactly
	 * what .github/workflows/release.yml publishes as the release body.
	 */
	private static function realChangelogSection(): string {
		$readme  = (string) file_get_contents( __DIR__ . '/../readme.txt' );
		$section = '';
		$copy    = false;

		foreach ( preg_split( '/\R/', $readme ) as $line ) {
			$heading = 1 === preg_match( '/^= [0-9]+\.[0-9]+\.[0-9]+ =\s*$/', $line );

			if ( $heading && $copy ) {
				break;
			}

			if ( $heading ) {
				$copy = true;
				continue;
			}

			if ( $copy ) {
				$section .= $line . "\n";
			}
		}

		return trim( $section );
	}
}

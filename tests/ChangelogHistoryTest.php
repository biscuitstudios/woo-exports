<?php
/**
 * Covers the changelog the details modal builds: which release gets offered,
 * which versions are listed, and where each version's notes come from.
 *
 * One call to the releases list answers both questions, so the parsing here is
 * load-bearing for updates as well as for the modal. A mistake that drops the
 * newest release stops 62 sites being offered anything, silently, which is the
 * exact failure this updater exists to end.
 */

namespace WooExports\Tests;

if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Wooex_Updater;

final class ChangelogHistoryTest extends TestCase {

	private const MAIN = __DIR__ . '/../woo-exports.php';

	protected function tearDown(): void {
		unset( $GLOBALS['_wooex_test_http'], $GLOBALS['_wooex_test_http_code'] );
	}

	/** Calls a private method on a real instance. */
	private static function call( Wooex_Updater $updater, string $method, array $args = [] ) {
		$ref = new ReflectionMethod( Wooex_Updater::class, $method );
		$ref->setAccessible( true );

		return $ref->invokeArgs( $updater, $args );
	}

	/** One release as the GitHub API returns it. */
	private static function apiRelease( string $tag, string $published, string $body = '* A thing', array $overrides = [] ): array {
		return array_merge(
			[
				'tag_name'     => $tag,
				'published_at' => $published,
				'body'         => $body,
				'html_url'     => 'https://github.com/biscuitstudios/woo-exports/releases/tag/' . $tag,
				'draft'        => false,
				'prerelease'   => false,
				'assets'       => [
					[
						'name'                 => 'woo-exports-v' . ltrim( $tag, 'v' ) . '.zip',
						'browser_download_url' => 'https://github.com/biscuitstudios/woo-exports/releases/download/'
							. $tag . '/woo-exports-v' . ltrim( $tag, 'v' ) . '.zip',
					],
				],
			],
			$overrides
		);
	}

	private static function fetch( array $releases ) {
		$GLOBALS['_wooex_test_http'] = (string) json_encode( $releases );

		return self::call( new Wooex_Updater( self::MAIN ), 'fetch_release' );
	}

	/* ------------------------------------------------ which release is offered */

	public function test_it_offers_the_newest_release(): void {
		$release = self::fetch( [
			self::apiRelease( 'v0.17.0', '2026-09-14T10:00:00Z' ),
			self::apiRelease( 'v0.16.0', '2026-09-11T10:00:00Z' ),
		] );

		$this->assertSame( '0.17.0', $release['version'] );
		$this->assertSame( 'https://github.com/biscuitstudios/woo-exports/releases/download/v0.17.0/woo-exports-v0.17.0.zip', $release['package'] );
	}

	public function test_drafts_and_prereleases_are_skipped(): void {
		// This is what releases/latest does for us, and switching to the list
		// endpoint moved the job here. Getting it wrong offers every site a
		// release that was never meant to ship.
		$release = self::fetch( [
			self::apiRelease( 'v0.19.0', '2026-09-16T10:00:00Z', '* A thing', [ 'draft' => true ] ),
			self::apiRelease( 'v0.18.0', '2026-09-15T10:00:00Z', '* A thing', [ 'prerelease' => true ] ),
			self::apiRelease( 'v0.17.0', '2026-09-14T10:00:00Z' ),
		] );

		$this->assertSame( '0.17.0', $release['version'] );
		$this->assertCount( 1, $release['log'] );
	}

	public function test_a_tag_that_is_not_a_version_is_skipped(): void {
		$release = self::fetch( [
			self::apiRelease( 'nightly', '2026-09-16T10:00:00Z' ),
			self::apiRelease( 'v0.17.0', '2026-09-14T10:00:00Z' ),
		] );

		$this->assertSame( '0.17.0', $release['version'] );
	}

	public function test_a_newest_release_with_no_zip_offers_nothing(): void {
		// GitHub's own "Source code (zip)" is not an asset, so a release with
		// no built zip is not installable. Offering it would hand every site a
		// package that cannot be installed.
		$release = self::fetch( [
			self::apiRelease( 'v0.17.0', '2026-09-14T10:00:00Z', '* A thing', [ 'assets' => [] ] ),
		] );

		$this->assertNull( $release );
	}

	public function test_an_empty_list_offers_nothing(): void {
		$this->assertNull( self::fetch( [] ) );
	}

	/* ------------------------------------------------------------ the log list */

	public function test_the_log_is_newest_first_and_carries_dates(): void {
		$release = self::fetch( [
			self::apiRelease( 'v0.17.0', '2026-09-14T10:00:00Z' ),
			self::apiRelease( 'v0.16.0', '2026-09-11T10:00:00Z' ),
			self::apiRelease( 'v0.15.1', '2026-09-08T10:00:00Z' ),
		] );

		$this->assertSame( [ '0.17.0', '0.16.0', '0.15.1' ], array_column( $release['log'], 'version' ) );
		$this->assertSame( '2026-09-14T10:00:00Z', $release['log'][0]['published'] );
	}

	public function test_more_releases_than_the_cap_are_reported_as_truncated(): void {
		// A capped list that says nothing reads as the whole history.
		$releases = [];

		for ( $i = 20; $i > 0; $i-- ) {
			$releases[] = self::apiRelease( 'v1.0.' . $i, '2026-09-14T10:00:00Z' );
		}

		$release = self::fetch( $releases );

		$this->assertTrue( $release['truncated'] );
		$this->assertCount( 10, $release['log'] );
	}

	public function test_a_short_history_is_not_truncated(): void {
		$release = self::fetch( [ self::apiRelease( 'v0.17.0', '2026-09-14T10:00:00Z' ) ] );

		$this->assertFalse( $release['truncated'] );
	}

	public function test_assets_are_not_kept_in_the_cached_log(): void {
		// The log goes into a site transient. Every asset of every release is
		// a large amount of JSON nothing reads back.
		$release = self::fetch( [ self::apiRelease( 'v0.17.0', '2026-09-14T10:00:00Z' ) ] );

		$this->assertArrayNotHasKey( 'assets', $release['log'][0] );
	}

	public function test_one_request_serves_both_jobs(): void {
		self::fetch( [ self::apiRelease( 'v0.17.0', '2026-09-14T10:00:00Z' ) ] );

		// 60 unauthenticated calls an hour per IP, shared by client sites on
		// the same host. Asking releases/latest as well would double that.
		$this->assertStringContainsString( '/releases?per_page=11', $GLOBALS['_wooex_test_http_url'] );
	}

	/* ------------------------------------------------------- the compare link */

	public function test_the_trailing_compare_link_is_stripped(): void {
		$release = self::fetch( [
			self::apiRelease(
				'v0.17.0',
				'2026-09-14T10:00:00Z',
				"* A thing\n\n**Full Changelog**: https://github.com/biscuitstudios/woo-exports/compare/v0.16.0...v0.17.0"
			),
		] );

		$this->assertSame( '* A thing', $release['log'][0]['notes'] );
	}

	public function test_a_compare_link_written_into_the_notes_survives(): void {
		// Only a trailing one is GitHub's. One in the middle was written by a
		// person and means something.
		$body = "* See **Full Changelog**: https://example.com/x for detail\n* A second thing";

		$release = self::fetch( [ self::apiRelease( 'v0.17.0', '2026-09-14T10:00:00Z', $body ) ] );

		$this->assertSame( $body, $release['log'][0]['notes'] );
	}

	/* ------------------------------------------------------------- the markup */

	private static function render( array $release ): string {
		return self::call( new Wooex_Updater( self::MAIN ), 'render_changelog', [ $release ] );
	}

	private static function renderable( array $log, bool $truncated = false ): array {
		return [
			'log'          => $log,
			'truncated'    => $truncated,
			'releases_url' => 'https://github.com/biscuitstudios/woo-exports/releases',
		];
	}

	public function test_every_version_gets_a_heading_with_its_date(): void {
		$html = self::render( self::renderable( [
			[ 'version' => '0.17.0', 'published' => '2026-09-14T10:00:00Z', 'notes' => '* A thing' ],
			[ 'version' => '0.16.0', 'published' => '2026-09-11T10:00:00Z', 'notes' => '* Another thing' ],
		] ) );

		$this->assertStringContainsString( '<h3>0.17.0 | September 14, 2026</h3>', $html );
		$this->assertStringContainsString( '<h3>0.16.0 | September 11, 2026</h3>', $html );
	}

	public function test_a_version_heading_outranks_a_heading_inside_release_notes(): void {
		// GitHub's generated notes carry their own "What's Changed" heading. At
		// the same level it reads as another version rather than part of one.
		$html = self::render( self::renderable( [
			[ 'version' => '0.10.1', 'published' => '2026-08-22T10:00:00Z', 'notes' => "## What's Changed\n* A thing" ],
		] ) );

		$this->assertStringContainsString( '<h3>0.10.1 | August 22, 2026</h3>', $html );
		$this->assertStringContainsString( '<h4>What&#039;s Changed</h4>', $html );
	}

	public function test_a_version_with_no_date_still_gets_a_heading(): void {
		$html = self::render( self::renderable( [
			[ 'version' => '0.17.0', 'published' => '', 'notes' => '* A thing' ],
		] ) );

		$this->assertStringContainsString( '<h3>0.17.0</h3>', $html );
	}

	public function test_notes_fall_back_to_the_shipped_readme(): void {
		// Everything tagged before the changelog went into the release body has
		// an empty body once the compare link is stripped. The history is in
		// readme.txt, and this is what stops the modal reading as ten blanks.
		$html = self::render( self::renderable( [
			[ 'version' => '0.16.0', 'published' => '2026-09-11T10:00:00Z', 'notes' => '' ],
		] ) );

		$this->assertStringNotContainsString( 'No release notes were published', $html );
		$this->assertStringContainsString( '<li>', $html );
	}

	public function test_a_version_missing_from_both_sources_says_so(): void {
		$html = self::render( self::renderable( [
			[ 'version' => '9.9.9', 'published' => '2026-09-11T10:00:00Z', 'notes' => '' ],
		] ) );

		$this->assertStringContainsString( 'No release notes were published', $html );
	}

	public function test_the_footer_link_names_what_was_left_out(): void {
		$full = self::render( self::renderable( [ [ 'version' => '0.17.0', 'published' => '', 'notes' => '* A thing' ] ] ) );
		$cut  = self::render( self::renderable( [ [ 'version' => '0.17.0', 'published' => '', 'notes' => '* A thing' ] ], true ) );

		$this->assertStringContainsString( 'View all releases on GitHub', $full );
		$this->assertStringContainsString( 'Earlier releases are on GitHub', $cut );
	}

	public function test_nothing_renders_a_pre_tag(): void {
		// The regression guard for the horizontal scroll. Core's wp_kses()
		// allows <pre> but strips every attribute, so a <pre> here cannot be
		// made to wrap and the modal scrolls sideways.
		$html = self::render( self::renderable( [
			[ 'version' => '0.17.0', 'published' => '2026-09-14T10:00:00Z', 'notes' => "* A very long line that would otherwise run off the side of the modal and force the reader to scroll" ],
		] ) );

		$this->assertStringNotContainsString( '<pre', $html );
	}

	/* --------------------------------------------------------- the readme read */

	public function test_the_shipped_readme_parses(): void {
		// Positive control. Every other assertion here uses input written for
		// the test; this one reads the file the plugin actually ships.
		$sections = self::call( new Wooex_Updater( self::MAIN ), 'readme_changelog' );

		$this->assertNotEmpty( $sections );
		$this->assertArrayHasKey( '0.16.0', $sections );
		$this->assertStringStartsWith( '*', $sections['0.16.0'] );
	}

	public function test_the_changelog_read_stops_at_the_next_readme_section(): void {
		// Changelog is last in all three readmes today, so nothing real
		// exercises the terminator. A section added after it would otherwise be
		// swallowed into the final version's notes.
		$dir = sys_get_temp_dir() . '/wooex-readme-test-' . uniqid();
		mkdir( $dir );
		file_put_contents(
			$dir . '/readme.txt',
			"== Changelog ==\n\n= 1.0.0 =\n* A thing\n\n== Upgrade Notice ==\n\n= 1.0.0 =\nDo not swallow this.\n"
		);

		$sections = self::call( new Wooex_Updater( $dir . '/plugin.php' ), 'readme_changelog' );

		unlink( $dir . '/readme.txt' );
		rmdir( $dir );

		$this->assertSame( '* A thing', $sections['1.0.0'] );
	}

	public function test_a_missing_readme_is_not_fatal(): void {
		$sections = self::call( new Wooex_Updater( sys_get_temp_dir() . '/definitely-not-here/plugin.php' ), 'readme_changelog' );

		$this->assertSame( [], $sections );
	}
}

<?php
/**
 * The admin script carries its own version so a stale cached copy can announce
 * itself at runtime. That only works while the number in the script matches the
 * plugin, and nothing at runtime can check that, so it is checked here.
 *
 * Same reasoning as the header-against-constant check in bin/build.sh: three
 * places now hold this version, and shipping any two of them out of step is
 * silent.
 */

namespace WooExports\Tests;

if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;

final class AssetVersionTest extends TestCase {

	private const ROOT = __DIR__ . '/..';

	private static function pluginHeaderVersion(): string {
		$main = (string) file_get_contents( self::ROOT . '/woo-exports.php' );
		preg_match( '/^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/m', $main, $m );
		return $m[1] ?? '';
	}

	private static function versionConstant(): string {
		$main = (string) file_get_contents( self::ROOT . '/woo-exports.php' );
		preg_match( "/define\(\s*'WOOEX_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'/", $main, $m );
		return $m[1] ?? '';
	}

	private static function scriptVersion(): string {
		$js = (string) file_get_contents( self::ROOT . '/assets/js/wooex-admin-reports.js' );
		preg_match( "/var\s+SCRIPT_VERSION\s*=\s*'([0-9]+\.[0-9]+\.[0-9]+)'/", $js, $m );
		return $m[1] ?? '';
	}

	public function test_all_three_versions_are_readable(): void {
		$this->assertNotSame( '', self::pluginHeaderVersion(), 'No Version header in woo-exports.php.' );
		$this->assertNotSame( '', self::versionConstant(), 'No WOOEX_VERSION constant in woo-exports.php.' );
		$this->assertNotSame(
			'',
			self::scriptVersion(),
			'No SCRIPT_VERSION in assets/js/wooex-admin-reports.js. The stale-asset check needs it.'
		);
	}

	public function test_the_script_version_matches_the_plugin(): void {
		$header = self::pluginHeaderVersion();

		$this->assertSame(
			$header,
			self::versionConstant(),
			'WOOEX_VERSION does not match the plugin header. bin/build.sh refuses to build on this.'
		);

		$this->assertSame(
			$header,
			self::scriptVersion(),
			'SCRIPT_VERSION in wooex-admin-reports.js does not match the plugin header, so every '
			. 'admin page would report a stale script even on a clean install. Bump it with the release.'
		);
	}

	/**
	 * The runtime half of the check. PHP has to actually send the version, or
	 * the comparison in the script is against undefined and never fires.
	 */
	public function test_php_localizes_the_version_to_the_script(): void {
		$admin = (string) file_get_contents( self::ROOT . '/admin/class-wooex-admin.php' );
		$this->assertMatchesRegularExpression(
			"/'version'\s*=>\s*WOOEX_VERSION/",
			$admin,
			'Wooex_Admin no longer localizes the version, so the stale-asset check cannot fire.'
		);
	}

	/**
	 * The guard that replaced a bare early return. If this ever goes back to
	 * returning silently, a missing dialog is a mystery again.
	 */
	public function test_the_dialog_openers_do_not_fail_silently(): void {
		$js = (string) file_get_contents( self::ROOT . '/assets/js/wooex-admin-reports.js' );

		$this->assertStringNotContainsString(
			'! emailDialog ) { return; }',
			$js,
			'An Email Export opener returns silently when the dialog is missing.'
		);
		// The call guard specifically, not the identifier: counting the
		// identifier also matches the function declaration and reads as three.
		$this->assertSame(
			2,
			substr_count( $js, 'if ( ! emailDialogReady() ) { return; }' ),
			'Both Email Export openers should go through emailDialogReady().'
		);
		$this->assertStringContainsString(
			'function emailDialogReady()',
			$js,
			'emailDialogReady() is called but no longer defined.'
		);
	}
}

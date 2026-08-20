<?php
/**
 * The guard that decides whether the integration scripts may write to a site.
 *
 * Those scripts create orders, delete orders and change the order storage
 * backend. The studio rule is that a client's live site is never touched, and
 * this function is the only thing enforcing it in code.
 *
 * It is tested here rather than by running it, because the sites available to
 * run it against all report themselves as local, so the refusal path cannot be
 * exercised in place. Extracting the decision as a pure function is what makes
 * it checkable at all.
 */

namespace WooExports\Tests;

if ( ! defined( 'ABSPATH' ) ) exit;

use PHPUnit\Framework\TestCase;

final class DevSiteCheckTest extends TestCase {

	/**
	 * @dataProvider allowedSites
	 */
	public function test_allows_development_sites( string $env, string $host ): void {
		$this->assertTrue(
			wooex_it_is_dev_site( $env, $host ),
			"Should allow env='$env' host='$host'"
		);
	}

	public static function allowedSites(): array {
		return [
			'Local, declared'        => [ 'local', 'biscuit-bootstrap-dev.local' ],
			'development, declared'  => [ 'development', 'anything.example.com' ],
			'.local host'            => [ 'production', 'the-georgia-trust.local' ],
			'.test host'             => [ 'production', 'client.test' ],
			'localhost'              => [ 'production', 'localhost' ],
			'.localhost subdomain'   => [ 'production', 'site.localhost' ],
			'loopback v4'            => [ 'production', '127.0.0.1' ],
			'loopback v6'            => [ 'production', '::1' ],
			'mixed case host'        => [ 'production', 'Biscuit-Dev.LOCAL' ],
			'padded host'            => [ 'production', '  client.local  ' ],
		];
	}

	/**
	 * @dataProvider refusedSites
	 */
	public function test_refuses_everything_else( string $env, string $host ): void {
		$this->assertFalse(
			wooex_it_is_dev_site( $env, $host ),
			"MUST refuse env='$env' host='$host'"
		);
	}

	public static function refusedSites(): array {
		return [
			// The ones that actually matter: real client sites in the portfolio.
			'live client site'       => [ 'production', 'www.thegeorgiatrust.org' ],
			'live client, apex'      => [ 'production', 'thegeorgiatrust.org' ],
			'studio site'            => [ 'production', 'biscuitstudios.com' ],

			// Staging is NOT development. It has real data and real customers.
			'staging environment'    => [ 'staging', 'staging.client.org' ],
			'staging subdomain'      => [ 'production', 'staging.client.org' ],

			// Near-misses on the suffix list. A domain that merely CONTAINS a
			// local-looking word is not local.
			'localhost in the middle'=> [ 'production', 'localhost.evil.com' ],
			'local in the middle'    => [ 'production', 'mylocal.com' ],
			'test in the middle'     => [ 'production', 'test.example.com' ],
			'dot-local-ish TLD'      => [ 'production', 'example.locale' ],

			// No signal at all is not evidence of safety.
			'empty host'             => [ 'production', '' ],
			'whitespace host'        => [ 'production', '   ' ],
			'unknown environment'    => [ 'production', 'example.com' ],
		];
	}

	/**
	 * `.local` must match as a suffix, never as a substring. A domain like
	 * localhost.evil.com is a plausible way to fool a naive contains() check,
	 * and is covered above; this pins the rule itself.
	 */
	public function test_suffix_match_is_anchored_not_substring(): void {
		$this->assertTrue( wooex_it_is_dev_site( 'production', 'shop.client.local' ) );
		$this->assertFalse( wooex_it_is_dev_site( 'production', 'client.local.com' ) );
	}
}

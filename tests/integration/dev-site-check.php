<?php
/**
 * The "is this safe to write to" decision, as a pure function.
 *
 * Kept separate from bootstrap.php, and free of any WordPress dependency, so it
 * can be unit-tested without a database. A guard that has never been shown to
 * refuse anything is a comment, not a guard, and this one stands between a
 * fixture seeder and somebody's live store.
 *
 * @package WooExports
 */

/**
 * Hostnames that mean "not a real site".
 *
 * Kept as suffixes rather than a regex so the list reads as data. `.local` is
 * what Local uses, `.test` is the reserved TLD for this purpose, and the
 * loopback forms cover a plain built-in server.
 */
const WOOEX_IT_LOCAL_HOST_SUFFIXES = [ '.local', '.test', '.localhost', 'localhost', '127.0.0.1', '::1' ];

/**
 * May these scripts write to a site reporting this environment and hostname?
 *
 * Either signal is enough. WordPress reports `production` whenever
 * WP_ENVIRONMENT_TYPE is unset, which is most hand-rolled local installs, so
 * requiring the environment alone would refuse almost everywhere. Requiring the
 * hostname alone would refuse a legitimately local site on a custom domain.
 *
 * @param string $environment Result of wp_get_environment_type().
 * @param string $host        Host part of the site URL.
 */
function wooex_it_is_dev_site( string $environment, string $host ): bool {
	if ( in_array( $environment, [ 'local', 'development' ], true ) ) {
		return true;
	}

	$host = strtolower( trim( $host ) );
	if ( '' === $host ) {
		// No hostname and no dev environment is not evidence of safety.
		return false;
	}

	foreach ( WOOEX_IT_LOCAL_HOST_SUFFIXES as $suffix ) {
		if ( $host === $suffix || str_ends_with( $host, $suffix ) ) {
			return true;
		}
	}

	return false;
}

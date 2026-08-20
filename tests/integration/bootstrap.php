<?php
/**
 * Shared bootstrap for the integration scripts in this directory.
 *
 * These are not PHPUnit tests. They need a booted WordPress with a real
 * database, which the unit suite deliberately does not have. PHPUnit only
 * collects `*Test.php`, so nothing here is picked up by `composer test`.
 *
 * Responsibilities, in order of how much trouble they save:
 *
 *  1. Refuse to run write operations against anything that is not obviously a
 *     development site. These scripts create and delete orders.
 *  2. Find WordPress without anyone hardcoding a path.
 *  3. Reach the database when invoked from a plain CLI PHP rather than through
 *     the host's own runtime.
 *  4. Make sure nothing sends email.
 *
 * @package WooExports
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( "Integration scripts are CLI only.\n" );
}

require_once __DIR__ . '/dev-site-check.php';

define( 'DISABLE_WP_CRON', true );
define( 'WP_USE_THEMES', false );

// Raise this BEFORE wp-load, not after. Booting a real site loads every active
// plugin, and on a well-populated install that alone exceeds PHP's 128M CLI
// default. wp_raise_memory_limit() is no help: it cannot run until WordPress
// is already loaded, which is the part that runs out.
if ( -1 !== (int) ini_get( 'memory_limit' ) ) {
	@ini_set( 'memory_limit', getenv( 'WOOEX_IT_MEMORY' ) ?: '1G' );
}

/**
 * Walk up from this file to find wp-load.php.
 *
 * The plugin normally sits at wp-content/plugins/woo-exports/tests/integration,
 * but counting directories breaks the moment someone uses a custom content
 * directory or a symlinked plugin, so search rather than assume.
 */
function wooex_it_find_wp_load(): string {
	$dir = __DIR__;
	for ( $i = 0; $i < 12; $i++ ) {
		if ( is_file( $dir . '/wp-load.php' ) ) {
			return $dir . '/wp-load.php';
		}
		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			break;
		}
		$dir = $parent;
	}
	fwrite( STDERR, "Could not locate wp-load.php above " . __DIR__ . ".\n" );
	exit( 1 );
}

$wooex_it_wp_load = wooex_it_find_wp_load();
$wooex_it_wp_root = dirname( $wooex_it_wp_load );

/**
 * Work out a DB_HOST that a plain CLI PHP can actually connect through.
 *
 * Local writes `DB_HOST = localhost` into wp-config, which only resolves inside
 * Local's own PHP, and its MySQL refuses TCP from 127.0.0.1 outright. The
 * working route is the per-site unix socket, whose path contains a site ID that
 * only Local's sites.json knows.
 *
 * Resolution order:
 *   1. WOOEX_DB_HOST, if set. Always wins, works anywhere.
 *   2. Local's socket, looked up by matching this site's path in sites.json.
 *   3. Nothing. wp-config's own value stands, which is correct under WP-CLI or
 *      any host that does not play this trick.
 */
function wooex_it_resolve_db_host( string $wp_root ): ?string {
	$explicit = getenv( 'WOOEX_DB_HOST' );
	if ( is_string( $explicit ) && '' !== $explicit ) {
		return $explicit;
	}

	$home = getenv( 'HOME' );
	if ( ! $home ) {
		return null;
	}
	$sites_json = $home . '/Library/Application Support/Local/sites.json';
	if ( ! is_readable( $sites_json ) ) {
		return null;
	}

	$sites = json_decode( (string) file_get_contents( $sites_json ), true );
	if ( ! is_array( $sites ) ) {
		return null;
	}

	$real_root = realpath( $wp_root ) ?: $wp_root;
	foreach ( $sites as $id => $site ) {
		$path = $site['path'] ?? '';
		if ( ! is_string( $path ) || '' === $path ) {
			continue;
		}
		$real_site = realpath( $path ) ?: $path;
		// The WP root sits under the site path (usually at app/public).
		if ( 0 !== strpos( $real_root, rtrim( $real_site, '/' ) . '/' ) && $real_root !== $real_site ) {
			continue;
		}
		$socket = $home . '/Library/Application Support/Local/run/' . $id . '/mysql/mysqld.sock';
		if ( file_exists( $socket ) ) {
			return 'localhost:' . $socket;
		}
	}

	return null;
}

$wooex_it_db_host = wooex_it_resolve_db_host( $wooex_it_wp_root );
if ( null !== $wooex_it_db_host ) {
	define( 'DB_HOST', $wooex_it_db_host );
}

// Defining DB_HOST first makes wp-config's own define() a no-op, which is the
// point, but PHP warns about it on stdout and corrupts JSON output. Swallow
// that one warning and nothing else.
set_error_handler(
	static function ( int $errno, string $errstr ) {
		return (bool) preg_match( '/Constant DB_HOST already defined/', $errstr );
	},
	E_WARNING
);
require_once $wooex_it_wp_load;
restore_error_handler();

// Nothing here may send mail. Creating an order and setting its status fires
// WooCommerce's transactional emails; pre_wp_mail short-circuits the send and
// reports success, so nothing leaves the machine even on a misconfigured site.
add_filter( 'pre_wp_mail', '__return_true', PHP_INT_MAX );

/**
 * Hard stop before anything that writes.
 *
 * These scripts create orders, delete orders, and flip the order storage
 * backend. On a live store that is a disaster, and the studio rule is that
 * client sites are never touched at all. Refuse unless the site looks like
 * development, and make the override loud and deliberate.
 */
function wooex_it_require_dev_site(): void {
	if ( getenv( 'WOOEX_IT_FORCE' ) === '1' ) {
		fwrite( STDERR, "WOOEX_IT_FORCE=1 set: skipping the development-site check.\n" );
		return;
	}

	$env  = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
	$host = (string) wp_parse_url( (string) get_option( 'siteurl' ), PHP_URL_HOST );

	if ( wooex_it_is_dev_site( $env, $host ) ) {
		return;
	}

	fwrite(
		STDERR,
		"REFUSING TO RUN.\n"
		. "  Site: {$host}\n"
		. "  Environment: {$env}\n"
		. "This script writes to the database. It only runs on a site that reports an\n"
		. "environment of local/development, or whose host looks local. If you are\n"
		. "certain, re-run with WOOEX_IT_FORCE=1.\n"
	);
	exit( 1 );
}

/** Emit a result as JSON on stdout and exit with the given code. */
function wooex_it_emit( array $data, int $exit_code = 0 ): void {
	echo json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), "\n";
	exit( $exit_code );
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	fwrite( STDERR, "WooCommerce is not active on this site.\n" );
	exit( 1 );
}

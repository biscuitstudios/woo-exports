<?php
/**
 * Lightweight WP-function stubs so the pure-logic classes under test can be
 * required without bootstrapping a full WordPress install.
 *
 * Anything that touches the database or HTTP is NOT exercised here — those
 * paths need a full WP test bootstrap, which is out of scope for the smoke
 * suite. The goal here is to catch timezone / parsing / regex regressions
 * in helpers we extracted as static functions.
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): DateTimeZone {
		// Override per-test by setting $GLOBALS['_wooex_test_tz']. Defaults to UTC
		// for predictability.
		return new DateTimeZone( $GLOBALS['_wooex_test_tz'] ?? 'UTC' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, $default = false ) {
		return $GLOBALS['_wooex_test_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( string $format, ?int $ts = null ): string {
		$ts  = $ts ?? time();
		$dt  = ( new DateTimeImmutable( '@' . $ts ) )->setTimezone( wp_timezone() );
		return $dt->format( $format );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( $n, $d = 0 ) { return number_format( (float) $n, (int) $d ); }
}
if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	function wp_specialchars_decode( $s, $q = null ) { return htmlspecialchars_decode( (string) $s ); }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $w ) { return $GLOBALS['_wooex_test_options']['bloginfo_' . $w] ?? ''; }
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( string $email ): string {
		$email = trim( $email );
		// Approximation — real sanitize_email is stricter, but enough for parsing tests.
		return preg_replace( '/[^a-zA-Z0-9._%+\-@]/', '', $email );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( string $email ) {
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
}

if ( ! function_exists( 'error_log' ) ) {
	// PHP's real error_log is available; this is just defensive.
	function error_log( string $msg ): bool { return true; }
}

// Pure, WordPress-free, so the integration scripts' safety guard can be tested
// without a database. See tests/DevSiteCheckTest.php.
require_once __DIR__ . '/integration/dev-site-check.php';

require_once __DIR__ . '/../includes/class-wooex-scheduler.php';
require_once __DIR__ . '/../includes/class-wooex-exporter.php';
require_once __DIR__ . '/../includes/class-wooex-data-products.php';
require_once __DIR__ . '/../includes/class-wooex-data-attendees.php';
require_once __DIR__ . '/../includes/class-wooex-data-orders.php';
require_once __DIR__ . '/../includes/class-wooex-mailer.php';

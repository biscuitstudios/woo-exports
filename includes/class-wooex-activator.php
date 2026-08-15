<?php
/**
 * Activation / deactivation hooks.
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wooex_Activator {

	/**
	 * Default export directory location. Sits at the wp-content level rather than
	 * inside wp-content/uploads/ so it doesn't get mixed into media-library browsing
	 * tools or the common attacker-script targets. Still inside WP_CONTENT_DIR so
	 * permissions inherit normally; the .htaccess + random filename suffix combine
	 * to keep files unreachable from the web even if a server config gap exists.
	 *
	 * Set the `WOOEX_EXPORT_DIR` constant in wp-config.php to override (e.g. point
	 * at a path outside the document root for an extra defense layer).
	 */
	public static function activate(): void {
		if ( false === get_option( 'wooex_reports', false ) ) {
			add_option( 'wooex_reports', [] );
		}

		self::ensure_export_dir();
		self::migrate_legacy_export_dir();
	}

	public static function deactivate(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'wooex_run_report', [], 'woo-exports' );
		}
	}

	public static function ensure_export_dir(): string {
		$dir = defined( 'WOOEX_EXPORT_DIR' )
			? rtrim( WOOEX_EXPORT_DIR, '/\\' )
			: trailingslashit( WP_CONTENT_DIR ) . 'wooex-exports';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Apache / LiteSpeed deny rule. nginx ignores .htaccess by design — for
		// nginx servers the DEPLOY runbook documents an equivalent location block,
		// and the random filename suffix below is the cross-server guarantee.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents(
				$htaccess,
				"# WooExports — files are served only through the authenticated\n" .
				"# download endpoints, never via direct URL.\n" .
				"<IfModule mod_authz_core.c>\n" .
				"\tRequire all denied\n" .
				"</IfModule>\n" .
				"<IfModule !mod_authz_core.c>\n" .
				"\tDeny from all\n" .
				"</IfModule>\n"
			);
		}

		// Defense in depth: hide any directory listing the server might otherwise
		// allow, and keep the dir out of any future git commits.
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		$gi = $dir . '/.gitignore';
		if ( ! file_exists( $gi ) ) {
			@file_put_contents( $gi, "*\n!.gitignore\n!.htaccess\n!index.php\n" );
		}

		return $dir;
	}

	/**
	 * Sweep stale export files left over from the pre-0.8 location
	 * (wp-content/uploads/wooex-exports/). The directory itself is left in place
	 * so admins can verify it's empty before removing.
	 */
	private static function migrate_legacy_export_dir(): void {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return;
		}
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return;
		}
		$legacy = trailingslashit( $uploads['basedir'] ) . 'wooex-exports';
		if ( ! is_dir( $legacy ) ) {
			return;
		}

		foreach ( (array) glob( $legacy . '/wooex-*.{csv,xlsx}', GLOB_BRACE ) as $file ) {
			@unlink( $file );
		}
	}
}

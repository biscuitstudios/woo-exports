<?php
/**
 * Serves plugin updates from the repo's GitHub Releases.
 *
 * WordPress only checks wordpress.org for plugins whose `Update URI` header
 * points there. This plugin's header points at GitHub, so core hands it to the
 * `update_plugins_github.com` filter instead and offers nothing at all unless
 * something answers. That filter defaults to false and core `continue`s on a
 * falsy return, so an unhooked filter is silent: no notice, no error, no log.
 * This class is what makes the update appear on the plugins screen.
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Wooex_Updater {

	private const REPO = 'biscuitstudios/woo-exports';

	private const CACHE_KEY = 'wooex_updater_release';

	/**
	 * GitHub allows 60 unauthenticated API calls an hour per IP, and client
	 * sites on the same host share one. Cache hard, and back off separately
	 * after a failure so a rate-limited or offline lookup is not retried on
	 * every admin page load.
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	private const CACHE_TTL_FAILED = 1 * HOUR_IN_SECONDS;

	private string $file;

	private string $basename;

	private string $slug;

	public function __construct( string $file ) {
		$this->file     = $file;
		$this->basename = plugin_basename( $file );
		$this->slug     = dirname( $this->basename );
	}

	public function init(): void {
		add_filter( 'update_plugins_github.com', [ $this, 'check_for_update' ], 10, 3 );
		add_filter( 'plugins_api', [ $this, 'plugin_information' ], 10, 3 );

		// Core clears its own update_plugins transient on "Check Again" and
		// after an install. Ours has a 12 hour life of its own, so without this
		// a release tagged minutes ago stays invisible however many times
		// someone presses the button.
		add_action( 'delete_site_transient_update_plugins', [ $this, 'flush_cache' ] );
	}

	/**
	 * Answers core's per-hostname update filter.
	 *
	 * @param array|false $update      Update data set by a previous filter, or false.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename being asked about.
	 *
	 * @return array|false
	 */
	public function check_for_update( $update, array $plugin_data, string $plugin_file ) {
		// This filter fires for EVERY active plugin whose Update URI host is
		// github.com, which on a Biscuit site means all of them. Answer only
		// for this one, and pass anything else straight through: returning
		// false here would discard a sibling plugin's answer and silently
		// break its updates.
		if ( $plugin_file !== $this->basename ) {
			return $update;
		}

		$release = $this->get_release();

		if ( null === $release ) {
			return $update;
		}

		// No version comparison here on purpose. Core compares new_version
		// against the installed header and files the result under response or
		// no_update itself, and it needs the no_update entry to render "You
		// have the latest version" and the auto-update controls.
		return [
			'slug'         => $this->slug,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'icons'        => $this->icons(),
			'requires'     => $plugin_data['RequiresWP'] ?? '',
			'requires_php' => $plugin_data['RequiresPHP'] ?? '',
		];
	}

	/**
	 * Fills the "View version X details" modal.
	 *
	 * Core forces TB_iframe onto that link, so pointing it at a GitHub release
	 * page would open an empty box: GitHub refuses to be framed. Serving the
	 * details locally is what makes the link work.
	 *
	 * @param object|array|false $result The result object or array. Default false.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Arguments for the API call.
	 *
	 * @return object|array|false
	 */
	public function plugin_information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		// Guard both ways: without the slug check this would answer for every
		// plugin on the site, including ones that legitimately come from
		// wordpress.org.
		if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->get_release();

		if ( null === $release ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$data = get_plugin_data( $this->file, false, false );

		$info                = new stdClass();
		$info->name          = $data['Name'];
		$info->slug          = $this->slug;
		$info->version       = $release['version'];
		$info->author        = $data['Author'];
		$info->homepage      = $data['PluginURI'];
		$info->requires      = $data['RequiresWP'];
		$info->requires_php  = $data['RequiresPHP'];
		$info->download_link = $release['package'];
		$info->icons         = $this->icons();
		$info->last_updated  = $release['published'];
		$info->sections      = [
			'description' => wpautop( esc_html( $data['Description'] ) ),
			'changelog'   => $this->render_changelog( $release ),
		];

		return $info;
	}

	/**
	 * Artwork for this plugin, as core's update and install screens expect it.
	 *
	 * Nothing supplies this for us. A wordpress.org plugin gets icons from the
	 * .org API; ours is served from GitHub Releases, so if the updater does not
	 * hand core an `icons` array the screens fall back to a generic dashicon.
	 *
	 * The file ships inside the plugin, so the URL is local and needs no
	 * network call. Core reads the keys in the order svg, 2x, 1x, default
	 * (see wp-admin/update-core.php), so `svg` is what actually gets used;
	 * `default` is there for any consumer that does not look at `svg`. Both
	 * point at the same file, which is fine because core renders an icon as
	 * <img src="...">, and an <img> scales an SVG to whatever size it needs.
	 *
	 * @return array<string,string>
	 */
	private function icons(): array {
		$url = plugins_url( 'assets/img/icon.svg', $this->file );

		return [
			'svg'     => $url,
			'default' => $url,
		];
	}

	public function flush_cache(): void {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Returns the latest release, or null when it cannot be established.
	 *
	 * @return array{version:string,package:string,url:string,changelog:string,published:string}|null
	 */
	private function get_release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );

		// A failed lookup is cached as a scalar sentinel so it is telling apart
		// from both a hit (array) and a cold cache (false).
		if ( 'failed' === $cached ) {
			return null;
		}

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$release = $this->fetch_release();

		if ( null === $release ) {
			set_site_transient( self::CACHE_KEY, 'failed', self::CACHE_TTL_FAILED );

			return null;
		}

		set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

		return $release;
	}

	/**
	 * @return array{version:string,package:string,url:string,changelog:string,published:string}|null
	 */
	private function fetch_release(): ?array {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[WooExports] Update check failed: ' . $response->get_error_message() );

			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			// 403 here is almost always the unauthenticated rate limit rather
			// than a permissions problem, and it clears on its own.
			error_log( '[WooExports] Update check failed: HTTP ' . $code . ' from ' . self::REPO );

			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			error_log( '[WooExports] Update check failed: no tag_name in the response for ' . self::REPO );

			return null;
		}

		$version = ltrim( (string) $body['tag_name'], 'vV' );

		if ( ! preg_match( '/^\d+\.\d+\.\d+/', $version ) ) {
			error_log( '[WooExports] Update check failed: unusable tag "' . $body['tag_name'] . '"' );

			return null;
		}

		$package = $this->find_zip_asset( is_array( $body['assets'] ?? null ) ? $body['assets'] : [], $version );

		// A release with no built zip attached is not installable. GitHub's own
		// "Source code (zip)" is not an asset, which is the outcome we want:
		// that archive has no vendor/ in it and would break the XLSX writer on
		// every site that took it.
		if ( '' === $package ) {
			error_log( '[WooExports] Update check failed: release ' . $body['tag_name'] . ' has no zip asset.' );

			return null;
		}

		return [
			'version'   => $version,
			'package'   => $package,
			'url'       => (string) ( $body['html_url'] ?? '' ),
			'changelog' => (string) ( $body['body'] ?? '' ),
			'published' => (string) ( $body['published_at'] ?? '' ),
		];
	}

	/**
	 * Picks the built zip out of a release's attached assets.
	 *
	 * Prefers the name bin/build.sh produces and falls back to any other zip,
	 * so a hand-uploaded asset still works.
	 */
	private function find_zip_asset( array $assets, string $version ): string {
		$preferred = $this->slug . '-v' . $version . '.zip';
		$fallback  = '';

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}

			$name = (string) ( $asset['name'] ?? '' );
			$url  = (string) ( $asset['browser_download_url'] ?? '' );

			if ( '' === $url || ! str_ends_with( strtolower( $name ), '.zip' ) ) {
				continue;
			}

			if ( $name === $preferred ) {
				return $url;
			}

			if ( '' === $fallback ) {
				$fallback = $url;
			}
		}

		return $fallback;
	}

	/**
	 * Turns a release body into the markup the details modal shows.
	 *
	 * @param array{version:string,package:string,url:string,changelog:string,published:string} $release
	 */
	private function render_changelog( array $release ): string {
		$body = trim( $release['changelog'] );
		$out  = '';

		if ( '' === $body ) {
			$out .= '<p>' . esc_html__( 'No release notes were published for this version.', 'woo-exports' ) . '</p>';
		} else {
			$out .= self::render_markdown( $body );
		}

		if ( '' !== $release['url'] ) {
			// No target or rel here on purpose. Core passes every section
			// through links_add_target(), which adds the target itself, and
			// then through wp_kses(), which strips rel either way.
			$out .= sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $release['url'] ),
				esc_html__( 'View this release on GitHub', 'woo-exports' )
			);
		}

		return $out;
	}

	/**
	 * Renders the subset of Markdown the release notes actually use.
	 *
	 * Release bodies are built from this plugin's own readme.txt changelog by
	 * .github/workflows/release.yml, so the vocabulary is small and known:
	 * `*` bullets with indented continuation lines, `**bold**`, backtick code
	 * spans, links, and the "What's Changed" heading GitHub appends
	 * underneath. Shipping a Markdown library to 62 client sites to format one
	 * modal is not a trade worth making.
	 *
	 * This replaced a `<pre style="white-space:pre-wrap">` that never wrapped.
	 * Core filters every section through wp_kses() against a fixed allowlist
	 * ($plugins_allowedtags in wp-admin/includes/plugin-install.php) which
	 * permits <pre> but none of its attributes, so that style was always being
	 * stripped and long lines always overflowed. Everything below stays on
	 * that list and sets no attribute other than href.
	 */
	public static function render_markdown( string $markdown ): string {
		$lines = preg_split( '/\R/', $markdown );

		if ( false === $lines ) {
			return '';
		}

		$html  = '';
		$para  = [];
		$items = [];
		$open  = false; // Whether a bullet is still open to continuation lines.

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			// A blank line ends a paragraph and stops a bullet taking any more
			// continuation lines. It deliberately does NOT close the list:
			// readme.txt and GitHub both allow a blank line between items.
			if ( '' === $trimmed ) {
				$html .= self::close_paragraph( $para );
				$open  = false;
				continue;
			}

			if ( preg_match( '/^#{1,6}\s+(.*)$/', $trimmed, $m ) ) {
				$html .= self::close_paragraph( $para );
				$html .= self::close_list( $items );
				$html .= '<h4>' . self::inline( $m[1] ) . '</h4>';
				$open  = false;
				continue;
			}

			if ( preg_match( '/^[*-]\s+(.*)$/', $trimmed, $m ) ) {
				$html   .= self::close_paragraph( $para );
				$items[] = $m[1];
				$open    = true;
				continue;
			}

			// An indented line under a bullet belongs to that bullet. The
			// changelog wraps at 78 columns, so most entries run to three or
			// four lines and would otherwise break into separate paragraphs
			// mid-sentence.
			if ( $open && 1 === preg_match( '/^\s/', $line ) ) {
				$items[ count( $items ) - 1 ] .= ' ' . $trimmed;
				continue;
			}

			$html  .= self::close_list( $items );
			$open   = false;
			$para[] = $trimmed;
		}

		$html .= self::close_paragraph( $para );
		$html .= self::close_list( $items );

		return $html;
	}

	/**
	 * @param array<int,string> $para Paragraph lines. Emptied by this call.
	 */
	private static function close_paragraph( array &$para ): string {
		if ( [] === $para ) {
			return '';
		}

		$text = self::inline( implode( ' ', $para ) );
		$para = [];

		return '<p>' . $text . '</p>';
	}

	/**
	 * @param array<int,string> $items List items. Emptied by this call.
	 */
	private static function close_list( array &$items ): string {
		if ( [] === $items ) {
			return '';
		}

		$out = '<ul>';

		foreach ( $items as $item ) {
			$out .= '<li>' . self::inline( $item ) . '</li>';
		}

		$items = [];

		return $out . '</ul>';
	}

	/**
	 * Escapes a run of text, then adds inline formatting to it.
	 *
	 * Escaping comes first and every tag is added after it, so nothing written
	 * in a release body can introduce markup of its own. The other order would
	 * have esc_html() eat the tags this adds.
	 */
	private static function inline( string $text ): string {
		$text = esc_html( $text );

		// Code spans before bold. A code span can contain asterisks, and bold
		// run first would chew straight through them.
		$text = (string) preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

		// One pass for both link forms. Nothing above has produced an <a>, so
		// the bare-URL branch cannot match a URL that is already an href.
		return (string) preg_replace_callback(
			'/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)|(https?:\/\/[^\s<]+)/',
			static function ( array $m ): string {
				if ( ! isset( $m[3] ) || '' === $m[3] ) {
					return self::link( $m[2], $m[1] );
				}

				// A bare URL. Trailing sentence punctuation is not part of it:
				// "see https://example.com/x." must not link the full stop.
				$url  = $m[3];
				$tail = '';

				if ( preg_match( '/[.,;:!?)\]]+$/', $url, $p ) ) {
					$tail = $p[0];
					$url  = substr( $url, 0, -strlen( $tail ) );
				}

				return self::link( $url, $url ) . $tail;
			},
			$text
		);
	}

	/**
	 * Builds one link from an already-escaped URL and label.
	 *
	 * The URL arrives HTML-escaped, so an ampersand reads as `&amp;`. esc_url()
	 * would take that literally and encode it a second time, hence the decode.
	 * A URL esc_url() rejects outright is shown as plain text rather than
	 * dropped, so nothing silently disappears from the notes.
	 */
	private static function link( string $url, string $label ): string {
		$href = esc_url( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $href ) {
			return $label;
		}

		return '<a href="' . $href . '">' . $label . '</a>';
	}
}

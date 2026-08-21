<?php
/**
 * Phase 4 — production admin UI for managing export configurations.
 *
 * Registers the top-level WooExports admin menu, enqueues assets, and exposes
 * AJAX endpoints for create / edit / delete / run-now / toggle-active.
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wooex_Admin {

	public const MENU_SLUG = 'wooex-reports';
	private const NONCE    = 'wooex_ajax';

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'admin_init', [ $this, 'maybe_handle_row_action' ] );
		add_filter( 'set-screen-option', [ $this, 'save_screen_option' ], 10, 3 );

		add_action( 'wp_ajax_wooex_save_report',     [ $this, 'ajax_save_report' ] );
		add_action( 'wp_ajax_wooex_delete_report',   [ $this, 'ajax_delete_report' ] );
		add_action( 'wp_ajax_wooex_run_now',         [ $this, 'ajax_run_now' ] );
		add_action( 'wp_ajax_wooex_get_report',      [ $this, 'ajax_get_report' ] );
		add_action( 'wp_ajax_wooex_toggle_active',   [ $this, 'ajax_toggle_active' ] );
		add_action( 'wp_ajax_wooex_review_report',   [ $this, 'ajax_review_report' ] );
		add_action( 'wp_ajax_wooex_search_parent_posts', [ $this, 'ajax_search_parent_posts' ] );
		add_action( 'wp_ajax_wooex_search_customers',    [ $this, 'ajax_search_customers' ] );
		add_action( 'wp_ajax_wooex_search_products',     [ $this, 'ajax_search_products' ] );

		add_action( 'admin_post_wooex_download_report',  [ $this, 'handle_download_report' ] );
		add_action( 'admin_post_wooex_download_preview', [ $this, 'handle_download_preview' ] );
	}

	public function register_menu(): void {
		$hook = add_menu_page(
			'WooExports',
			'WooExports',
			'manage_woocommerce',
			self::MENU_SLUG,
			[ $this, 'render_page' ],
			'dashicons-media-spreadsheet',
			56
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, [ $this, 'add_screen_options' ] );
		}
	}

	public function add_screen_options(): void {
		$view = isset( $_GET['wooex_view'] ) ? sanitize_key( wp_unslash( $_GET['wooex_view'] ) ) : '';
		if ( 'new' === $view || 'edit' === $view ) {
			return; // builder doesn't need pagination
		}
		add_screen_option(
			'per_page',
			[
				'label'   => 'Exports per page',
				'default' => 25,
				'option'  => 'wooex_reports_per_page',
			]
		);
	}

	public function save_screen_option( $status, $option, $value ) {
		return 'wooex_reports_per_page' === $option ? (int) $value : $status;
	}

	public function enqueue_assets( $hook ): void {
		if ( ! is_string( $hook ) || false === strpos( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_style( 'woocommerce_admin_styles' );

		wp_enqueue_style(
			'wooex-admin',
			WOOEX_URL . 'assets/css/wooex-admin.css',
			[],
			WOOEX_VERSION
		);

		wp_enqueue_script(
			'wooex-admin-reports',
			WOOEX_URL . 'assets/js/wooex-admin-reports.js',
			[ 'jquery', 'wc-enhanced-select' ],
			WOOEX_VERSION,
			true
		);

		wp_localize_script(
			'wooex-admin-reports',
			'wooexAdmin',
			[
				'ajax_url'         => admin_url( 'admin-ajax.php' ),
				'admin_post_url'   => admin_url( 'admin-post.php' ),
				'nonce'            => wp_create_nonce( self::NONCE ),
				'nonce_war'        => wp_create_nonce( 'wooex_search' ),
				'nonce_preview_dl' => wp_create_nonce( 'wooex_download_preview' ),
				// Which ranges honour day_start. Passed from PHP rather than
				// restated here, so the field's visibility cannot disagree with
				// what the resolver actually does.
				'day_start_ranges' => Wooex_Data_Orders::DAY_START_RANGES,
				'i18n'          => [
					'confirm_delete' => __( 'Delete this export? This cannot be undone.', 'woo-exports' ),
					'saving'         => __( 'Saving…', 'woo-exports' ),
					'running'        => __( 'Running…', 'woo-exports' ),
					'invalid_email'  => __( 'One or more recipient addresses are invalid.', 'woo-exports' ),
					'name_required'  => __( 'Export name is required.', 'woo-exports' ),
					'recipients_required' => __( 'At least one recipient is required.', 'woo-exports' ),
					'custom_range_required' => __( 'Custom range requires both From and To dates.', 'woo-exports' ),
					'custom_range_order'    => __( 'The "To" date must be on or after the "From" date.', 'woo-exports' ),
					'days_required'         => __( 'Select at least one day of the week.', 'woo-exports' ),
					'range_note_stale'      => __( 'Save to see the window this resolves to.', 'woo-exports' ),
				],
			]
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized' );
		}

		$view = isset( $_GET['wooex_view'] ) ? sanitize_key( wp_unslash( $_GET['wooex_view'] ) ) : '';

		if ( 'new' === $view || 'edit' === $view ) {
			$this->render_builder( $view );
			return;
		}

		$this->render_list();
	}

	private function render_list(): void {
		if ( ! class_exists( 'Wooex_Reports_List_Table' ) ) {
			require_once WOOEX_DIR . 'admin/class-wooex-reports-list-table.php';
		}

		$table = new Wooex_Reports_List_Table();
		$table->prepare();
		$this->maybe_handle_bulk_action( $table );

		$list_url = self::list_url();
		$new_url  = self::builder_url( 'new' );
		$flashes  = $this->pop_flashes();

		include WOOEX_DIR . 'admin/views/page-reports.php';
	}

	public function maybe_handle_row_action(): void {
		if ( ! isset( $_GET['page'] ) || self::MENU_SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['wooex_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['wooex_action'] ) );

		if ( 'empty_trash' === $action ) {
			check_admin_referer( 'wooex_empty_trash' );
			$count = 0;
			foreach ( Wooex_Report_Store::all_trashed() as $r ) {
				if ( ! empty( $r['id'] ) ) {
					( new Wooex_Scheduler() )->unschedule( (string) $r['id'] );
					Wooex_Report_Store::delete( (string) $r['id'] );
					$count++;
				}
			}
			$this->flash( 'success', sprintf( '%d export(s) permanently deleted.', $count ) );
			wp_safe_redirect( remove_query_arg( [ 'wooex_action', '_wpnonce' ] ) );
			exit;
		}

		$id = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : '';
		if ( ! $id ) {
			return;
		}
		check_admin_referer( 'wooex_row_action_' . $id );

		switch ( $action ) {
			case 'trash':
				Wooex_Report_Store::trash( $id );
				( new Wooex_Scheduler() )->unschedule( $id );
				$this->flash( 'success', 'Export moved to trash.' );
				break;

			case 'restore':
				Wooex_Report_Store::restore( $id );
				$r = Wooex_Report_Store::get( $id );
				if ( $r && ! empty( $r['active'] ) ) {
					( new Wooex_Scheduler() )->schedule( $r );
				}
				$this->flash( 'success', 'Export restored.' );
				break;

			case 'delete_permanent':
				( new Wooex_Scheduler() )->unschedule( $id );
				Wooex_Report_Store::delete( $id );
				$this->flash( 'success', 'Export permanently deleted.' );
				break;

			default:
				return;
		}

		wp_safe_redirect( remove_query_arg( [ 'wooex_action', 'report_id', '_wpnonce' ] ) );
		exit;
	}

	private function maybe_handle_bulk_action( Wooex_Reports_List_Table $table ): void {
		$action = $table->current_action();
		if ( ! $action ) {
			return;
		}

		check_admin_referer( Wooex_Reports_List_Table::BULK_NONCE_ACTION );

		$ids = isset( $_REQUEST['report'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_REQUEST['report'] ) ) : [];
		$ids = array_values( array_filter( $ids ) );
		if ( empty( $ids ) ) {
			return;
		}

		$scheduler = new Wooex_Scheduler();
		$count     = 0;

		foreach ( $ids as $id ) {
			switch ( $action ) {
				case 'trash':
					Wooex_Report_Store::trash( $id );
					$scheduler->unschedule( $id );
					$count++;
					break;
				case 'restore':
					Wooex_Report_Store::restore( $id );
					$r = Wooex_Report_Store::get( $id );
					if ( $r && ! empty( $r['active'] ) ) {
						$scheduler->schedule( $r );
					}
					$count++;
					break;
				case 'delete_permanent':
					$scheduler->unschedule( $id );
					Wooex_Report_Store::delete( $id );
					$count++;
					break;
			}
		}

		$messages = [
			'trash'            => '%d export(s) moved to trash.',
			'restore'          => '%d export(s) restored.',
			'delete_permanent' => '%d export(s) permanently deleted.',
		];

		if ( isset( $messages[ $action ] ) ) {
			$this->flash( 'success', sprintf( $messages[ $action ], $count ) );
		}

		wp_safe_redirect( remove_query_arg( [ 'action', 'action2', 'report', '_wpnonce', '_wp_http_referer' ] ) );
		exit;
	}

	private function flash( string $type, string $message ): void {
		$key     = 'wooex_admin_flash_' . get_current_user_id();
		$stored  = get_transient( $key );
		$flashes = is_array( $stored ) ? $stored : [];
		$flashes[] = [ 'type' => $type, 'message' => $message ];
		set_transient( $key, $flashes, 60 );
	}

	private function pop_flashes(): array {
		$key    = 'wooex_admin_flash_' . get_current_user_id();
		$stored = get_transient( $key );
		delete_transient( $key );
		return is_array( $stored ) ? $stored : [];
	}

	private function render_builder( string $view ): void {
		$report = null;
		if ( 'edit' === $view ) {
			$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
			$report = $id ? Wooex_Report_Store::get( $id ) : null;
			if ( ! $report ) {
				wp_safe_redirect( self::list_url() );
				exit;
			}
		}

		$attendees_ready  = Wooex_Data_Attendees::is_available();
		$attendees_reason = $attendees_ready
			? ''
			: ( Wooex_Data_Attendees::detected_version()
				? 'Event Tickets Plus must be 6.9.2 or higher (currently ' . Wooex_Data_Attendees::detected_version() . ').'
				: 'Event Tickets Plus is not active.' );

		$cat_terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'number' => 500 ] );
		$tag_terms = get_terms( [ 'taxonomy' => 'product_tag', 'hide_empty' => false, 'number' => 500 ] );
		$cat_terms = is_array( $cat_terms ) ? $cat_terms : [];
		$tag_terms = is_array( $tag_terms ) ? $tag_terms : [];

		$wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];

		$preselected = $report ? $this->preselected_labels( $report['filters'] ?? [] ) : [
			'customer_ids'    => [],
			'product_ids'     => [],
			'parent_post_ids' => [],
		];

		$list_url = self::list_url();

		include WOOEX_DIR . 'admin/views/page-report-builder.php';
	}

	public static function list_url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	public static function builder_url( string $view, string $id = '' ): string {
		$args = [ 'page' => self::MENU_SLUG, 'wooex_view' => $view ];
		if ( '' !== $id ) {
			$args['id'] = $id;
		}
		return admin_url( 'admin.php?' . http_build_query( $args ) );
	}

	public static function download_url( string $id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=wooex_download_report&id=' . rawurlencode( $id ) ),
			'wooex_download_' . $id
		);
	}

	// =====================================================================
	// AJAX
	// =====================================================================

	public function ajax_save_report(): void {
		$this->guard();

		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		$type = sanitize_key( $_POST['type'] ?? '' );
		if ( ! in_array( $type, [ 'products', 'orders', 'customers', 'attendees' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid export type.' ], 400 );
		}

		$format = sanitize_key( $_POST['format'] ?? 'xlsx' );
		if ( ! in_array( $format, [ 'xlsx', 'csv' ], true ) ) {
			$format = 'xlsx';
		}

		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		if ( '' === $name ) {
			wp_send_json_error( [ 'message' => 'Export name is required.' ], 400 );
		}
		if ( mb_strlen( $name ) > 60 ) {
			$name = mb_substr( $name, 0, 60 );
		}

		$active     = ! empty( $_POST['active'] );
		$recipients = $this->sanitize_recipients( $_POST['recipients'] ?? '' );

		if ( $active && empty( $recipients ) ) {
			wp_send_json_error( [ 'message' => 'At least one valid recipient email is required for a scheduled export.' ], 400 );
		}

		$filters  = $this->sanitize_filters( $_POST['filters'] ?? [] );
		$schedule = $this->sanitize_schedule( $_POST['schedule'] ?? [] );

		$existing = $id ? Wooex_Report_Store::get( $id ) : null;

		$report = $existing ?: [];
		$report['id']         = $existing['id'] ?? Wooex_Report_Store::generate_id();
		$report['name']       = $name;
		$report['type']       = $type;
		$report['format']     = $format;
		$report['filters']    = $filters;
		$report['schedule']   = $schedule;
		$report['recipients'] = $recipients;
		$report['active']     = $active;
		if ( empty( $report['created_at'] ) ) {
			$report['created_at'] = time();
		}

		Wooex_Report_Store::save( $report );

		$scheduler = new Wooex_Scheduler();
		if ( $active ) {
			$scheduler->schedule( $report );
		} else {
			$scheduler->unschedule( $report['id'] );
			$report['next_run'] = null;
			Wooex_Report_Store::save( $report );
		}

		$fresh = Wooex_Report_Store::get( $report['id'] );
		wp_send_json_success(
			[
				'id'           => $fresh['id'],
				'download_url' => self::download_url( $fresh['id'] ),
				'message'      => $existing ? 'Export updated.' : 'Export created.',
			]
		);
	}

	public function ajax_delete_report(): void {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Missing export ID.' ], 400 );
		}

		( new Wooex_Scheduler() )->unschedule( $id );
		$ok = Wooex_Report_Store::delete( $id );

		if ( $ok ) {
			wp_send_json_success( [ 'message' => 'Export deleted.' ] );
		}
		wp_send_json_error( [ 'message' => 'Export not found.' ], 404 );
	}

	public function ajax_run_now(): void {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Missing export ID.' ], 400 );
		}

		$report = Wooex_Report_Store::get( $id );
		if ( ! $report ) {
			wp_send_json_error( [ 'message' => 'Export not found.' ], 404 );
		}

		( new Wooex_Scheduler() )->run_report( $id );
		$fresh = Wooex_Report_Store::get( $id );

		wp_send_json_success(
			[
				'status'  => $fresh['last_run_status'] ?? 'unknown',
				'message' => $fresh['last_run_message'] ?? '',
			]
		);
	}

	public function ajax_get_report(): void {
		$this->guard();
		$id = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );

		$report = Wooex_Report_Store::get( $id );
		if ( ! $report ) {
			wp_send_json_error( [ 'message' => 'Export not found.' ], 404 );
		}

		$report['preselected'] = $this->preselected_labels( $report['filters'] ?? [] );
		$report['recipients_text'] = implode( "\n", (array) ( $report['recipients'] ?? [] ) );

		wp_send_json_success( $report );
	}

	/**
	 * The clock a preview should resolve its date range against.
	 *
	 * With a shifted day boundary the window moves with the time of day: the
	 * same "Yesterday at 7:00 pm" report previewed at 10am and sent at 9pm
	 * covers two different periods, a whole day apart. The builder prints the
	 * window for the NEXT SCHEDULED RUN, so a preview evaluated at "now" would
	 * contradict the sentence sitting directly above the button.
	 *
	 * The clock comes from the saved schedule, not from whatever is in the form
	 * right now. That is deliberate: the note is rendered from the saved report
	 * too, so the two cannot disagree. Unsaved schedule edits are covered by the
	 * note going stale on change.
	 *
	 * Returns null when the report is unsaved or not scheduled. There "now" is
	 * the only meaningful answer, and the note says "Currently resolves to".
	 */
	private function preview_reference_ts( string $id ): ?int {
		if ( '' === $id ) {
			return null;
		}
		$report = Wooex_Report_Store::get( $id );
		if ( ! $report || empty( $report['active'] ) ) {
			return null;
		}
		return Wooex_Scheduler::next_run_timestamp( $report );
	}

	public function ajax_review_report(): void {
		$this->guard();

		$type = sanitize_key( $_POST['type'] ?? '' );
		if ( ! in_array( $type, [ 'products', 'orders', 'customers', 'attendees' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid export type.' ], 400 );
		}

		if ( 'attendees' === $type && ! Wooex_Data_Attendees::is_available() ) {
			wp_send_json_error( [ 'message' => 'Attendees report type is unavailable on this site.' ], 400 );
		}

		$preview_at = $this->preview_reference_ts(
			sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) )
		);

		// Large all-time / this-year queries can churn through tens of thousands
		// of orders. Without these, PHP hits its default memory/time ceiling and
		// returns a 500 — which the JS surfaces as a useless "Network error".
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		@set_time_limit( 120 );

		// Catch fatals (E_ERROR, OOM, max_execution_time, deprecation-as-error
		// converters in some hosts) so the AJAX call returns a parseable JSON
		// response with an actual error message instead of a 500 that the JS
		// flattens to "Network error".
		register_shutdown_function( function (): void {
			$err = error_get_last();
			if ( ! $err ) {
				return;
			}
			$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
			if ( ! ( $err['type'] & $fatal_types ) ) {
				return;
			}
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			if ( ! headers_sent() ) {
				status_header( 500 );
				header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			}
			echo wp_json_encode( [
				'success' => false,
				'data'    => [
					'message' => sprintf(
						'PHP error during preview: %s in %s:%d',
						$err['message'],
						basename( (string) $err['file'] ),
						(int) $err['line']
					),
				],
			] );
		} );

		// Buffer any stray notice/warning output that would otherwise corrupt
		// the JSON response. Discarded right before wp_send_json_success.
		ob_start();

		$filters = $this->sanitize_filters( $_POST['filters'] ?? [] );

		$preview_limit = 20;
		$start         = microtime( true );
		$rows          = [];
		$total         = 0;
		try {
			switch ( $type ) {
				case 'orders':
					// Use the paginated loader — runs COUNT(*) + LIMIT 20 instead
					// of loading every matching order into memory. Critical for
					// All Time on sites with hundreds of thousands of orders.
					$result = Wooex_Data_Orders::get_paginated( $filters, $preview_limit, $preview_at );
					$rows   = $result['rows'];
					$total  = $result['total'];
					break;
				case 'products':  $rows = Wooex_Data_Products::get( $filters );  break;
				case 'customers': $rows = Wooex_Data_Customers::get( $filters, $preview_at ); break;
				case 'attendees': $rows = Wooex_Data_Attendees::get( $filters, $preview_at ); break;
				default:          $rows = [];
			}
		} catch ( \Throwable $e ) {
			$stray = ob_get_clean();
			if ( $stray ) {
				error_log( '[WooExports] Stray output before exception: ' . substr( $stray, 0, 500 ) );
			}
			error_log(
				sprintf(
					'[WooExports] Review query failed (%s): %s in %s:%d',
					$type,
					$e->getMessage(),
					$e->getFile(),
					$e->getLine()
				)
			);
			wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
		}
		$elapsed = microtime( true ) - $start;

		$stray = ob_get_clean();
		if ( $stray ) {
			// Log but don't fail — preview succeeded, the stray output is just
			// a notice/warning we want to be aware of.
			error_log( '[WooExports] Stray output during preview: ' . substr( $stray, 0, 500 ) );
		}

		$rows = (array) $rows;
		// Orders set $total via get_paginated; everything else still loads the
		// full set so the count matches the row array.
		if ( 'orders' !== $type ) {
			$total = count( $rows );
		}

		// State the window the preview actually ran over. Without it a
		// boundary-shifted range is invisible: the rows look plausible and the
		// only way to notice a mismatch is to read order dates by eye.
		$range_str = ( 'products' === $type )
			? ''
			: Wooex_Mailer::format_range_for_filters( $filters, $preview_at );

		wp_send_json_success(
			[
				'rows'     => array_slice( $rows, 0, $preview_limit ),
				'count'    => $total,
				'time'     => round( $elapsed, 3 ),
				'range'    => $range_str,
				'range_at' => ( null === $preview_at )
					? ''
					: wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $preview_at ),
			]
		);
	}

	/**
	 * One-off XLSX download from the builder's current filters — no saved
	 * report config is required. Invoked from the Preview Export → Download
	 * button after the user has successfully previewed.
	 */
	public function handle_download_preview(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized', 'Forbidden', [ 'response' => 403 ] );
		}
		check_admin_referer( 'wooex_download_preview' );

		// Throttle: a preview-download builds a fresh XLSX from live data, which
		// for an "All Time" export can hold a PHP-FPM worker for minutes. Cap to
		// one preview every 5 seconds per user so a runaway browser tab / rage-
		// click can't exhaust the worker pool.
		$throttle_key = 'wooex_preview_dl_' . get_current_user_id();
		if ( get_transient( $throttle_key ) ) {
			wp_die(
				'Please wait a few seconds before requesting another preview download.',
				'Too Many Requests',
				[ 'response' => 429 ]
			);
		}
		set_transient( $throttle_key, 1, 5 );

		$type = sanitize_key( $_POST['type'] ?? '' );
		if ( ! in_array( $type, [ 'products', 'orders', 'customers', 'attendees' ], true ) ) {
			wp_die( 'Invalid export type.' );
		}
		if ( 'attendees' === $type && ! Wooex_Data_Attendees::is_available() ) {
			wp_die( 'Attendees export type is unavailable on this site.' );
		}

		$filters = $this->sanitize_filters( $_POST['filters'] ?? [] );

		// Same clock as the on-screen preview, so the downloaded file and the
		// table it was generated from hold the same rows.
		$preview_at = $this->preview_reference_ts(
			sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) )
		);

		$report = [
			'id'      => 'preview-' . wp_generate_password( 8, false, false ),
			'type'    => $type,
			'format'  => 'xlsx',
			'filters' => $filters,
		];

		$path = Wooex_Exporter::run( $report, $preview_at );
		if ( false === $path || ! file_exists( $path ) ) {
			wp_die( 'Preview download failed — see PHP error log for [WooExports] entries.' );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		// Files served once — delete immediately so they don't sit on disk
		// until the 24h cleanup pass. Shrinks the exposure window to ~0.
		@unlink( $path );
		exit;
	}

	public function handle_download_report(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Unauthorized', 'Forbidden', [ 'response' => 403 ] );
		}

		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		if ( ! $id ) {
			wp_die( 'Missing export ID.' );
		}

		check_admin_referer( 'wooex_download_' . $id );

		$report = Wooex_Report_Store::get( $id );
		if ( ! $report ) {
			wp_die( 'Export not found.' );
		}

		$path = Wooex_Exporter::run( $report );
		if ( false === $path || ! file_exists( $path ) ) {
			wp_die( 'Export failed — see PHP error log for [WooExports] entries.' );
		}

		$format = ( 'csv' === ( $report['format'] ?? '' ) ) ? 'csv' : 'xlsx';
		$mime   = ( 'csv' === $format )
			? 'text/csv; charset=UTF-8'
			: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		// See comment in handle_download_preview().
		@unlink( $path );
		exit;
	}

	public function ajax_toggle_active(): void {
		$this->guard();
		$id     = sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) );
		$active = ! empty( $_POST['active'] );

		$report = Wooex_Report_Store::get( $id );
		if ( ! $report ) {
			wp_send_json_error( [ 'message' => 'Export not found.' ], 404 );
		}

		$report['active'] = $active;
		Wooex_Report_Store::save( $report );

		$scheduler = new Wooex_Scheduler();
		if ( $active ) {
			$scheduler->schedule( $report );
		} else {
			$scheduler->unschedule( $id );
			$report['next_run'] = null;
			Wooex_Report_Store::save( $report );
		}

		wp_send_json_success();
	}

	// =====================================================================
	// Sanitisation helpers
	// =====================================================================

	private function guard(): void {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
		}
	}

	private function sanitize_recipients( $raw ): array {
		$raw = is_string( $raw ) ? wp_unslash( $raw ) : '';
		$out = [];
		foreach ( preg_split( '/[\s,;]+/', $raw ) as $piece ) {
			$clean = sanitize_email( $piece );
			if ( $clean && is_email( $clean ) ) {
				$out[] = $clean;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private function sanitize_filters( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];

		$date_range = sanitize_key( $raw['date_range'] ?? 'today' );
		$valid_ranges = [ 'today', 'yesterday', 'this_week', 'last_week', 'this_month', 'last_month', 'this_year', 'last_year', 'all_time', 'custom' ];
		if ( ! in_array( $date_range, $valid_ranges, true ) ) {
			$date_range = 'today';
		}

		$date_from = $this->sanitize_date_ymd( $raw['date_from'] ?? '' );
		$date_to   = $this->sanitize_date_ymd( $raw['date_to'] ?? '' );
		$day_start = $this->sanitize_time_hhmm( $raw['day_start'] ?? '00:00', '00:00' );

		$statuses = (array) ( $raw['statuses'] ?? [] );
		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );

		return [
			'date_range'      => $date_range,
			'date_from'       => $date_from,
			'date_to'         => $date_to,
			'day_start'       => $day_start,
			'statuses'        => $statuses,
			'customer_ids'    => $this->ints( $raw['customer_ids'] ?? [] ),
			'product_ids'     => $this->ints( $raw['product_ids'] ?? [] ),
			'product_cat_ids' => $this->ints( $raw['product_cat_ids'] ?? [] ),
			'product_tag_ids' => $this->ints( $raw['product_tag_ids'] ?? [] ),
			'parent_post_ids' => $this->ints( $raw['parent_post_ids'] ?? [] ),
		];
	}

	private function sanitize_schedule( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];

		$frequency = sanitize_key( $raw['frequency'] ?? 'daily' );
		if ( ! in_array( $frequency, [ 'daily', 'weekly', 'monthly' ], true ) ) {
			$frequency = 'daily';
		}

		$time = $this->sanitize_time_hhmm( $raw['time'] ?? '06:00', '06:00' );

		$valid_days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];

		$days_raw = $raw['days'] ?? [];
		if ( ! is_array( $days_raw ) && isset( $raw['day'] ) ) {
			$days_raw = [ $raw['day'] ];
		}
		$days = [];
		foreach ( (array) $days_raw as $d ) {
			$d = sanitize_key( $d );
			if ( in_array( $d, $valid_days, true ) && ! in_array( $d, $days, true ) ) {
				$days[] = $d;
			}
		}
		if ( empty( $days ) ) {
			$days = [ 'monday' ];
		}

		// Store in week order rather than the order the checkboxes happened to
		// POST in, so the list table's "Sun, Tue, Thu" summary reads the same for
		// a report saved today and one saved before the checkbox row was
		// reordered. Sunday first, matching the builder.
		$week_order = [ 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ];
		$days       = array_values( array_intersect( $week_order, $days ) );

		$dom = max( 1, min( 28, (int) ( $raw['day_of_month'] ?? 1 ) ) );

		return [
			'frequency'    => $frequency,
			'time'         => $time,
			'days'         => $days,
			'day'          => $days[0], // legacy single-day field, kept in sync with first checked day
			'day_of_month' => $dom,
		];
	}

	/**
	 * Validate an HH:MM wall-clock time, falling back to $fallback.
	 *
	 * Shared by the filter's `day_start` and the schedule's `time` against
	 * Wooex_Data_Orders::TIME_PATTERN, so the two cannot drift on what they
	 * accept. resolve_dates() re-validates with the same pattern, because saved
	 * options can also be written by code that never passed through here.
	 *
	 * The parameter is `$fallback` rather than the more obvious `$default`
	 * because `default` is a reserved keyword, which WordPress-Extra flags.
	 */
	private function sanitize_time_hhmm( $raw, string $fallback ): string {
		$value = sanitize_text_field( is_scalar( $raw ) ? (string) $raw : '' );
		return preg_match( Wooex_Data_Orders::TIME_PATTERN, $value ) ? $value : $fallback;
	}

	private function sanitize_date_ymd( $raw ): string {
		$raw = is_string( $raw ) ? trim( wp_unslash( $raw ) ) : '';
		if ( '' === $raw ) {
			return '';
		}
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d', $raw );
		return $dt && $dt->format( 'Y-m-d' ) === $raw ? $raw : '';
	}

	private function ints( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = [ $raw ];
		}
		$out = [];
		foreach ( $raw as $v ) {
			$id = (int) $v;
			if ( $id > 0 ) {
				$out[] = $id;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * For Edit modal: resolve currently-selected IDs to {id, text} pairs
	 * so the AJAX-driven selectWoo fields can render their chips immediately.
	 */
	private function preselected_labels( array $filters ): array {
		$out = [
			'customer_ids'    => [],
			'product_ids'     => [],
			'parent_post_ids' => [],
		];

		foreach ( (array) ( $filters['customer_ids'] ?? [] ) as $cid ) {
			$u = get_user_by( 'id', (int) $cid );
			if ( $u ) {
				$out['customer_ids'][] = [
					'id'   => (int) $cid,
					'text' => sprintf( '%s (%s)', $u->display_name, $u->user_email ),
				];
			}
		}

		foreach ( (array) ( $filters['product_ids'] ?? [] ) as $pid ) {
			$p = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $pid ) : null;
			if ( $p ) {
				$sku = $p->get_sku();
				$out['product_ids'][] = [
					'id'   => (int) $pid,
					'text' => $p->get_name() . ( $sku ? ' (' . $sku . ')' : '' ) . ' #' . $pid,
				];
			}
		}

		foreach ( (array) ( $filters['parent_post_ids'] ?? [] ) as $pid ) {
			$post = get_post( (int) $pid );
			if ( $post ) {
				$type_obj   = get_post_type_object( $post->post_type );
				$type_label = $type_obj ? $type_obj->labels->singular_name : $post->post_type;
				$out['parent_post_ids'][] = [
					'id'   => (int) $pid,
					'text' => sprintf( '%s — %s (#%d)', $post->post_title, $type_label, $pid ),
				];
			}
		}

		return $out;
	}

	// =====================================================================
	// AJAX — selectWoo search endpoints (ticket pages, customers)
	// =====================================================================

	public function ajax_search_parent_posts(): void {
		check_ajax_referer( 'wooex_search', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		if ( strlen( $term ) < 3 ) {
			wp_send_json( [ 'results' => [] ] );
		}

		$exclude = [
			'product',
			'product_variation',
			'attachment',
			'tribe_wooticket',
			'shop_order',
			'shop_order_refund',
			'shop_coupon',
			'shop_subscription',
			'nav_menu_item',
			'wp_block',
			'wp_template',
			'wp_template_part',
			'wp_navigation',
		];
		$public = array_keys( get_post_types( [ 'public' => true ] ) );
		$types  = array_values( array_diff( $public, $exclude ) );

		foreach ( [ 'ticket', 'tribe_event_series' ] as $extra ) {
			if ( post_type_exists( $extra ) && ! in_array( $extra, $types, true ) ) {
				$types[] = $extra;
			}
		}

		global $wpdb;
		$like      = '%' . $wpdb->esc_like( $term ) . '%';
		$type_ph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$statuses  = [ 'publish', 'private' ];
		$status_ph = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// Restrict to posts that have at least one attendee (tribe_wooticket)
		// attached. Past attendees count — the join keys off ETP's parent-post
		// meta which persists for the lifetime of the attendee record, so a
		// post whose ticket sales happened months ago still appears here as
		// long as those attendee records still exist.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_title, p.post_type
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm
				   ON pm.meta_key = '_tribe_wooticket_event'
				   AND pm.meta_value = p.ID
				 WHERE p.post_title LIKE %s
				   AND p.post_type IN ($type_ph)
				   AND p.post_status IN ($status_ph)
				 ORDER BY p.post_title ASC
				 LIMIT 50",
				array_merge( [ $like ], $types, $statuses )
			)
		);

		$results = [];
		foreach ( (array) $rows as $r ) {
			$type_obj   = get_post_type_object( $r->post_type );
			$type_label = $type_obj ? $type_obj->labels->singular_name : $r->post_type;
			$results[]  = [
				'id'   => (int) $r->ID,
				'text' => sprintf( '%s — %s (#%d)', $r->post_title, $type_label, $r->ID ),
			];
		}

		wp_send_json( [ 'results' => $results ] );
	}

	public function ajax_search_customers(): void {
		check_ajax_referer( 'wooex_search', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		if ( strlen( $term ) < 3 ) {
			wp_send_json( [ 'results' => [] ] );
		}

		$ids = [];
		if ( ctype_digit( $term ) ) {
			$user = get_user_by( 'id', (int) $term );
			if ( $user ) {
				$ids[] = (int) $user->ID;
			}
		}

		if ( empty( $ids ) && class_exists( 'WC_Data_Store' ) ) {
			$store = WC_Data_Store::load( 'customer' );
			if ( $store ) {
				$ids = (array) $store->search_customers( $term, 30 );
			}
		}

		$results = [];
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$user = get_user_by( 'id', $id );
			if ( ! $user ) {
				continue;
			}
			$results[] = [
				'id'   => $id,
				'text' => sprintf( '%s (%s)', $user->display_name, $user->user_email ),
			];
		}

		wp_send_json( [ 'results' => $results ] );
	}

	/**
	 * Product search for the Products filter.
	 *
	 * This replaces WooCommerce's `woocommerce_json_search_products`, which is
	 * the wrong tool for a catalogue with families of similarly-named products.
	 * That endpoint runs `ORDER BY post_parent ASC, post_title ASC LIMIT 30`
	 * with no notion of relevance, so a search for "Rhodes" on a site holding
	 * 30+ "Legends and Lore at Rhodes Hall" tickets returns nothing but those
	 * and never reaches "Rhodes Hall Tour". The matches exist; they are sorted
	 * off the end of the list, and the dropdown gives no hint that happened.
	 *
	 * @see \WC_Product_Data_Store_CPT::search_products()
	 *
	 * Two changes fix it. Results are bucketed by how the term matched — exact
	 * title, then title starting with the term, then anything else — so a
	 * leading match can never be crowded out by alphabetically earlier
	 * substring matches. And the cap is 100 rather than 30.
	 *
	 * There is still a cap, and a truncated list says so rather than pretending
	 * to be complete. "Rhodes" alone matches 171 products on the site this was
	 * found on.
	 *
	 * Drafts and private products are searchable too. Reports run over historic
	 * orders, and the product a past event sold through is often no longer
	 * published.
	 */
	public function ajax_search_products(): void {
		check_ajax_referer( 'wooex_search', 'security' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		if ( strlen( $term ) < 3 && ! ctype_digit( $term ) ) {
			wp_send_json( [ 'results' => [] ] );
		}

		global $wpdb;

		$esc        = $wpdb->esc_like( $term );
		$contains   = '%' . $esc . '%';
		$starts     = $esc . '%';
		$exact      = $esc;
		$statuses   = [ 'publish', 'private', 'draft' ];
		$status_ph  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$id_match   = ctype_digit( $term ) ? (int) $term : 0;
		$limit      = 100;

		// GROUP BY rather than DISTINCT: the _sku join is one row per product in
		// practice, but a duplicated meta row would otherwise double the entry.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_status, MAX(sku.meta_value) AS sku
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} sku
				   ON sku.post_id = p.ID AND sku.meta_key = '_sku'
				 WHERE p.post_type = 'product'
				   AND p.post_status IN ($status_ph)
				   AND ( p.post_title LIKE %s OR sku.meta_value LIKE %s OR p.ID = %d )
				 GROUP BY p.ID, p.post_title, p.post_status
				 ORDER BY
				   CASE
				     WHEN p.ID = %d THEN 0
				     WHEN p.post_title LIKE %s THEN 1
				     WHEN p.post_title LIKE %s THEN 2
				     ELSE 3
				   END,
				   p.post_title ASC
				 LIMIT %d",
				array_merge(
					$statuses,
					[ $contains, $contains, $id_match, $id_match, $exact, $starts, $limit ]
				)
			)
		);

		$results = [];
		foreach ( (array) $rows as $r ) {
			// Same label shape as preselected_labels(), so a product reads
			// identically whether it was just searched for or restored from a
			// saved report.
			$label = $r->post_title;
			if ( ! empty( $r->sku ) ) {
				$label .= ' (' . $r->sku . ')';
			}
			$label .= ' #' . (int) $r->ID;
			if ( 'publish' !== $r->post_status ) {
				$label .= ' — ' . $r->post_status;
			}

			$results[] = [
				'id'   => (int) $r->ID,
				'text' => $label,
			];
		}

		// A full page means there is probably more behind it. Say so — a list
		// that quietly stops at the cap reads as "that is everything".
		if ( count( $results ) >= $limit ) {
			$results[] = [
				'id'       => '',
				'text'     => sprintf( 'Showing the first %d matches — type more to narrow.', $limit ),
				'disabled' => true,
			];
		}

		wp_send_json( [ 'results' => $results ] );
	}
}

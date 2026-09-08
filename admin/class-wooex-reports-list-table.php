<?php
/**
 * Exports table — WC Analytics-style.
 *
 * Renders the list of saved exports using the same DOM shape as WooCommerce
 * Analytics' Orders table (single <table> with the header as the first <tr>,
 * `table-layout: auto`, sortable column buttons, scroll wrapper at narrow
 * viewports). Does not extend WP_List_Table — that class hard-codes a
 * fundamentally different layout we're moving away from.
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Wooex_Reports_List_Table {

	public const BULK_NONCE_ACTION = 'wooex_bulk_reports';

	/** @var array<int,array<string,mixed>> */
	private array $items = [];
	private int $total = 0;
	private int $per_page = 20;
	private int $page = 1;
	private int $total_pages = 1;
	private string $orderby = 'name';
	private string $order = 'asc';
	private string $view = 'all';
	private string $search = '';
	private string $type_filter = '';

	/**
	 * Read filtering/sort/pagination state from the request and populate $items.
	 */
	public function prepare(): void {
		$this->view        = ( isset( $_GET['wooex_status'] ) && 'trash' === $_GET['wooex_status'] ) ? 'trash' : 'all';
		$this->orderby     = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'name';
		$this->order       = ( isset( $_GET['order'] ) && 'desc' === strtolower( (string) wp_unslash( $_GET['order'] ) ) ) ? 'desc' : 'asc';
		$this->search      = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$this->type_filter = isset( $_GET['wooex_type'] ) ? sanitize_key( wp_unslash( $_GET['wooex_type'] ) ) : '';
		$this->per_page    = (int) ( get_user_meta( get_current_user_id(), 'wooex_reports_per_page', true ) ?: 20 );
		$this->page        = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );

		$all = 'trash' === $this->view ? Wooex_Report_Store::all_trashed() : Wooex_Report_Store::all_active();

		if ( $this->type_filter ) {
			$all = array_values(
				array_filter( $all, fn( $r ) => ( $r['type'] ?? '' ) === $this->type_filter )
			);
		}

		if ( '' !== $this->search ) {
			$needle = strtolower( $this->search );
			$all    = array_values(
				array_filter(
					$all,
					static fn( $r ) => false !== strpos( strtolower( (string) ( $r['name'] ?? '' ) ), $needle )
				)
			);
		}

		$orderby = $this->orderby;
		$order   = $this->order;
		usort(
			$all,
			static function ( $a, $b ) use ( $orderby, $order ) {
				$av = $a[ $orderby ] ?? '';
				$bv = $b[ $orderby ] ?? '';
				$cmp = is_numeric( $av ) && is_numeric( $bv ) ? ( $av <=> $bv ) : strcasecmp( (string) $av, (string) $bv );
				return 'desc' === $order ? -$cmp : $cmp;
			}
		);

		$this->total       = count( $all );
		$this->total_pages = max( 1, (int) ceil( $this->total / $this->per_page ) );
		$this->page        = min( $this->page, $this->total_pages );
		$this->items       = array_slice( $all, ( $this->page - 1 ) * $this->per_page, $this->per_page );
	}

	public function current_view(): string {
		return $this->view;
	}

	/**
	 * Returns the bulk action key from $_REQUEST (checks both top and bottom selects),
	 * or '' if no action is selected.
	 */
	public function current_action(): string {
		$top = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( $top && '-1' !== $top ) {
			return $top;
		}
		$bottom = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
		if ( $bottom && '-1' !== $bottom ) {
			return $bottom;
		}
		return '';
	}

	// =====================================================================
	// Rendering
	// =====================================================================

	public function render(): void {
		?>
		<div class="wooex-table-card">
			<?php
			$this->render_card_header();
			$this->render_bulk_form_open();
			$this->render_toolbar();
			$this->render_table();
			$this->render_footer();
			$this->render_bulk_form_close();
			?>
		</div>
		<?php
	}

	private function render_card_header(): void {
		?>
		<div class="wooex-table-card-header">
			<?php $this->render_views(); ?>
			<?php $this->render_search(); ?>
		</div>
		<?php
	}

	private function render_views(): void {
		$all_count   = count( Wooex_Report_Store::all_active() );
		$trash_count = count( Wooex_Report_Store::all_trashed() );
		$base        = Wooex_Admin::list_url();
		?>
		<ul class="wooex-table-views" role="tablist">
			<li>
				<a href="<?php echo esc_url( $base ); ?>"
					class="<?php echo 'all' === $this->view ? 'is-current' : ''; ?>"
					role="tab"
					aria-selected="<?php echo 'all' === $this->view ? 'true' : 'false'; ?>">
					All <span class="wooex-table-views-count">(<?php echo (int) $all_count; ?>)</span>
				</a>
			</li>
			<li>
				<a href="<?php echo esc_url( add_query_arg( 'wooex_status', 'trash', $base ) ); ?>"
					class="<?php echo 'trash' === $this->view ? 'is-current' : ''; ?>"
					role="tab"
					aria-selected="<?php echo 'trash' === $this->view ? 'true' : 'false'; ?>">
					Trash <span class="wooex-table-views-count">(<?php echo (int) $trash_count; ?>)</span>
				</a>
			</li>
		</ul>
		<?php
	}

	private function render_search(): void {
		?>
		<form class="wooex-table-search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( Wooex_Admin::MENU_SLUG ); ?>" />
			<?php if ( 'trash' === $this->view ) : ?>
				<input type="hidden" name="wooex_status" value="trash" />
			<?php endif; ?>
			<?php if ( $this->type_filter ) : ?>
				<input type="hidden" name="wooex_type" value="<?php echo esc_attr( $this->type_filter ); ?>" />
			<?php endif; ?>
			<label class="screen-reader-text" for="wooex-search-input">Search exports</label>
			<input type="search" id="wooex-search-input" name="s" value="<?php echo esc_attr( $this->search ); ?>" placeholder="Search exports&hellip;" />
			<button type="submit" class="button">Search</button>
		</form>
		<?php
	}

	private function render_bulk_form_open(): void {
		?>
		<form method="post" action="<?php echo esc_url( Wooex_Admin::list_url() ); ?>" id="wooex-bulk-form" class="wooex-table-form">
			<?php wp_nonce_field( self::BULK_NONCE_ACTION ); ?>
			<input type="hidden" name="page" value="<?php echo esc_attr( Wooex_Admin::MENU_SLUG ); ?>" />
			<?php if ( 'trash' === $this->view ) : ?>
				<input type="hidden" name="wooex_status" value="trash" />
			<?php endif; ?>
		<?php
	}

	private function render_bulk_form_close(): void {
		echo '</form>';
	}

	private function render_toolbar(): void {
		$is_trash = 'trash' === $this->view;
		$base     = Wooex_Admin::list_url();
		?>
		<div class="wooex-table-toolbar">
			<div class="wooex-table-toolbar-section">
				<label class="screen-reader-text" for="wooex-bulk-action">Bulk action</label>
				<select name="action" id="wooex-bulk-action">
					<option value="-1">Bulk actions</option>
					<?php if ( $is_trash ) : ?>
						<option value="restore">Restore</option>
						<option value="delete_permanent">Delete Permanently</option>
					<?php else : ?>
						<option value="trash">Move to Trash</option>
					<?php endif; ?>
				</select>
				<button type="submit" class="button">Apply</button>

				<label class="screen-reader-text" for="wooex-type-filter">Filter by type</label>
				<select name="wooex_type" id="wooex-type-filter">
					<option value="">All Types</option>
					<?php foreach ( Wooex_Exporter::type_options() as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $this->type_filter, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" name="filter_action" value="1" class="button">Filter</button>

				<?php if ( $is_trash && Wooex_Report_Store::all_trashed() ) : ?>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'wooex_action' => 'empty_trash' ], $base ), 'wooex_empty_trash' ) ); ?>" class="button">Empty Trash</a>
				<?php endif; ?>
			</div>

			<div class="wooex-table-toolbar-section wooex-table-toolbar-right">
				<span class="wooex-table-count"><?php echo (int) $this->total; ?> item<?php echo 1 === $this->total ? '' : 's'; ?></span>
			</div>
		</div>
		<?php
	}

	private function render_table(): void {
		?>
		<div class="wooex-table-scroller">
			<table class="wooex-table" role="table">
				<caption class="screen-reader-text">List of exports</caption>
				<tbody>
					<?php $this->render_header_row(); ?>
					<?php if ( empty( $this->items ) ) : ?>
						<tr class="wooex-table-row wooex-table-empty">
							<td class="wooex-table-cell" colspan="9">
								<?php echo 'trash' === $this->view
									? 'Trash is empty.'
									: 'No exports yet. Click <strong>Add New Export</strong> to create one.'; ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $this->items as $item ) : ?>
							<?php $this->render_body_row( $item ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_header_row(): void {
		?>
		<tr class="wooex-table-row wooex-table-row-header">
			<th scope="col" class="wooex-table-header wooex-col-cb is-center-aligned">
				<input type="checkbox" id="wooex-cb-select-all" aria-label="Select all" />
			</th>
			<?php
			$this->render_header_cell( 'name',     'Name',     true );
			$this->render_header_cell( 'type',     'Type',     true );
			$this->render_header_cell( 'format',   'Format',   false );
			$this->render_header_cell( 'schedule', 'Schedule', false );
			$this->render_header_cell( 'next_run', 'Next Run', true );
			$this->render_header_cell( 'last_run', 'Last Run', true );
			$this->render_header_cell( 'active',   'Active',   false, 'is-center-aligned' );
			?>
			<th scope="col" class="wooex-table-header wooex-col-actions is-center-aligned">
				<span class="screen-reader-text">Actions</span>
			</th>
		</tr>
		<?php
	}

	private function render_header_cell( string $key, string $label, bool $sortable, string $extra_class = '' ): void {
		$classes = 'wooex-table-header is-left-aligned';
		if ( $extra_class ) {
			$classes .= ' ' . $extra_class;
		}
		if ( ! $sortable ) {
			printf( '<th scope="col" class="%s"><span>%s</span></th>', esc_attr( $classes ), esc_html( $label ) );
			return;
		}

		$is_sorted = $this->orderby === $key;
		$dir       = $is_sorted && 'asc' === $this->order ? 'desc' : 'asc';
		$indicator = $is_sorted
			? ( 'asc' === $this->order ? ' <span class="wooex-sort-indicator" aria-hidden="true">▲</span>' : ' <span class="wooex-sort-indicator" aria-hidden="true">▼</span>' )
			: ' <span class="wooex-sort-indicator wooex-sort-indicator-idle" aria-hidden="true">⇅</span>';

		$url = add_query_arg(
			[ 'orderby' => $key, 'order' => $dir ],
			Wooex_Admin::list_url()
		);
		if ( 'trash' === $this->view ) {
			$url = add_query_arg( 'wooex_status', 'trash', $url );
		}
		if ( $this->type_filter ) {
			$url = add_query_arg( 'wooex_type', $this->type_filter, $url );
		}
		if ( $this->search ) {
			$url = add_query_arg( 's', rawurlencode( $this->search ), $url );
		}

		$classes .= ' is-sortable';
		if ( $is_sorted ) {
			$classes .= ' is-sorted is-sorted-' . $this->order;
		}

		printf(
			'<th scope="col" class="%s" aria-sort="%s"><a href="%s" class="wooex-table-sort"><span>%s</span>%s</a></th>',
			esc_attr( $classes ),
			$is_sorted ? ( 'asc' === $this->order ? 'ascending' : 'descending' ) : 'none',
			esc_url( $url ),
			esc_html( $label ),
			$indicator
		);
	}

	private function render_body_row( array $item ): void {
		$id = (string) ( $item['id'] ?? '' );
		?>
		<tr class="wooex-table-row">
			<td class="wooex-table-cell wooex-col-cb is-center-aligned">
				<input type="checkbox" name="report[]" value="<?php echo esc_attr( $id ); ?>" aria-label="Select export" />
			</td>
			<th scope="row" class="wooex-table-cell wooex-col-name is-left-aligned">
				<?php echo $this->cell_name( $item ); ?>
			</th>
			<td class="wooex-table-cell is-left-aligned"><?php echo esc_html( Wooex_Exporter::type_options()[ $item['type'] ?? '' ] ?? (string) ( $item['type'] ?? '' ) ); ?></td>
			<td class="wooex-table-cell is-left-aligned"><?php echo esc_html( strtoupper( (string) ( $item['format'] ?? '' ) ) ); ?></td>
			<td class="wooex-table-cell is-left-aligned"><?php echo $this->cell_schedule( $item ); ?></td>
			<td class="wooex-table-cell is-left-aligned"><?php echo $this->cell_next_run( $item ); ?></td>
			<td class="wooex-table-cell is-left-aligned"><?php echo $this->cell_last_run( $item ); ?></td>
			<td class="wooex-table-cell is-center-aligned"><?php echo $this->cell_active( $item ); ?></td>
			<td class="wooex-table-cell is-center-aligned wooex-col-actions"><?php echo $this->cell_actions( $item ); ?></td>
		</tr>
		<?php
	}

	// ---- Cell renderers ------------------------------------------------------

	private function cell_name( array $item ): string {
		$id   = (string) ( $item['id'] ?? '' );
		$name = esc_html( (string) ( $item['name'] ?? '' ) );

		if ( 'trash' === $this->view ) {
			return '<strong>' . $name . '</strong>';
		}

		return sprintf(
			'<a class="wooex-row-title" href="%s">%s</a>',
			esc_url( Wooex_Admin::builder_url( 'edit', $id ) ),
			$name
		);
	}

	private function cell_schedule( array $item ): string {
		if ( empty( $item['active'] ) ) {
			return '<em class="wooex-muted">Not scheduled</em>';
		}

		$s         = (array) ( $item['schedule'] ?? [] );
		$frequency = (string) ( $s['frequency'] ?? '' );
		$time_raw  = (string) ( $s['time'] ?? '' );
		$time_disp = '';
		if ( $time_raw && preg_match( '/^([0-1]?\d|2[0-3]):([0-5]\d)$/', $time_raw, $m ) ) {
			$dt        = ( new DateTimeImmutable( 'now', wp_timezone() ) )->setTime( (int) $m[1], (int) $m[2], 0 );
			$time_disp = wp_date( get_option( 'time_format' ), $dt->getTimestamp() );
		}

		$text = ucfirst( $frequency );
		if ( $time_disp ) {
			$text .= ' @ ' . $time_disp;
		}
		if ( 'weekly' === $frequency ) {
			$days = (array) ( $s['days'] ?? [] );
			if ( empty( $days ) && ! empty( $s['day'] ) ) {
				$days = [ $s['day'] ];
			}
			$labels = array_map( static fn( $d ) => ucfirst( substr( (string) $d, 0, 3 ) ), $days );
			if ( $labels ) {
				$text .= ' (' . implode( ', ', $labels ) . ')';
			}
		} elseif ( 'monthly' === $frequency ) {
			$text .= ' (day ' . (int) ( $s['day_of_month'] ?? 1 ) . ')';
		}
		return esc_html( $text );
	}

	private function cell_next_run( array $item ): string {
		if ( empty( $item['next_run'] ) ) {
			return '<span class="wooex-muted">—</span>';
		}

		$next     = (int) $item['next_run'];
		$last     = (int) ( $item['last_run'] ?? 0 );
		$active   = ! empty( $item['active'] );
		$rendered = esc_html( wp_date( self::dt_fmt(), $next ) );

		// Flag "late" runs: scheduled time has passed by >1h AND we haven't
		// recorded a run after it. Almost always means real cron isn't running
		// — see docs/DEPLOY.md for the fix.
		if ( $active && $next < time() - HOUR_IN_SECONDS && $last < $next ) {
			$rendered .= '<br><span class="wooex-status-late" title="Scheduled run missed. Check that real cron is configured on the server (see DEPLOY.md).">⚠ Late</span>';
		}

		return $rendered;
	}

	private function cell_last_run( array $item ): string {
		$out = empty( $item['last_run'] )
			? '<span class="wooex-muted">—</span>'
			: esc_html( wp_date( self::dt_fmt(), (int) $item['last_run'] ) );

		$status = (string) ( $item['last_run_status'] ?? '' );
		$msg    = esc_attr( (string) ( $item['last_run_message'] ?? '' ) );
		if ( 'success' === $status ) {
			$out .= '<br><span class="wooex-status wooex-status-success" title="' . $msg . '">✓ Success</span>';
		} elseif ( 'failed' === $status ) {
			$out .= '<br><span class="wooex-status wooex-status-failed" title="' . $msg . '">✗ Failed</span>';
		}
		return $out;
	}

	private function cell_active( array $item ): string {
		$id       = esc_attr( (string) ( $item['id'] ?? '' ) );
		$checked  = ! empty( $item['active'] ) ? 'checked' : '';
		$disabled = 'trash' === $this->view ? 'disabled' : '';
		return sprintf(
			'<label class="wooex-switch"><input type="checkbox" class="wooex-toggle-active" data-report-id="%1$s" %2$s %3$s /><span class="wooex-switch-slider"></span></label>',
			$id,
			$checked,
			$disabled
		);
	}

	private function cell_actions( array $item ): string {
		$id       = (string) ( $item['id'] ?? '' );
		$is_trash = 'trash' === $this->view;

		if ( $is_trash ) {
			$items = [
				sprintf( '<li><a role="menuitem" href="%s">Restore</a></li>', esc_url( self::row_action_url( 'restore', $id ) ) ),
				sprintf( '<li><a role="menuitem" href="%s" class="submitdelete" onclick="return confirm(\'Delete permanently?\');">Delete Permanently</a></li>', esc_url( self::row_action_url( 'delete_permanent', $id ) ) ),
			];
		} else {
			$items = [
				sprintf( '<li><a role="menuitem" href="%s">Edit</a></li>', esc_url( Wooex_Admin::builder_url( 'edit', $id ) ) ),
				sprintf( '<li><a role="menuitem" href="%s">Download</a></li>', esc_url( Wooex_Admin::download_url( $id ) ) ),
				// Offered whether or not the report is scheduled. An unscheduled
				// report is exactly the one you send by hand.
				sprintf(
					'<li><a role="menuitem" href="#" class="wooex-email-export-action" data-report-id="%s" data-report-name="%s">Email Export</a></li>',
					esc_attr( $id ),
					esc_attr( (string) ( $item['name'] ?? '' ) )
				),
			];
			if ( ! empty( $item['active'] ) ) {
				$items[] = sprintf( '<li><a role="menuitem" href="#" class="wooex-run-now-action" data-report-id="%s">Run Now</a></li>', esc_attr( $id ) );
			}
			$items[] = '<li class="wooex-rowmenu-divider" role="separator"></li>';
			$items[] = sprintf( '<li><a role="menuitem" href="%s" class="submitdelete">Trash</a></li>', esc_url( self::row_action_url( 'trash', $id ) ) );
		}

		return sprintf(
			'<div class="wooex-rowmenu">
				<button type="button" class="wooex-rowmenu-toggle" aria-haspopup="true" aria-expanded="false" aria-label="Row actions">
					<span aria-hidden="true">⋮</span>
				</button>
				<ul class="wooex-rowmenu-list" role="menu" hidden>%s</ul>
			</div>',
			implode( '', $items )
		);
	}

	// ---- Footer / pagination -------------------------------------------------

	private function render_footer(): void {
		if ( $this->total_pages <= 1 && $this->total <= 5 ) {
			return; // nothing meaningful to render
		}
		?>
		<div class="wooex-table-footer">
			<span class="wooex-table-count"><?php echo (int) $this->total; ?> item<?php echo 1 === $this->total ? '' : 's'; ?></span>
			<?php if ( $this->total_pages > 1 ) : ?>
				<?php $this->render_pagination(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_pagination(): void {
		$base = Wooex_Admin::list_url();
		if ( 'trash' === $this->view ) {
			$base = add_query_arg( 'wooex_status', 'trash', $base );
		}
		if ( $this->type_filter ) {
			$base = add_query_arg( 'wooex_type', $this->type_filter, $base );
		}
		if ( $this->search ) {
			$base = add_query_arg( 's', rawurlencode( $this->search ), $base );
		}
		$base = add_query_arg( [ 'orderby' => $this->orderby, 'order' => $this->order ], $base );

		$prev_disabled = $this->page <= 1;
		$next_disabled = $this->page >= $this->total_pages;
		$prev_url      = $prev_disabled ? '#' : add_query_arg( 'paged', $this->page - 1, $base );
		$next_url      = $next_disabled ? '#' : add_query_arg( 'paged', $this->page + 1, $base );
		?>
		<nav class="wooex-table-pagination" aria-label="Pagination">
			<a class="wooex-pagination-btn <?php echo $prev_disabled ? 'is-disabled' : ''; ?>"
				href="<?php echo esc_url( $prev_url ); ?>"
				<?php echo $prev_disabled ? 'aria-disabled="true" tabindex="-1"' : 'aria-label="Previous page"'; ?>>‹</a>
			<span class="wooex-pagination-status">
				Page <?php echo (int) $this->page; ?> of <?php echo (int) $this->total_pages; ?>
			</span>
			<a class="wooex-pagination-btn <?php echo $next_disabled ? 'is-disabled' : ''; ?>"
				href="<?php echo esc_url( $next_url ); ?>"
				<?php echo $next_disabled ? 'aria-disabled="true" tabindex="-1"' : 'aria-label="Next page"'; ?>>›</a>
		</nav>
		<?php
	}

	// ---- Helpers -------------------------------------------------------------

	private static function dt_fmt(): string {
		return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	}

	public static function row_action_url( string $action, string $id ): string {
		$base = Wooex_Admin::list_url();
		if ( isset( $_GET['wooex_status'] ) ) {
			$base = add_query_arg( 'wooex_status', sanitize_key( wp_unslash( $_GET['wooex_status'] ) ), $base );
		}
		return wp_nonce_url(
			add_query_arg(
				[ 'wooex_action' => $action, 'report_id' => rawurlencode( $id ) ],
				$base
			),
			'wooex_row_action_' . $id
		);
	}
}

<?php
/**
 * Report builder page (Add / Edit).
 *
 * Locals from Wooex_Admin::render_builder():
 *  $report (?array), $cat_terms, $tag_terms, $wc_statuses,
 *  $attendees_ready (bool), $attendees_reason (str),
 *  $preselected (array), $list_url (string).
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_edit = is_array( $report );
$r       = $is_edit ? $report : [];
$f       = (array) ( $r['filters'] ?? [] );
$s       = (array) ( $r['schedule'] ?? [] );

$page_title       = $is_edit ? 'Edit Export' : 'Add New Export';
$recipients_text  = $is_edit ? implode( "\n", (array) ( $r['recipients'] ?? [] ) ) : '';
$selected_status  = $is_edit ? (array) ( $f['statuses'] ?? [] ) : [ 'wc-completed', 'wc-processing' ];
$selected_cat_ids = array_map( 'intval', (array) ( $f['product_cat_ids'] ?? [] ) );
$selected_tag_ids = array_map( 'intval', (array) ( $f['product_tag_ids'] ?? [] ) );

$valid_days     = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];

// Render order only. Sunday leads the row because that is where a US week
// starts; the canonical list above stays ISO-ordered because that is what the
// scheduler validates against. Order has no effect on scheduling — the
// scheduler sorts its candidate timestamps before picking one.
$display_days = [ 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' ];

$selected_days  = (array) ( $s['days'] ?? [] );
if ( empty( $selected_days ) && ! empty( $s['day'] ) ) {
	$selected_days = [ $s['day'] ];
}
$selected_days = array_values( array_intersect( $valid_days, array_map( 'sanitize_key', $selected_days ) ) );
if ( empty( $selected_days ) ) {
	$selected_days = [ 'monday' ];
}

$tz_now          = new DateTimeImmutable( 'now', wp_timezone() );
$this_month_name = $tz_now->format( 'F' );
$last_month_name = $tz_now->modify( 'first day of last month' )->format( 'F' );
$this_year       = $tz_now->format( 'Y' );
$last_year       = (string) ( (int) $this_year - 1 );

$ranges = [
	'today'      => 'Today',
	'yesterday'  => 'Yesterday',
	'this_week'  => 'This Week',
	'last_week'  => 'Last Week',
	'this_month' => sprintf( 'This Month (%s)', $this_month_name ),
	'last_month' => sprintf( 'Last Month (%s)', $last_month_name ),
	'this_year'  => sprintf( 'This Year (%s)', $this_year ),
	'last_year'  => sprintf( 'Last Year (%s)', $last_year ),
	'all_time'   => 'All Time',
	'custom'     => 'Custom',
];

$cur_type      = $r['type']      ?? 'orders';
$cur_format    = $r['format']    ?? 'xlsx';
$cur_range     = $f['date_range'] ?? 'today';
$cur_day_start = $f['day_start']  ?? '00:00';

// Ranges the boundary does nothing for hide the field rather than showing a
// control that is inert. Rendered server-side too, so there is no flash of a
// field that is about to disappear on load.
$show_day_start = in_array( $cur_range, Wooex_Data_Orders::DAY_START_RANGES, true );

// The window this report resolves to, in the same words the emailed report will
// use, so the boundary can be checked here rather than discovered in tomorrow
// morning's inbox.
//
// Rendered here on load and refreshed over AJAX on every change, both through
// Wooex_Admin::range_note(), which is also what the preview uses. One function
// means the sentence and the rows behind it cannot describe different windows.
$note           = Wooex_Admin::range_note( $f, $s, $is_edit && ! empty( $r['active'] ) );
$preview_intro  = $note['intro'];
$resolved_range = $note['range'];
$cur_frequency = $s['frequency'] ?? 'daily';
$cur_time      = $s['time']      ?? '06:00';
$cur_day       = $s['day']       ?? 'monday';
$cur_dom       = (int) ( $s['day_of_month'] ?? 1 );
$cur_active    = $is_edit ? ! empty( $r['active'] ) : false; // default: NOT scheduled

$download_url = ( $is_edit && ! empty( $r['id'] ) ) ? Wooex_Admin::download_url( $r['id'] ) : '';
?>
<div class="wrap wooex-report-builder">
	<div class="wooex-builder-header">
		<h1 class="wp-heading-inline"><?php echo esc_html( $page_title ); ?></h1>
		<div class="wooex-builder-header-actions">
			<a href="<?php echo esc_url( $list_url ); ?>" class="button button-large wooex-back">&larr; Exports</a>
			<a href="<?php echo esc_url( $list_url ); ?>" class="button button-large wooex-cancel-btn">Cancel</a>
			<button type="submit" form="wooex-report-form" class="button button-primary button-large wooex-submit-btn">Save Export</button>
		</div>
	</div>

	<div class="wooex-notice-area" aria-live="polite"></div>

	<form id="wooex-report-form" autocomplete="off">
		<input type="hidden" name="id" value="<?php echo esc_attr( $r['id'] ?? '' ); ?>" />

		<!-- ====================================================== -->
		<!-- General                                                  -->
		<!-- ====================================================== -->
		<div class="wooex-card">
			<div class="wooex-card-header"><h2>General</h2></div>
			<div class="wooex-card-body">

				<div class="wooex-field">
					<label for="wooex-field-name">Export Name <span class="wooex-required">*</span></label>
					<input type="text" name="name" id="wooex-field-name" maxlength="60" required value="<?php echo esc_attr( $r['name'] ?? '' ); ?>" />
				</div>

				<div class="wooex-field-row">
					<div class="wooex-field">
						<label for="wooex-field-type">Export Type <span class="wooex-required">*</span></label>
						<select name="type" id="wooex-field-type" required>
							<?php
							foreach ( Wooex_Exporter::type_options() as $val => $label ) {
								$disabled = ( 'attendees' === $val && ! $attendees_ready ) ? 'disabled' : '';
								$sel      = selected( $cur_type, $val, false );
								$suffix   = ( 'attendees' === $val && ! $attendees_ready ) ? ' (unavailable)' : '';
								printf(
									'<option value="%s" %s %s>%s%s</option>',
									esc_attr( $val ),
									esc_attr( $sel ),
									$disabled,
									esc_html( $label ),
									esc_html( $suffix )
								);
							}
							?>
						</select>
						<?php if ( ! $attendees_ready ) : ?>
							<p class="description"><?php echo esc_html( $attendees_reason ); ?></p>
						<?php endif; ?>
					</div>
					<div class="wooex-field">
						<label for="wooex-field-format">Export Format</label>
						<select name="format" id="wooex-field-format">
							<option value="xlsx" <?php selected( $cur_format, 'xlsx' ); ?>>Excel (.xlsx)</option>
							<option value="csv"  <?php selected( $cur_format, 'csv' ); ?>>CSV (.csv)</option>
						</select>
					</div>
				</div>

			</div>
		</div>

		<!-- ====================================================== -->
		<!-- Filters                                                  -->
		<!-- ====================================================== -->
		<div class="wooex-card">
			<div class="wooex-card-header"><h2>Filters</h2></div>
			<div class="wooex-card-body">

				<div class="wooex-field" data-filter="dates">
					<div class="wooex-date-row">
						<div class="wooex-date-col">
							<label class="wooex-field-label" for="wooex-field-date-range">Date Range</label>
							<select name="date_range" id="wooex-field-date-range">
								<?php foreach ( $ranges as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $cur_range, $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="wooex-date-col wooex-day-start-col"<?php echo $show_day_start ? '' : ' style="display:none;"'; ?>>
							<label class="wooex-field-label" for="wooex-field-day-start">Day starts at</label>
							<input
								type="time"
								name="day_start"
								id="wooex-field-day-start"
								step="60"
								value="<?php echo esc_attr( $cur_day_start ); ?>"
							/>
						</div>

						<span class="wooex-custom-dates" style="display:<?php echo 'custom' === $cur_range ? 'inline-flex' : 'none'; ?>;">
							<label>From <input type="date" name="date_from" value="<?php echo esc_attr( $f['date_from'] ?? '' ); ?>" /></label>
							<label>To <input type="date" name="date_to" value="<?php echo esc_attr( $f['date_to'] ?? '' ); ?>" /></label>
						</span>
					</div>

					<p class="description wooex-day-start-help"<?php echo $show_day_start ? '' : ' style="display:none;"'; ?>>
						Leave at 12:00&nbsp;AM to export orders for a full day. Setting a time
						exports orders from that time on, so &ldquo;Yesterday&rdquo; at
						7:00&nbsp;PM means 7:00&nbsp;PM the previous day through 6:59&nbsp;PM
						today. Week, month, and year ranges always run from midnight to
						midnight. Times use the site timezone
						(<?php echo esc_html( wp_timezone_string() ); ?>).
					</p>
					<p class="wooex-range-note"<?php echo '' === $resolved_range ? ' style="display:none;"' : ''; ?>>
						<?php echo esc_html( $preview_intro ); ?>:
						<strong><?php echo esc_html( $resolved_range ); ?></strong>
					</p>
				</div>

				<div class="wooex-field" data-filter="statuses">
					<label for="wooex-field-statuses">Order Statuses</label>
					<select name="statuses[]" id="wooex-field-statuses" multiple data-placeholder="Select one or more…">
						<?php foreach ( $wc_statuses as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( in_array( $slug, $selected_status, true ), true ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="wooex-field" data-filter="customers">
					<label for="wooex-field-customer-ids">Customers</label>
					<select name="customer_ids[]" id="wooex-field-customer-ids" multiple data-placeholder="Type a name or email (3+ chars)…">
						<?php foreach ( $preselected['customer_ids'] as $opt ) : ?>
							<option value="<?php echo (int) $opt['id']; ?>" selected><?php echo esc_html( $opt['text'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="wooex-field" data-filter="products">
					<label for="wooex-field-product-ids">Products</label>
					<select name="product_ids[]" id="wooex-field-product-ids" multiple data-placeholder="Type a product name or SKU (3+ chars)…">
						<?php foreach ( $preselected['product_ids'] as $opt ) : ?>
							<option value="<?php echo (int) $opt['id']; ?>" selected><?php echo esc_html( $opt['text'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="wooex-field-row" data-filter-pair="cats-tags">
					<div class="wooex-field" data-filter="cats">
						<label for="wooex-field-product-cat-ids">Product Categories</label>
						<select name="product_cat_ids[]" id="wooex-field-product-cat-ids" multiple data-placeholder="Select one or more…">
							<?php foreach ( $cat_terms as $term ) : ?>
								<option value="<?php echo (int) $term->term_id; ?>" <?php selected( in_array( (int) $term->term_id, $selected_cat_ids, true ), true ); ?>><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="wooex-field" data-filter="tags">
						<label for="wooex-field-product-tag-ids">Product Tags</label>
						<select name="product_tag_ids[]" id="wooex-field-product-tag-ids" multiple data-placeholder="Select one or more…">
							<?php foreach ( $tag_terms as $term ) : ?>
								<option value="<?php echo (int) $term->term_id; ?>" <?php selected( in_array( (int) $term->term_id, $selected_tag_ids, true ), true ); ?>><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="wooex-field" data-filter="parents">
					<label for="wooex-field-parent-post-ids">Events &amp; Tickets</label>
					<select name="parent_post_ids[]" id="wooex-field-parent-post-ids" multiple data-placeholder="Type a post title (3+ chars)…">
						<?php foreach ( $preselected['parent_post_ids'] as $opt ) : ?>
							<option value="<?php echo (int) $opt['id']; ?>" selected><?php echo esc_html( $opt['text'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Events, pages, posts, or any CPT the tickets are attached to.</p>
				</div>

				<div class="wooex-review-area">
					<button type="button" class="button wooex-review-btn">Preview Export</button>
					<button type="button" class="button wooex-preview-download-btn" style="display:none;">Download</button>
					<button type="button" class="button wooex-email-export-btn" data-source="builder">Email Export</button>
					<div class="wooex-review-results" style="display:none;"></div>
				</div>

			</div>
		</div>

		<!-- ====================================================== -->
		<!-- Schedule                                                 -->
		<!-- ====================================================== -->
		<div class="wooex-card">
			<div class="wooex-card-header"><h2>Schedule</h2></div>
			<div class="wooex-card-body">

				<div class="wooex-field">
					<label>
						<input type="checkbox" name="active" id="wooex-field-active" <?php checked( $cur_active ); ?> />
						<strong>Schedule this report to run automatically</strong>
					</label>
				</div>

				<div class="wooex-schedule-gated">

					<div class="wooex-field-row">
						<div class="wooex-field">
							<label for="wooex-field-frequency">Frequency</label>
							<select name="frequency" id="wooex-field-frequency">
								<option value="daily"   <?php selected( $cur_frequency, 'daily' ); ?>>Daily</option>
								<option value="weekly"  <?php selected( $cur_frequency, 'weekly' ); ?>>Weekly</option>
								<option value="monthly" <?php selected( $cur_frequency, 'monthly' ); ?>>Monthly</option>
							</select>
						</div>
						<div class="wooex-field">
							<label for="wooex-field-time">Send Time</label>
							<input type="time" name="time" id="wooex-field-time" value="<?php echo esc_attr( $cur_time ); ?>" />
						</div>
						<div class="wooex-field wooex-field-monthly" style="display:<?php echo 'monthly' === $cur_frequency ? 'block' : 'none'; ?>;">
							<label for="wooex-field-dom">Day of Month</label>
							<input type="number" name="day_of_month" id="wooex-field-dom" min="1" max="28" value="<?php echo (int) $cur_dom; ?>" />
						</div>
					</div>

					<div class="wooex-field wooex-field-weekly" style="display:<?php echo 'weekly' === $cur_frequency ? 'block' : 'none'; ?>;">
						<span class="wooex-field-label">Days of Week</span>
						<div class="wooex-days-grid">
							<?php foreach ( $display_days as $d ) : ?>
								<label class="wooex-day-check">
									<input type="checkbox" name="days[]" value="<?php echo esc_attr( $d ); ?>" <?php checked( in_array( $d, $selected_days, true ) ); ?> />
									<?php echo esc_html( ucfirst( $d ) ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description">Send on one or more days each week.</p>
					</div>

					<div class="wooex-field">
						<label for="wooex-field-recipients">Recipients <span class="wooex-required">*</span></label>
						<textarea name="recipients" id="wooex-field-recipients" rows="3" placeholder="one@example.com&#10;two@example.com"><?php echo esc_textarea( $recipients_text ); ?></textarea>
						<p class="description">One email address per line. Only required when scheduled.</p>
					</div>

				</div>

			</div>
		</div>

		<div class="wooex-form-error notice notice-error inline" style="display:none;"></div>
	</form>

	<?php include WOOEX_DIR . 'admin/views/partial-email-dialog.php'; ?>
</div>

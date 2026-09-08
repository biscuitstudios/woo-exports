<?php
/**
 * Exports dashboard page (list view).
 *
 * Locals from Wooex_Admin::render_list():
 *  $table   Wooex_Reports_List_Table  (already prepared)
 *  $list_url, $new_url, $flashes
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap wooex-reports-page wooex-reports-list">
	<h1 class="wp-heading-inline">Exports</h1>
	<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action">Add New Export</a>
	<hr class="wp-header-end">

	<div class="wooex-notice-area" aria-live="polite">
		<?php foreach ( $flashes as $flash ) : ?>
			<div class="notice notice-<?php echo 'success' === $flash['type'] ? 'success' : 'error'; ?> is-dismissible">
				<p><?php echo esc_html( $flash['message'] ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>

	<?php $table->render(); ?>

	<?php include WOOEX_DIR . 'admin/views/partial-email-dialog.php'; ?>
</div>

<?php
/**
 * Email Export dialog. Shared by the list page and the builder.
 *
 * Included once per page, inside the `.wrap` so it inherits the plugin's form
 * tokens (`--wooex-input-height`), and on the builder deliberately OUTSIDE
 * `#wooex-report-form` — a nested <dialog> would put its buttons in that form's
 * submit scope.
 *
 * A native <dialog> rather than a hand-rolled overlay: showModal() gives the
 * backdrop, the focus trap, the inert background and Esc-to-close without any
 * of that being this plugin's code to get wrong.
 *
 * The JS fills the title, the summary line and the recipients field on open,
 * and reads `data-source` / `data-report-id` off the element that opened it.
 *
 * @package WooExports
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<dialog id="wooex-email-dialog" class="wooex-dialog" aria-labelledby="wooex-email-dialog-title">
	<div class="wooex-dialog-inner">
		<h2 class="wooex-dialog-title" id="wooex-email-dialog-title">Email Export</h2>

		<p class="wooex-dialog-summary"></p>

		<div class="wooex-field">
			<label for="wooex-email-dialog-recipients">Send to <span class="wooex-required">*</span></label>
			<textarea
				id="wooex-email-dialog-recipients"
				class="wooex-dialog-recipients"
				rows="3"
				placeholder="one@example.com&#10;two@example.com"
				autocomplete="off"
				spellcheck="false"></textarea>
			<p class="description">
				One email address per line. Does not change the export's saved recipients.
			</p>
		</div>

		<div class="wooex-dialog-error notice notice-error inline" style="display:none;"></div>

		<div class="wooex-dialog-actions">
			<button type="button" class="button button-large wooex-dialog-cancel">Cancel</button>
			<button type="button" class="button button-primary button-large wooex-dialog-send">Send Export</button>
		</div>
	</div>
</dialog>

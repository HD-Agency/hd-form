<?php
/**
 * Form Settings — Cleanup Section.
 *
 * Renders and sanitizes the Cleanup tab: retention periods for
 * trashed entries, mail queue, and form logs.
 *
 * @package HDForm\Admin\Settings
 */

declare(strict_types=1);

namespace HDForm\Admin\Settings;

use HDForm\FormConfig;

defined( 'ABSPATH' ) || exit;

final class CleanupSection {

	/**
	 * Render the Cleanup settings tab.
	 *
	 * @param array  $options Current saved options.
	 * @param string $optKey  Option key for form field names.
	 */
	public static function renderTab( array $options, string $optKey ): void {
		$cleanup = $options['cleanup'] ?? [];

		?>
		<div class="hd-form-tab-content" id="hd-tab-cleanup">
			<p><?php esc_html_e( 'Automatic monthly cleanup of old data. Set retention period in days.', 'hd-form' ); ?></p>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="hd-trash-days"><?php esc_html_e( 'Trashed Entries', 'hd-form' ); ?></label></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $optKey ); ?>[trash_days]" id="hd-trash-days" value="<?php echo (int) ( $cleanup['trash_days'] ?? FormConfig::CLEANUP_DEFAULTS['trash_days'] ); ?>" min="1" max="365" class="small-text">
						<span class="description"><?php esc_html_e( 'days', 'hd-form' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-mail-days"><?php esc_html_e( 'Mail Queue (sent/failed)', 'hd-form' ); ?></label></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $optKey ); ?>[mail_queue_days]" id="hd-mail-days" value="<?php echo (int) ( $cleanup['mail_queue_days'] ?? FormConfig::CLEANUP_DEFAULTS['mail_queue_days'] ); ?>" min="1" max="730" class="small-text">
						<span class="description"><?php esc_html_e( 'days', 'hd-form' ); ?></span>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-log-days"><?php esc_html_e( 'Form Logs', 'hd-form' ); ?></label></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $optKey ); ?>[log_days]" id="hd-log-days" value="<?php echo (int) ( $cleanup['log_days'] ?? FormConfig::CLEANUP_DEFAULTS['log_days'] ); ?>" min="1" max="730" class="small-text">
						<span class="description"><?php esc_html_e( 'days', 'hd-form' ); ?></span>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Sanitize Cleanup section settings.
	 *
	 * @param array $input Raw input.
	 *
	 * @return array Sanitized partial.
	 */
	public static function sanitize( array $input ): array {
		return [
			'cleanup' => [
				'trash_days'      => max( 1, absint( $input['trash_days'] ?? FormConfig::CLEANUP_DEFAULTS['trash_days'] ) ),
				'mail_queue_days' => max( 1, absint( $input['mail_queue_days'] ?? FormConfig::CLEANUP_DEFAULTS['mail_queue_days'] ) ),
				'log_days'        => max( 1, absint( $input['log_days'] ?? FormConfig::CLEANUP_DEFAULTS['log_days'] ) ),
			],
		];
	}
}

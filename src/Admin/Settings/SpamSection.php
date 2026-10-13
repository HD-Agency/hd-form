<?php
/**
 * Form Settings — Spam & Validation Section.
 *
 * Renders and sanitizes the Spam tab: global spam detection toggle,
 * minimum submit time, maximum render age, and phone validation.
 *
 * @package HDForm\Admin\Settings
 */

declare(strict_types=1);

namespace HDForm\Admin\Settings;

defined( 'ABSPATH' ) || exit;

final class SpamSection {

	/**
	 * Render the Spam & Validation settings tab.
	 *
	 * @param array  $options Current saved options.
	 * @param string $optKey  Option key for form field names.
	 */
	public static function renderTab( array $options, string $optKey ): void {
		?>
		<div class="hd-form-tab-content" id="hd-tab-spam">
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Spam Check', 'hd-form' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[spam_check]" value="1" <?php checked( $options['spam_check'] ?? true ); ?>>
							<?php esc_html_e( 'Enable global spam detection', 'hd-form' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Checks submissions against Akismet (if active) and WordPress Disallowed Words list (Settings → Discussion). Individual form types can override this in their code config.', 'hd-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-min-submit"><?php esc_html_e( 'Minimum Submit Time (seconds)', 'hd-form' ); ?></label></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $optKey ); ?>[min_submit_time]" id="hd-min-submit" value="<?php echo (int) ( $options['min_submit_time'] ?? 3 ); ?>" min="0" max="30" class="small-text">
						<p class="description"><?php esc_html_e( 'Reject submissions faster than this. Set 0 to disable.', 'hd-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-max-render-age"><?php esc_html_e( 'Maximum Render Age (seconds)', 'hd-form' ); ?></label></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $optKey ); ?>[max_render_age]" id="hd-max-render-age" value="<?php echo (int) ( $options['max_render_age'] ?? 1800 ); ?>" min="0" max="86400" class="small-text">
						<p class="description"><?php esc_html_e( 'Reject stale submissions rendered before this age. Set 0 to disable.', 'hd-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Vietnam Phone Only', 'hd-form' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[phone_vn_only]" value="1" <?php checked( ! empty( $options['phone_vn_only'] ) ); ?>>
							<?php esc_html_e( 'Only accept Vietnamese phone numbers (0xx / +84xx).', 'hd-form' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Sanitize Spam section settings.
	 *
	 * @param array $input Raw input.
	 *
	 * @return array Sanitized partial.
	 */
	public static function sanitize( array $input ): array {
		return [
			'spam_check'      => ! empty( $input['spam_check'] ),
			'min_submit_time' => absint( $input['min_submit_time'] ?? 3 ),
			'max_render_age'  => min( 86400, absint( $input['max_render_age'] ?? 1800 ) ),
			'phone_vn_only'   => ! empty( $input['phone_vn_only'] ),
		];
	}
}

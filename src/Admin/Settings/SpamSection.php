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
		$spam          = is_array( $options['spam'] ?? null ) ? $options['spam'] : [];
		$dupe          = is_array( $options['duplicate_prevention'] ?? null ) ? $options['duplicate_prevention'] : [];
		$ipBlockList   = implode( "\n", is_array( $spam['ip_block_list'] ?? null ) ? $spam['ip_block_list'] : [] );
		$wordBlacklist = implode( "\n", is_array( $spam['word_blacklist'] ?? null ) ? $spam['word_blacklist'] : [] );
		$dupeEnabled   = ! isset( $dupe['enabled'] ) || ! empty( $dupe['enabled'] );
		$dupeWindow    = max( 1, (int) ( $dupe['window_minutes'] ?? 30 ) );
		$dupeCheckMode = (string) ( $dupe['check_mode'] ?? 'email_phone' );
		$dupeActMode   = (string) ( $dupe['action_mode'] ?? 'silent_success' );
		$dupeCustomMsg = (string) ( $dupe['custom_message'] ?? '' );

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
					<th scope="row"><?php esc_html_e( 'User Interaction Guard', 'hd-form' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[spam][user_interaction_enabled]" value="1" <?php checked( $spam['user_interaction_enabled'] ?? true ); ?>>
							<?php esc_html_e( 'Require genuine user interaction (mouse, keyboard, or touch) before form submission', 'hd-form' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Akismet Integration', 'hd-form' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[spam][akismet_enabled]" value="1" <?php checked( ! empty( $spam['akismet_enabled'] ) ); ?>>
							<?php esc_html_e( 'Verify submissions with Akismet API when the Akismet plugin is configured', 'hd-form' ); ?>
						</label>
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

			<h2><?php esc_html_e( 'IP Blocklist & Keyword Blacklist', 'hd-form' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable IP Blocklist', 'hd-form' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[spam][ip_block_enabled]" value="1" <?php checked( ! empty( $spam['ip_block_enabled'] ) ); ?>>
							<?php esc_html_e( 'Silently drop submissions from blocked IP addresses or CIDR ranges', 'hd-form' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-ip-block-list"><?php esc_html_e( 'Blocked IPs / CIDR Ranges', 'hd-form' ); ?></label></th>
					<td>
						<textarea name="<?php echo esc_attr( $optKey ); ?>[spam][ip_block_list]" id="hd-ip-block-list" rows="4" cols="50" class="large-text code" placeholder="203.0.113.5&#10;198.51.100.*&#10;192.0.2.0/24"><?php echo esc_textarea( $ipBlockList ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One IPv4/IPv6 address, wildcard pattern (*), or CIDR range per line.', 'hd-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-word-blacklist"><?php esc_html_e( 'Keyword Blacklist', 'hd-form' ); ?></label></th>
					<td>
						<textarea name="<?php echo esc_attr( $optKey ); ?>[spam][word_blacklist]" id="hd-word-blacklist" rows="4" cols="50" class="large-text code" placeholder="crypto&#10;*casino*&#10;viagra"><?php echo esc_textarea( $wordBlacklist ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Silently drop submissions containing any of these words or wildcard (*) patterns. One per line.', 'hd-form' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Duplicate Submission Prevention', 'hd-form' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Duplicate Guard', 'hd-form' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[duplicate_prevention][enabled]" value="1" <?php checked( $dupeEnabled ); ?>>
							<?php esc_html_e( 'Prevent duplicate submissions from the same sender within the configured time window', 'hd-form' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-dupe-window"><?php esc_html_e( 'Time Window (minutes)', 'hd-form' ); ?></label></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $optKey ); ?>[duplicate_prevention][window_minutes]" id="hd-dupe-window" value="<?php echo (int) $dupeWindow; ?>" min="1" max="1440" class="small-text">
						<p class="description"><?php esc_html_e( 'Duration in minutes (1–1440) during which identical submissions are treated as duplicates.', 'hd-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-dupe-check-mode"><?php esc_html_e( 'Fingerprint Check Mode', 'hd-form' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( $optKey ); ?>[duplicate_prevention][check_mode]" id="hd-dupe-check-mode">
							<option value="email_phone" <?php selected( $dupeCheckMode, 'email_phone' ); ?>><?php esc_html_e( 'Email + Phone (Default)', 'hd-form' ); ?></option>
							<option value="email_only" <?php selected( $dupeCheckMode, 'email_only' ); ?>><?php esc_html_e( 'Email Only', 'hd-form' ); ?></option>
							<option value="phone_only" <?php selected( $dupeCheckMode, 'phone_only' ); ?>><?php esc_html_e( 'Phone Only', 'hd-form' ); ?></option>
							<option value="all_fields" <?php selected( $dupeCheckMode, 'all_fields' ); ?>><?php esc_html_e( 'All Submitted Fields', 'hd-form' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-dupe-action-mode"><?php esc_html_e( 'On Duplicate Action', 'hd-form' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( $optKey ); ?>[duplicate_prevention][action_mode]" id="hd-dupe-action-mode">
							<option value="silent_success" <?php selected( $dupeActMode, 'silent_success' ); ?>><?php esc_html_e( 'Silent Success (HTTP 200 without saving)', 'hd-form' ); ?></option>
							<option value="error_message" <?php selected( $dupeActMode, 'error_message' ); ?>><?php esc_html_e( 'Show Error Message (HTTP 429)', 'hd-form' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="hd-dupe-custom-msg"><?php esc_html_e( 'Custom Duplicate Error Message', 'hd-form' ); ?></label></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $optKey ); ?>[duplicate_prevention][custom_message]" id="hd-dupe-custom-msg" value="<?php echo esc_attr( $dupeCustomMsg ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'A submission with this content was already received recently.', 'hd-form' ); ?>">
						<p class="description"><?php esc_html_e( 'Used when On Duplicate Action is set to Show Error Message.', 'hd-form' ); ?></p>
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
		$rawSpam = is_array( $input['spam'] ?? null ) ? $input['spam'] : [];
		$rawDupe = is_array( $input['duplicate_prevention'] ?? null ) ? $input['duplicate_prevention'] : [];

		$checkMode = sanitize_key( (string) ( $input['duplicate_check_mode'] ?? ( $rawDupe['check_mode'] ?? 'email_phone' ) ) );
		$checkMode = in_array( $checkMode, [ 'email_phone', 'email_only', 'phone_only', 'all_fields' ], true ) ? $checkMode : 'email_phone';

		$actionMode = sanitize_key( (string) ( $input['duplicate_action_mode'] ?? ( $rawDupe['action_mode'] ?? 'silent_success' ) ) );
		$actionMode = in_array( $actionMode, [ 'silent_success', 'error_message' ], true ) ? $actionMode : 'silent_success';

		$rawWindow     = $input['duplicate_window_minutes'] ?? ( $rawDupe['window_minutes'] ?? 30 );
		$windowMinutes = min( 1440, max( 1, (int) $rawWindow ) );

		$customMsg = sanitize_textarea_field( (string) ( $input['duplicate_custom_message'] ?? ( $rawDupe['custom_message'] ?? '' ) ) );

		return [
			'spam_check'           => ! empty( $input['spam_check'] ),
			'min_submit_time'      => absint( $input['min_submit_time'] ?? 3 ),
			'max_render_age'       => min( 86400, absint( $input['max_render_age'] ?? 1800 ) ),
			'phone_vn_only'        => ! empty( $input['phone_vn_only'] ),
			'spam'                 => [
				'user_interaction_enabled' => ! empty( $input['user_interaction_enabled'] ?? ( $rawSpam['user_interaction_enabled'] ?? false ) ),
				'akismet_enabled'          => ! empty( $input['akismet_enabled'] ?? ( $rawSpam['akismet_enabled'] ?? false ) ),
				'ip_block_enabled'         => ! empty( $input['ip_block_enabled'] ?? ( $rawSpam['ip_block_enabled'] ?? false ) ),
				'ip_block_list'            => GeneralSection::parseLines( $input['ip_block_list'] ?? ( $rawSpam['ip_block_list'] ?? '' ) ),
				'word_blacklist'           => GeneralSection::parseLines( $input['word_blacklist'] ?? ( $rawSpam['word_blacklist'] ?? '' ) ),
			],
			'duplicate_prevention' => [
				'enabled'        => ! empty( $input['duplicate_enabled'] ?? ( $rawDupe['enabled'] ?? false ) ),
				'window_minutes' => $windowMinutes,
				'check_mode'     => $checkMode,
				'action_mode'    => $actionMode,
				'custom_message' => $customMsg,
			],
		];
	}
}

<?php
/**
 * Form Settings — Notifications Section.
 *
 * Renders and sanitizes the Notifications tab: channel toggles,
 * credentials for Telegram/Viber/Zalo/Webhook/Google Sheets, and weekly digest settings.
 *
 * @package HDForm\Admin\Settings
 */

declare(strict_types=1);

namespace HDForm\Admin\Settings;

defined( 'ABSPATH' ) || exit;

final class NotificationsSection {

	/**
	 * Render the Notifications settings tab.
	 *
	 * @param array  $options Current saved options.
	 * @param string $optKey  Option key for form field names.
	 */
	public static function renderTab( array $options, string $optKey ): void {
		$channels = $options['notifications']['channels'] ?? [];
		$digest   = $options['weekly_digest'] ?? [];

		$emailEnabled        = $channels['email']['enabled'] ?? true;
		$telegramEnabled     = ! empty( $channels['telegram']['enabled'] );
		$viberEnabled        = ! empty( $channels['viber']['enabled'] );
		$zaloEnabled         = ! empty( $channels['zalo']['enabled'] );
		$webhookEnabled      = ! empty( $channels['webhook']['enabled'] );
		$googleSheetsEnabled = ! empty( $channels['google_sheets']['enabled'] );

		?>
		<div class="hd-form-tab-content" id="hd-tab-notifications">
			<p><?php esc_html_e( 'Enable channels and configure credentials. Email is always available.', 'hd-form' ); ?></p>

			<!-- Email -->
			<div class="hd-channel-block">
				<h3>
					<input type="hidden" name="<?php echo esc_attr( $optKey ); ?>[notify_email]" value="0">
					<label><input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[notify_email]" value="1" <?php checked( $emailEnabled ); ?>> <?php esc_html_e( 'Email', 'hd-form' ); ?></label>
				</h3>
				<p class="description"><?php esc_html_e( 'Uses wp_mail(). Recipients are configured per form type or via Default Email Recipients in General tab.', 'hd-form' ); ?></p>
			</div>

			<!-- Webhook -->
			<div class="hd-channel-block">
				<h3>
					<label><input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[notify_webhook]" value="1" <?php checked( $webhookEnabled ); ?>> <?php esc_html_e( 'Webhook', 'hd-form' ); ?></label>
				</h3>
				<div class="hd-channel-fields">
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Endpoint URL', 'hd-form' ); ?></th>
							<td>
								<input type="url" name="<?php echo esc_attr( $optKey ); ?>[webhook_url]" value="<?php echo esc_url( $channels['webhook']['url'] ?? '' ); ?>" class="large-text code" placeholder="https://hook.eu1.make.com/...">
								<p class="description"><?php esc_html_e( 'Enter your Zapier, Make, Pabbly or custom CRM Webhook URL.', 'hd-form' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'HTTP Method', 'hd-form' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( $optKey ); ?>[webhook_method]">
									<option value="POST" <?php selected( $channels['webhook']['method'] ?? 'POST', 'POST' ); ?>>POST</option>
									<option value="PUT" <?php selected( $channels['webhook']['method'] ?? '', 'PUT' ); ?>>PUT</option>
									<option value="PATCH" <?php selected( $channels['webhook']['method'] ?? '', 'PATCH' ); ?>>PATCH</option>
									<option value="GET" <?php selected( $channels['webhook']['method'] ?? '', 'GET' ); ?>>GET</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Payload Format', 'hd-form' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( $optKey ); ?>[webhook_format]">
									<option value="json" <?php selected( $channels['webhook']['format'] ?? 'json', 'json' ); ?>>JSON</option>
									<option value="formdata" <?php selected( $channels['webhook']['format'] ?? '', 'formdata' ); ?>>Form Data (URL-Encoded)</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Secret Key (HMAC)', 'hd-form' ); ?></th>
							<td>
								<input type="password" name="<?php echo esc_attr( $optKey ); ?>[webhook_secret_key]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave blank to keep existing.', 'hd-form' ); ?>">
								<p class="description"><?php esc_html_e( 'Optional secret key for X-HD-Signature SHA256 header validation.', 'hd-form' ); ?></p>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<!-- Google Sheets -->
			<div class="hd-channel-block">
				<h3>
					<label><input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[notify_google_sheets]" value="1" <?php checked( $googleSheetsEnabled ); ?>> <?php esc_html_e( 'Google Sheets', 'hd-form' ); ?></label>
				</h3>
				<div class="hd-channel-fields">
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Spreadsheet ID', 'hd-form' ); ?></th>
							<td>
								<input type="text" name="<?php echo esc_attr( $optKey ); ?>[google_sheets_sheet_id]" value="<?php echo esc_attr( $channels['google_sheets']['sheet_id'] ?? '' ); ?>" class="large-text code" placeholder="1BxiMVs0XRX5nZy1WOmwud_1yP6f0pZ7...">
								<p class="description"><?php esc_html_e( 'The ID found in your Google Sheet URL (docs.google.com/spreadsheets/d/{ID}/edit).', 'hd-form' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Tab Name', 'hd-form' ); ?></th>
							<td>
								<input type="text" name="<?php echo esc_attr( $optKey ); ?>[google_sheets_tab_name]" value="<?php echo esc_attr( $channels['google_sheets']['tab_name'] ?? 'Sheet1' ); ?>" class="regular-text">
								<p class="description"><?php esc_html_e( 'Sheet tab name (default: Sheet1 or Trang tính1).', 'hd-form' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Service Account JSON', 'hd-form' ); ?></th>
							<td>
								<?php if ( ! empty( $channels['google_sheets']['credentials'] ) ) : ?>
									<p class="description" style="color:#00a32a;">&#10003; <?php esc_html_e( 'A service account key is saved. Leave blank to keep it; paste a new key to replace it.', 'hd-form' ); ?></p>
								<?php endif; ?>
								<?php /* The stored private key is never echoed back into the page. */ ?>
								<textarea name="<?php echo esc_attr( $optKey ); ?>[google_sheets_credentials]" rows="4" cols="50" class="large-text code" autocomplete="off" placeholder="<?php esc_attr_e( 'Paste Service Account JSON key here...', 'hd-form' ); ?>"></textarea>
								<p class="description"><?php esc_html_e( 'Google Cloud Console Service Account JSON key. Share your sheet with the client_email as Editor.', 'hd-form' ); ?></p>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<!-- Telegram -->
			<div class="hd-channel-block">
				<h3>
					<label><input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[notify_telegram]" value="1" <?php checked( $telegramEnabled ); ?>> <?php esc_html_e( 'Telegram', 'hd-form' ); ?></label>
				</h3>
				<div class="hd-channel-fields">
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Bot Token', 'hd-form' ); ?></th>
							<td>
								<input type="password" name="<?php echo esc_attr( $optKey ); ?>[telegram_bot_token]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave blank to keep existing.', 'hd-form' ); ?>">
								<p class="description"><?php esc_html_e( 'Leave blank to keep the saved bot token.', 'hd-form' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Chat ID', 'hd-form' ); ?></th>
							<td><input type="text" name="<?php echo esc_attr( $optKey ); ?>[telegram_chat_id]" value="<?php echo esc_attr( $channels['telegram']['chat_id'] ?? '' ); ?>" class="regular-text code"></td>
						</tr>
					</table>
				</div>
			</div>

			<!-- Viber -->
			<div class="hd-channel-block">
				<h3>
					<label><input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[notify_viber]" value="1" <?php checked( $viberEnabled ); ?>> <?php esc_html_e( 'Viber', 'hd-form' ); ?></label>
				</h3>
				<div class="hd-channel-fields">
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Auth Token', 'hd-form' ); ?></th>
							<td>
								<input type="password" name="<?php echo esc_attr( $optKey ); ?>[viber_auth_token]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave blank to keep existing.', 'hd-form' ); ?>">
								<p class="description"><?php esc_html_e( 'Leave blank to keep the saved auth token.', 'hd-form' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Receiver', 'hd-form' ); ?></th>
							<td><input type="text" name="<?php echo esc_attr( $optKey ); ?>[viber_receiver]" value="<?php echo esc_attr( $channels['viber']['receiver'] ?? '' ); ?>" class="regular-text code"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Sender Name', 'hd-form' ); ?></th>
							<td><input type="text" name="<?php echo esc_attr( $optKey ); ?>[viber_sender_name]" value="<?php echo esc_attr( $channels['viber']['sender']['name'] ?? 'HD Notify' ); ?>" class="regular-text"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Sender Avatar URL', 'hd-form' ); ?></th>
							<td><input type="url" name="<?php echo esc_attr( $optKey ); ?>[viber_sender_avatar]" value="<?php echo esc_url( $channels['viber']['sender']['avatar'] ?? '' ); ?>" class="regular-text code"></td>
						</tr>
					</table>
				</div>
			</div>

			<!-- Zalo -->
			<div class="hd-channel-block">
				<h3>
					<label><input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[notify_zalo]" value="1" <?php checked( $zaloEnabled ); ?>> <?php esc_html_e( 'Zalo', 'hd-form' ); ?></label>
				</h3>
				<div class="hd-channel-fields">
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Bot Token', 'hd-form' ); ?></th>
							<td>
								<input type="password" name="<?php echo esc_attr( $optKey ); ?>[zalo_bot_token]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave blank to keep existing.', 'hd-form' ); ?>">
								<p class="description"><?php esc_html_e( 'Leave blank to keep the saved bot token.', 'hd-form' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Chat ID', 'hd-form' ); ?></th>
							<td><input type="text" name="<?php echo esc_attr( $optKey ); ?>[zalo_chat_id]" value="<?php echo esc_attr( $channels['zalo']['chat_id'] ?? '' ); ?>" class="regular-text code"></td>
						</tr>
					</table>
				</div>
			</div>

			<!-- Weekly Digest -->
			<div class="hd-channel-block">
				<h3>
					<label><input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[digest_enabled]" value="1" <?php checked( ! empty( $digest['enabled'] ) ); ?>> <?php esc_html_e( 'Weekly Email Summary', 'hd-form' ); ?></label>
				</h3>
				<div class="hd-channel-fields">
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Recipients', 'hd-form' ); ?></th>
							<td>
								<textarea name="<?php echo esc_attr( $optKey ); ?>[digest_recipients]" rows="2" cols="50" class="large-text code" placeholder="admin@example.com"><?php echo esc_textarea( implode( "\n", $digest['recipients'] ?? [] ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One email per line. Defaults to admin email if empty.', 'hd-form' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Send Day', 'hd-form' ); ?></th>
							<td>
								<select name="<?php echo esc_attr( $optKey ); ?>[digest_day]">
									<?php

									$days = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];
									foreach ( $days as $d ) :
										?>
										<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $digest['day'] ?? 'monday', $d ); ?>><?php echo esc_html( ucfirst( $d ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<span class="description"><?php esc_html_e( 'at 8:00 AM', 'hd-form' ); ?></span>
							</td>
						</tr>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize Notifications section settings.
	 *
	 * @param array    $input          Raw input.
	 * @param array    $existing       Existing saved settings.
	 * @param callable $sanitizeSecret Secret sanitizer callback.
	 *
	 * @return array Sanitized partial.
	 */
	public static function sanitize( array $input, array $existing, callable $sanitizeSecret ): array {
		$existingEmailEnabled = (bool) ( $existing['notifications']['channels']['email']['enabled'] ?? true );

		$clean = [];

		// Handle Google Sheets credentials JSON.
		$rawCredsInput    = trim( (string) ( $input['google_sheets_credentials'] ?? '' ) );
		$existingCreds    = $existing['notifications']['channels']['google_sheets']['credentials'] ?? [];
		$cleanGSheetCreds = $existingCreds;

		if ( '' !== $rawCredsInput ) {
			$decoded = json_decode( wp_unslash( $rawCredsInput ), true );
			if ( is_array( $decoded ) && ! empty( $decoded ) ) {
				$cleanGSheetCreds = $decoded;
			}
		}

		$clean['notifications'] = [
			'channels' => [
				'email'         => [
					'enabled' => array_key_exists( 'notify_email', $input ) ? ! empty( $input['notify_email'] ) : $existingEmailEnabled,
				],
				'webhook'       => [
					'enabled'    => ! empty( $input['notify_webhook'] ),
					'url'        => esc_url_raw( trim( (string) ( $input['webhook_url'] ?? '' ) ) ),
					'method'     => in_array( strtoupper( (string) ( $input['webhook_method'] ?? 'POST' ) ), [ 'POST', 'PUT', 'PATCH', 'GET' ], true ) ? strtoupper( (string) $input['webhook_method'] ) : 'POST',
					'format'     => in_array( strtolower( (string) ( $input['webhook_format'] ?? 'json' ) ), [ 'json', 'formdata' ], true ) ? strtolower( (string) $input['webhook_format'] ) : 'json',
					'secret_key' => $sanitizeSecret( $input, 'webhook_secret_key', $existing['notifications']['channels']['webhook']['secret_key'] ?? '' ),
				],
				'google_sheets' => [
					'enabled'     => ! empty( $input['notify_google_sheets'] ),
					'sheet_id'    => sanitize_text_field( trim( (string) ( $input['google_sheets_sheet_id'] ?? '' ) ) ),
					'tab_name'    => sanitize_text_field( trim( (string) ( $input['google_sheets_tab_name'] ?? 'Sheet1' ) ) ) ?: 'Sheet1',
					'credentials' => $cleanGSheetCreds,
				],
				'telegram'      => [
					'enabled'   => ! empty( $input['notify_telegram'] ),
					'bot_token' => $sanitizeSecret( $input, 'telegram_bot_token', $existing['notifications']['channels']['telegram']['bot_token'] ?? '' ),
					'chat_id'   => sanitize_text_field( $input['telegram_chat_id'] ?? '' ),
				],
				'viber'         => [
					'enabled'    => ! empty( $input['notify_viber'] ),
					'auth_token' => $sanitizeSecret( $input, 'viber_auth_token', $existing['notifications']['channels']['viber']['auth_token'] ?? '' ),
					'receiver'   => sanitize_text_field( $input['viber_receiver'] ?? '' ),
					'sender'     => [
						'name'   => sanitize_text_field( $input['viber_sender_name'] ?? 'HD Notify' ),
						'avatar' => sanitize_url( $input['viber_sender_avatar'] ?? '' ),
					],
				],
				'zalo'          => [
					'enabled'   => ! empty( $input['notify_zalo'] ),
					'bot_token' => $sanitizeSecret( $input, 'zalo_bot_token', $existing['notifications']['channels']['zalo']['bot_token'] ?? '' ),
					'chat_id'   => sanitize_text_field( $input['zalo_chat_id'] ?? '' ),
				],
			],
		];

		// Weekly Digest.
		$digestRecipients       = GeneralSection::parseLines( $input['digest_recipients'] ?? '' );
		$clean['weekly_digest'] = [
			'enabled'    => ! empty( $input['digest_enabled'] ),
			'recipients' => array_filter( $digestRecipients, 'is_email' ),
			'day'        => self::sanitizeDigestDay( $input['digest_day'] ?? 'monday' ),
		];

		return $clean;
	}

	/**
	 * Sanitize digest day to a valid weekday name.
	 */
	private static function sanitizeDigestDay( mixed $day ): string {
		$day     = sanitize_key( (string) $day );
		$allowed = [ 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ];

		return in_array( $day, $allowed, true ) ? $day : 'monday';
	}
}

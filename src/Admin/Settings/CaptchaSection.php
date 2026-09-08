<?php
/**
 * Form Settings — CAPTCHA Section.
 *
 * Renders and sanitizes the CAPTCHA tab: provider selection,
 * keys for reCAPTCHA v2/v3 and Turnstile, and admin notices.
 *
 * @package HDForm\Admin\Settings
 */

declare(strict_types=1);

namespace HDForm\Admin\Settings;

defined( 'ABSPATH' ) || exit;

final class CaptchaSection {

	/**
	 * Render the CAPTCHA settings tab.
	 *
	 * @param array  $options Current saved options.
	 * @param string $optKey  Option key for form field names.
	 */
	public static function renderTab( array $options, string $optKey ): void {
		$captcha  = $options['captcha'] ?? [];
		$provider = $captcha['default_provider'] ?? 'none';

		$providers = [
			'none'         => __( 'None (disabled)', 'hd-form' ),
			'recaptcha_v2' => __( 'Google reCAPTCHA v2', 'hd-form' ),
			'recaptcha_v3' => __( 'Google reCAPTCHA v3', 'hd-form' ),
			'turnstile'    => __( 'Cloudflare Turnstile', 'hd-form' ),
		];

		?>
		<div class="hd-form-tab-content" id="hd-tab-captcha">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="hd-captcha-provider"><?php esc_html_e( 'Default Provider', 'hd-form' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( $optKey ); ?>[captcha_default_provider]" id="hd-captcha-provider">
							<?php foreach ( $providers as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $provider, $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Global default. Individual form types can override this in code config.', 'hd-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Network Error Policy', 'hd-form' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $optKey ); ?>[captcha_fail_open_on_network_error]" value="1" <?php checked( ! empty( $captcha['fail_open_on_network_error'] ) ); ?>>
							<?php esc_html_e( 'Allow submissions when CAPTCHA provider verification has a network error.', 'hd-form' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Default is fail-closed. Enable only when provider outages are a larger business risk than spam.', 'hd-form' ); ?></p>
					</td>
				</tr>
			</table>

			<?php

			self::renderProviderCard( 'reCAPTCHA v2', 'recaptcha_v2', $captcha, $optKey );
			self::renderProviderCard( 'reCAPTCHA v3', 'recaptcha_v3', $captcha, $optKey, true );
			self::renderProviderCard( 'Cloudflare Turnstile', 'turnstile', $captcha, $optKey );
			?>
		</div>
		<?php
	}

	/**
	 * Render a CAPTCHA provider card.
	 */
	private static function renderProviderCard( string $title, string $slug, array $captcha, string $optKey, bool $hasThreshold = false ): void {
		$siteKey = $captcha[ $slug ]['site_key'] ?? '';

		?>
		<div class="hd-captcha-provider">
			<h3><?php echo esc_html( $title ); ?></h3>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Site Key', 'hd-form' ); ?></th>
					<td><input type="text" name="<?php echo esc_attr( $optKey ); ?>[<?php echo esc_attr( $slug ); ?>_site_key]" value="<?php echo esc_attr( $siteKey ); ?>" class="regular-text code"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Secret Key', 'hd-form' ); ?></th>
					<td>
						<input type="password" name="<?php echo esc_attr( $optKey ); ?>[<?php echo esc_attr( $slug ); ?>_secret_key]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave blank to keep existing.', 'hd-form' ); ?>">
						<p class="description"><?php esc_html_e( 'Leave blank to keep the saved secret key.', 'hd-form' ); ?></p>
					</td>
				</tr>
				<?php if ( $hasThreshold ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Score Threshold', 'hd-form' ); ?></th>
					<td>
						<input type="number" name="<?php echo esc_attr( $optKey ); ?>[recaptcha_v3_score_threshold]" value="<?php echo esc_attr( $captcha['recaptcha_v3']['score_threshold'] ?? 0.5 ); ?>" min="0" max="1" step="0.1" class="small-text">
						<span class="description"><?php esc_html_e( '0.0 (allow all) → 1.0 (strictest)', 'hd-form' ); ?></span>
					</td>
				</tr>
				<?php endif; ?>
			</table>
		</div>
		<?php
	}

	/**
	 * Render CAPTCHA admin notices (key warnings).
	 *
	 * @param array $options Current saved options.
	 */
	public static function renderAdminNotices( array $options ): void {
		foreach ( self::providerKeyWarnings( $options ) as $warning ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $warning ) . '</p></div>';
		}
	}

	/**
	 * Check for missing CAPTCHA provider keys.
	 *
	 * @return array<int, string>
	 */
	private static function providerKeyWarnings( array $options ): array {
		$captcha  = $options['captcha'] ?? [];
		$provider = $captcha['default_provider'] ?? 'none';
		if ( 'none' === $provider || '' === $provider ) {
			return [];
		}

		$providerConfig = $captcha[ $provider ] ?? [];
		if ( ! empty( $providerConfig['site_key'] ) && ! empty( $providerConfig['secret_key'] ) ) {
			return [];
		}

		return [
			sprintf( 'CAPTCHA provider "%s" is configured but missing site key or secret key.', $provider ),
		];
	}

	/**
	 * Sanitize CAPTCHA section settings.
	 *
	 * @param array    $input    Raw input.
	 * @param array    $existing Existing saved settings.
	 * @param callable $sanitizeSecret Secret sanitizer callback.
	 *
	 * @return array Sanitized partial.
	 */
	public static function sanitize( array $input, array $existing, callable $sanitizeSecret ): array {
		return [
			'captcha' => [
				'default_provider'           => sanitize_key( $input['captcha_default_provider'] ?? 'none' ),
				'fail_open_on_network_error' => ! empty( $input['captcha_fail_open_on_network_error'] ),
				'recaptcha_v2'               => [
					'site_key'   => sanitize_text_field( $input['recaptcha_v2_site_key'] ?? '' ),
					'secret_key' => $sanitizeSecret( $input, 'recaptcha_v2_secret_key', $existing['captcha']['recaptcha_v2']['secret_key'] ?? '' ),
				],
				'recaptcha_v3'               => [
					'site_key'        => sanitize_text_field( $input['recaptcha_v3_site_key'] ?? '' ),
					'secret_key'      => $sanitizeSecret( $input, 'recaptcha_v3_secret_key', $existing['captcha']['recaptcha_v3']['secret_key'] ?? '' ),
					'score_threshold' => min( 1.0, max( 0.0, (float) ( $input['recaptcha_v3_score_threshold'] ?? 0.5 ) ) ),
				],
				'turnstile'                  => [
					'site_key'   => sanitize_text_field( $input['turnstile_site_key'] ?? '' ),
					'secret_key' => $sanitizeSecret( $input, 'turnstile_secret_key', $existing['captcha']['turnstile']['secret_key'] ?? '' ),
				],
			],
		];
	}
}

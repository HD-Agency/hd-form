<?php
/**
 * Form Settings — Success Section.
 *
 * Renders and sanitizes the Success tab: per-form-type action overrides
 * (message vs. redirect), redirect URL, and delay.
 *
 * @package HDForm\Admin\Settings
 */

declare(strict_types=1);

namespace HDForm\Admin\Settings;

use HDForm\FormConfig;

defined( 'ABSPATH' ) || exit;

final class SuccessSection {

	/**
	 * Render the Success settings tab.
	 *
	 * @param array  $options Current saved options.
	 * @param string $optKey  Option key for form field names.
	 */
	public static function renderTab( array $options, string $optKey ): void {
		$config    = FormConfig::all();
		$formTypes = $config['form_types'] ?? [];
		$overrides = isset( $options['on_success'] ) && is_array( $options['on_success'] )
			? ( $options['on_success']['form_types'] ?? [] )
			: [];
		$overrides = is_array( $overrides ) ? $overrides : [];

		$actions = [
			''         => __( 'Use code default', 'hd-form' ),
			'message'  => __( 'Show success message', 'hd-form' ),
			'redirect' => __( 'Redirect to URL', 'hd-form' ),
		];

		?>
		<div class="hd-form-tab-content" id="hd-tab-success">
			<p><?php esc_html_e( 'Choose what happens after a valid submission. Leave fields blank to use the form type defaults from code.', 'hd-form' ); ?></p>

			<?php

			foreach ( $formTypes as $type => $definition ) :
				$type      = sanitize_key( (string) $type );
				$label     = is_array( $definition ) ? ( $definition['label'] ?? $type ) : $type;
				$override  = $overrides[ $type ] ?? [];
				$override  = is_array( $override ) ? $override : [];
				$action    = $override['action'] ?? '';
				$url       = $override['redirect_url'] ?? '';
				$delay     = array_key_exists( 'redirect_delay', $override ) ? (string) $override['redirect_delay'] : '';
				$fieldName = $optKey . '[on_success][form_types][' . $type . ']';

				?>
				<div class="hd-channel-block">
					<h3><?php echo esc_html( $label ); ?></h3>
					<table class="form-table">
						<tr>
							<th scope="row"><label for="hd-success-action-<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Action', 'hd-form' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( $fieldName ); ?>[action]" id="hd-success-action-<?php echo esc_attr( $type ); ?>">
									<?php foreach ( $actions as $value => $text ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $action, $value ); ?>><?php echo esc_html( $text ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hd-success-url-<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Redirect URL', 'hd-form' ); ?></label></th>
							<td>
								<input type="text" name="<?php echo esc_attr( $fieldName ); ?>[redirect_url]" id="hd-success-url-<?php echo esc_attr( $type ); ?>" value="<?php echo esc_url( (string) $url ); ?>" class="regular-text code" placeholder="<?php echo esc_attr( home_url( '/thank-you/' ) ); ?>">
								<p class="description"><?php esc_html_e( 'Only same-site URLs are accepted.', 'hd-form' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="hd-success-delay-<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Redirect Delay', 'hd-form' ); ?></label></th>
							<td>
								<input type="number" name="<?php echo esc_attr( $fieldName ); ?>[redirect_delay]" id="hd-success-delay-<?php echo esc_attr( $type ); ?>" value="<?php echo esc_attr( $delay ); ?>" min="0" max="10" step="1" class="small-text">
								<span class="description"><?php esc_html_e( 'seconds, 0 to redirect immediately.', 'hd-form' ); ?></span>
							</td>
						</tr>
					</table>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Sanitize Success section settings.
	 *
	 * @param array $input    Raw input.
	 * @param array $existing Existing saved settings.
	 *
	 * @return array Sanitized partial.
	 */
	public static function sanitize( array $input, array $existing ): array {
		return [ 'on_success' => self::sanitizeOnSuccessSettings( $input, $existing ) ];
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $existing
	 *
	 * @return array{form_types?: array<string, array<string, string|int>>}
	 */
	private static function sanitizeOnSuccessSettings( array $input, array $existing ): array {
		if ( ! array_key_exists( 'on_success', $input ) ) {
			return is_array( $existing['on_success'] ?? null ) ? $existing['on_success'] : [];
		}

		$config    = FormConfig::all();
		$formTypes = $config['form_types'] ?? [];
		$raw       = is_array( $input['on_success'] ?? null ) ? $input['on_success'] : [];
		$rawTypes  = is_array( $raw['form_types'] ?? null ) ? $raw['form_types'] : [];
		$clean     = [];

		foreach ( $formTypes as $type => $definition ) {
			$type = sanitize_key( (string) $type );
			if ( '' === $type || ! array_key_exists( $type, $rawTypes ) || ! is_array( $rawTypes[ $type ] ) ) {
				continue;
			}

			$typeInput       = $rawTypes[ $type ];
			$rawAction       = $typeInput['action'] ?? '';
			$requestedAction = is_scalar( $rawAction ) ? sanitize_key( (string) $rawAction ) : '';
			$override        = [];

			foreach ( [ 'action', 'redirect_url', 'redirect_delay' ] as $key ) {
				$value = $typeInput[ $key ] ?? null;
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$override[ $key ] = $value;
				}
			}

			if ( [] === $override ) {
				continue;
			}

			$override = FormConfig::sanitizeOnSuccessOverride( $override );

			if ( 'redirect' === ( $override['action'] ?? '' ) && empty( $override['redirect_url'] ) ) {
				if ( 'redirect' === $requestedAction ) {
					$label = is_array( $definition ) ? ( $definition['label'] ?? $type ) : $type;

					add_settings_error(
						'hd_form_settings',
						'invalid_success_redirect_' . $type,
						sprintf(
							/* translators: %s: form type label. */
							__( 'Redirect URL for "%s" must be a valid same-site URL.', 'hd-form' ),
							sanitize_text_field( (string) $label )
						),
						'error'
					);
				}

				$override = [
					'action'         => 'message',
					'redirect_delay' => 0,
				];
			}

			$clean[ $type ] = $override;
		}

		return [] !== $clean ? [ 'form_types' => $clean ] : [];
	}
}

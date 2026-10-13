<?php
/**
 * Form Settings — Workflow Section.
 *
 * Renders and sanitizes the Workflow tab: custom workflow statuses
 * for business processing and pipeline transitions.
 *
 * @package HDForm\Admin\Settings
 */

declare(strict_types=1);

namespace HDForm\Admin\Settings;

defined( 'ABSPATH' ) || exit;

final class WorkflowSection {

	/**
	 * Render the Workflow settings tab.
	 *
	 * @param array  $options Current saved options.
	 * @param string $optKey  Option key for form field names.
	 */
	public static function renderTab( array $options, string $optKey ): void {
		$statuses = $options['workflow_statuses'] ?? [];
		$statuses = is_array( $statuses ) ? array_values( $statuses ) : [];

		?>
		<div class="hd-form-tab-content" id="hd-tab-workflow">
			<h2><?php esc_html_e( 'Workflow Statuses', 'hd-form' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Define the business processing states for form entries. Leave empty to hide workflow UI from all entry views.', 'hd-form' ); ?></p>

			<div id="hd-workflow-container" style="margin-top: 15px; max-width: 800px;">
				<div id="hd-workflow-rows">
					<?php foreach ( $statuses as $index => $status ) : ?>
						<?php
						$slug     = sanitize_key( (string) ( $status['slug'] ?? '' ) );
						$label    = sanitize_text_field( (string) ( $status['label'] ?? '' ) );
						$rawColor = (string) ( $status['color'] ?? '#a7aaad' );
						$color    = preg_match( '/^#[0-9a-fA-F]{6}$/', $rawColor ) ? $rawColor : '#a7aaad';
						$position = absint( $status['position'] ?? $index );
						?>
						<div class="hd-workflow-row" data-index="<?php echo esc_attr( (string) $index ); ?>" style="display: flex; align-items: center; gap: 10px; padding: 12px; margin-bottom: 8px; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px;">
							<span class="hd-workflow-color-preview" style="width: 24px; height: 24px; border-radius: 50%; background: <?php echo esc_attr( $color ); ?>; border: 1px solid rgba(0,0,0,0.1); flex-shrink: 0;"></span>

							<div style="flex: 1;">
								<label style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 2px;"><?php esc_html_e( 'Label', 'hd-form' ); ?></label>
								<input type="text" class="widefat wf-label" name="<?php echo esc_attr( $optKey ); ?>[workflow_statuses][<?php echo esc_attr( (string) $index ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'e.g. Pending Review', 'hd-form' ); ?>" required />
							</div>

							<div style="width: 140px;">
								<label style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 2px;"><?php esc_html_e( 'Slug', 'hd-form' ); ?></label>
								<input type="text" class="widefat wf-slug code" name="<?php echo esc_attr( $optKey ); ?>[workflow_statuses][<?php echo esc_attr( (string) $index ); ?>][slug]" value="<?php echo esc_attr( $slug ); ?>" placeholder="<?php esc_attr_e( 'pending_review', 'hd-form' ); ?>" required />
							</div>

							<div style="width: 130px;">
								<label style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 2px;"><?php esc_html_e( 'Color', 'hd-form' ); ?></label>
								<div style="display: flex; align-items: center; gap: 4px;">
									<input type="color" class="wf-color-picker" value="<?php echo esc_attr( $color ); ?>" style="width: 32px; height: 30px; padding: 0; border: 1px solid #c3c4c7; border-radius: 4px; cursor: pointer;" />
									<input type="text" class="wf-color code" name="<?php echo esc_attr( $optKey ); ?>[workflow_statuses][<?php echo esc_attr( (string) $index ); ?>][color]" value="<?php echo esc_attr( $color ); ?>" maxlength="7" style="width: 80px;" />
								</div>
							</div>

							<input type="hidden" class="wf-position" name="<?php echo esc_attr( $optKey ); ?>[workflow_statuses][<?php echo esc_attr( (string) $index ); ?>][position]" value="<?php echo esc_attr( (string) $position ); ?>" />

							<div style="padding-top: 16px;">
								<button type="button" class="button hd-workflow-delete-btn" style="color: #b32d2e; border-color: #e5a5a5;" aria-label="<?php esc_attr_e( 'Remove status', 'hd-form' ); ?>">✕</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

				<button type="button" id="hd-workflow-add-btn" class="button button-secondary" style="margin-top: 8px;">
					+ <?php esc_html_e( 'Add Status', 'hd-form' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Sanitize Workflow section settings.
	 *
	 * @param array $input Raw input.
	 *
	 * @return array Sanitized partial.
	 */
	public static function sanitize( array $input ): array {
		$raw = $input['workflow_statuses'] ?? [];
		if ( ! is_array( $raw ) ) {
			return [ 'workflow_statuses' => [] ];
		}

		$seen  = [];
		$clean = [];

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
			$slug  = sanitize_key( (string) ( $item['slug'] ?? '' ) );

			// Auto-generate slug from label if omitted.
			if ( '' === $slug && '' !== $label ) {
				$slug = sanitize_key( str_replace( [ ' ', '-' ], '_', strtolower( $label ) ) );
			}

			if ( '' === $slug || '' === $label ) {
				continue;
			}

			// Reject duplicate slugs.
			if ( isset( $seen[ $slug ] ) ) {
				continue;
			}
			$seen[ $slug ] = true;

			$rawColor = (string) ( $item['color'] ?? '' );
			$color    = preg_match( '/^#[0-9a-fA-F]{6}$/', $rawColor ) ? $rawColor : '#a7aaad';

			$clean[] = [
				'slug'     => $slug,
				'label'    => $label,
				'color'    => $color,
				'position' => absint( $item['position'] ?? count( $clean ) ),
			];
		}

		usort( $clean, static fn( array $a, array $b ): int => ( $a['position'] ?? 0 ) <=> ( $b['position'] ?? 0 ) );

		return [
			'workflow_statuses' => array_values( $clean ),
		];
	}
}

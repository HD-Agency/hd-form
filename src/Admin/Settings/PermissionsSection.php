<?php
/**
 * Form Settings — Permissions Section.
 *
 * Renders and sanitizes the Permissions tab.
 *
 * @package HDForm\Admin\Settings
 */

declare(strict_types=1);

namespace HDForm\Admin\Settings;

defined( 'ABSPATH' ) || exit;

final class PermissionsSection {

	/**
	 * Render the Permissions settings tab.
	 *
	 * @param array  $options Current saved options.
	 * @param string $optKey  Option key for form field names.
	 */
	public static function renderTab( array $options, string $optKey ): void {
		$wp_roles = wp_roles();
		$roles    = $options['roles'] ?? [ 'administrator' ];
		if ( ! is_array( $roles ) ) {
			$roles = [ 'administrator' ];
		}

		?>
		<div class="hd-form-tab-content" id="hd-tab-permissions">
			<h2><?php esc_html_e( 'Permissions', 'hd-form' ); ?></h2>
			<p><?php esc_html_e( 'Select which roles can view form entries and logs. Administrators always have full access; the Settings screen additionally requires the WordPress manage_options capability.', 'hd-form' ); ?></p>

			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Roles', 'hd-form' ); ?></th>
					<td>
						<fieldset>
							<?php
							if ( isset( $wp_roles->roles ) && is_array( $wp_roles->roles ) ) {
								foreach ( $wp_roles->roles as $roleKey => $roleData ) {
									if ( ! is_array( $roleData ) || ! self::canRoleAccessAdmin( (string) $roleKey, $roleData ) ) {
										continue;
									}
									$isAdministrator = 'administrator' === $roleKey;
									$isChecked       = $isAdministrator || in_array( $roleKey, $roles, true );
									$fieldName       = sprintf( '%s[roles][]', $optKey );
									?>
									<label style="display: block; margin-bottom: 8px;">
										<input type="checkbox" name="<?php echo esc_attr( $fieldName ); ?>" value="<?php echo esc_attr( $roleKey ); ?>" <?php checked( $isChecked ); ?> <?php disabled( $isAdministrator ); ?>>
										<strong><?php echo esc_html( translate_user_role( $roleData['name'] ?? (string) $roleKey ) ); ?></strong>
										<?php if ( $isAdministrator ) : ?>
											<span class="description" style="color: #666;">(<?php esc_html_e( 'Always Allowed', 'hd-form' ); ?>)</span>
										<?php else : ?>
											<span class="description" style="color: #666;">(<?php echo esc_html( sprintf( __( 'Grant capability to %s', 'hd-form' ), translate_user_role( $roleData['name'] ?? (string) $roleKey ) ) ); ?>)</span>
										<?php endif; ?>
									</label>
									<?php
								}
							}
							?>
						</fieldset>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Determine if a WordPress user role has backend/admin dashboard access.
	 *
	 * @param string               $roleKey  Role slug.
	 * @param array<string, mixed> $roleData Role data containing name and capabilities.
	 *
	 * @return bool
	 */
	public static function canRoleAccessAdmin( string $roleKey, array $roleData ): bool {
		if ( 'administrator' === $roleKey ) {
			return true;
		}

		$caps = $roleData['capabilities'] ?? [];
		if ( ! is_array( $caps ) ) {
			return false;
		}

		// Must have at least read capability.
		if ( empty( $caps['read'] ) ) {
			return false;
		}

		// Frontend-only roles with only 'read' (or basic customer capabilities) are excluded.
		$frontendOnlyRoles = apply_filters( 'hd_form_frontend_roles', [ 'subscriber', 'customer' ] );
		if ( in_array( $roleKey, (array) $frontendOnlyRoles, true ) ) {
			return false;
		}

		// Must possess at least one backend management/editing capability.
		$adminCaps = [
			'manage_options',
			'edit_posts',
			'edit_pages',
			'publish_posts',
			'publish_pages',
			'upload_files',
			'edit_others_posts',
			'manage_woocommerce',
			'edit_theme_options',
		];

		$hasAdminCap = false;
		foreach ( $adminCaps as $cap ) {
			if ( ! empty( $caps[ $cap ] ) ) {
				$hasAdminCap = true;
				break;
			}
		}

		return (bool) apply_filters( 'hd_form_role_can_access_admin', $hasAdminCap, $roleKey, $roleData );
	}

	/**
	 * Sanitize Permissions settings.
	 *
	 * @param array $input Raw input.
	 *
	 * @return array Sanitized partial.
	 */
	public static function sanitize( array $input ): array {
		$clean = [];
		$roles = $input['roles'] ?? [];
		if ( ! is_array( $roles ) ) {
			$roles = [];
		}

		$wp_roles       = wp_roles();
		$availableRoles = $wp_roles->roles ?? [];

		$sanitizedRoles = array_values(
			array_filter(
				array_map( 'sanitize_key', $roles ),
				static fn( string $roleKey ): bool => isset( $availableRoles[ $roleKey ] )
					&& is_array( $availableRoles[ $roleKey ] )
					&& self::canRoleAccessAdmin( $roleKey, $availableRoles[ $roleKey ] )
			)
		);

		if ( ! in_array( 'administrator', $sanitizedRoles, true ) ) {
			array_unshift( $sanitizedRoles, 'administrator' );
		}

		$clean['roles'] = $sanitizedRoles;

		return $clean;
	}
}

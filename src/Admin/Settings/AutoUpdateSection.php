<?php
/**
 * Form Settings — Auto-Update Section.
 *
 * Renders the Auto-Update settings tab inside the standard HD Form channel block.
 *
 * @package HDForm\Admin\Settings
 */

declare(strict_types=1);

namespace HDForm\Admin\Settings;

use HDForm\Updater\GitHubUpdater;

defined( 'ABSPATH' ) || exit;

final class AutoUpdateSection {

	/**
	 * Render the Auto-Update settings tab.
	 */
	public static function renderTab(): void {
		$hasToken    = GitHubUpdater::hasToken();
		$source      = GitHubUpdater::tokenSource();
		$sourceLabel = 'constant' === $source ? __( 'Environment', 'hd-form' ) : __( 'Encrypted Database', 'hd-form' );

		?>
		<div class="hd-form-tab-content" id="hd-tab-update">
			<p><?php esc_html_e( 'Configure access credentials to enable automatic background plugin updates and seamless version upgrades.', 'hd-form' ); ?></p>

			<div class="hd-channel-block">
				<div class="hdf-vault-header">
					<div class="hdf-vault-header-left">
						<div class="hdf-vault-icon">
							<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg>
						</div>
						<div>
							<h3 class="hdf-vault-title"><?php esc_html_e( 'Auto-Update Authentication', 'hd-form' ); ?></h3>
							<p class="hdf-vault-subtitle"><?php esc_html_e( 'Enable automatic background plugin updates and seamless version upgrades.', 'hd-form' ); ?></p>
						</div>
					</div>
					<div class="hdf-vault-header-right">
						<?php if ( $hasToken ) : ?>
							<div class="hdf-status-indicator active">
								<span class="hdf-status-dot"></span>
								<span class="hdf-status-text"><?php esc_html_e( 'Active', 'hd-form' ); ?></span>
								<span class="hdf-status-source">(<?php echo esc_html( $sourceLabel ); ?>)</span>
							</div>
						<?php else : ?>
							<div class="hdf-status-indicator inactive">
								<span class="hdf-status-dot"></span>
								<span class="hdf-status-text"><?php esc_html_e( 'Not Configured', 'hd-form' ); ?></span>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<div class="hdf-vault-body">
					<?php if ( $hasToken ) : ?>
						<div class="hdf-token-active-view" id="hdf-token-active-view">
							<div class="hdf-token-key-display">
								<span class="hdf-key-label"><?php esc_html_e( 'ACCESS TOKEN', 'hd-form' ); ?></span>
								<span class="hdf-key-hash">••••••••••••••••••••••••••••••••</span>
								<div class="hdf-key-specs">
									<span class="hdf-spec-item"><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Sodium Encrypted', 'hd-form' ); ?></span>
									<span class="hdf-spec-item"><span class="dashicons dashicons-admin-plugins"></span> <?php esc_html_e( 'Channel: Production Release', 'hd-form' ); ?></span>
								</div>
							</div>
							<div class="hdf-token-active-actions">
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?puc_check_for_updates=1&puc_slug=hd-form' ), 'puc_check_for_updates' ) ); ?>" class="btn-action-primary">
									<?php esc_html_e( 'Check for Updates', 'hd-form' ); ?>
								</a>
								<button type="button" id="hdf-btn-edit-token" class="btn-action-neutral">
									<?php esc_html_e( 'Replace Token', 'hd-form' ); ?>
								</button>
								<?php if ( 'db' === $source ) : ?>
									<button type="button" id="hdf-delete-token" class="btn-action-danger">
										<?php esc_html_e( 'Remove Token', 'hd-form' ); ?>
									</button>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>

					<div class="hdf-token-edit-view" id="hdf-token-edit-view" style="<?php echo $hasToken ? 'display: none;' : ''; ?>">
						<?php if ( $hasToken ) : ?>
							<div class="hdf-edit-notice">
								<span class="dashicons dashicons-info"></span>
								<span><?php esc_html_e( 'Enter a new access token to replace the current active token.', 'hd-form' ); ?></span>
							</div>
						<?php else : ?>
							<div class="hdf-setup-guide">
								<p><?php esc_html_e( 'Configure an access token to enable automatic background plugin updates and seamless version upgrades.', 'hd-form' ); ?></p>
							</div>
						<?php endif; ?>

						<div class="hdf-input-action-card">
							<label for="hdf-github-token" class="hdf-input-label"><?php echo $hasToken ? esc_html__( 'New Access Token', 'hd-form' ) : esc_html__( 'Access Token', 'hd-form' ); ?></label>
							<div class="hdf-token-input-wrapper">
								<span class="hdf-input-icon dashicons dashicons-admin-network"></span>
								<input type="password" id="hdf-github-token" placeholder="••••••••••••••••••••••••••••••••" autocomplete="off" spellcheck="false">
							</div>

							<div class="hdf-input-actions-row">
								<button type="button" id="hdf-save-token" class="btn-action-submit">
									<?php echo $hasToken ? esc_html__( 'Update Token', 'hd-form' ) : esc_html__( 'Save & Connect', 'hd-form' ); ?>
								</button>
								<?php if ( $hasToken ) : ?>
									<button type="button" id="hdf-btn-cancel-edit" class="btn-action-cancel">
										<?php esc_html_e( 'Cancel', 'hd-form' ); ?>
									</button>
								<?php endif; ?>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'plugins.php?puc_check_for_updates=1&puc_slug=hd-form' ), 'puc_check_for_updates' ) ); ?>" class="btn-action-primary" style="margin-left: auto;">
									<?php esc_html_e( 'Check for Updates', 'hd-form' ); ?>
								</a>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

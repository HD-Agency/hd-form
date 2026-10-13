<?php
/**
 * HD Forms Settings Page — Tabbed UI.
 *
 * Submenu under Form Entries → Settings.
 * Manages: CAPTCHA keys, email filters, notifications, spam, cleanup.
 *
 * @package HDForm\Admin
 */

declare(strict_types=1);

namespace HDForm\Admin;

use HDForm\Compat\Helper;
use HDForm\Admin\Settings\AutoUpdateSection;
use HDForm\Admin\Settings\CaptchaSection;
use HDForm\Admin\Settings\CleanupSection;
use HDForm\Admin\Settings\GeneralSection;
use HDForm\Admin\Settings\NotificationsSection;
use HDForm\Admin\Settings\PermissionsSection;
use HDForm\Admin\Settings\SpamSection;
use HDForm\Admin\Settings\SuccessSection;
use HDForm\Admin\Settings\WorkflowSection;
use HDForm\FormConfig;

defined( 'ABSPATH' ) || exit;

final class FormSettingsPage {

	private const OPTION_KEY = 'hd_form_settings';
	private const MENU_SLUG  = 'hd-form-settings';

	/** Inline CSS for the settings page. */
	private const ADMIN_CSS = '
		.hd-form-tab-content { display: none; }
		.hd-form-tab-content.active { display: block; }
		.hd-form-settings .nav-tab-wrapper { margin-bottom: 20px; }
		.hd-captcha-provider { border: 1px solid #c3c4c7; padding: 16px 20px; margin-bottom: 12px; border-radius: 4px; background: #f9f9f9; }
		.hd-captcha-provider h3 { margin: 0 0 10px; font-size: 14px; }
		.hd-captcha-provider .form-table { margin: 0; }
		.hd-captcha-provider .form-table th { padding: 8px 10px 8px 0; width: 140px; }
		.hd-captcha-provider .form-table td { padding: 8px 0; }
		.hd-channel-block { border: 1px solid #c3c4c7; padding: 16px 20px; margin-bottom: 12px; border-radius: 4px; background: #f9f9f9; }
		.hd-channel-block h3 { margin: 0 0 10px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
		.hd-channel-block .form-table { margin: 0; }
		.hd-channel-block .form-table th { padding: 8px 10px 8px 0; width: 140px; }
		.hd-channel-block .form-table td { padding: 8px 0; }
		.hd-channel-fields { margin-top: 8px; }
		.hd-email-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
		.hd-email-tags .hd-tag { background: #2271b1; color: #fff; padding: 4px 8px; border-radius: 3px; font-size: 12px; display: inline-flex; align-items: center; gap: 4px; }
		.hd-email-tags .hd-tag button { background: none; border: none; color: #fff; cursor: pointer; font-size: 14px; line-height: 1; padding: 0; }

		/* Auto-Update Vault Styling inside hd-channel-block */
		.hd-channel-block .hdf-vault-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 12px; }
		.hd-channel-block .hdf-vault-header-left { display: flex; align-items: center; gap: 12px; }
		.hd-channel-block .hdf-vault-icon { width: 38px; height: 38px; background: #0f172a; color: #fff; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
		.hd-channel-block .hdf-vault-icon svg { width: 20px; height: 20px; stroke: currentColor; fill: none; }
		.hd-channel-block .hdf-vault-title { margin: 0 !important; font-size: 15px !important; font-weight: 600 !important; color: #0f172a !important; line-height: 1.3 !important; }
		.hd-channel-block .hdf-vault-subtitle { margin: 3px 0 0 !important; font-size: 12.5px !important; color: #64748b !important; line-height: 1.4 !important; }
		.hd-channel-block .hdf-status-indicator { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
		.hd-channel-block .hdf-status-indicator.active { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
		.hd-channel-block .hdf-status-indicator.active .hdf-status-dot { width: 8px; height: 8px; border-radius: 50%; background: #10b981; box-shadow: 0 0 0 2px rgba(16,185,129,0.25); }
		.hd-channel-block .hdf-status-indicator.inactive { background: #f1f5f9; color: #64748b; border: 1px solid #cbd5e1; }
		.hd-channel-block .hdf-status-indicator.inactive .hdf-status-dot { width: 8px; height: 8px; border-radius: 50%; background: #94a3b8; }
		.hd-channel-block .hdf-status-source { font-size: 11px; font-weight: 500; opacity: 0.85; }

		.hd-channel-block .hdf-token-active-view { display: flex; justify-content: space-between; align-items: center; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; flex-wrap: wrap; gap: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
		.hd-channel-block .hdf-token-key-display .hdf-key-label { display: block; font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; margin-bottom: 4px; }
		.hd-channel-block .hdf-token-key-display .hdf-key-hash { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 15px; font-weight: 600; color: #0f172a; letter-spacing: 0.08em; }
		.hd-channel-block .hdf-token-key-display .hdf-key-specs { display: flex; gap: 14px; margin-top: 6px; font-size: 11.5px; color: #64748b; }
		.hd-channel-block .hdf-spec-item { display: inline-flex; align-items: center; gap: 4px; }
		.hd-channel-block .hdf-spec-item .dashicons { font-size: 14px; width: 14px; height: 14px; }
		.hd-channel-block .hdf-token-active-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

		/* Semantic Action Buttons */
		.hd-channel-block .btn-action-primary { display: inline-flex; align-items: center; justify-content: center; height: 32px; padding: 0 14px; font-size: 12.5px; font-weight: 600; color: #1d4ed8 !important; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 6px; text-decoration: none !important; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 1px 2px rgba(29,78,216,0.05); }
		.hd-channel-block .btn-action-primary:hover { background: #dbeafe !important; border-color: #93c5fd !important; color: #1e40af !important; transform: translateY(-1px); box-shadow: 0 2px 4px rgba(29,78,216,0.12); }

		.hd-channel-block .btn-action-neutral { display: inline-flex; align-items: center; justify-content: center; height: 32px; padding: 0 14px; font-size: 12.5px; font-weight: 500; color: #334155 !important; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 6px; text-decoration: none !important; cursor: pointer; transition: all 0.15s ease; box-shadow: 0 1px 2px rgba(0,0,0,0.03); }
		.hd-channel-block .btn-action-neutral:hover { background: #f8fafc !important; border-color: #94a3b8 !important; color: #0f172a !important; }

		.hd-channel-block .btn-action-danger { display: inline-flex; align-items: center; justify-content: center; height: 32px; padding: 0 12px; font-size: 12.5px; font-weight: 500; color: #dc2626 !important; background: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; text-decoration: none !important; cursor: pointer; transition: all 0.15s ease; }
		.hd-channel-block .btn-action-danger:hover { background: #fee2e2 !important; border-color: #fca5a5 !important; color: #991b1b !important; }

		.hd-channel-block .hdf-token-edit-view .hdf-setup-guide { background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 12px 16px; margin-bottom: 14px; }
		.hd-channel-block .hdf-setup-guide p { margin: 0; color: #166534; font-size: 13px; font-weight: 500; line-height: 1.5; }
		.hd-channel-block .hdf-token-edit-view .hdf-edit-notice { display: flex; align-items: center; gap: 8px; background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 14px; margin-bottom: 14px; color: #1e40af; font-size: 13px; font-weight: 500; }
		.hd-channel-block .hdf-input-action-card .hdf-input-label { display: block; font-size: 13px; font-weight: 600; color: #0f172a; margin-bottom: 6px; }
		.hd-channel-block .hdf-token-input-wrapper { position: relative; max-width: 520px; }
		.hd-channel-block .hdf-token-input-wrapper .hdf-input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 16px; width: 16px; height: 16px; pointer-events: none; z-index: 2; }
		.hd-channel-block .hdf-token-input-wrapper input { width: 100%; padding: 6px 12px 6px 36px !important; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; height: 36px; box-sizing: border-box; }
		.hd-channel-block .hdf-token-input-wrapper input:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.2); outline: none; }
		.hd-channel-block .hdf-input-actions-row { display: flex; align-items: center; gap: 8px; margin-top: 14px; flex-wrap: wrap; }

		.hd-channel-block .btn-action-submit { display: inline-flex; align-items: center; justify-content: center; height: 34px; padding: 0 16px; font-size: 13px; font-weight: 600; color: #ffffff !important; background: #2271b1 !important; border: 1px solid #2271b1 !important; border-radius: 6px !important; cursor: pointer; box-shadow: 0 1px 2px rgba(34,113,177,0.25); transition: all 0.15s ease !important; }
		.hd-channel-block .btn-action-submit:hover { background: #135e96 !important; border-color: #135e96 !important; transform: translateY(-1px); box-shadow: 0 2px 4px rgba(34,113,177,0.35); }

		.hd-channel-block .btn-action-cancel { display: inline-flex; align-items: center; justify-content: center; height: 34px; padding: 0 12px; font-size: 13px; font-weight: 500; color: #475569 !important; background: transparent !important; border: 1px solid #cbd5e1 !important; border-radius: 6px; cursor: pointer; transition: all 0.15s ease; }
		.hd-channel-block .btn-action-cancel:hover { background: #f1f5f9 !important; border-color: #94a3b8 !important; color: #0f172a !important; }
	';

	/**
	 * Register admin hooks.
	 */
	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'addPage' ], 31 );
		add_action( 'admin_init', [ self::class, 'registerSettings' ] );
	}

	/**
	 * Add submenu page under Form Entries.
	 */
	public static function addPage(): void {
		$hook = add_submenu_page(
			'hd-form-entries',
			__( 'Settings', 'hd-form' ),
			__( 'Settings', 'hd-form' ),
			'manage_options',
			self::MENU_SLUG,
			[ self::class, 'renderPage' ]
		);

		add_action(
			'admin_print_styles-' . $hook,
			static function () {
				echo '<style>' . self::ADMIN_CSS . '</style>';
			}
		);
	}

	/**
	 * Register the single option for all settings.
	 */
	public static function registerSettings(): void {
		register_setting(
			self::MENU_SLUG,
			self::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ self::class, 'sanitize' ],
				'default'           => [],
			]
		);
	}

	/** ── Sanitize ─────────────────────────────────────────────── */

	/**
	 * Sanitize all settings on save.
	 *
	 * @param array $input Raw input.
	 *
	 * @return array Sanitized.
	 */
	public static function sanitize( array $input ): array {
		$existing = Helper::getOption( self::OPTION_KEY, [] );
		$existing = is_array( $existing ) ? $existing : [];

		$sanitizeSecret = [ self::class, 'sanitizeSecret' ];

		$clean = array_merge(
			GeneralSection::sanitize( $input ),
			CaptchaSection::sanitize( $input, $existing, $sanitizeSecret ),
			NotificationsSection::sanitize( $input, $existing, $sanitizeSecret ),
			SuccessSection::sanitize( $input, $existing ),
			WorkflowSection::sanitize( $input ),
			SpamSection::sanitize( $input ),
			CleanupSection::sanitize( $input ),
		);

		if ( current_user_can( 'manage_options' ) ) {
			$clean = array_merge( $clean, PermissionsSection::sanitize( $input ) );
		} else {
			$clean['roles'] = $existing['roles'] ?? [ 'administrator' ];
		}

		FormConfig::resetCache();

		return $clean;
	}

	/** ── Render ───────────────────────────────────────────────── */

	/**
	 * Render the tabbed settings page.
	 */
	public static function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = Helper::getOption( self::OPTION_KEY, [] );
		$optKey  = self::OPTION_KEY;

		$tabs = [
			'general'       => __( 'General', 'hd-form' ),
			'captcha'       => __( 'CAPTCHA', 'hd-form' ),
			'notifications' => __( 'Notifications', 'hd-form' ),
			'success'       => __( 'Success', 'hd-form' ),
			'workflow'      => __( 'Workflow', 'hd-form' ),
			'spam'          => __( 'Spam & Validation', 'hd-form' ),
			'cleanup'       => __( 'Cleanup', 'hd-form' ),
			'update'        => __( 'Auto-Update', 'hd-form' ),
		];

		if ( current_user_can( 'manage_options' ) ) {
			$tabs['permissions'] = __( 'Permissions', 'hd-form' );
		}

		?>
		<div class="wrap hd-form-settings">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php self::renderSettingsNotices(); ?>
			<?php CaptchaSection::renderAdminNotices( $options ); ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="#" class="nav-tab<?php echo 'general' === $slug ? ' nav-tab-active' : ''; ?>" data-tab="hd-tab-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php">
				<?php settings_fields( self::MENU_SLUG ); ?>

				<?php GeneralSection::renderTab( $options, $optKey ); ?>
				<?php CaptchaSection::renderTab( $options, $optKey ); ?>
				<?php NotificationsSection::renderTab( $options, $optKey ); ?>
				<?php SuccessSection::renderTab( $options, $optKey ); ?>
				<?php WorkflowSection::renderTab( $options, $optKey ); ?>
				<?php SpamSection::renderTab( $options, $optKey ); ?>
				<?php CleanupSection::renderTab( $options, $optKey ); ?>
				<?php AutoUpdateSection::renderTab(); ?>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					<?php PermissionsSection::renderTab( $options, $optKey ); ?>
				<?php endif; ?>

				<?php submit_button(); ?>
			</form>
		</div>

		<script>
		(function() {
			const wrap = document.querySelector('.hd-form-settings');
			if (!wrap) return;

			function activateTab(tabId) {
				if (!tabId) return;
				const tab = wrap.querySelector('.nav-tab[data-tab="' + tabId + '"]');
				const content = wrap.querySelector('#' + tabId);
				const submitBtn = wrap.querySelector('p.submit');
				if (tab && content) {
					wrap.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('nav-tab-active'));
					wrap.querySelectorAll('.hd-form-tab-content').forEach(c => c.classList.remove('active'));
					tab.classList.add('nav-tab-active');
					content.classList.add('active');
					if (submitBtn) {
						submitBtn.style.display = tabId === 'hd-tab-update' ? 'none' : 'block';
					}
				}
			}

			const initialHash = window.location.hash.replace(/^#/, '');
			if (initialHash) {
				activateTab(initialHash);
			}

			wrap.querySelectorAll('.nav-tab').forEach(tab => {
				tab.addEventListener('click', e => {
					e.preventDefault();
					const targetId = tab.dataset.tab;
					activateTab(targetId);
					if (history.replaceState) {
						history.replaceState(null, '', '#' + targetId);
					} else {
						window.location.hash = targetId;
					}
				});
			});

			// Workflow dynamic repeater controls
			const wfRows = document.getElementById('hd-workflow-rows');
			const addBtn = document.getElementById('hd-workflow-add-btn');
			const optKey = <?php echo wp_json_encode( $optKey ); ?>;

			function slugify(text) {
				return (text || '')
					.toLowerCase()
					.replace(/[^a-z0-9]+/g, '_')
					.replace(/^_+|_+$/g, '')
					.slice(0, 50);
			}

			function initWorkflowRow(row) {
				const delBtn = row.querySelector('.hd-workflow-delete-btn');
				const colorPicker = row.querySelector('.wf-color-picker');
				const colorText = row.querySelector('.wf-color');
				const preview = row.querySelector('.hd-workflow-color-preview');
				const labelInput = row.querySelector('.wf-label');
				const slugInput = row.querySelector('.wf-slug');

				if (delBtn) {
					delBtn.addEventListener('click', function() {
						if (confirm(<?php echo wp_json_encode( __( 'Remove this workflow status?', 'hd-form' ) ); ?>)) {
							row.remove();
						}
					});
				}

				function updateColor(val) {
					if (preview) preview.style.background = val;
					if (colorText && colorText.value !== val) colorText.value = val;
					if (colorPicker && colorPicker.value !== val) colorPicker.value = val;
				}

				if (colorPicker) {
					colorPicker.addEventListener('input', function() {
						updateColor(colorPicker.value);
					});
				}

				if (colorText) {
					colorText.addEventListener('input', function() {
						const val = colorText.value.trim();
						if (/^#[0-9a-fA-F]{6}$/.test(val)) {
							updateColor(val);
						}
					});
				}

				if (labelInput && slugInput) {
					labelInput.addEventListener('blur', function() {
						if (!slugInput.value.trim()) {
							slugInput.value = slugify(labelInput.value);
						}
					});
				}
			}

			let nextIndex = 0;
			if (wfRows) {
				wfRows.querySelectorAll('.hd-workflow-row').forEach(function(row) {
					const idx = parseInt(row.dataset.index, 10);
					if (!isNaN(idx) && idx >= nextIndex) {
						nextIndex = idx + 1;
					}
					initWorkflowRow(row);
				});
			}

			if (addBtn && wfRows) {
				addBtn.addEventListener('click', function() {
					const count = nextIndex++;
					const row = document.createElement('div');
					row.className = 'hd-workflow-row';
					row.dataset.index = String(count);
					row.style.cssText = 'display: flex; align-items: center; gap: 10px; padding: 12px; margin-bottom: 8px; background: #fff; border: 1px solid #c3c4c7; border-radius: 6px;';
					row.innerHTML = `
						<span class="hd-workflow-color-preview" style="width: 24px; height: 24px; border-radius: 50%; background: #a7aaad; border: 1px solid rgba(0,0,0,0.1); flex-shrink: 0;"></span>
						<div style="flex: 1;">
							<label style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 2px;"><?php echo esc_js( __( 'Label', 'hd-form' ) ); ?></label>
							<input type="text" class="widefat wf-label" name="${optKey}[workflow_statuses][${count}][label]" value="" placeholder="<?php echo esc_attr( __( 'e.g. Pending Review', 'hd-form' ) ); ?>" required />
						</div>
						<div style="width: 140px;">
							<label style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 2px;"><?php echo esc_js( __( 'Slug', 'hd-form' ) ); ?></label>
							<input type="text" class="widefat wf-slug code" name="${optKey}[workflow_statuses][${count}][slug]" value="" placeholder="<?php echo esc_attr( __( 'pending_review', 'hd-form' ) ); ?>" required />
						</div>
						<div style="width: 130px;">
							<label style="display: block; font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 2px;"><?php echo esc_js( __( 'Color', 'hd-form' ) ); ?></label>
							<div style="display: flex; align-items: center; gap: 4px;">
								<input type="color" class="wf-color-picker" value="#a7aaad" style="width: 32px; height: 30px; padding: 0; border: 1px solid #c3c4c7; border-radius: 4px; cursor: pointer;" />
								<input type="text" class="wf-color code" name="${optKey}[workflow_statuses][${count}][color]" value="#a7aaad" maxlength="7" style="width: 80px;" />
							</div>
						</div>
						<input type="hidden" class="wf-position" name="${optKey}[workflow_statuses][${count}][position]" value="${count}" />
						<div style="padding-top: 16px;">
							<button type="button" class="button hd-workflow-delete-btn" style="color: #b32d2e; border-color: #e5a5a5;" aria-label="<?php echo esc_attr( __( 'Remove status', 'hd-form' ) ); ?>">✕</button>
						</div>
					`;
					wfRows.appendChild(row);
					initWorkflowRow(row);
				});
			}

			// Auto-Update Vault Handlers (HD Standard)
			const vaultRestUrl   = <?php echo wp_json_encode( esc_url_raw( rest_url( HD_FORM_REST_NAMESPACE . '/settings/github-token' ) ) ); ?>;
			const vaultNonce     = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
			const saveTokenBtn   = document.getElementById('hdf-save-token');
			const deleteTokenBtn = document.getElementById('hdf-delete-token');
			const editTokenBtn   = document.getElementById('hdf-btn-edit-token');
			const cancelTokenBtn = document.getElementById('hdf-btn-cancel-edit');
			const tokenInput     = document.getElementById('hdf-github-token');
			const activeView     = document.getElementById('hdf-token-active-view');
			const editView       = document.getElementById('hdf-token-edit-view');

			if (editTokenBtn) {
				editTokenBtn.addEventListener('click', function() {
					if (activeView) activeView.style.display = 'none';
					if (editView) editView.style.display = 'block';
					if (tokenInput) tokenInput.focus();
				});
			}

			if (cancelTokenBtn) {
				cancelTokenBtn.addEventListener('click', function() {
					if (editView) editView.style.display = 'none';
					if (activeView) activeView.style.display = 'flex';
				});
			}

			if (saveTokenBtn) {
				saveTokenBtn.addEventListener('click', async function() {
					const token = tokenInput ? tokenInput.value.trim() : '';
					if (!token) {
						alert(<?php echo wp_json_encode( __( 'Please enter a valid access token.', 'hd-form' ) ); ?>);
						return;
					}
					saveTokenBtn.disabled = true;
					try {
						const res = await fetch(vaultRestUrl, {
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': vaultNonce,
							},
							body: JSON.stringify({ token: token })
						});
						const data = await res.json();
						alert(data.message || <?php echo wp_json_encode( __( 'Access token saved securely.', 'hd-form' ) ); ?>);
						window.location.reload();
					} catch (err) {
						alert(<?php echo wp_json_encode( __( 'Error saving access token.', 'hd-form' ) ); ?>);
					} finally {
						saveTokenBtn.disabled = false;
					}
				});
			}

			if (deleteTokenBtn) {
				deleteTokenBtn.addEventListener('click', async function() {
					if (!confirm(<?php echo wp_json_encode( __( 'Are you sure you want to remove the stored access token?', 'hd-form' ) ); ?>)) {
						return;
					}
					deleteTokenBtn.disabled = true;
					try {
						const res = await fetch(vaultRestUrl, {
							method: 'DELETE',
							headers: {
								'X-WP-Nonce': vaultNonce,
							}
						});
						const data = await res.json();
						alert(data.message || <?php echo wp_json_encode( __( 'Access token removed.', 'hd-form' ) ); ?>);
						window.location.reload();
					} catch (err) {
						alert(<?php echo wp_json_encode( __( 'Error removing access token.', 'hd-form' ) ); ?>);
					} finally {
						deleteTokenBtn.disabled = false;
					}
				});
			}
		})();
		</script>
		<?php
	}

	/** ── Shared Helpers ───────────────────────────────────────── */

	/**
	 * Render settings saved/error notices.
	 */
	private static function renderSettingsNotices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Settings API redirects with this flag after saving.
		$settingsUpdated = 'true' === (string) ( $_GET['settings-updated'] ?? '' );
		$errors          = get_settings_errors( self::OPTION_KEY );
		$hasError        = false;

		foreach ( $errors as $error ) {
			if ( 'error' === ( $error['type'] ?? '' ) ) {
				$hasError = true;
				break;
			}
		}

		if ( $settingsUpdated && ! $hasError ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'hd-form' ) . '</p></div>';
		}

		foreach ( $errors as $error ) {
			$type    = 'error' === ( $error['type'] ?? '' ) ? 'notice-error' : 'notice-warning';
			$message = (string) ( $error['message'] ?? '' );
			if ( '' === $message ) {
				continue;
			}

			echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Sanitize a secret field while preserving the existing value on blank input.
	 *
	 * @param array  $input    Raw settings input.
	 * @param string $key      Input key.
	 * @param mixed  $existing Existing saved secret.
	 */
	public static function sanitizeSecret( array $input, string $key, mixed $existing ): string {
		$value = isset( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : '';

		return '' !== $value ? $value : sanitize_text_field( (string) $existing );
	}
}

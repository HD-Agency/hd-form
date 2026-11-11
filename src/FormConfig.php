<?php
/**
 * Form Configuration Handler.
 *
 * Reads form config from options/filters and provides accessors
 * for per-type configuration (CAPTCHA, recipients, templates, workflow).
 *
 * @package HDForm
 */

declare(strict_types=1);

namespace HDForm;

use HDForm\Compat\Helper;

defined( 'ABSPATH' ) || exit;

class FormConfig {
	public const OPTION_KEY = 'hd_form_settings';

	private const SUCCESS_ACTION_MESSAGE  = 'message';
	private const SUCCESS_ACTION_POPUP    = 'popup';
	private const SUCCESS_ACTION_REDIRECT = 'redirect';
	private const MAX_REDIRECT_DELAY      = 10;

	private static ?array $cache = null;

	public const DEFAULTS = [
		'form_types'           => [
			'contact' => [
				'label'          => 'Contact',
				'required'       => [ 'name', 'phone' ],
				'spam_check'     => true,
				'email_to'       => [],
				'email_cc'       => [],
				'email_bcc'      => [],
				'email_template' => 'contact',
				'on_success'     => [
					'action'         => 'message',
					'redirect_url'   => '',
					'redirect_delay' => 0,
				],
			],
		],
		'notifications'        => [
			'channels' => [
				'email'         => [
					'enabled' => true,
				],
				'webhook'       => [
					'enabled'    => false,
					'url'        => '',
					'method'     => 'POST',
					'format'     => 'json',
					'secret_key' => '',
				],
				'google_sheets' => [
					'enabled'     => false,
					'sheet_id'    => '',
					'tab_name'    => 'Sheet1',
					'credentials' => '',
				],
				'telegram'      => [
					'enabled'   => false,
					'bot_token' => '',
					'chat_id'   => '',
				],
				'viber'         => [
					'enabled'    => false,
					'auth_token' => '',
					'receiver'   => '',
					'sender'     => [
						'name'   => 'HD Notify',
						'avatar' => '',
					],
				],
				'zalo'          => [
					'enabled'   => false,
					'bot_token' => '',
					'chat_id'   => '',
				],
			],
		],
		'on_success'           => [
			'action'         => 'message',
			'message'        => '',
			'popup_title'    => '',
			'popup_content'  => '',
			'redirect_url'   => '',
			'redirect_delay' => 0,
			'form_types'     => [],
		],
		'spam'                 => [
			'honeypot_enabled'         => true,
			'user_interaction_enabled' => true,
			'captcha_enabled'          => false,
			'akismet_enabled'          => false,
			'ip_block_enabled'         => false,
			'ip_block_list'            => [],
			'word_blacklist'           => [],
		],
		'cleanup'              => false,
		'debug'                => false,
		'email_filter'         => [
			'deny_domains'  => [],
			'allow_domains' => [],
		],
		'default_email_to'     => [],
		'default_email_cc'     => [],
		'default_email_bcc'    => [],
		'duplicate_prevention' => [
			'enabled'        => true,
			'check_mode'     => 'email_phone',
			'window_minutes' => 30,
			'action_mode'    => 'silent_success',
			'custom_message' => '',
		],
		'min_submit_time'      => 3,
		'max_render_age'       => 1800,
		'phone_vn_only'        => false,
		'workflow_statuses'    => [],
		'uploads'              => [
			'random_filename' => false,
			'allow_multiple'  => false,
		],
	];

	/** Shared cleanup retention defaults consumed by both the settings UI and the cron cleaner. */
	public const CLEANUP_DEFAULTS = [
		'trash_days'      => 30,
		'mail_queue_days' => 60,
		'log_days'        => 180,
	];

	public static function register(): void {
		add_action( 'add_option_' . self::OPTION_KEY, [ self::class, 'resetCache' ], 10, 0 );
		add_action( 'update_option_' . self::OPTION_KEY, [ self::class, 'resetCache' ], 10, 0 );
		add_action( 'delete_option_' . self::OPTION_KEY, [ self::class, 'resetCache' ], 10, 0 );

		add_action( 'after_setup_theme', [ self::class, 'clearMemoryCache' ], 1, 0 );
		add_action( 'after_setup_theme', [ self::class, 'clearMemoryCache' ], 999, 0 );
	}

	/**
	 * Get all form configurations merged with 3-tier precedence.
	 *
	 * Precedence: Admin DB Option > Theme filter > Plugin DEFAULTS.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$base = self::DEFAULTS;

		$filteredTypes = (array) apply_filters( 'hd_form_types', $base['form_types'] );
		if ( ! empty( $filteredTypes ) ) {
			foreach ( $filteredTypes as $typeKey => $typeDef ) {
				if ( is_array( $typeDef ) ) {
					$filteredTypes[ $typeKey ] = array_merge(
						[
							'email_to'  => [],
							'email_cc'  => [],
							'email_bcc' => [],
						],
						$typeDef
					);
				}
			}
			$base['form_types'] = $filteredTypes;
		}

		$themeConfig = Helper::filterSettingOptions( 'form_config' );
		if ( is_array( $themeConfig ) && ! empty( $themeConfig ) ) {
			$base = self::mergeSettings( $base, $themeConfig );
		}

		$admin = Helper::getOption( self::OPTION_KEY, [] );
		if ( is_array( $admin ) && ! empty( $admin ) ) {
			$base = self::mergeSettings( $base, $admin );
		}

		self::$cache = apply_filters( 'hd_form_config', $base );

		return self::$cache;
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback if key is not found.
	 *
	 * @return mixed
	 */
	public static function get( string $key, mixed $fallback = null ): mixed {
		return self::all()[ $key ] ?? $fallback;
	}

	/**
	 * Check whether a form type slug is registered.
	 *
	 * @param string $type Form type slug.
	 *
	 * @return bool
	 */
	public static function isRegistered( string $type ): bool {
		if ( '' === $type ) {
			return false;
		}

		return isset( static::all()['form_types'][ $type ] );
	}

	/**
	 * Get configuration for a specific form type.
	 *
	 * @param string $type Form type slug.
	 *
	 * @return array|null
	 */
	public static function getFormType( string $type ): ?array {
		$config = static::all();

		return $config['form_types'][ $type ] ?? null;
	}

	/**
	 * Get the CAPTCHA provider configured for a form type.
	 *
	 * @param string $formType Form type slug.
	 *
	 * @return string Provider slug.
	 */
	public static function getCaptchaProvider( string $formType = '' ): string {
		$config = static::all();

		if ( '' !== $formType ) {
			$formConfig = static::getFormType( $formType );
			if ( ! empty( $formConfig['captcha'] ) ) {
				return $formConfig['captcha'];
			}
		}

		return $config['captcha']['default_provider'] ?? $config['captcha']['type'] ?? 'none';
	}

	/**
	 * Get email recipients for a form type (falls back to default).
	 *
	 * @param string $formType Form type slug.
	 *
	 * @return array<string>
	 */
	public static function getEmailRecipients( string $formType ): array {
		$formConfig = static::getFormType( $formType );
		$recipients = $formConfig['email_to'] ?? [];

		if ( empty( $recipients ) ) {
			$config     = static::all();
			$recipients = ! empty( $config['default_email_to'] )
				? $config['default_email_to']
				: [ Helper::getOption( 'admin_email' ) ];
		}

		return $recipients;
	}

	/**
	 * Get CC email recipients for a form type (falls back to default_email_cc).
	 *
	 * @param string $formType Form type slug.
	 *
	 * @return array<string>
	 */
	public static function getEmailCcRecipients( string $formType ): array {
		$formConfig = static::getFormType( $formType );
		$recipients = $formConfig['email_cc'] ?? [];

		if ( empty( $recipients ) ) {
			$config     = static::all();
			$recipients = ! empty( $config['default_email_cc'] ) && is_array( $config['default_email_cc'] )
				? $config['default_email_cc']
				: [];
		}

		return is_array( $recipients ) ? $recipients : [];
	}

	/**
	 * Get BCC email recipients for a form type (falls back to default_email_bcc).
	 *
	 * @param string $formType Form type slug.
	 *
	 * @return array<string>
	 */
	public static function getEmailBccRecipients( string $formType ): array {
		$formConfig = static::getFormType( $formType );
		$recipients = $formConfig['email_bcc'] ?? [];

		if ( empty( $recipients ) ) {
			$config     = static::all();
			$recipients = ! empty( $config['default_email_bcc'] ) && is_array( $config['default_email_bcc'] )
				? $config['default_email_bcc']
				: [];
		}

		return is_array( $recipients ) ? $recipients : [];
	}

	/**
	 * Get email template for a form type.
	 *
	 * @param string $formType Form type slug.
	 *
	 * @return string Template name (without extension).
	 */
	public static function getEmailTemplate( string $formType ): string {
		$formConfig = static::getFormType( $formType );

		return $formConfig['email_template'] ?? 'default';
	}

	/**
	 * Check whether uploaded files should be given random filenames.
	 */
	public static function isRandomFilenameEnabled(): bool {
		$config = static::all();

		return ! empty( $config['uploads']['random_filename'] );
	}

	/**
	 * Check whether multiple file selection is allowed for upload fields.
	 */
	public static function isAllowMultipleUploadsEnabled(): bool {
		$config = static::all();

		return ! empty( $config['uploads']['allow_multiple'] );
	}

	/**
	 * Resolve the successful-submit action for a form type.
	 *
	 * @param string               $formType         Form type slug.
	 * @param array<string, mixed> $instanceOverride Per-form HTML override.
	 *
	 * @return array{action: string, message: string, popup_title: string, popup_content: string, redirect_url: string, redirect_delay: int}
	 */
	public static function getOnSuccess( string $formType, array $instanceOverride = [] ): array {
		$formType = sanitize_key( $formType );
		$config   = static::all();

		$formConfig   = $config['form_types'][ $formType ] ?? [];
		$typeDefault  = is_array( $formConfig['on_success'] ?? null ) ? $formConfig['on_success'] : [];
		$adminGlobal  = is_array( $config['on_success'] ?? null ) ? $config['on_success'] : [];
		$adminTypeDef = is_array( $adminGlobal['form_types'][ $formType ] ?? null ) ? $adminGlobal['form_types'][ $formType ] : [];

		$settings = self::defaultOnSuccess();
		$settings = self::mergeSettings( $settings, self::sanitizeOnSuccessOverride( $typeDefault, false ) );
		$settings = self::mergeSettings( $settings, self::sanitizeOnSuccessOverride( $adminGlobal ) );
		$settings = self::mergeSettings( $settings, self::sanitizeOnSuccessOverride( $adminTypeDef ) );
		if ( ! empty( $instanceOverride ) ) {
			$settings = self::mergeSettings( $settings, self::sanitizeOnSuccessOverride( $instanceOverride, false ) );
		}
		$normalized = self::normalizeOnSuccess( $settings );

		return (array) apply_filters( 'hd_form_on_success', $normalized, $formType, $instanceOverride );
	}

	/**
	 * Sanitize a partial on-success override.
	 *
	 * @param array<string, mixed> $settings        Raw settings.
	 * @param bool                 $emptyMeansUnset Whether blank action or URL should keep the lower-precedence value.
	 *
	 * @return array<string, string|int>
	 */
	public static function sanitizeOnSuccessOverride( array $settings, bool $emptyMeansUnset = true ): array {
		$clean = [];

		if ( array_key_exists( 'action', $settings ) ) {
			$action = sanitize_key( (string) $settings['action'] );
			if ( '' !== $action || ! $emptyMeansUnset ) {
				$clean['action'] = in_array( $action, self::successActions(), true )
					? $action
					: self::SUCCESS_ACTION_MESSAGE;
			}
		}

		if ( array_key_exists( 'message', $settings ) ) {
			$msg = sanitize_textarea_field( (string) $settings['message'] );
			if ( '' !== $msg || ! $emptyMeansUnset ) {
				$clean['message'] = $msg;
			}
		}

		if ( array_key_exists( 'popup_title', $settings ) ) {
			$title = sanitize_text_field( (string) $settings['popup_title'] );
			if ( '' !== $title || ! $emptyMeansUnset ) {
				$clean['popup_title'] = $title;
			}
		}

		if ( array_key_exists( 'popup_content', $settings ) ) {
			$content = sanitize_textarea_field( (string) $settings['popup_content'] );
			if ( '' !== $content || ! $emptyMeansUnset ) {
				$clean['popup_content'] = $content;
			}
		}

		if ( array_key_exists( 'redirect_url', $settings ) ) {
			$url = self::validateRedirectUrl( (string) $settings['redirect_url'] );
			if ( '' !== $url || ! $emptyMeansUnset ) {
				$clean['redirect_url'] = $url;
			}
		}

		if ( array_key_exists( 'redirect_delay', $settings ) ) {
			$clean['redirect_delay'] = self::normalizeRedirectDelay( $settings['redirect_delay'] );
		}

		return $clean;
	}

	/**
	 * Clear in-memory cache on `after_setup_theme` so theme extension filters
	 * (`hd_form_types`, `hd_form_config`) take effect after early `plugins_loaded` reads.
	 */
	public static function clearMemoryCache(): void {
		self::$cache = null;
	}

	/**
	 * Reset cached config (call after settings update).
	 */
	public static function resetCache(): void {
		self::clearMemoryCache();
	}

	/**
	 * Check whether workflow statuses are configured.
	 *
	 * When false, all workflow UI (column, filter, bulk action, selector, timeline, export)
	 * is hidden — the feature is dormant until admin configures at least one status.
	 *
	 * @return bool
	 */
	public static function hasWorkflowStatuses(): bool {
		return ! empty( self::getWorkflowStatuses() );
	}

	/**
	 * Get all configured workflow statuses.
	 *
	 * @return array<int, array{slug: string, label: string, color: string, position?: int}>
	 */
	public static function getWorkflowStatuses(): array {
		$statuses = self::all()['workflow_statuses'] ?? [];

		return is_array( $statuses ) ? $statuses : [];
	}

	/**
	 * Get a single workflow status by slug.
	 *
	 * @param string $slug Workflow status slug.
	 *
	 * @return array{slug: string, label: string, color: string, position?: int}|null
	 */
	public static function getWorkflowStatusBySlug( string $slug ): ?array {
		foreach ( self::getWorkflowStatuses() as $status ) {
			if ( isset( $status['slug'] ) && $status['slug'] === $slug ) {
				return $status;
			}
		}

		return null;
	}

	/**
	 * @return array{action: string, message: string, popup_title: string, popup_content: string, redirect_url: string, redirect_delay: int}
	 */
	private static function defaultOnSuccess(): array {
		return [
			'action'         => self::SUCCESS_ACTION_MESSAGE,
			'message'        => '',
			'popup_title'    => '',
			'popup_content'  => '',
			'redirect_url'   => '',
			'redirect_delay' => 0,
		];
	}

	/**
	 * @param array<string, mixed> $settings
	 *
	 * @return array{action: string, message: string, popup_title: string, popup_content: string, redirect_url: string, redirect_delay: int}
	 */
	private static function normalizeOnSuccess( array $settings ): array {
		$action = sanitize_key( (string) ( $settings['action'] ?? self::SUCCESS_ACTION_MESSAGE ) );
		if ( ! in_array( $action, self::successActions(), true ) ) {
			$action = self::SUCCESS_ACTION_MESSAGE;
		}

		$rawMessage      = trim( sanitize_textarea_field( (string) ( $settings['message'] ?? '' ) ) );
		$rawPopupTitle   = trim( sanitize_text_field( (string) ( $settings['popup_title'] ?? '' ) ) );
		$rawPopupContent = trim( sanitize_textarea_field( (string) ( $settings['popup_content'] ?? '' ) ) );
		$rawRedirectUrl  = trim( (string) ( $settings['redirect_url'] ?? '' ) );

		$defaultMessage = __( 'Thank you! We have received your information.', 'hd-form' );

		$message       = '' !== $rawMessage ? $rawMessage : $defaultMessage;
		$popupTitle    = '' !== $rawPopupTitle ? $rawPopupTitle : __( 'Thank you!', 'hd-form' );
		$popupContent  = '' !== $rawPopupContent ? $rawPopupContent : $message;
		$redirectUrl   = '' !== $rawRedirectUrl ? self::validateRedirectUrl( $rawRedirectUrl ) : '';
		$redirectDelay = self::normalizeRedirectDelay( $settings['redirect_delay'] ?? 0 );

		if ( self::SUCCESS_ACTION_REDIRECT === $action && '' === $redirectUrl ) {
			$action        = self::SUCCESS_ACTION_MESSAGE;
			$redirectDelay = 0;
		}

		if ( self::SUCCESS_ACTION_MESSAGE === $action || self::SUCCESS_ACTION_POPUP === $action ) {
			$redirectUrl = '';
		}

		return [
			'action'         => $action,
			'message'        => $message,
			'popup_title'    => $popupTitle,
			'popup_content'  => $popupContent,
			'redirect_url'   => $redirectUrl,
			'redirect_delay' => $redirectDelay,
		];
	}

	public static function validateRedirectUrl( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		if ( is_string( $scheme ) && ! in_array( strtolower( $scheme ), [ 'http', 'https' ], true ) ) {
			return '';
		}

		return esc_url_raw( (string) wp_validate_redirect( $url, '' ) );
	}

	private static function normalizeRedirectDelay( mixed $delay ): int {
		return min( self::MAX_REDIRECT_DELAY, max( 0, absint( $delay ) ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function successActions(): array {
		return [
			self::SUCCESS_ACTION_MESSAGE,
			self::SUCCESS_ACTION_POPUP,
			self::SUCCESS_ACTION_REDIRECT,
		];
	}

	private static function mergeSettings( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			// Legacy boolean duplicate_prevention compatibility: normalize into ['enabled' => bool].
			if ( 'duplicate_prevention' === $key && is_bool( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ]['enabled'] = $value;
				continue;
			}

			// Do not allow an empty list [] to wipe an associative dictionary schema.
			if (
				is_array( $value )
				&& empty( $value )
				&& isset( $base[ $key ] )
				&& is_array( $base[ $key ] )
				&& ! array_is_list( $base[ $key ] )
			) {
				continue;
			}

			if (
				is_array( $value )
				&& isset( $base[ $key ] )
				&& is_array( $base[ $key ] )
				&& ! array_is_list( $value )
				&& ! array_is_list( $base[ $key ] )
			) {
				$base[ $key ] = self::mergeSettings( $base[ $key ], $value );
				continue;
			}

			$base[ $key ] = $value;
		}

		return $base;
	}
}

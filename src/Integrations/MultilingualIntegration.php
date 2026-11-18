<?php
/**
 * Generic Multilingual Integration for HD Form.
 *
 * Provider-agnostic integration layer for the standalone HD Form plugin.
 * Supports HDE's MultilingualManager when active, or standalone Polylang (`pll_*`)
 * and WPML (`wpml_*`) APIs directly.
 *
 * @package HDForm\Integrations
 */

declare(strict_types=1);

namespace HDForm\Integrations;

use HDForm\FormConfig;
use HDForm\FormEntry;

defined( 'ABSPATH' ) || exit;

final class MultilingualIntegration {

	private const STRING_GROUP = 'hd-form';

	/**
	 * Run guard to prevent duplicate string registrations in the same request.
	 */
	private static bool $registered = false;

	/**
	 * Register filter and action hooks for form multilingual integration.
	 *
	 * Called during Plugin::boot().
	 */
	public static function init(): void {
		// Filter dynamic options query (REST API) by active language.
		add_filter( 'hd_form_dynamic_options_query', [ self::class, 'filterDynamicOptionsQuery' ], 10, 3 );

		// Attach submission language attribute to entry payload before DB insert.
		add_filter( 'hd_form_prepare_entry', [ self::class, 'attachSubmissionLanguage' ], 10, 4 );

		// Translate resolved on-success configuration dynamically for active submission language.
		add_filter( 'hd_form_on_success', [ self::class, 'translateOnSuccess' ], 10, 3 );

		// Register form configuration strings for Polylang / WPML String Translation.
		if ( is_admin() ) {
			add_action( 'admin_init', [ self::class, 'onAdminInit' ] );
		}

		add_action( 'rest_api_init', [ self::class, 'onRestApiInit' ], 25 );
		add_action( 'hd_form_settings_updated', [ self::class, 'onSettingsUpdated' ] );
	}

	/**
	 * Whether any supported multilingual provider is currently active.
	 */
	public static function isActive(): bool {
		if ( class_exists( \HDE\Integrations\Multilingual\MultilingualManager::class ) && \HDE\Integrations\Multilingual\MultilingualManager::isActive() ) {
			return true;
		}

		if ( function_exists( 'pll_current_language' ) ) {
			return true;
		}

		return defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_current_language' );
	}

	/**
	 * Hook handler for admin_init.
	 */
	public static function onAdminInit(): void {
		self::registerFormStrings();
	}

	/**
	 * Hook handler for rest_api_init.
	 */
	public static function onRestApiInit(): void {
		self::registerFormStrings();
	}

	/**
	 * Filter dynamic query parameters for category/post REST endpoints by active language.
	 *
	 * @param mixed $queryArgs Query args.
	 * @param mixed $source    Data source (e.g. 'category', 'post').
	 * @param mixed $request   REST request object.
	 *
	 * @return array<string, mixed> Filtered query args.
	 */
	public static function filterDynamicOptionsQuery( mixed $queryArgs = [], mixed $source = '', mixed $request = null ): array {
		unset( $source );
		$queryArgs = is_array( $queryArgs ) ? $queryArgs : [];

		if ( ! self::isActive() || ! $request instanceof \WP_REST_Request ) {
			return $queryArgs;
		}

		$requestLang = sanitize_key( (string) ( $request->get_param( 'lang' ) ?? $request->get_param( '_lang' ) ?? '' ) );
		$targetLang  = self::resolveLanguage( [], '' !== $requestLang ? $requestLang : null );

		if ( class_exists( \HDE\Integrations\Multilingual\MultilingualManager::class ) && \HDE\Integrations\Multilingual\MultilingualManager::isActive() ) {
			return \HDE\Integrations\Multilingual\MultilingualManager::filterQuery( $queryArgs, (string) $targetLang );
		}

		if ( null !== $targetLang && '' !== $targetLang ) {
			$queryArgs['lang'] = $targetLang;
		}

		return $queryArgs;
	}

	/**
	 * Attach current language slug to submission entry data before DB insertion.
	 *
	 * @param mixed $entry     Form entry DTO instance.
	 * @param mixed $formType  Form type slug.
	 * @param mixed $validated Validated form payload.
	 * @param mixed $input     Raw request input payload.
	 *
	 * @return mixed Filtered entry instance.
	 */
	public static function attachSubmissionLanguage( mixed $entry = null, mixed $formType = '', mixed $validated = [], mixed $input = [] ): mixed {
		unset( $formType, $validated );
		if ( ! $entry instanceof FormEntry ) {
			return $entry;
		}
		$input = is_array( $input ) ? $input : [];

		// Resolve language: explicit form input -> active page context -> default site language.
		$payload = is_array( $entry->data ) ? array_merge( $entry->data, $input ) : $input;
		$lang    = self::resolveLanguage( $payload );

		if ( null !== $lang && '' !== $lang && is_array( $entry->data ) ) {
			$newData           = $entry->data;
			$newData['__lang'] = strtolower( $lang );

			return new FormEntry(
				formType:       $entry->formType,
				formId:         $entry->formId,
				name:           $entry->name,
				email:          $entry->email,
				phone:          $entry->phone,
				phoneCountry:   $entry->phoneCountry,
				phoneNational:  $entry->phoneNational,
				ipAddress:      $entry->ipAddress,
				userAgent:      $entry->userAgent,
				refererUrl:     $entry->refererUrl,
				pageUrl:        $entry->pageUrl,
				data:           $newData,
				submissionHash: $entry->submissionHash,
				utmSource:      $entry->utmSource,
				utmMedium:      $entry->utmMedium,
				utmCampaign:    $entry->utmCampaign,
				utmTerm:        $entry->utmTerm,
				utmContent:     $entry->utmContent,
				attachments:    $entry->attachments,
				userId:         $entry->userId,
			);
		}

		return $entry;
	}

	/**
	 * Translate resolved on-success configuration (message, popup title/content, redirect_url) dynamically.
	 *
	 * @param mixed $onSuccess        Normalized on-success array.
	 * @param mixed $formType         Form type slug.
	 * @param mixed $instanceOverride Per-submission or instance override.
	 *
	 * @return array<string, mixed>
	 */
	public static function translateOnSuccess( mixed $onSuccess = [], mixed $formType = '', mixed $instanceOverride = [] ): array {
		$onSuccess        = is_array( $onSuccess ) ? $onSuccess : [];
		$formType         = is_string( $formType ) ? $formType : '';
		$instanceOverride = is_array( $instanceOverride ) ? $instanceOverride : [];

		if ( ! self::isActive() ) {
			return $onSuccess;
		}

		// Resolve target submission language: instance override -> fallback null (active request language).
		$targetLang = self::resolveLanguage( $instanceOverride );
		$cleanType  = sanitize_key( $formType );

		$config        = FormConfig::all();
		$typeOnSuccess = self::resolveTypeOnSuccess( $config, $cleanType );

		$hasTypeMessage = ! empty( $typeOnSuccess['message'] ) && is_string( $typeOnSuccess['message'] );
		$hasTypeTitle   = ! empty( $typeOnSuccess['popup_title'] ) && is_string( $typeOnSuccess['popup_title'] );
		$hasTypeContent = ! empty( $typeOnSuccess['popup_content'] ) && is_string( $typeOnSuccess['popup_content'] );
		$hasTypeUrl     = ! empty( $typeOnSuccess['redirect_url'] ) && is_string( $typeOnSuccess['redirect_url'] );

		$hasInstanceMessage = array_key_exists( 'message', $instanceOverride );
		$hasInstanceTitle   = array_key_exists( 'popup_title', $instanceOverride );
		$hasInstanceContent = array_key_exists( 'popup_content', $instanceOverride );
		$hasInstanceUrl     = array_key_exists( 'redirect_url', $instanceOverride );

		if ( ! empty( $onSuccess['message'] ) && is_string( $onSuccess['message'] ) ) {
			$name                 = $hasInstanceMessage
				? null
				: ( ( '' !== $cleanType && $hasTypeMessage ) ? "form_{$cleanType}_success_message" : 'form_global_success_message' );
			$onSuccess['message'] = self::translate( $onSuccess['message'], $targetLang, self::STRING_GROUP, $name );
		}

		if ( ! empty( $onSuccess['popup_title'] ) && is_string( $onSuccess['popup_title'] ) ) {
			$name                     = $hasInstanceTitle
				? null
				: ( ( '' !== $cleanType && $hasTypeTitle ) ? "form_{$cleanType}_popup_title" : 'form_global_popup_title' );
			$onSuccess['popup_title'] = self::translate( $onSuccess['popup_title'], $targetLang, self::STRING_GROUP, $name );
		}

		if ( ! empty( $onSuccess['popup_content'] ) && is_string( $onSuccess['popup_content'] ) ) {
			$name                       = $hasInstanceContent
				? null
				: ( ( '' !== $cleanType && $hasTypeContent ) ? "form_{$cleanType}_popup_content" : 'form_global_popup_content' );
			$onSuccess['popup_content'] = self::translate( $onSuccess['popup_content'], $targetLang, self::STRING_GROUP, $name );
		}

		if ( ! empty( $onSuccess['redirect_url'] ) && is_string( $onSuccess['redirect_url'] ) ) {
			$name                      = $hasInstanceUrl
				? null
				: ( ( '' !== $cleanType && $hasTypeUrl ) ? "form_{$cleanType}_redirect_url" : 'form_global_redirect_url' );
			$translatedUrl             = self::translate( $onSuccess['redirect_url'], $targetLang, self::STRING_GROUP, $name );
			$onSuccess['redirect_url'] = FormConfig::validateRedirectUrl( $translatedUrl );
			if ( ( $onSuccess['action'] ?? '' ) === 'redirect' && '' === $onSuccess['redirect_url'] ) {
				$onSuccess['action']         = 'message';
				$onSuccess['redirect_delay'] = 0;
			}
		}

		return $onSuccess;
	}

	/**
	 * Translate arbitrary text for the Form module with active or explicit language context.
	 *
	 * @param string      $text       Original text or fallback.
	 * @param string|null $targetLang Target language slug (or null for active context).
	 * @param string      $context    Textdomain / group context (defaults to 'hd-form').
	 * @param string|null $name       Optional registered symbolic name.
	 *
	 * @return string Translated text.
	 */
	public static function translate( string $text, ?string $targetLang = null, string $context = self::STRING_GROUP, ?string $name = null ): string {
		if ( '' === $text ) {
			return '';
		}

		if ( class_exists( \HDE\Integrations\Multilingual\MultilingualManager::class ) && \HDE\Integrations\Multilingual\MultilingualManager::isActive() ) {
			return \HDE\Integrations\Multilingual\MultilingualManager::translate( $text, $context, $targetLang, $name );
		}

		if ( null !== $targetLang && '' !== $targetLang && function_exists( 'pll_translate_string' ) ) {
			return (string) pll_translate_string( $text, $targetLang );
		}

		if ( function_exists( 'pll__' ) ) {
			return (string) pll__( $text );
		}

		if ( has_filter( 'wpml_translate_single_string' ) && null !== $name && '' !== $name ) {
			return (string) apply_filters( 'wpml_translate_single_string', $text, $context, $name, $targetLang );
		}

		return $text;
	}

	/**
	 * Resolve target language slug from request input, explicit param, or active context.
	 *
	 * @param array<string, mixed> $input        Submission or request payload.
	 * @param string|null          $explicitLang Explicit language slug.
	 *
	 * @return string|null Resolved language slug or null.
	 */
	public static function resolveLanguage( array $input = [], ?string $explicitLang = null ): ?string {
		$candidate = null;
		if ( null !== $explicitLang && '' !== $explicitLang ) {
			$candidate = sanitize_key( $explicitLang );
		} else {
			$rawPayloadLang = $input['_lang'] ?? $input['lang'] ?? '';
			$rawLang        = is_string( $rawPayloadLang ) ? $rawPayloadLang : '';
			if ( '' !== $rawLang ) {
				$candidate = sanitize_key( $rawLang );
			}
		}

		if ( class_exists( \HDE\Integrations\Multilingual\MultilingualManager::class ) && \HDE\Integrations\Multilingual\MultilingualManager::isActive() ) {
			$validLangs = \HDE\Integrations\Multilingual\MultilingualManager::languages();
			if ( null !== $candidate && '' !== $candidate ) {
				if ( empty( $validLangs ) || in_array( $candidate, $validLangs, true ) ) {
					return $candidate;
				}

				return \HDE\Integrations\Multilingual\MultilingualManager::defaultLang();
			}

			$lang = \HDE\Integrations\Multilingual\MultilingualManager::lang();
			return '' !== $lang ? $lang : null;
		}

		if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_current_language' ) ) {
			$validLangs = (array) pll_languages_list( [ 'fields' => 'slug' ] );
			if ( null !== $candidate && '' !== $candidate ) {
				if ( empty( $validLangs ) || in_array( $candidate, $validLangs, true ) ) {
					return $candidate;
				}

				return function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : null;
			}

			$current = pll_current_language( 'slug' );
			return is_string( $current ) && '' !== $current ? $current : null;
		}

		if ( has_filter( 'wpml_current_language' ) ) {
			if ( null !== $candidate && '' !== $candidate ) {
				return $candidate;
			}

			$current = apply_filters( 'wpml_current_language', null );
			return is_string( $current ) && '' !== $current ? sanitize_key( $current ) : null;
		}

		return ( null !== $candidate && '' !== $candidate ) ? $candidate : null;
	}

	/**
	 * Handle form settings update: force refresh string registrations.
	 */
	public static function onSettingsUpdated(): void {
		self::registerFormStrings( true );
	}

	/**
	 * Reset in-memory registration guard (for unit testing and dynamic reloads).
	 */
	public static function resetRegistration(): void {
		self::$registered = false;
	}

	/**
	 * Register all configured Form strings into Polylang / WPML String Translation.
	 *
	 * @param bool $force Force re-registration bypassing in-memory run guard.
	 */
	public static function registerFormStrings( bool $force = false ): void {
		if ( self::$registered && ! $force ) {
			return;
		}
		self::$registered = true;

		if ( ! self::isActive() ) {
			return;
		}

		$config = FormConfig::all();

		// 1. Global on_success defaults.
		$globalOnSuccess = $config['on_success'] ?? [];
		$globalMessage   = ! empty( $globalOnSuccess['message'] ) && is_string( $globalOnSuccess['message'] )
			? $globalOnSuccess['message']
			: __( 'Thank you! We have received your information.', 'hd-form' );
		self::registerString( 'form_global_success_message', $globalMessage, false );

		$globalPopupTitle = ! empty( $globalOnSuccess['popup_title'] ) && is_string( $globalOnSuccess['popup_title'] )
			? $globalOnSuccess['popup_title']
			: __( 'Thank you!', 'hd-form' );
		self::registerString( 'form_global_popup_title', $globalPopupTitle, false );

		$globalPopupContent = ! empty( $globalOnSuccess['popup_content'] ) && is_string( $globalOnSuccess['popup_content'] )
			? $globalOnSuccess['popup_content']
			: $globalMessage;
		self::registerString( 'form_global_popup_content', $globalPopupContent, true );

		if ( ! empty( $globalOnSuccess['redirect_url'] ) && is_string( $globalOnSuccess['redirect_url'] ) ) {
			self::registerString( 'form_global_redirect_url', $globalOnSuccess['redirect_url'], false );
		}

		// 2. Per-form-type labels and overrides.
		$formTypes = $config['form_types'] ?? [];
		if ( is_array( $formTypes ) ) {
			foreach ( $formTypes as $slug => $def ) {
				$slug = sanitize_key( (string) $slug );
				if ( '' === $slug || ! is_array( $def ) ) {
					continue;
				}

				if ( ! empty( $def['label'] ) && is_string( $def['label'] ) ) {
					self::registerString( "form_{$slug}_label", $def['label'], false );
				}

				$typeOnSuccess = self::resolveTypeOnSuccess( $config, $slug );

				if ( ! empty( $typeOnSuccess['message'] ) && is_string( $typeOnSuccess['message'] ) ) {
					self::registerString( "form_{$slug}_success_message", $typeOnSuccess['message'], false );
				}
				if ( ! empty( $typeOnSuccess['popup_title'] ) && is_string( $typeOnSuccess['popup_title'] ) ) {
					self::registerString( "form_{$slug}_popup_title", $typeOnSuccess['popup_title'], false );
				}
				if ( ! empty( $typeOnSuccess['popup_content'] ) && is_string( $typeOnSuccess['popup_content'] ) ) {
					self::registerString( "form_{$slug}_popup_content", $typeOnSuccess['popup_content'], true );
				}
				if ( ! empty( $typeOnSuccess['redirect_url'] ) && is_string( $typeOnSuccess['redirect_url'] ) ) {
					self::registerString( "form_{$slug}_redirect_url", $typeOnSuccess['redirect_url'], false );
				}
			}
		}

		// 3. Spam & Duplicate Prevention custom error message.
		$dupeConfig = $config['duplicate_prevention'] ?? [];
		if ( ! empty( $dupeConfig['custom_message'] ) && is_string( $dupeConfig['custom_message'] ) ) {
			self::registerString( 'form_duplicate_custom_message', $dupeConfig['custom_message'], false );
		}
	}

	/**
	 * Register a single string with active multilingual provider.
	 *
	 * @param string $name      Symbolic name.
	 * @param string $value     Default string value.
	 * @param bool   $multiline Whether string spans multiple lines.
	 */
	private static function registerString( string $name, string $value, bool $multiline = false ): void {
		if ( '' === $value ) {
			return;
		}

		if ( class_exists( \HDE\Integrations\Multilingual\MultilingualManager::class ) && \HDE\Integrations\Multilingual\MultilingualManager::isActive() ) {
			\HDE\Integrations\Multilingual\MultilingualManager::registerString( $name, $value, self::STRING_GROUP, $multiline );
			return;
		}

		if ( function_exists( 'pll_register_string' ) ) {
			pll_register_string( $name, $value, self::STRING_GROUP, $multiline );
			return;
		}

		if ( has_action( 'wpml_register_single_string' ) ) {
			do_action( 'wpml_register_single_string', self::STRING_GROUP, $name, $value );
		}
	}

	/**
	 * Resolve merged on_success configuration for a specific form type.
	 *
	 * @param array<string, mixed> $config Form config.
	 * @param string               $slug   Form type slug.
	 *
	 * @return array<string, mixed>
	 */
	private static function resolveTypeOnSuccess( array $config, string $slug ): array {
		$globalOnSuccess = is_array( $config['on_success'] ?? null ) ? $config['on_success'] : [];
		$typeDef         = is_array( $config['form_types'][ $slug ] ?? null ) ? $config['form_types'][ $slug ] : [];
		$codeDefaults    = is_array( $typeDef['on_success'] ?? null ) ? $typeDef['on_success'] : [];
		$adminSettings   = is_array( $globalOnSuccess['form_types'][ $slug ] ?? null ) ? $globalOnSuccess['form_types'][ $slug ] : [];

		return array_merge( $codeDefaults, $adminSettings );
	}
}

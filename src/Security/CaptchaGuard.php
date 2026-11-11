<?php
/**
 * CAPTCHA Guard Factory
 *
 * Resolves the correct CaptchaGuardInterface implementation
 * based on form_type config or data-* fallback from HTML attributes.
 *
 * @package HDForm\Security
 */

declare(strict_types=1);

namespace HDForm\Security;

use HDForm\FormConfig;

defined( 'ABSPATH' ) || exit;

final class CaptchaGuard {

	private const PROVIDERS = [ 'recaptcha_v2', 'recaptcha_v3', 'turnstile' ];

	/**
	 * Build a CaptchaGuard from the config registry.
	 *
	 * Falls back to data-* attributes when the form_type is NOT
	 * registered in form_config → form_types.
	 *
	 * @param string      $formType Form type slug.
	 * @param string|null $provider Override provider (from data-captcha attribute).
	 *
	 * @return CaptchaGuardInterface
	 */
	public static function make( string $formType = '', ?string $provider = null ): CaptchaGuardInterface {
		$provider ??= FormConfig::getCaptchaProvider( $formType );
		if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
			return new NullGuard();
		}

		$config         = FormConfig::all()['captcha'] ?? [];
		$providerConfig = is_array( $config[ $provider ] ?? null ) ? $config[ $provider ] : [];

		// A half-configured provider cannot work end-to-end (the frontend would
		// render no widget while the server rejects every submission) — treat it
		// as disabled instead of bricking the form.
		if ( empty( $providerConfig['site_key'] ) || empty( $providerConfig['secret_key'] ) ) {
			return new NullGuard();
		}

		$failOpen = ! empty( $config['fail_open_on_network_error'] );

		return match ( $provider ) {
			'recaptcha_v2' => new RecaptchaV2Guard(
				(string) $providerConfig['site_key'],
				(string) $providerConfig['secret_key'],
				$failOpen,
			),
			'recaptcha_v3' => new RecaptchaV3Guard(
				(string) $providerConfig['site_key'],
				(string) $providerConfig['secret_key'],
				(float) ( $providerConfig['score_threshold'] ?? 0.5 ),
				(string) ( $providerConfig['action'] ?? 'form_submit' ),
				$failOpen,
			),
			default => new TurnstileGuard(
				(string) $providerConfig['site_key'],
				(string) $providerConfig['secret_key'],
				$failOpen,
			),
		};
	}

	/**
	 * Frontend projection of the active CAPTCHA provider for hdConfig.captcha.
	 *
	 * @param string $formType Form type slug.
	 *
	 * @return array{provider: string, siteKey: string}|null Null when CAPTCHA is inactive.
	 */
	public static function frontendConfig( string $formType = '' ): ?array {
		if ( self::make( $formType ) instanceof NullGuard ) {
			return null;
		}

		$provider       = FormConfig::getCaptchaProvider( $formType );
		$providerConfig = FormConfig::all()['captcha'][ $provider ] ?? [];

		return [
			'provider' => $provider,
			'siteKey'  => (string) ( is_array( $providerConfig ) ? ( $providerConfig['site_key'] ?? '' ) : '' ),
		];
	}
}

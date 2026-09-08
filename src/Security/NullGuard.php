<?php
/**
 * Null CAPTCHA Guard — always passes (dev/testing or forms with no CAPTCHA)
 *
 * @package HDForm\Security
 */

declare(strict_types=1);

namespace HDForm\Security;

defined( 'ABSPATH' ) || exit;

final class NullGuard implements CaptchaGuardInterface {

	/** @inheritDoc */
	public function verify( string $token, string $ip ): bool {
		return true;
	}

	/** @inheritDoc */
	public function renderField(): string {
		return '';
	}

	/** @inheritDoc */
	public function getScriptUrl(): string {
		return '';
	}
}

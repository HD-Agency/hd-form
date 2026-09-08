<?php
/**
 * Spam Checker - local checks plus Akismet integration.
 *
 * @package HDForm\Security
 */

declare(strict_types=1);

namespace HDForm\Security;

use HDForm\Compat\Helper;
use HDForm\FormEntry;
use HDForm\FormConfig;

defined( 'ABSPATH' ) || exit;

final class SpamChecker {
	private static ?string $disallowedSource = null;

	/**
	 * @var array{plain: array<int, string>, urls: array<int, string>}
	 */
	private static array $disallowedRules = [
		'plain' => [],
		'urls'  => [],
	];

	/**
	 * Check whether a form entry is spam.
	 *
	 * @return array<array{agent: string, reason: string}>
	 */
	public static function check( FormEntry $entry, ?string $override = null ): array {
		$reasons = self::checkCheap( $entry, $override );
		if ( $reasons ) {
			return $reasons;
		}

		return self::checkAkismet( $entry, $override );
	}

	/**
	 * Run cheap local spam checks only.
	 *
	 * @return array<array{agent: string, reason: string}>
	 */
	public static function checkCheap( FormEntry $entry, ?string $override = null ): array {
		if ( ! self::isEnabled( $entry, $override ) ) {
			return [];
		}

		$reasons = [];

		if ( strlen( $entry->userAgent ) < 2 ) {
			$reasons[] = [
				'agent'  => 'user_agent',
				'reason' => 'User-Agent string is unnaturally short.',
			];
		}

		if ( self::hasDisallowedWords( $entry ) ) {
			$reasons[] = [
				'agent'  => 'disallowed_list',
				'reason' => 'Disallowed words are used.',
			];
		}

		return $reasons;
	}

	/**
	 * Run the remote Akismet check only.
	 *
	 * @return array<array{agent: string, reason: string}>
	 */
	public static function checkAkismet( FormEntry $entry, ?string $override = null ): array {
		if ( ! self::isEnabled( $entry, $override ) ) {
			return [];
		}

		if ( self::isAkismetAvailable() && self::checkWithAkismet( $entry ) ) {
			return [
				[
					'agent'  => 'akismet',
					'reason' => 'Akismet classified as spam.',
				],
			];
		}

		return [];
	}

	private static function isEnabled( FormEntry $entry, ?string $override = null ): bool {
		if ( null !== $override ) {
			return filter_var( $override, FILTER_VALIDATE_BOOLEAN );
		}

		$formConfig   = FormConfig::getFormType( $entry->formType );
		$globalConfig = FormConfig::all();

		return (bool) ( $formConfig['spam_check'] ?? $globalConfig['spam_check'] ?? true );
	}

	private static function hasDisallowedWords( FormEntry $entry ): bool {
		$rules = self::disallowedRules();
		if ( ! $rules['plain'] && ! $rules['urls'] ) {
			return false;
		}

		$parts = [
			$entry->name,
			$entry->email,
			$entry->phone,
			$entry->ipAddress,
			$entry->userAgent,
		];

		foreach ( $entry->data as $value ) {
			if ( is_string( $value ) ) {
				$parts[] = $value;
			}
		}

		$target      = implode( "\n", array_filter( $parts ) );
		$targetLower = strtolower( $target );

		foreach ( $rules['urls'] as $needle ) {
			if ( str_contains( $targetLower, $needle ) ) {
				return true;
			}
		}

		foreach ( $rules['plain'] as $pattern ) {
			if ( preg_match( $pattern, $target ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array{plain: array<int, string>, urls: array<int, string>}
	 */
	private static function disallowedRules(): array {
		$source = trim( (string) Helper::getOption( 'disallowed_keys', '' ) );
		if ( self::$disallowedSource === $source ) {
			return self::$disallowedRules;
		}

		$rules = [
			'plain' => [],
			'urls'  => [],
		];

		foreach ( explode( "\n", $source ) as $word ) {
			$word   = trim( $word );
			$length = strlen( $word );

			if ( $length < 2 || $length > 256 ) {
				continue;
			}

			if ( self::isUrlLikePattern( $word ) ) {
				$rules['urls'][] = strtolower( $word );
				continue;
			}

			$quoted           = preg_quote( $word, '#' );
			$rules['plain'][] = '#(?<![\pL\pN_])' . $quoted . '(?![\pL\pN_])#iu';
		}

		self::$disallowedSource = $source;
		self::$disallowedRules  = $rules;

		return $rules;
	}

	private static function isUrlLikePattern( string $word ): bool {
		return str_contains( $word, '://' )
			|| str_contains( $word, 'www.' )
			|| str_contains( $word, '/' )
			|| (bool) preg_match( '#[a-z0-9-]+\.[a-z]{2,}#i', $word );
	}

	private static function isAkismetAvailable(): bool {
		if ( ! class_exists( 'Akismet' ) ) {
			return false;
		}

		$key = \Akismet::get_api_key();

		return ! empty( $key );
	}

	private static function checkWithAkismet( FormEntry $entry ): bool {
		$params = [
			'blog'                 => home_url(),
			'user_ip'              => $entry->ipAddress,
			'user_agent'           => $entry->userAgent,
			'referrer'             => $entry->refererUrl,
			'comment_type'         => 'contact-form',
			'comment_author'       => $entry->name,
			'comment_author_email' => $entry->email,
			'comment_content'      => $entry->data['message'] ?? '',
		];

		$response = \Akismet::http_post(
			\Akismet::build_query( $params ),
			'comment-check'
		);

		if ( ! is_array( $response ) || empty( $response[1] ) ) {
			return false;
		}

		return 'true' === trim( $response[1] );
	}
}

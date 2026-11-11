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
	 * Reset static cache for CLI or long-running workers.
	 */
	public static function resetCache(): void {
		self::$disallowedSource = null;
		self::$disallowedRules  = [
			'plain' => [],
			'urls'  => [],
		];
	}

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
		if ( ! self::isEnabled( $entry, $override ) || ! self::isAkismetEnabled( $entry ) ) {
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

	/**
	 * Check if client IP matches any entry in the blocklist (exact, wildcard *, or CIDR /prefix).
	 *
	 * @param string             $ip        Client IP address.
	 * @param array<int, string> $blockList List of blocked IP rules.
	 */
	public static function isBlockedIp( string $ip, array $blockList ): bool {
		$ip = trim( $ip );
		if ( '' === $ip || empty( $blockList ) ) {
			return false;
		}

		foreach ( $blockList as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' === $rule ) {
				continue;
			}

			if ( strcasecmp( $ip, $rule ) === 0 ) {
				return true;
			}

			if ( str_contains( $rule, '*' ) || str_contains( $rule, '?' ) ) {
				if ( fnmatch( $rule, $ip, FNM_CASEFOLD ) ) {
					return true;
				}
				continue;
			}

			if ( str_contains( $rule, '/' ) && self::ipMatchesCidr( $ip, $rule ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if any string value in validated fields contains a blacklisted word/pattern.
	 *
	 * @param array<string, mixed> $validated     Validated payload.
	 * @param array<int, string>   $wordBlacklist Word blacklist entries.
	 */
	public static function hasBlacklistedWord( array $validated, array $wordBlacklist ): bool {
		if ( empty( $wordBlacklist ) ) {
			return false;
		}

		$values  = [];
		$flatten = static function ( mixed $val, callable $recurse ) use ( &$values ): void {
			if ( is_array( $val ) ) {
				foreach ( $val as $v ) {
					$recurse( $v, $recurse );
				}
			} elseif ( is_string( $val ) && '' !== trim( $val ) ) {
				$values[] = strtolower( trim( $val ) );
			}
		};

		$userPayload = [
			'name'   => $validated['name'] ?? '',
			'email'  => $validated['email'] ?? '',
			'phone'  => $validated['phone'] ?? '',
			'fields' => $validated['fields'] ?? [],
		];

		$flatten( $userPayload, $flatten );

		if ( empty( $values ) ) {
			return false;
		}

		$combinedText = implode( "\n", $values );

		foreach ( $wordBlacklist as $word ) {
			$word = strtolower( trim( (string) $word ) );
			if ( '' === $word ) {
				continue;
			}

			$hasWildcard = str_contains( $word, '*' ) || str_contains( $word, '?' );

			if ( $hasWildcard ) {
				foreach ( $values as $val ) {
					if ( fnmatch( $word, $val, FNM_CASEFOLD ) ) {
						return true;
					}
				}
			} elseif ( str_contains( $combinedText, $word ) ) {
				return true;
			}
		}

		return false;
	}

	private static function ipMatchesCidr( string $ip, string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );
		if ( 2 !== count( $parts ) || '' === $parts[0] || ! ctype_digit( $parts[1] ) ) {
			return false;
		}

		$subnetBin = filter_var( $parts[0], FILTER_VALIDATE_IP ) ? inet_pton( $parts[0] ) : false;
		$ipBin     = filter_var( $ip, FILTER_VALIDATE_IP ) ? inet_pton( $ip ) : false;

		if ( false === $subnetBin || false === $ipBin || strlen( $subnetBin ) !== strlen( $ipBin ) ) {
			return false;
		}

		$prefix  = (int) $parts[1];
		$maxBits = strlen( $ipBin ) * 8;
		if ( $prefix < 0 || $prefix > $maxBits ) {
			return false;
		}

		$fullBytes = intdiv( $prefix, 8 );
		$remBits   = $prefix % 8;

		if ( $fullBytes > 0 && substr( $ipBin, 0, $fullBytes ) !== substr( $subnetBin, 0, $fullBytes ) ) {
			return false;
		}

		if ( $remBits > 0 ) {
			$mask = ( 0xFF << ( 8 - $remBits ) ) & 0xFF;
			if ( ( ord( $ipBin[ $fullBytes ] ) & $mask ) !== ( ord( $subnetBin[ $fullBytes ] ) & $mask ) ) {
				return false;
			}
		}

		return true;
	}

	private static function isEnabled( FormEntry $entry, ?string $override = null ): bool {
		if ( null !== $override ) {
			return filter_var( $override, FILTER_VALIDATE_BOOLEAN );
		}

		$formConfig   = FormConfig::getFormType( $entry->formType );
		$globalConfig = FormConfig::all();

		return (bool) ( $formConfig['spam_check'] ?? $globalConfig['spam_check'] ?? true );
	}

	private static function isAkismetEnabled( FormEntry $entry ): bool {
		$formConfig   = FormConfig::getFormType( $entry->formType );
		$globalConfig = FormConfig::all();

		$akismetEnabled = $formConfig['spam']['akismet_enabled']
			?? $globalConfig['spam']['akismet_enabled']
			?? true;

		return (bool) $akismetEnabled;
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

		$extractStrings = static function ( mixed $val, callable $recurse ) use ( &$parts ): void {
			if ( is_array( $val ) ) {
				foreach ( $val as $subVal ) {
					$recurse( $subVal, $recurse );
				}
			} elseif ( is_string( $val ) && '' !== trim( $val ) ) {
				$parts[] = $val;
			}
		};

		foreach ( $entry->data as $key => $value ) {
			if ( str_starts_with( (string) $key, '__' ) ) {
				continue;
			}
			$extractStrings( $value, $extractStrings );
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
		$content = (string) ( $entry->data['message'] ?? '' );

		// Append extra text fields so Akismet analyzes the full submission content.
		$extraParts   = [];
		$extractExtra = static function ( mixed $val, callable $recurse ) use ( &$extraParts ): void {
			if ( is_array( $val ) ) {
				foreach ( $val as $subVal ) {
					$recurse( $subVal, $recurse );
				}
			} elseif ( is_scalar( $val ) && '' !== trim( (string) $val ) ) {
				$extraParts[] = (string) $val;
			}
		};

		foreach ( $entry->data as $key => $val ) {
			if ( 'message' === $key || str_starts_with( (string) $key, '__' ) ) {
				continue;
			}
			$extractExtra( $val, $extractExtra );
		}

		if ( ! empty( $extraParts ) ) {
			$content = trim( $content . "\n\n" . implode( "\n", $extraParts ) );
		}

		$params = [
			'blog'                 => home_url(),
			'blog_lang'            => get_locale(),
			'blog_charset'         => (string) Helper::getOption( 'blog_charset', 'UTF-8' ),
			'user_ip'              => $entry->ipAddress,
			'user_agent'           => $entry->userAgent,
			'referrer'             => $entry->refererUrl ?: home_url(),
			'permalink'            => $entry->pageUrl ?: home_url(),
			'comment_type'         => 'contact-form',
			'comment_author'       => $entry->name,
			'comment_author_email' => $entry->email,
			'comment_content'      => $content,
		];

		// Merge safe HTTP header variables for Akismet analysis (exclude server filesystem paths & secrets).
		$ignoredHeaders = [ 'HTTP_COOKIE', 'HTTP_COOKIE2', 'HTTP_AUTHORIZATION' ];
		foreach ( $_SERVER as $k => $v ) {
			if ( ! is_string( $k ) || ! is_scalar( $v ) || isset( $params[ $k ] ) ) {
				continue;
			}

			$isHttpHeader = str_starts_with( $k, 'HTTP_' ) && ! in_array( $k, $ignoredHeaders, true );
			$isSafeMeta   = in_array( $k, [ 'REMOTE_ADDR', 'REQUEST_URI' ], true );

			if ( $isHttpHeader || $isSafeMeta ) {
				$params[ $k ] = (string) $v;
			}
		}

		$params = apply_filters( 'hd_form_akismet_params', $params, $entry );

		$response = \Akismet::http_post(
			\Akismet::build_query( (array) $params ),
			'comment-check'
		);

		if ( ! is_array( $response ) || empty( $response[1] ) ) {
			return false;
		}

		return 'true' === trim( (string) $response[1] );
	}
}

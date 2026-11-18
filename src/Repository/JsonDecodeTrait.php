<?php
/**
 * JSON Decode Trait for Repositories.
 *
 * Consolidates the identical decodeJsonArray helper that was duplicated
 * across FormEntryRepository, FormLogRepository, and MailQueueRepository.
 *
 * @package HDForm\Repository
 */

declare(strict_types=1);

namespace HDForm\Repository;

use HDForm\Compat\Helper;

defined( 'ABSPATH' ) || exit;

trait JsonDecodeTrait {

	/**
	 * Safely decode a JSON string into an associative array.
	 *
	 * Logs malformed JSON via Helper::errorLog and returns [] on failure.
	 *
	 * @param mixed  $json    Raw JSON value from the database.
	 * @param string $context Diagnostic label for error logging (e.g. 'entry.data.42').
	 *
	 * @return array<string, mixed>
	 */
	private static function decodeJsonArray( mixed $json, string $context ): array {
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return [];
		}

		try {
			$decoded = json_decode( $json, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $exception ) {
			Helper::errorLog(
				sprintf(
					'HD %s JSON decode failed for %s: %s',
					static::class,
					$context,
					$exception->getMessage()
				)
			);

			return [];
		}

		return is_array( $decoded ) ? $decoded : [];
	}
}

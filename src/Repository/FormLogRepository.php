<?php
/**
 * Form Log Repository
 *
 * @package HDForm\Repository
 */

declare(strict_types=1);

namespace HDForm\Repository;

use HDForm\Compat\DB;
use HDForm\Schema;

defined( 'ABSPATH' ) || exit;

class FormLogRepository {
	use JsonDecodeTrait;

	private const TABLE = Schema::TABLE_LOGS;

	/**
	 * Log a form event.
	 *
	 * @param int    $entryId   Entry ID.
	 * @param string $event     Event type.
	 * @param string $message   Log message.
	 * @param array  $context   Extra context.
	 * @param string $actor     Actor identifier.
	 * @param string $ipAddress Client IP.
	 *
	 * @return int|\WP_Error Insert ID on success.
	 */
	public function log( int $entryId, string $event, string $message, array $context = [], string $actor = 'system', string $ipAddress = '' ): int|\WP_Error {
		$data = [
			'entry_id'   => $entryId,
			'event'      => $event,
			'message'    => $message,
			'context'    => wp_json_encode( $context ),
			'actor'      => $actor,
			'ip_address' => $ipAddress,
			'created_at' => current_time( 'mysql' ),
		];

		return DB::insertOneRow( self::TABLE, $data );
	}

	/**
	 * Get logs for a specific entry.
	 *
	 * @param int $entryId Entry ID.
	 *
	 * @return array
	 */
	public function findByEntryId( int $entryId ): array {
		$results = DB::getRows(
			self::TABLE,
			[ 'entry_id' => $entryId ],
			1,
			100,
			'created_at',
			'DESC'
		);

		if ( is_array( $results ) ) {
			foreach ( $results as &$result ) {
				if ( isset( $result['context'] ) ) {
					$result['context'] = self::decodeJsonArray( $result['context'], 'log.context.' . ( $result['id'] ?? 'unknown' ) );
				}
			}
			unset( $result );
		}

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Get logs with pagination and filters.
	 *
	 * @param array $filters Filters (event, actor, entry_id).
	 * @param int   $page    Current page.
	 * @param int   $perPage Items per page.
	 *
	 * @return array
	 */
	public function findAll( array $filters = [], int $page = 1, int $perPage = 50 ): array {
		$table  = DB::tableNameFull( self::TABLE );
		$offset = ( $page - 1 ) * $perPage;
		$where  = [];
		$params = [];

		if ( ! empty( $filters['event'] ) ) {
			$where[]  = '`event` = %s';
			$params[] = (string) $filters['event'];
		}

		if ( ! empty( $filters['actor'] ) ) {
			$where[]  = '`actor` = %s';
			$params[] = (string) $filters['actor'];
		}

		if ( ! empty( $filters['entry_id'] ) ) {
			$where[]  = '`entry_id` = %d';
			$params[] = (int) $filters['entry_id'];
		}

		$whereSql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$sql      = "SELECT * FROM {$table} {$whereSql} ORDER BY `created_at` DESC LIMIT %d OFFSET %d";
		$params[] = $perPage;
		$params[] = $offset;

		$prepared = DB::db()->prepare( $sql, ...$params );
		$results  = DB::db()->get_results( $prepared, ARRAY_A );

		if ( null === $results && str_contains( strtolower( (string) DB::db()->last_error ), "doesn't exist" ) ) {
			Schema::install();
			$results = DB::db()->get_results( $prepared, ARRAY_A );
		}

		if ( is_array( $results ) ) {
			foreach ( $results as &$result ) {
				if ( isset( $result['context'] ) ) {
					$result['context'] = self::decodeJsonArray( $result['context'], 'log.context.' . ( $result['id'] ?? 'unknown' ) );
				}
			}
			unset( $result );
		}

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Count all logs.
	 *
	 * @param array $filters Filters (event, actor, entry_id).
	 *
	 * @return int
	 */
	public function countAll( array $filters = [] ): int {
		$table  = DB::tableNameFull( self::TABLE );
		$where  = [];
		$params = [];

		if ( ! empty( $filters['event'] ) ) {
			$where[]  = '`event` = %s';
			$params[] = (string) $filters['event'];
		}

		if ( ! empty( $filters['actor'] ) ) {
			$where[]  = '`actor` = %s';
			$params[] = (string) $filters['actor'];
		}

		if ( ! empty( $filters['entry_id'] ) ) {
			$where[]  = '`entry_id` = %d';
			$params[] = (int) $filters['entry_id'];
		}

		$whereSql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$sql      = "SELECT COUNT(*) FROM {$table} {$whereSql}";
		if ( $params ) {
			$sql = DB::db()->prepare( $sql, ...$params );
		}

		$result = DB::db()->get_var( $sql );

		if ( null === $result && str_contains( strtolower( (string) DB::db()->last_error ), "doesn't exist" ) ) {
			Schema::install();
			$result = DB::db()->get_var( $sql );
		}

		return is_numeric( $result ) ? (int) $result : 0;
	}
}

<?php
/**
 * Form Entry Repository
 *
 * @package HDForm\Repository
 */

declare(strict_types=1);

namespace HDForm\Repository;

use HDForm\Compat\DB;
use HDForm\FileUploadHandler;
use HDForm\FormEntry;
use HDForm\FormEntryStatus;
use HDForm\Schema;

defined( 'ABSPATH' ) || exit;

class FormEntryRepository {
	use JsonDecodeTrait;

	private const TABLE = Schema::TABLE_ENTRIES;

	/**
	 * Insert a new form entry.
	 *
	 * @param FormEntry $entry The form entry DTO.
	 *
	 * @return int|\WP_Error Insert ID on success.
	 */
	public function insert( FormEntry $entry ): int|\WP_Error {
		$data = [
			'form_type'       => $entry->formType,
			'form_id'         => $entry->formId,
			'status'          => FormEntryStatus::New->value,
			'name'            => $entry->name,
			'email'           => $entry->email,
			'phone'           => $entry->phone,
			'phone_country'   => $entry->phoneCountry,
			'phone_national'  => $entry->phoneNational,
			'ip_address'      => $entry->ipAddress,
			'user_agent'      => $entry->userAgent,
			'referer_url'     => $entry->refererUrl,
			'page_url'        => $entry->pageUrl,
			'utm_source'      => $entry->utmSource,
			'utm_medium'      => $entry->utmMedium,
			'utm_campaign'    => $entry->utmCampaign,
			'utm_term'        => $entry->utmTerm,
			'utm_content'     => $entry->utmContent,
			'data'            => wp_json_encode( $entry->data ),
			'workflow_status' => '',
			'user_id'         => $entry->userId,
			'created_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
		];

		if ( '' !== $entry->submissionHash ) {
			$data['submission_hash'] = $entry->submissionHash;
		}

		$result = DB::insertOneRow( self::TABLE, $data );
		if ( is_wp_error( $result ) && $this->isDuplicateSubmissionError( $result ) ) {
			return new \WP_Error( 'duplicate_submission', __( 'Duplicate submission.', 'hd-form' ) );
		}

		return $result;
	}

	/**
	 * Find an entry by ID (raw array for admin pages).
	 *
	 * @param int $id Entry ID.
	 *
	 * @return array|null
	 */
	public function findById( int $id ): ?array {
		$result = DB::getOneWhere( self::TABLE, [ 'id' => $id ] );
		if ( is_wp_error( $result ) ) {
			return null;
		}

		if ( $result && isset( $result['data'] ) ) {
			$result['data'] = self::decodeJsonArray( $result['data'], 'entry.data.' . $id );
		}

		return $result;
	}

	/**
	 * Find an entry by ID as a typed DTO.
	 *
	 * @param int $id Entry ID.
	 *
	 * @return FormEntry|null
	 */
	public function findAsDto( int $id ): ?FormEntry {
		$row = $this->findById( $id );
		if ( null === $row ) {
			return null;
		}

		return FormEntry::fromRow( $row );
	}

	/**
	 * Get entries with pagination and filters.
	 *
	 * @param array  $filters Available: 'status', 'form_type', 'workflow_status', 'search', etc.
	 * @param int    $page    Current page.
	 * @param int    $perPage Items per page.
	 * @param string $orderBy Column to order by.
	 * @param string $order   ASC or DESC.
	 *
	 * @return array|\WP_Error
	 */
	public function findAll( array $filters = [], int $page = 1, int $perPage = 20, string $orderBy = 'id', string $order = 'DESC' ): array|\WP_Error {
		$table               = DB::tableNameFull( self::TABLE );
		$page                = max( 1, $page );
		$perPage             = max( 1, min( 500, $perPage ) );
		[ $whereSql, $args ] = self::buildListWhere( $filters );

		$allowed = [ 'id', 'name', 'email', 'created_at', 'form_type', 'status', 'workflow_status' ];
		$orderBy = in_array( $orderBy, $allowed, true ) ? $orderBy : 'id';
		$order   = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		$offset      = ( $page - 1 ) * $perPage;
		$orderClause = "`{$orderBy}` {$order}";

		$sql    = "SELECT * FROM {$table} {$whereSql} ORDER BY {$orderClause} LIMIT %d OFFSET %d";
		$args[] = $perPage;
		$args[] = $offset;

		$prepared = DB::db()->prepare( $sql, ...$args );
		$results  = DB::db()->get_results( $prepared, ARRAY_A );

		if ( is_array( $results ) ) {
			foreach ( $results as &$result ) {
				if ( isset( $result['data'] ) ) {
					$result['data'] = self::decodeJsonArray( $result['data'], 'entry.data.' . ( $result['id'] ?? 'unknown' ) );
				}
			}
			unset( $result );
		}

		return $results ?: [];
	}

	/**
	 * Get entries strictly older than the given ID (keyset pagination).
	 *
	 * Unlike LIMIT/OFFSET paging this stays correct under concurrent inserts,
	 * which is what long-running exports need.
	 *
	 * @param array $filters Filters.
	 * @param int   $lastId  Highest ID of the previous batch (0 → empty).
	 * @param int   $limit   Batch size.
	 *
	 * @return array|\WP_Error
	 */
	public function findAllBeforeId( array $filters, int $lastId, int $limit = 500 ): array|\WP_Error {
		if ( $lastId <= 0 ) {
			return [];
		}

		$limit               = max( 1, min( 5000, $limit ) );
		$table               = DB::tableNameFull( self::TABLE );
		[ $whereSql, $args ] = self::buildListWhere( $filters );

		$whereSql = '' !== $whereSql ? $whereSql . ' AND `id` < %d' : 'WHERE `id` < %d';
		$args[]   = $lastId;

		$sql    = "SELECT * FROM {$table} {$whereSql} ORDER BY `id` DESC LIMIT %d";
		$args[] = $limit;

		$prepared = DB::db()->prepare( $sql, ...$args );
		$results  = DB::db()->get_results( $prepared, ARRAY_A );

		if ( is_array( $results ) ) {
			foreach ( $results as &$result ) {
				if ( isset( $result['data'] ) ) {
					$result['data'] = self::decodeJsonArray( $result['data'], 'entry.data.' . ( $result['id'] ?? 'unknown' ) );
				}
			}
			unset( $result );
		}

		return $results ?: [];
	}

	/**
	 * Bulk update status for multiple entries.
	 *
	 * @param array  $ids    Entry IDs.
	 * @param string $status New status.
	 *
	 * @return int Number of affected rows.
	 */
	public function bulkUpdateStatus( array $ids, string $status ): int {
		if ( empty( $ids ) ) {
			return 0;
		}

		$status = FormEntryStatus::fromRaw( $status );
		if ( null === $status ) {
			return 0;
		}

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = DB::tableNameFull( self::TABLE );

		$isSpam   = FormEntryStatus::Spam === $status ? 1 : 0;
		$sql      = "UPDATE {$table} SET `status` = %s, `is_spam` = %d, `updated_at` = %s WHERE `id` IN ($placeholders)";
		$prepared = DB::db()->prepare( $sql, $status->value, $isSpam, current_time( 'mysql' ), ...$ids );

		$result = DB::db()->query( $prepared );

		return false !== $result ? (int) $result : 0;
	}

	/**
	 * Update status for a specific entry.
	 *
	 * @param int    $id     Entry ID.
	 * @param string $status New status.
	 *
	 * @return bool
	 */
	public function updateStatus( int $id, string $status ): bool {
		$status = FormEntryStatus::fromRaw( $status );
		if ( null === $status ) {
			return false;
		}

		$result = DB::updateOneRow(
			self::TABLE,
			$id,
			[
				'status'     => $status->value,
				'updated_at' => current_time( 'mysql' ),
			]
		);

		return (bool) $result;
	}

	/**
	 * Update admin notes for a specific entry.
	 *
	 * @param int    $id    Entry ID.
	 * @param string $notes Notes content.
	 *
	 * @return bool
	 */
	public function updateNotes( int $id, string $notes ): bool {
		$result = DB::updateOneRow(
			self::TABLE,
			$id,
			[
				'notes'      => $notes,
				'updated_at' => current_time( 'mysql' ),
			]
		);

		return (bool) $result;
	}

	/**
	 * Update workflow status for a specific entry.
	 *
	 * @param int    $id             Entry ID.
	 * @param string $workflowStatus New workflow status slug.
	 *
	 * @return bool
	 */
	public function updateWorkflowStatus( int $id, string $workflowStatus ): bool {
		$result = DB::updateOneRow(
			self::TABLE,
			$id,
			[
				'workflow_status' => $workflowStatus,
				'updated_at'      => current_time( 'mysql' ),
			]
		);

		return (bool) $result;
	}

	/**
	 * Delete a specific entry.
	 *
	 * @param int $id Entry ID.
	 *
	 * @return bool
	 */
	public function delete( int $id ): bool {
		return $this->bulkDelete( [ $id ] ) > 0;
	}

	/**
	 * Bulk delete entries (and their uploaded files).
	 *
	 * @param array $ids Entry IDs.
	 *
	 * @return int Number of deleted rows.
	 */
	public function bulkDelete( array $ids ): int {
		$ids = self::normalizeIds( $ids );
		if ( empty( $ids ) ) {
			return 0;
		}

		// Collect upload URLs first — the rows are gone after the transaction.
		$files = $this->collectEntryFiles( $ids );

		$result = DB::transaction(
			static function () use ( $ids ): int {
				self::deleteRowsByEntryIds( Schema::TABLE_LOGS, $ids );
				self::deleteRowsByEntryIds( Schema::TABLE_MAIL_QUEUE, $ids );
				self::deleteRowsByEntryIds( Schema::TABLE_WORKFLOW_HISTORY, $ids );

				return self::deleteEntryRows( $ids );
			}
		);

		$deleted = is_int( $result ) ? $result : 0;

		if ( $deleted > 0 ) {
			self::deleteFiles( $files );
			EntryCountCache::flush();
		}

		return $deleted;
	}

	/**
	 * Collect uploaded-file URLs of the given entries.
	 *
	 * @param list<int> $ids Entry IDs.
	 *
	 * @return array<int, string>
	 */
	private function collectEntryFiles( array $ids ): array {
		$table        = DB::tableNameFull( Schema::TABLE_ENTRIES );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = "SELECT `id`, `data` FROM {$table} WHERE `id` IN ($placeholders)";
		$rows         = DB::db()->get_results( DB::db()->prepare( $sql, ...$ids ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$urls = [];
		foreach ( $rows as $row ) {
			$data  = self::decodeJsonArray( (string) ( $row['data'] ?? '' ), 'entry.data.' . ( $row['id'] ?? 'unknown' ) );
			$files = is_array( $data ) ? ( $data['__files'] ?? [] ) : [];
			if ( ! is_array( $files ) ) {
				continue;
			}

			foreach ( $files as $url ) {
				if ( is_string( $url ) && '' !== $url ) {
					$urls[] = $url;
				}
			}
		}

		return array_values( array_unique( $urls ) );
	}

	/**
	 * Remove uploaded files whose owning entries were deleted.
	 *
	 * @param array<int, string> $urls Upload URLs.
	 */
	private static function deleteFiles( array $urls ): void {
		foreach ( $urls as $url ) {
			$path = FileUploadHandler::urlToPath( $url );
			if ( null !== $path ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Count entries based on filters.
	 *
	 * @param array $filters Filters.
	 *
	 * @return int
	 */
	public function countAll( array $filters = [] ): int {
		$table               = DB::tableNameFull( self::TABLE );
		[ $whereSql, $args ] = self::buildListWhere( $filters );
		$sql                 = "SELECT COUNT(*) FROM {$table} {$whereSql}";

		if ( $args ) {
			$sql = DB::db()->prepare( $sql, ...$args );
		}

		$result = DB::db()->get_var( $sql );

		if ( null === $result && str_contains( strtolower( (string) DB::db()->last_error ), "doesn't exist" ) ) {
			Schema::install();
			$result = DB::db()->get_var( $sql );
		}

		return is_numeric( $result ) ? (int) $result : 0;
	}

	/**
	 * Get counts grouped by status.
	 *
	 * @return array<string, int>
	 */
	public function countByStatus(): array {
		$table = DB::tableNameFull( self::TABLE );
		$sql   = "SELECT `status`, COUNT(*) as `count` FROM {$table} GROUP BY `status`";
		$rows  = DB::db()->get_results( $sql, ARRAY_A );

		$counts = [
			'all'     => 0,
			'new'     => 0,
			'read'    => 0,
			'starred' => 0,
			'spam'    => 0,
			'trash'   => 0,
		];

		if ( ! is_array( $rows ) ) {
			if ( str_contains( strtolower( (string) DB::db()->last_error ), "doesn't exist" ) ) {
				Schema::install();
				$rows = DB::db()->get_results( $sql, ARRAY_A );
			}
		}

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$status = $row['status'] ?? '';
				$count  = (int) ( $row['count'] ?? 0 );

				if ( isset( $counts[ $status ] ) ) {
					$counts[ $status ] = $count;
				}

				if ( ! in_array( $status, [ 'spam', 'trash' ], true ) ) {
					$counts['all'] += $count;
				}
			}
		}

		return $counts;
	}

	/**
	 * Build WHERE clause and arguments.
	 *
	 * @param array $filters Filters.
	 *
	 * @return array{0: string, 1: list<int|string>}
	 */
	private static function buildListWhere( array $filters ): array {
		$where = [];
		$args  = [];

		$status = $filters['status'] ?? 'all';
		if ( 'all' !== $status ) {
			$where[] = '`status` = %s';
			$args[]  = $status;
		} else {
			$where[] = "`status` NOT IN ('spam', 'trash')";
		}

		if ( ! empty( $filters['form_type'] ) ) {
			$where[] = '`form_type` = %s';
			$args[]  = $filters['form_type'];
		}

		if ( ! empty( $filters['workflow_status'] ) ) {
			$where[] = '`workflow_status` = %s';
			$args[]  = $filters['workflow_status'];
		}

		if ( ! empty( $filters['search'] ) ) {
			$searchLike = '%' . DB::db()->esc_like( (string) $filters['search'] ) . '%';
			$where[]    = '(`name` LIKE %s OR `email` LIKE %s OR `phone` LIKE %s)';
			$args[]     = $searchLike;
			$args[]     = $searchLike;
			$args[]     = $searchLike;
		}

		$whereSql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		return [ $whereSql, $args ];
	}

	/**
	 * Normalize array of IDs.
	 *
	 * @param array $ids Raw IDs.
	 *
	 * @return list<int>
	 */
	private static function normalizeIds( array $ids ): array {
		$filtered = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		return array_filter( $filtered, static fn( int $id ): bool => $id > 0 );
	}

	/**
	 * Delete child rows associated with given entry IDs.
	 *
	 * @param string    $table Table name.
	 * @param list<int> $ids   Entry IDs.
	 *
	 * @throws \RuntimeException On query failure.
	 */
	private static function deleteRowsByEntryIds( string $table, array $ids ): void {
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$tableFull    = DB::tableNameFull( $table );
		$sql          = DB::db()->prepare( "DELETE FROM {$tableFull} WHERE `entry_id` IN ($placeholders)", ...$ids );
		$result       = DB::db()->query( $sql );

		if ( false === $result ) {
			throw new \RuntimeException( esc_html( DB::db()->last_error ?: "Failed to delete from {$table}." ) );
		}
	}

	/**
	 * Delete entry rows.
	 *
	 * @param list<int> $ids Entry IDs.
	 *
	 * @return int
	 * @throws \RuntimeException On query failure.
	 */
	private static function deleteEntryRows( array $ids ): int {
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$tableFull    = DB::tableNameFull( self::TABLE );
		$sql          = DB::db()->prepare( "DELETE FROM {$tableFull} WHERE `id` IN ($placeholders)", ...$ids );
		$result       = DB::db()->query( $sql );

		if ( false === $result ) {
			throw new \RuntimeException( esc_html( DB::db()->last_error ?: 'Failed to delete entries.' ) );
		}

		return (int) $result;
	}

	/**
	 * Check if DB error is a duplicate submission.
	 *
	 * @param \WP_Error $error The DB error.
	 *
	 * @return bool
	 */
	private function isDuplicateSubmissionError( \WP_Error $error ): bool {
		$message = strtolower( $error->get_error_message() );

		return str_contains( $message, 'duplicate' ) || str_contains( $message, 'uniq_form_submission' );
	}
}

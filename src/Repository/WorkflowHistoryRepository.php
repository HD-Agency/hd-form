<?php
/**
 * Workflow History Repository
 *
 * Persists and queries per-entry workflow status transition history.
 * Each row records one status change or admin note, with the workflow
 * status slug at that point in time, the note text, the acting user,
 * and the creation timestamp.
 *
 * Orphan rows (whose workflow_status slug no longer exists in config)
 * are preserved as audit trail and rendered with a gray fallback.
 *
 * @package HDForm\Repository
 */

declare(strict_types=1);

namespace HDForm\Repository;

use HDForm\Compat\DB;
use HDForm\Schema;

defined( 'ABSPATH' ) || exit;

class WorkflowHistoryRepository {

	private const TABLE = Schema::TABLE_WORKFLOW_HISTORY;

	/**
	 * Insert a new workflow history row.
	 *
	 * @param int    $entryId        Form entry ID.
	 * @param string $workflowStatus Workflow status slug at time of action.
	 * @param string $note           Optional admin note.
	 * @param int    $userId         WP user ID of the actor (0 for system).
	 *
	 * @return int|\WP_Error Inserted row ID on success, WP_Error on failure.
	 */
	public function insert( int $entryId, string $workflowStatus, string $note, int $userId ): int|\WP_Error {
		return DB::insertOneRow(
			self::TABLE,
			[
				'entry_id'        => $entryId,
				'workflow_status' => $workflowStatus,
				'note'            => $note,
				'user_id'         => $userId,
				'created_at'      => current_time( 'mysql' ),
			]
		);
	}

	/**
	 * Get all history rows for a specific entry, ordered oldest first.
	 *
	 * Rows whose workflow_status slug no longer matches a configured status
	 * are included — callers should handle orphan display gracefully.
	 *
	 * @param int $entryId Form entry ID.
	 *
	 * @return array<int, array{id: int, entry_id: int, workflow_status: string, note: string, user_id: int, created_at: string}>
	 */
	public function findByEntryId( int $entryId ): array {
		$table    = DB::tableNameFull( self::TABLE );
		$prepared = DB::db()->prepare(
			"SELECT * FROM {$table} WHERE `entry_id` = %d ORDER BY `created_at` ASC",
			$entryId
		);
		$results  = DB::db()->get_results( $prepared, ARRAY_A );

		return is_array( $results ) ? $results : [];
	}

	/**
	 * Delete all history rows for a set of entry IDs.
	 *
	 * Used by FormEntryRepository::bulkDelete() for cascade cleanup inside
	 * a DB transaction — throws RuntimeException on failure so the transaction
	 * is rolled back.
	 *
	 * @param array<int, int> $ids Entry IDs.
	 *
	 * @return int Number of deleted rows.
	 *
	 * @throws \RuntimeException On DB failure.
	 */
	public function deleteByEntryIds( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = DB::tableNameFull( self::TABLE );
		$sql          = DB::db()->prepare( "DELETE FROM {$table} WHERE `entry_id` IN ($placeholders)", ...$ids );
		$result       = DB::db()->query( $sql );

		if ( false === $result ) {
			throw new \RuntimeException( esc_html( DB::db()->last_error ?: 'Failed to delete workflow history rows.' ) );
		}

		return (int) $result;
	}

	/**
	 * Reset workflow_status to empty string for all entries using a given slug.
	 *
	 * Called when an admin deletes a workflow status from config.
	 * History rows are NOT affected — they retain the slug as audit trail.
	 *
	 * @param string $slug The slug being removed from config.
	 *
	 * @return int Number of affected entry rows.
	 */
	public function clearEntriesBySlug( string $slug ): int {
		$entriesTable = DB::tableNameFull( Schema::TABLE_ENTRIES );
		$prepared     = DB::db()->prepare(
			"UPDATE {$entriesTable} SET `workflow_status` = '' WHERE `workflow_status` = %s",
			$slug
		);
		$result       = DB::db()->query( $prepared );

		return false !== $result ? (int) $result : 0;
	}

	/**
	 * Count entries currently using a specific workflow status slug.
	 *
	 * Used to display warning before deleting a status from config.
	 *
	 * @param string $slug Workflow status slug.
	 *
	 * @return int
	 */
	public function countEntriesBySlug( string $slug ): int {
		$table    = DB::tableNameFull( Schema::TABLE_ENTRIES );
		$prepared = DB::db()->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE `workflow_status` = %s",
			$slug
		);

		return (int) DB::db()->get_var( $prepared );
	}
}

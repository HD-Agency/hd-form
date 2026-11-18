<?php
/**
 * DB Compat — minimal replacement for HD\Core\DB.
 *
 * Implements only the methods used by the Form module classes.
 * No dependency on themes/hd.
 *
 * @package HDForm\Compat
 */

declare(strict_types=1);

namespace HDForm\Compat;

defined( 'ABSPATH' ) || exit;

final class DB {

	/** @var array<string, string[]> */
	private static array $schemaCache = [];

	/**
	 * Return the global $wpdb instance.
	 *
	 * @return \wpdb
	 */
	public static function db(): \wpdb {
		global $wpdb;

		return $wpdb;
	}

	/**
	 * Return the full prefixed table name.
	 *
	 * @param string $table Short table name (e.g. 'hd_form_entries').
	 *
	 * @return string Full prefixed name (e.g. 'wp_hd_form_entries').
	 */
	public static function tableNameFull( string $table ): string {
		global $wpdb;

		if ( str_starts_with( $table, $wpdb->prefix ) ) {
			return $table;
		}

		return $wpdb->prefix . self::sanitizeIdentifier( $table );
	}

	/**
	 * Sanitize a column/table identifier (alphanumeric + underscore only).
	 *
	 * @param string $identifier Raw identifier.
	 *
	 * @return string
	 */
	public static function sanitizeIdentifier( string $identifier ): string {
		return (string) preg_replace( '/\W/', '', $identifier );
	}

	/**
	 * Return the full prefixed table name wrapped in backticks.
	 *
	 * @param string $table Short table name.
	 *
	 * @return string Backtick-wrapped prefixed name.
	 */
	public static function tableQuoted( string $table ): string {
		return '`' . self::tableNameFull( $table ) . '`';
	}

	/**
	 * Check whether a column exists in a table.
	 *
	 * @param string $table  Short table name.
	 * @param string $column Column name.
	 *
	 * @return bool
	 */
	public static function tableHasColumn( string $table, string $column ): bool {
		$cols = self::getTableColumns( $table );

		if ( is_wp_error( $cols ) ) {
			return false;
		}

		return in_array( $column, $cols, true );
	}

	/**
	 * Return array of column names for a table.
	 *
	 * @param string $tableName Short table name.
	 *
	 * @return string[]|\WP_Error
	 */
	public static function getTableColumns( string $tableName ): \WP_Error|array {
		$cacheKey = self::tableNameFull( $tableName );

		if ( isset( self::$schemaCache[ $cacheKey ] ) ) {
			return self::$schemaCache[ $cacheKey ];
		}

		$table = self::tableQuoted( $tableName );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = self::db()->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );

		if ( null === $rows ) {
			return new \WP_Error( 'db_describe_failed', self::db()->last_error ?: 'Failed to describe table.' );
		}

		$cols = array_map( static fn( $r ) => $r['Field'] ?? '', $rows );

		self::$schemaCache[ $cacheKey ] = $cols;

		return $cols;
	}

	/**
	 * Clear the schema cache for one table or all tables.
	 *
	 * @param string|null $tableName Short table name or null for all.
	 */
	public static function clearSchemaCache( ?string $tableName = null ): void {
		if ( null === $tableName ) {
			self::$schemaCache = [];

			return;
		}

		unset( self::$schemaCache[ self::tableNameFull( $tableName ) ] );
	}

	/**
	 * Insert a single row into a table.
	 *
	 * @param string     $tableName Short table name.
	 * @param array      $data      Column => value pairs.
	 * @param array|null $format    Optional wpdb formats.
	 *
	 * @return int|\WP_Error Insert ID on success.
	 */
	public static function insertOneRow( string $tableName, array $data, ?array $format = null ): \WP_Error|int {
		if ( ! $data ) {
			return new \WP_Error( 'no_data', 'No data provided.' );
		}

		$cols = self::getTableColumns( $tableName );
		if ( is_wp_error( $cols ) ) {
			return $cols;
		}

		$valid = array_intersect_key( $data, array_flip( $cols ) );
		if ( ! $valid ) {
			return new \WP_Error( 'no_valid_columns', 'No valid columns provided for insertion.' );
		}

		$table  = self::tableNameFull( $tableName );
		$result = self::db()->insert( $table, $valid, $format ?? array_fill( 0, count( $valid ), '%s' ) );

		if ( false === $result ) {
			return new \WP_Error( 'insert_failed', self::db()->last_error );
		}

		return self::db()->insert_id;
	}

	/**
	 * Update one row by primary key.
	 *
	 * @param string     $tableName  Short table name.
	 * @param int|string $id         Primary key value.
	 * @param array      $data       Column => value pairs.
	 * @param string     $primaryKey Primary key column name.
	 * @param array|null $format     Optional wpdb formats.
	 *
	 * @return int|\WP_Error Rows updated or WP_Error.
	 */
	public static function updateOneRow( string $tableName, int|string $id, array $data, string $primaryKey = 'id', ?array $format = null ): \WP_Error|int {
		if ( ! $data ) {
			return new \WP_Error( 'no_data', 'No data provided for update.' );
		}

		$cols = self::getTableColumns( $tableName );
		if ( is_wp_error( $cols ) ) {
			return $cols;
		}

		$valid = array_intersect_key( $data, array_flip( $cols ) );
		if ( ! $valid ) {
			return new \WP_Error( 'no_valid_columns', 'No valid columns provided for update.' );
		}

		$table  = self::tableNameFull( $tableName );
		$result = self::db()->update(
			$table,
			$valid,
			[ $primaryKey => $id ],
			$format ?? array_fill( 0, count( $valid ), '%s' ),
			[ is_int( $id ) ? '%d' : '%s' ]
		);

		if ( false === $result ) {
			return new \WP_Error( 'update_failed', self::db()->last_error );
		}

		return (int) $result;
	}

	/**
	 * Get one row with validated associative where predicates.
	 *
	 * @param string $tableName Short table name.
	 * @param array  $where     Associative col => value predicates.
	 *
	 * @return array|null|\WP_Error
	 */
	public static function getOneWhere( string $tableName, array $where ): \WP_Error|array|null {
		$cols = self::getTableColumns( $tableName );
		if ( is_wp_error( $cols ) ) {
			return $cols;
		}

		$whereClauses = [];
		$values       = [];

		foreach ( $where as $col => $val ) {
			$col = self::sanitizeIdentifier( (string) $col );
			if ( ! in_array( $col, $cols, true ) ) {
				continue;
			}

			if ( null === $val ) {
				$whereClauses[] = "`{$col}` IS NULL";
				continue;
			}

			$whereClauses[] = "`{$col}` = %s";
			$values[]       = (string) $val;
		}

		if ( ! $whereClauses ) {
			return new \WP_Error( 'no_valid_where', 'No valid WHERE columns provided.' );
		}

		$tableFull = self::tableQuoted( $tableName );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$tableFull} WHERE " . implode( ' AND ', $whereClauses ) . ' LIMIT 1';
		$sql = $values ? self::db()->prepare( $sql, ...$values ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return self::db()->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Get multiple rows with optional filter, paging and ordering.
	 *
	 * @param string $tableName Short table name.
	 * @param array  $where     Associative col => value (ANDed).
	 * @param int    $page      1-based page.
	 * @param int    $perPage   Items per page.
	 * @param string $orderBy   Column to order by.
	 * @param string $order     ASC or DESC.
	 *
	 * @return array|\WP_Error
	 */
	public static function getRows( string $tableName, array $where = [], int $page = 1, int $perPage = 20, string $orderBy = 'id', string $order = 'ASC' ): \WP_Error|array {
		$cols = self::getTableColumns( $tableName );
		if ( is_wp_error( $cols ) ) {
			return $cols;
		}

		$orderBy = self::sanitizeIdentifier( $orderBy );
		if ( ! in_array( $orderBy, $cols, true ) ) {
			$orderBy = $cols[0] ?? 'id';
		}

		$order = strtoupper( $order );
		$order = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'ASC';

		$table        = self::tableQuoted( $tableName );
		$whereClauses = [];
		$values       = [];

		foreach ( $where as $col => $val ) {
			$col = self::sanitizeIdentifier( (string) $col );
			if ( in_array( $col, $cols, true ) ) {
				$whereClauses[] = "`{$col}` = %s";
				$values[]       = (string) $val;
			}
		}

		$whereSql = $whereClauses ? ' WHERE ' . implode( ' AND ', $whereClauses ) : '';
		$offset   = max( 0, ( $page - 1 ) * $perPage );
		$values[] = $offset;
		$values[] = $perPage;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql      = "SELECT * FROM {$table}{$whereSql} ORDER BY `{$orderBy}` {$order} LIMIT %d, %d";
		$prepared = self::db()->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $prepared ) {
			return new \WP_Error( 'prepare_failed', 'Failed to prepare select query.' );
		}

		$rows = self::db()->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return $rows ?? new \WP_Error( 'select_failed', self::db()->last_error );
	}

	/**
	 * Upsert (INSERT … ON DUPLICATE KEY UPDATE) helper.
	 *
	 * @param string              $table  Short table name.
	 * @param array<string,mixed> $insert Column => value pairs to insert.
	 * @param array<string,mixed> $update Column => raw SQL expression pairs for ON DUPLICATE KEY UPDATE.
	 *
	 * @return int|\WP_Error Rows affected, or WP_Error on failure.
	 */
	public static function upsert( string $table, array $insert, array $update ): int|\WP_Error {
		global $wpdb;

		if ( ! $insert || ! $update ) {
			return new \WP_Error( 'no_data', 'Missing insert data or update expressions.' );
		}

		$tableFull = self::tableNameFull( $table );
		$columns   = array_keys( $insert );
		$colList   = implode( ', ', array_map( static fn( string $c ) => "`{$c}`", $columns ) );
		$values    = array_values( $insert );

		$placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

		$updateParts = [];
		foreach ( $update as $col => $expression ) {
			$updateParts[] = "`{$col}` = {$expression}";
		}
		$updateSql = implode( ', ', $updateParts );

		$query = "INSERT INTO `{$tableFull}` ({$colList}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$updateSql}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWithoutPlaceholders
		$sql = $wpdb->prepare( $query, $values );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $sql );

		if ( false === $result ) {
			return new \WP_Error( 'db_upsert_failed', $wpdb->last_error );
		}

		return (int) $result;
	}

	/**
	 * Run a callback inside a MySQL transaction.
	 *
	 * @param callable $callback Callback to run. Return value is passed back.
	 *
	 * @return mixed|\WP_Error
	 */
	public static function transaction( callable $callback ): mixed {
		$wpdb = self::db();

		$wpdb->query( 'START TRANSACTION' );

		try {
			$result = $callback();

			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );

				return $result;
			}

			$wpdb->query( 'COMMIT' );

			return $result;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			Helper::errorLog( '[DB::transaction] Exception: ' . $e->getMessage() );

			return new \WP_Error( 'transaction_exception', 'Database transaction failed.' );
		}
	}
}

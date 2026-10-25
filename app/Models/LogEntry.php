<?php
/**
 * Delivery log model.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Models;

defined( 'ABSPATH' ) || exit;

final class LogEntry extends Model {

	protected $table = 'wpi_wcrds_logs';
	protected $json  = array( 'request', 'response' );

	/**
	 * Delete specific log rows in one statement.
	 *
	 * @param int[] $ids Row ids.
	 * @return int Rows deleted.
	 */
	public function delete_ids( array $ids ): int {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $this->table() ), $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only, values prepared below.
		return (int) $this->db()->query(
			$this->db()->prepare( "DELETE FROM %i WHERE id IN ( $placeholders )", $params )
		);
	}

	/**
	 * Delete rows matching an equality map; an empty map clears the log.
	 *
	 * @param array<string, string|int> $where column => value (columns trusted, values prepared).
	 * @return int Rows deleted.
	 */
	public function delete_where( array $where = array() ): int {
		$sql    = 'DELETE FROM %i';
		$params = array( $this->table() );

		if ( $where ) {
			$clauses = array();
			foreach ( $where as $column => $value ) {
				$clauses[] = '%i = ' . ( is_int( $value ) ? '%d' : '%s' );
				$params[]  = $column;
				$params[]  = $value;
			}
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		return (int) $this->db()->query( $this->db()->prepare( $sql, $params ) );
	}

	/**
	 * Delete log rows older than N days.
	 */
	public function purge_older_than( int $days ): void {
		$days = max( 1, $days );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- maintenance on custom table.
		$this->db()->query(
			$this->db()->prepare(
				'DELETE FROM %i WHERE created_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d DAY )',
				$this->table(),
				$days
			)
		);
	}
}

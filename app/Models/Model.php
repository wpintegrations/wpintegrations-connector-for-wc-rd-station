<?php
/**
 * Minimal base model over $wpdb. Every query is prepared.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Models;

defined( 'ABSPATH' ) || exit;

abstract class Model {

	/** @var string Unprefixed table name, e.g. 'wpi_wcrds_flows'. */
	protected $table;

	/** @var string[] Columns JSON-encoded on write and decoded on read. */
	protected $json = array();

	/** @var bool Whether the table has an updated_at column to touch. */
	protected $touches_updated = false;

	protected function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	public function table(): string {
		return $this->db()->prefix . $this->table;
	}

	/**
	 * @param int $id Row id.
	 * @return object|null
	 */
	public function find( int $id ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $this->db()->get_row( $this->db()->prepare( "SELECT * FROM %i WHERE id = %d", $this->table(), $id ) );
		return $row ? $this->cast( $row ) : null;
	}

	/**
	 * Fetch rows matching an equality map, newest first.
	 *
	 * @param array<string, string|int> $where  column => value (columns trusted, values prepared).
	 * @param int                       $limit  Max rows.
	 * @param int                       $offset Offset.
	 * @return object[]
	 */
	public function where( array $where = array(), int $limit = 100, int $offset = 0 ): array {
		$sql    = 'SELECT * FROM %i';
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

		$sql     .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- prepared above.
		$rows = (array) $this->db()->get_results( $this->db()->prepare( $sql, $params ) );
		return array_map( array( $this, 'cast' ), $rows );
	}

	/**
	 * @param array<string, mixed> $data Column => value.
	 * @return int Insert id (0 on failure).
	 */
	public function create( array $data ): int {
		$now                = current_time( 'mysql', true );
		$data['created_at'] = $data['created_at'] ?? $now;
		if ( $this->touches_updated ) {
			$data['updated_at'] = $data['updated_at'] ?? $now;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$this->db()->insert( $this->table(), $this->serialize( $data ) );
		return (int) $this->db()->insert_id;
	}

	/**
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Column => value.
	 */
	public function update( int $id, array $data ): bool {
		if ( $this->touches_updated ) {
			$data['updated_at'] = current_time( 'mysql', true );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		return false !== $this->db()->update( $this->table(), $this->serialize( $data ), array( 'id' => $id ) );
	}

	public function delete( int $id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		return (bool) $this->db()->delete( $this->table(), array( 'id' => $id ) );
	}

	/**
	 * Encode the declared JSON columns for writing.
	 */
	private function serialize( array $data ): array {
		foreach ( $this->json as $column ) {
			if ( array_key_exists( $column, $data ) && ! is_string( $data[ $column ] ) ) {
				$data[ $column ] = wp_json_encode( $data[ $column ] );
			}
		}
		return $data;
	}

	/**
	 * Decode the declared JSON columns on a fetched row.
	 */
	protected function cast( object $row ): object {
		foreach ( $this->json as $column ) {
			if ( isset( $row->{$column} ) && is_string( $row->{$column} ) ) {
				$decoded = json_decode( $row->{$column}, true );
				if ( null !== $decoded ) {
					$row->{$column} = $decoded;
				}
			}
		}
		return $row;
	}
}

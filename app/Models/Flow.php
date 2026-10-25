<?php
/**
 * Flow model: a configured trigger→action pipeline.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Models;

defined( 'ABSPATH' ) || exit;

final class Flow extends Model {

	protected $table           = 'wpi_wcrds_flows';
	protected $json            = array( 'field_map', 'settings' );
	protected $touches_updated = true;

	/**
	 * Titles keyed by flow id. Ids whose flow was deleted are absent.
	 *
	 * @param int[] $ids Flow ids.
	 * @return array<int, string>
	 */
	public function titles( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( array( $this->table() ), $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders from a counted fill, values passed to prepare().
		$rows = (array) $this->db()->get_results(
			$this->db()->prepare( "SELECT id, title FROM %i WHERE id IN ( $placeholders )", $params )
		);

		$titles = array();
		foreach ( $rows as $row ) {
			$titles[ (int) $row->id ] = (string) $row->title;
		}
		return $titles;
	}

	/**
	 * Active flows listening to a trigger, optionally narrowed to a source.
	 *
	 * @param string          $trigger_key Trigger identifier, e.g. 'woocommerce'.
	 * @param string|string[] $source      Source id(s); a flow matches any of the given
	 *                                     aliases, and flows bound to '' match everything.
	 * @return object[]
	 */
	public function for_trigger( string $trigger_key, $source = '' ): array {
		$sources = array_values( array_unique( array_filter( array_map( 'strval', (array) $source ), 'strlen' ) ) );

		$sql  = "SELECT * FROM %i WHERE trigger_key = %s AND status = 'active' AND trigger_source = ''";
		$args = array( $this->table(), $trigger_key );

		if ( $sources ) {
			$placeholders = implode( ', ', array_fill( 0, count( $sources ), '%s' ) );
			$sql          = "SELECT * FROM %i WHERE trigger_key = %s AND status = 'active' AND ( trigger_source IN ( {$placeholders} ) OR trigger_source = '' )";
			$args         = array_merge( $args, $sources );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom table; placeholders are built from a counted fill, values passed to prepare().
		$rows = (array) $this->db()->get_results( $this->db()->prepare( $sql, $args ) );
		return array_map( array( $this, 'cast' ), $rows );
	}
}

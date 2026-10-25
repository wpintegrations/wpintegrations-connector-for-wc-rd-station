<?php
/**
 * Delivery log listing.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Http\Controllers;

use WPIntegrations\WcRdStation\Models\Flow;
use WPIntegrations\WcRdStation\Models\LogEntry;

defined( 'ABSPATH' ) || exit;

final class LogController extends Controller {

	/** @var LogEntry */
	private $logs;

	/** @var Flow */
	private $flows;

	public function __construct( LogEntry $logs, Flow $flows ) {
		$this->logs  = $logs;
		$this->flows = $flows;
	}

	public function index( \WP_REST_Request $request ): \WP_REST_Response {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = 25;

		$items = $this->logs->where( $this->filters( $request ), $per_page, ( $page - 1 ) * $per_page );

		// Rows store only the flow id, so resolve this page's names in one query.
		$titles = $this->flows->titles( wp_list_pluck( $items, 'flow_id' ) );
		foreach ( $items as $item ) {
			$item->flow_title = $titles[ (int) $item->flow_id ] ?? '';
		}

		return $this->ok(
			array(
				'items' => $items,
				'page'  => $page,
			)
		);
	}

	/**
	 * Delete the log entries named in the request.
	 */
	public function destroy( \WP_REST_Request $request ): \WP_REST_Response {
		$ids = array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) );
		if ( ! $ids ) {
			return $this->fail( __( 'Select at least one log entry to delete.', 'wpintegrations-connector-for-wc-rd-station' ) );
		}

		return $this->ok( array( 'deleted' => $this->logs->delete_ids( $ids ) ) );
	}

	/**
	 * Delete every entry matching the current filters, or the whole log.
	 */
	public function clear( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( array( 'deleted' => $this->logs->delete_where( $this->filters( $request ) ) ) );
	}

	/**
	 * Filters shared by listing and bulk deletion, so "clear all" removes what
	 * the list is showing.
	 *
	 * @return array<string, string|int>
	 */
	private function filters( \WP_REST_Request $request ): array {
		$where = array();

		$flow_id = (int) $request->get_param( 'flow_id' );
		if ( $flow_id > 0 ) {
			$where['flow_id'] = $flow_id;
		}

		$level = sanitize_key( (string) $request->get_param( 'level' ) );
		if ( in_array( $level, array( 'success', 'error', 'info' ), true ) ) {
			$where['level'] = $level;
		}

		return $where;
	}
}

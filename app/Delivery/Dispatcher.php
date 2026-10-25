<?php
/**
 * Runs a flow's action once and records the attempt.
 *
 * Called inline when a form is submitted.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Delivery;

use WPIntegrations\WcRdStation\Integrations\Registry;
use WPIntegrations\WcRdStation\Models\LogEntry;

defined( 'ABSPATH' ) || exit;

/**
 * Runs one delivery attempt.
 */
final class Dispatcher {

	/**
	 * The integration registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * The delivery log.
	 *
	 * @var LogEntry
	 */
	private $logs;

	/**
	 * Wire up dependencies.
	 *
	 * @param Registry $registry The integration registry.
	 * @param LogEntry $logs     The delivery log.
	 */
	public function __construct( Registry $registry, LogEntry $logs ) {
		$this->registry = $registry;
		$this->logs     = $logs;
	}

	/**
	 * Deliver one submission and write its log row.
	 *
	 * @param object   $flow    Flow row (field_map/settings decoded).
	 * @param array    $payload Normalized submission: ['fields' => [...], 'meta' => [...]].
	 */
	public function deliver( object $flow, array $payload ): Result {
		$action = $this->registry->action( (string) $flow->action_key );

		if ( ! $action ) {
			$result = Result::failure( sprintf( 'Unknown action "%s".', $flow->action_key ) );
		} else {
			try {
				$result = $action->execute( $flow, $payload );
			} catch ( \Throwable $e ) {
				$result = Result::failure( $e->getMessage() );
			}
		}

		$this->logs->create(
			array(
				'flow_id'  => (int) $flow->id,
				'level'    => $result->ok ? 'success' : 'error',
				'message'  => $result->message,
				'request'  => $result->request,
				'response' => $result->response,
			)
		);

		return $result;
	}
}

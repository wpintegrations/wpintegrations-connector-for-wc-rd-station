<?php
/**
 * Metadata for the admin UI: triggers and actions, and recently seen form
 * shapes.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Http\Controllers;

use WPIntegrations\WcRdStation\Providers\AdminServiceProvider;
use WPIntegrations\WcRdStation\Integrations\Actions\RdStation\RdStationOAuth;
use WPIntegrations\WcRdStation\Integrations\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Metadata for the admin UI.
 */
final class MetaController extends Controller {

	/**
	 * The integration registry.
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * The Marketing OAuth connection slot.
	 *
	 * @var RdStationOAuth
	 */
	private $marketing;

	/**
	 * Wire up dependencies.
	 *
	 * @param Registry          $registry  The integration registry.
	 * @param RdStationOAuth    $marketing The Marketing OAuth connection slot.
	 */
	public function __construct( Registry $registry, RdStationOAuth $marketing ) {
		$this->registry  = $registry;
		$this->marketing = $marketing;
	}

	/**
	 * The admin bootstrap payload.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function show( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->ok( $this->payload() );
	}

	/**
	 * Build the admin bootstrap payload.
	 */
	private function payload(): array {
		$triggers = array();
		foreach ( $this->registry->triggers() as $trigger ) {
			$triggers[] = array(
				'key'       => $trigger->key(),
				'label'     => $trigger->label(),
				'available' => $trigger->available(),
			);
		}

		$actions = array();
		foreach ( $this->registry->actions() as $action ) {
			$actions[] = array(
				'key'           => $action->key(),
				'label'         => $action->label(),
				'target_fields' => $action->target_fields(),
				'flow_fields'   => $action->flow_fields(),
				'global_fields' => array_map(
					static function ( $field ) {
						return array(
							'key'    => $field['key'],
							'label'  => $field['label'],
							'secret' => ! empty( $field['secret'] ),
						);
					},
					$action->global_fields()
				),
			);
		}

		return array(
			'triggers'      => $triggers,
			'actions'       => $actions,
			// One connection slot per RD Station product, so the UI knows
			// which one a flow needs before it can be created.
			'oauth'         => array( 'connected' => $this->marketing->connected() ),
			'recent_shapes' => array_values( (array) get_option( 'wpi_wcrds_recent_shapes', array() ) ),
			// Where the Settings tab's note about the Pro plugin links to.
			'pro_url'       => AdminServiceProvider::PRO_URL,
			'version'       => WPI_WCRDS_VERSION,
		);
	}

	/**
	 * Sources each trigger can discover up front, with their fields, so the UI
	 * can offer a form picker before any submission arrives.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function sources( \WP_REST_Request $request ): \WP_REST_Response {
		$refresh = rest_sanitize_boolean( $request->get_param( 'refresh' ) );

		$sources = array();
		foreach ( $this->registry->triggers() as $trigger ) {
			$sources[ $trigger->key() ] = $trigger->available() ? $trigger->sources( $refresh ) : array();
		}

		return $this->ok( $sources );
	}

}

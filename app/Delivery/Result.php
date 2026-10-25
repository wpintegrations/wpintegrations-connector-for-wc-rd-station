<?php
/**
 * Typed result of an action execution.
 *
 * The contract between an Action and the Dispatcher that delivers it.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Delivery;

defined( 'ABSPATH' ) || exit;

final class Result {

	/** @var bool */
	public $ok;

	/** @var string */
	public $message;

	/** @var array|null Request excerpt stored in the log, never credentials. */
	public $request;

	/** @var array|null Response excerpt stored in the log. */
	public $response;

	private function __construct( bool $ok, string $message, $request = null, $response = null ) {
		$this->ok       = $ok;
		$this->message  = $message;
		$this->request  = $request;
		$this->response = $response;
	}

	public static function success( string $message = 'OK', $request = null, $response = null ): Result {
		return new self( true, $message, $request, $response );
	}

	public static function failure( string $message, $request = null, $response = null ): Result {
		return new self( false, $message, $request, $response );
	}
}

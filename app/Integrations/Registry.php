<?php
/**
 * Registry of available triggers and actions.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Integrations;

defined( 'ABSPATH' ) || exit;

final class Registry {

	/** @var array<string, Trigger> */
	private $triggers = array();

	/** @var array<string, Action> */
	private $actions = array();

	public function add_trigger( Trigger $trigger ): void {
		$this->triggers[ $trigger->key() ] = $trigger;
	}

	public function add_action( Action $action ): void {
		$this->actions[ $action->key() ] = $action;
	}

	public function trigger( string $key ): ?Trigger {
		return $this->triggers[ $key ] ?? null;
	}

	public function action( string $key ): ?Action {
		return $this->actions[ $key ] ?? null;
	}

	/** @return Trigger[] */
	public function triggers(): array {
		return $this->triggers;
	}

	/** @return Action[] */
	public function actions(): array {
		return $this->actions;
	}
}

<?php
/**
 * Flows: one row = one configured trigger→action pipeline.
 *
 * @package WPIntegrations\WcRdStation
 */

namespace WPIntegrations\WcRdStation\Database\Migrations;

use WPIntegrations\WcRdStation\Database\Migration;

defined( 'ABSPATH' ) || exit;

final class CreateFlowsTable extends Migration {

	public function up(): void {
		$this->create_table(
			'wpi_wcrds_flows',
			'id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			title varchar(190) NOT NULL,
			trigger_key varchar(60) NOT NULL,
			trigger_source varchar(190) NOT NULL DEFAULT "",
			action_key varchar(60) NOT NULL,
			field_map longtext NOT NULL,
			conditions longtext NULL,
			settings longtext NULL,
			status varchar(20) NOT NULL DEFAULT "active",
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY trigger_lookup (trigger_key,trigger_source(60),status)'
		);
	}
}

<?php
/**
 * Fixes the MCP log flood at its source, without touching any plugin.
 *
 * Rank Math's MCP server lists the shared abilities mcp-adapter/discover-abilities, get-ability-info and
 * execute-ability. The bundled MCP adapter registers them on the abilities hooks, but it attaches those hooks at
 * init:20, after the abilities registry on this site has already been built, so they never exist and every request
 * logs "WordPress ability 'mcp-adapter/...' does not exist." (this grew public_html/error_log to ~255 MB).
 * Here the category and the three abilities are registered on the proper hooks, only when the adapter classes are
 * loaded and only if nothing registered them first.
 *
 * Disable with: define( 'BC_MCP_LOG_QUIET_DISABLED', true );
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BC_MCP_LOG_QUIET_DISABLED' ) && BC_MCP_LOG_QUIET_DISABLED ) {
	return;
}

add_action(
	'wp_abilities_api_categories_init',
	function () {
		if ( ! class_exists( 'WP\\MCP\\Abilities\\DiscoverAbilitiesAbility' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'mcp-adapter' ) ) {
			return;
		}
		wp_register_ability_category(
			'mcp-adapter',
			array(
				'label'       => 'MCP Adapter',
				'description' => 'Abilities for the MCP Adapter',
			)
		);
	},
	999
);

add_action(
	'wp_abilities_api_init',
	function () {
		if ( ! class_exists( 'WP\\MCP\\Abilities\\DiscoverAbilitiesAbility' ) || ! function_exists( 'wp_has_ability' ) ) {
			return;
		}
		$abilities = array(
			'mcp-adapter/discover-abilities' => 'WP\\MCP\\Abilities\\DiscoverAbilitiesAbility',
			'mcp-adapter/get-ability-info'   => 'WP\\MCP\\Abilities\\GetAbilityInfoAbility',
			'mcp-adapter/execute-ability'    => 'WP\\MCP\\Abilities\\ExecuteAbilityAbility',
		);
		foreach ( $abilities as $name => $class ) {
			if ( ! wp_has_ability( $name ) && class_exists( $class ) ) {
				$class::register();
			}
		}
	},
	999
);
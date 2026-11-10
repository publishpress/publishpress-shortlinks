<?php

/** Private optional Shortlinks abilities. */

defined('ABSPATH') || exit;
if (!function_exists('wp_register_ability') || defined('PUBLISHPRESS_SHORTLINKS_MCP_LOADED')) {
    return;
}
define('PUBLISHPRESS_SHORTLINKS_MCP_LOADED', true);
require_once __DIR__ . '/Registry.php';
require_once __DIR__ . '/Fields.php';
require_once __DIR__ . '/Actions.php';
add_action('wp_abilities_api_categories_init', static function () {
    if (!wp_has_ability_category('publishpress')) {
        wp_register_ability_category('publishpress', ['label' => __('PublishPress', 'tinypress'), 'description' => __('PublishPress plugin actions.', 'tinypress')]);
    }
});
add_action('wp_abilities_api_init', ['PublishPress\Shortlinks\Mcp\Actions', 'register']);

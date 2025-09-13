<?php
/**
 * Plugin Name: Init Chat Engine
 * Plugin URI: https://inithtml.com/plugin/init-chat-engine/
 * Description: A lightweight, community-focused chat system built with REST API and Vanilla JS. Embed anywhere using the [init_chatbox] shortcode.
 * Version: 1.1.7
 * Author: Init HTML
 * Author URI: https://inithtml.com/
 * Text Domain: init-chat-engine
 * Domain Path: /languages
 * Requires at least: 5.5
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'INIT_PLUGIN_SUITE_CHAT_ENGINE_VERSION',        '1.1.7' );
define( 'INIT_PLUGIN_SUITE_CHAT_ENGINE_SLUG',           'init-chat-engine' );
define( 'INIT_PLUGIN_SUITE_CHAT_ENGINE_OPTION',         'init_plugin_suite_chat_engine_settings' );
define( 'INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE',      'initchat/v1' );
define( 'INIT_PLUGIN_SUITE_CHAT_ENGINE_URL',            plugin_dir_url( __FILE__ ) );
define( 'INIT_PLUGIN_SUITE_CHAT_ENGINE_PATH',           plugin_dir_path( __FILE__ ) );
define( 'INIT_PLUGIN_SUITE_CHAT_ENGINE_ASSETS_URL',     INIT_PLUGIN_SUITE_CHAT_ENGINE_URL  . 'assets/' );
define( 'INIT_PLUGIN_SUITE_CHAT_ENGINE_ASSETS_PATH',    INIT_PLUGIN_SUITE_CHAT_ENGINE_PATH . 'assets/' );
define( 'INIT_PLUGIN_SUITE_CHAT_ENGINE_LANGUAGES_PATH', INIT_PLUGIN_SUITE_CHAT_ENGINE_PATH . 'languages/' );
define( 'INIT_PLUGIN_SUITE_CHAT_ENGINE_INCLUDES_PATH',  INIT_PLUGIN_SUITE_CHAT_ENGINE_PATH . 'includes/' );
define( 'INIT_PLUGIN_SUITE_CHAT_ENGINE_TEMPLATES_PATH', INIT_PLUGIN_SUITE_CHAT_ENGINE_PATH . 'templates/' );

require_once INIT_PLUGIN_SUITE_CHAT_ENGINE_INCLUDES_PATH . 'init.php';
require_once INIT_PLUGIN_SUITE_CHAT_ENGINE_INCLUDES_PATH . 'rest-api.php';
require_once INIT_PLUGIN_SUITE_CHAT_ENGINE_INCLUDES_PATH . 'shortcodes.php';
require_once INIT_PLUGIN_SUITE_CHAT_ENGINE_INCLUDES_PATH . 'settings-page.php';
require_once INIT_PLUGIN_SUITE_CHAT_ENGINE_INCLUDES_PATH . 'management-page.php';

// ==========================
// Settings link
// ==========================

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'init_plugin_suite_chat_engine_add_settings_link');
// Add a "Settings" link to the plugin row in the Plugins admin screen
function init_plugin_suite_chat_engine_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=' . INIT_PLUGIN_SUITE_CHAT_ENGINE_SLUG) . '">' . __('Settings', 'init-chat-engine') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

<?php
/**
 * Plugin Name: Million Dollar Script - Bounty Engine
 * Plugin URI: https://milliondollarscript.com/extensions/bounty-engine
 * Description: Run public bounty and request boards with paid/featured posting fees, submissions, and no-escrow moderation workflows.
 * Version: 0.2.0
 * Author: Million Dollar Script
 * Author URI: https://milliondollarscript.com
 * Text Domain: mds-bounty-engine
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 7.1.3
 * Requires PHP: 8.1
 * License: GPL-3.0+
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires Plugins: million-dollar-script
 *
 * Requires MDS: 3.0.0
 * Requires MDS API: 1
 * Requires License: No
 * MDS Provides: inventory.bounty-engine sponsorship.inventory
 * MDS Requires: platform.core
 * MDS Recommends: mds-woocommerce
 * MDS Requires Service: no
 * MDS Setup Category: monetization
 * MDS Minimum Security Level: api_key_read
 * MDS LLM Safe Actions: read_boards read_bounties
 *
 * @package MillionDollarScript\Extensions\BountyEngine
 */

namespace MillionDollarScript\Extensions\BountyEngine;

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('MDS_BOUNTY_ENGINE_VERSION')) {
    define('MDS_BOUNTY_ENGINE_VERSION', '0.2.0');
}

if (!defined('MDS_BOUNTY_ENGINE_FILE')) {
    define('MDS_BOUNTY_ENGINE_FILE', __FILE__);
}

if (!defined('MDS_BOUNTY_ENGINE_PATH')) {
    define('MDS_BOUNTY_ENGINE_PATH', plugin_dir_path(__FILE__));
}

if (!defined('MDS_BOUNTY_ENGINE_URL')) {
    define('MDS_BOUNTY_ENGINE_URL', plugin_dir_url(__FILE__));
}

if (!defined('MDS_BOUNTY_ENGINE_BASENAME')) {
    define('MDS_BOUNTY_ENGINE_BASENAME', plugin_basename(__FILE__));
}

function mds_bounty_engine_check_mds_active() {
    return class_exists('\\MillionDollarScript\\Core\\Runtime')
        && \MillionDollarScript\Core\Runtime::is_ready();
}

function mds_bounty_engine_missing_mds_notice() {
    echo '<div class="notice notice-error"><p>';
    echo '<strong>' . esc_html__('Million Dollar Script - Bounty Engine', 'mds-bounty-engine') . '</strong>: ';
    echo esc_html__('This extension requires the Million Dollar Script plugin to be installed and activated.', 'mds-bounty-engine');
    echo ' <a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Manage Plugins', 'mds-bounty-engine') . '</a>';
    echo '</p></div>';
}

function mds_bounty_engine_load_files() {
    require_once MDS_BOUNTY_ENGINE_PATH . 'includes/Repository.php';
    require_once MDS_BOUNTY_ENGINE_PATH . 'includes/Privacy.php';
    require_once MDS_BOUNTY_ENGINE_PATH . 'includes/Retention.php';
    require_once MDS_BOUNTY_ENGINE_PATH . 'includes/Main.php';
}

function mds_bounty_engine_init() {
    if (!mds_bounty_engine_check_mds_active()) {
        add_action('admin_notices', __NAMESPACE__ . '\\mds_bounty_engine_missing_mds_notice');
        return;
    }

    mds_bounty_engine_load_files();
    Main::instance()->register();
}

function mds_bounty_engine_register_extension() {
    if (!mds_bounty_engine_check_mds_active()) {
        return;
    }

    if (class_exists('\\MillionDollarScript\\Extensions\\Registry')) {
        \MillionDollarScript\Extensions\Registry::register([
            'id' => 'mds-bounty-engine',
            'name' => 'Bounty Engine',
            'description' => 'Run public bounty and request boards with paid posting, claims, and moderation.',
            'version' => MDS_BOUNTY_ENGINE_VERSION,
            'author' => 'Million Dollar Script',
            'url' => 'https://milliondollarscript.com/extensions/bounty-engine',
            'main_file' => __FILE__,
            'premium' => false,
            'license_required' => false,
            'cleanup' => [
                'description' => 'Bounty boards, bounties, claims, and extension settings.',
            ],
            'capabilities' => [
                'admin_pages' => true,
                'bounty_inventory' => true,
                'frontend_assets' => true,
                'api' => true,
            ],
        ]);
    }
}

function mds_bounty_engine_activate() {
    if (!mds_bounty_engine_check_mds_active()) {
        deactivate_plugins(MDS_BOUNTY_ENGINE_BASENAME);
        wp_die(
            esc_html__('Million Dollar Script - Bounty Engine requires the Million Dollar Script plugin to be installed and activated.', 'mds-bounty-engine'),
            esc_html__('Plugin Activation Error', 'mds-bounty-engine'),
            ['back_link' => true]
        );
    }

    mds_bounty_engine_load_files();
    Main::register_post_types();
    Repository::maybe_seed();
    if (!wp_next_scheduled(Main::CRON_HOOK)) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', Main::CRON_HOOK);
    }
    flush_rewrite_rules();
}

function mds_bounty_engine_deactivate() {
    mds_bounty_engine_load_files();
    wp_clear_scheduled_hook(Main::CRON_HOOK);
    flush_rewrite_rules();
}

register_activation_hook(__FILE__, __NAMESPACE__ . '\\mds_bounty_engine_activate');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\\mds_bounty_engine_deactivate');

add_action('plugins_loaded', __NAMESPACE__ . '\\mds_bounty_engine_init', 20);
add_action('million-dollar-script/register/extensions', __NAMESPACE__ . '\\mds_bounty_engine_register_extension');

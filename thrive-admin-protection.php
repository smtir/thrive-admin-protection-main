<?php
/**
 * Plugin Name:       Thrive Admin Protection
 * Plugin Slug:       thrive-admin-protection
 * Plugin URI:        https://www.thrivewebdesigns.com
 * Description:       Secure access control with plugin/theme blocking, alerts, and filtered log viewer.
 * Version:           2.0.1
 * Author:            Thrive Web Designs
 * Author URI:        https://www.thrivewebdesigns.com
 * Text Domain:       thrive-admin-protection
 * Domain Path:       /languages
 * Requires at least: 5.4
 * Tested up to:      6.5
 * Requires PHP:      7.4
 * License: GPLv2 or later  
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html  
 *
 * @package THRIVE_DEV_ADMIN_ACCESS
 */

defined('ABSPATH') || exit;

// Define constants
define('THRIVE_DEV_VERSION', '2.0.1');
define('THRIVE_DEV_TGM_ID', 'thrive-admin-access-lock');
define('THRIVE_DEV_TEXT_DOMAIN', 'thrive-admin-protection');
define('THRIVE_DEV_CONFIG_CACHE_KEY', 'thrive_config_cache');
define('THRIVE_DEV_CONFIG_VERSION_KEY', 'thrive_config_version');
define('THRIVE_DEV_CONFIG_FALLBACK_KEY', 'thrive_last_good_config');
define('THRIVE_DEV_CONFIG_LAST_FETCH_KEY', 'thrive_last_config_fetch');
define('THRIVE_DEV_PATH', plugin_dir_path(__FILE__));
define('THRIVE_DEV_URL', plugin_dir_url(__FILE__));
define('THRIVE_DEV_PLUGIN_FILE', __FILE__);

// Require files with existence checks
$required_files = [
    'class-bootstrap.php',
    'class-config-manager.php',
    'class-settings.php',
    'class-access-controller.php',
    'class-plugin-theme-blocker.php',
    'class-alert-manager.php',
    'class-log-manager.php',
    'class-helper.php',
    'class-log-table.php',
    'class-log-page.php'
];

foreach ($required_files as $file) {
    $file_path = THRIVE_DEV_PATH . 'includes/' . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
    } elseif (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Thrive: Missing file ' . $file_path);
    }
}

final class THRIVE_DEV_ADMIN_ACCESS {
    public static function init() {
        // Load text domain
        add_action('plugins_loaded', function () {
            load_plugin_textdomain(THRIVE_DEV_TEXT_DOMAIN, false, THRIVE_DEV_PATH . 'languages');
        });

        // Get remote config
        $remote_config = THRIVE_DEV_CONFIG_MANAGER::get_config();

        // Check if $remote_config is null or empty and log an error if it is
        if (is_null($remote_config) || empty($remote_config)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Thrive: Remote config is null or empty.');
            }
            return;  // Exit early if config is not available
        }

        if (class_exists('THRIVE_DEV_BOOTSTRAP')) {
            THRIVE_DEV_BOOTSTRAP::init();
        }

        if (class_exists('THRIVE_DEV_ACCESS_CONTROLLER')) {
            THRIVE_DEV_ACCESS_CONTROLLER::init();
        }

        if (class_exists('THRIVE_DEV_PLUGIN_THEMES_BLOCKER')) {
            THRIVE_DEV_PLUGIN_THEMES_BLOCKER::init();
        }

        if (class_exists('THRIVE_DEV_LOG_PAGE')) {
            THRIVE_DEV_LOG_PAGE::init();
        }
    }
}

THRIVE_DEV_ADMIN_ACCESS::init();

register_activation_hook(__FILE__, function () {
    try {
        THRIVE_DEV_BOOTSTRAP::on_activation();
    } catch (Exception $e) {
        THRIVE_DEV_HELPER::maybe_debug_log('Thrive: Activation error - ' . $e->getMessage());
    }
});
register_deactivation_hook(__FILE__, ['THRIVE_DEV_BOOTSTRAP', 'on_deactivation']);
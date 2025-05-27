<?php
/**
 * Plugin Name:       Thrive Admin Protection
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
 */

defined('ABSPATH') || exit;

// Define constants if not already defined
foreach ([
    'THRIVE_DEV_VERSION' => '2.0.1',
    'THRIVE_DEV_TGM_ID' => 'thrive-admin-access-lock',
    'THRIVE_DEV_TEXT_DOMAIN' => 'thrive-admin-protection',
    'THRIVE_DEV_CONFIG_CACHE_KEY' => 'thrive_config_cache',
    'THRIVE_DEV_CONFIG_VERSION_KEY' => 'thrive_config_version',
    'THRIVE_DEV_CONFIG_FALLBACK_KEY' => 'thrive_last_good_config',
    'THRIVE_DEV_CONFIG_LAST_FETCH_KEY' => 'thrive_last_config_fetch',
    'THRIVE_DEV_PATH' => plugin_dir_path(__FILE__),
    'THRIVE_DEV_URL' => plugin_dir_url(__FILE__),
    'THRIVE_DEV_PLUGIN_FILE' => __FILE__
] as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

final class THRIVE_DEV_ADMIN_ACCESS {
    private static $required_files = [
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

    private static $required_classes = [
        'THRIVE_DEV_BOOTSTRAP',
        'THRIVE_DEV_CONFIG_MANAGER',
        'THRIVE_DEV_ACCESS_CONTROLLER',
        'THRIVE_DEV_PLUGIN_THEMES_BLOCKER',
        'THRIVE_DEV_LOG_PAGE'
    ];

    public static function init() {
        // Load text domain
        add_action('plugins_loaded', function() {
            load_plugin_textdomain(THRIVE_DEV_TEXT_DOMAIN, false, THRIVE_DEV_PATH . 'languages');
        });

        if (!self::load_required_files()) {
            return;
        }

        if (!self::check_dependencies()) {
            return;
        }

        // Get config with fallback
        $config = THRIVE_DEV_CONFIG_MANAGER::get_config();

        // Initialize components
        foreach (self::$required_classes as $class) {
            if (class_exists($class)) {
                $class::init();
            }
        }
    }

    private static function load_required_files() {
        $failed_files = [];
        
        foreach (self::$required_files as $file) {
            $file_path = THRIVE_DEV_PATH . 'includes/' . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                $failed_files[] = $file;
            }
        }

        if (!empty($failed_files)) {
            add_action('admin_notices', function() use ($failed_files) {
                $message = sprintf(
                    __('Thrive Admin Protection: Missing required files: %s', THRIVE_DEV_TEXT_DOMAIN),
                    implode(', ', $failed_files)
                );
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            });
            return false;
        }

        return true;
    }

    private static function check_dependencies() {
        $missing_classes = [];
        
        foreach (self::$required_classes as $class) {
            if (!class_exists($class)) {
                $missing_classes[] = $class;
            }
        }

        if (!empty($missing_classes)) {
            add_action('admin_notices', function() use ($missing_classes) {
                $message = sprintf(
                    __('Thrive Admin Protection: Missing required classes: %s', THRIVE_DEV_TEXT_DOMAIN),
                    implode(', ', $missing_classes)
                );
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            });
            return false;
        }

        return true;
    }
}

// Initialize plugin
add_action('plugins_loaded', ['THRIVE_DEV_ADMIN_ACCESS', 'init']);

// Register hooks
register_activation_hook(__FILE__, function() {
    try {
        if (class_exists('THRIVE_DEV_BOOTSTRAP')) {
            THRIVE_DEV_BOOTSTRAP::on_activation();
        }
    } catch (Exception $e) {
        THRIVE_DEV_HELPER::maybe_debug_log('Activation error: ' . $e->getMessage());
        wp_die(
            esc_html__('Plugin activation failed. Please check error logs.', THRIVE_DEV_TEXT_DOMAIN),
            '',
            ['back_link' => true]
        );
    }
});

register_deactivation_hook(__FILE__, ['THRIVE_DEV_BOOTSTRAP', 'on_deactivation']);
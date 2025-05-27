<?php
defined('ABSPATH') || exit;

class THRIVE_DEV_BOOTSTRAP {
    /**
     * Initialize hooks that should always run.
     */
    public static function init() {
        // Add settings link on plugins page
        add_filter('plugin_action_links_' . plugin_basename(THRIVE_DEV_PLUGIN_FILE), [self::class, 'add_action_links']);
        
        add_action('admin_notices', [THRIVE_DEV_HELPER::class, 'display_admin_notices']);
        add_action('admin_notices', [THRIVE_DEV_HELPER::class, 'maybe_display_notice']);
        add_action('wp', [THRIVE_DEV_HELPER::class, 'init_front_end_notices']);

        add_action('admin_menu', [self::class, 'add_thrive_menu']);

        // Schedule maintenance tasks with proper intervals
        add_action('init', [self::class, 'schedule_maintenance']);

        // Dashboard widget for authorized users
        add_action('wp_dashboard_setup', [self::class, 'maybe_add_dashboard_widget']);

        // Handle plugin management
        add_action('admin_init', [self::class, 'handle_plugin_actions']);
    }

    /**
     * Add action links to plugins page
     */
    public static function add_action_links($links) {
        if (!current_user_can('manage_options')) {
            return $links;
        }

        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=thrive-settings')),
            esc_html__('Settings', THRIVE_DEV_TEXT_DOMAIN)
        );

        $log_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=thrive-log')),
            esc_html__('Log', THRIVE_DEV_TEXT_DOMAIN)
        );

        array_unshift($links, $log_link);
        array_unshift($links, $settings_link);

        return $links;
    }

    /**
     * Schedule maintenance tasks with appropriate intervals
     */
    public static function schedule_maintenance() {
        // Daily log check
        if (!wp_next_scheduled('thrive_daily_log_check')) {
            wp_schedule_event(time(), 'daily', 'thrive_daily_log_check');
        }

        // Hourly config refresh
        if (!wp_next_scheduled('thrive_hourly_config_refresh')) {
            wp_schedule_event(time(), 'hourly', 'thrive_hourly_config_refresh');
        }

        // Daily plugin check
        if (!wp_next_scheduled('thrive_daily_plugin_check')) {
            wp_schedule_event(time(), 'daily', 'thrive_daily_plugin_check');
        }
    }

    /**
     * Add dashboard widget if user has permission
     */
    public static function maybe_add_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'thrive_config_status',
            esc_html__('Thrive Config Status', THRIVE_DEV_TEXT_DOMAIN),
            [THRIVE_DEV_HELPER::class, 'render_config_status_widget']
        );
    }

    /**
     * Handle plugin management actions with proper security checks
     */
    public static function handle_plugin_actions() {
        if (!current_user_can('install_plugins')) {
            return;
        }

        // Verify nonce and user capabilities for each action
        if (isset($_GET['thrive_action']) && isset($_GET['_wpnonce'])) {
            $action = sanitize_text_field($_GET['thrive_action']);
            $nonce = sanitize_text_field($_GET['_wpnonce']);

            if (!wp_verify_nonce($nonce, 'thrive_plugin_action')) {
                wp_die(__('Security check failed.', THRIVE_DEV_TEXT_DOMAIN));
            }

            switch ($action) {
                case 'install_plugin':
                    if (isset($_GET['plugin'])) {
                        self::handle_plugin_installation(sanitize_text_field($_GET['plugin']));
                    }
                    break;
                case 'activate_plugin':
                    if (isset($_GET['plugin'])) {
                        self::handle_plugin_activation(sanitize_text_field($_GET['plugin']));
                    }
                    break;
            }
        }
    }

    /**
     * Handle plugin installation with proper security checks
     */
    private static function handle_plugin_installation($slug) {
        if (!current_user_can('install_plugins')) {
            wp_die(__('You do not have permission to install plugins.', THRIVE_DEV_TEXT_DOMAIN));
        }

        $status = install_plugin_from_directory($slug);
        
        if (is_wp_error($status)) {
            THRIVE_DEV_HELPER::display_notice(
                $status->get_error_message(),
                'error',
                admin_url('admin.php?page=thrive-plugins')
            );
        } else {
            THRIVE_DEV_HELPER::display_notice(
                __('Plugin installed successfully.', THRIVE_DEV_TEXT_DOMAIN),
                'success',
                admin_url('admin.php?page=thrive-plugins')
            );
        }
    }

    /**
     * Handle plugin activation with proper security checks
     */
    private static function handle_plugin_activation($slug) {
        if (!current_user_can('activate_plugins')) {
            wp_die(__('You do not have permission to activate plugins.', THRIVE_DEV_TEXT_DOMAIN));
        }

        $plugin_file = $slug . '/' . $slug . '.php';
        
        if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
            THRIVE_DEV_HELPER::display_notice(
                __('Plugin file not found.', THRIVE_DEV_TEXT_DOMAIN),
                'error',
                admin_url('admin.php?page=thrive-plugins')
            );
            return;
        }

        $result = activate_plugin($plugin_file);
        
        if (is_wp_error($result)) {
            THRIVE_DEV_HELPER::display_notice(
                $result->get_error_message(),
                'error',
                admin_url('admin.php?page=thrive-plugins')
            );
        } else {
            THRIVE_DEV_HELPER::display_notice(
                __('Plugin activated successfully.', THRIVE_DEV_TEXT_DOMAIN),
                'success',
                admin_url('admin.php?page=thrive-plugins')
            );
        }
    }

    /**
     * Plugin activation handler with proper cleanup
     */
    public static function on_activation() {
        // Clear any existing schedules
        wp_clear_scheduled_hook('thrive_daily_log_check');
        wp_clear_scheduled_hook('thrive_hourly_config_refresh');
        wp_clear_scheduled_hook('thrive_daily_plugin_check');

        // Schedule new tasks
        self::schedule_maintenance();

        // Initialize plugin settings with defaults
        if (!get_option('thrive_settings')) {
            update_option('thrive_settings', [
                'enable_logging' => true,
                'log_retention_days' => 30,
                'enable_notifications' => true
            ]);
        }
    }

    /**
     * Plugin deactivation handler with proper cleanup
     */
    public static function on_deactivation() {
        // Clear scheduled tasks
        wp_clear_scheduled_hook('thrive_daily_log_check');
        wp_clear_scheduled_hook('thrive_hourly_config_refresh');
        wp_clear_scheduled_hook('thrive_daily_plugin_check');
    }

    /**
     * Add Thrive menu and submenus with proper capability checks
     */
    public static function add_thrive_menu() {
        if (!current_user_can('manage_options')) {
            return;
        }

        add_menu_page(
            esc_html__('Thrive', THRIVE_DEV_TEXT_DOMAIN),
            esc_html__('Thrive', THRIVE_DEV_TEXT_DOMAIN),
            'manage_options',
            'thrive-settings',
            [THRIVE_DEV_SETTINGS::class, 'render'],
            'dashicons-shield',
            65
        );

        add_submenu_page(
            'thrive-settings',
            esc_html__('Settings', THRIVE_DEV_TEXT_DOMAIN),
            esc_html__('Settings', THRIVE_DEV_TEXT_DOMAIN),
            'manage_options',
            'thrive-settings',
            [THRIVE_DEV_SETTINGS::class, 'render']
        );

        add_submenu_page(
            'thrive-settings',
            esc_html__('Logs', THRIVE_DEV_TEXT_DOMAIN),
            esc_html__('Logs', THRIVE_DEV_TEXT_DOMAIN),
            'manage_options',
            'thrive-log',
            [THRIVE_DEV_LOG_PAGE::class, 'render']
        );

        add_submenu_page(
            'thrive-settings',
            esc_html__('Plugins', THRIVE_DEV_TEXT_DOMAIN),
            esc_html__('Plugins', THRIVE_DEV_TEXT_DOMAIN),
            'manage_options',
            'thrive-plugins',
            [THRIVE_DEV_PLUGIN_THEMES_BLOCKER::class, 'render']
        );
    }
}
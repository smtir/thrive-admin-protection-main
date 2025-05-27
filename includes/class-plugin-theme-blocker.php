<?php
/**
 * Plugin Name: Thrive Plugin & Theme Blocker
 * Description: Enforces blocked and required plugin/theme policies for Thrive.
 */

defined('ABSPATH') || exit;

class THRIVE_DEV_PLUGIN_THEMES_BLOCKER {

    public static function init() {
        $remote_config = THRIVE_DEV_CONFIG_MANAGER::get_config();
        if (is_null($remote_config) || empty($remote_config)) {
            THRIVE_DEV_HELPER::maybe_debug_log('Thrive: Remote config is null or empty.');
            return;
        }

        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Hook core restrictions
        add_action('activated_plugin', function ($plugin) use ($remote_config) {
            self::handle_plugin_activation($plugin, $remote_config['blocked_plugins']);
        }, 10, 1);

        add_action('switch_theme', function ($new_name, $new_theme) use ($remote_config) {
            $blocked_themes = $remote_config['blocked_themes'] ?? [];
            self::handle_theme_activation($new_theme, $blocked_themes);
        }, 10, 2);

        add_action('admin_init', function () use ($remote_config) {
            //self::handle_required_plugins($remote_config['required_plugins'] ?? []);
        });

        add_filter('plugin_action_links', function ($actions, $plugin_file) use ($remote_config) {
            return self::filter_plugin_action_links($actions, $plugin_file, $remote_config['blocked_plugins']);
        }, 10, 2);

        add_filter('site_transient_update_plugins', function ($value) use ($remote_config) {
            return self::filter_plugin_updates($value, $remote_config['blocked_plugins']);
        });

        add_filter('site_transient_update_themes', function ($value) use ($remote_config) {
            return self::filter_theme_updates($value, $remote_config['blocked_themes']);
        });

        add_filter('upgrader_package_options', function ($options) use ($remote_config) {
            return self::filter_installation($options, $remote_config);
        });

        // Enforce deactivation/deletion every minute
        if (!get_transient('trive_plugin_check') && is_admin()) {
            add_action('admin_init', function () use ($remote_config) {
                self::enforce($remote_config);
                set_transient('trive_plugin_check', 1, 60);
            });
        }

        // AJAX handlers
        add_action('wp_ajax_thrive_force_plugin_action', [__CLASS__, 'ajax_plugin_action']);
        add_action('wp_ajax_thrive_force_theme_action', [__CLASS__, 'ajax_theme_action']);

        add_action('admin_notices', function () {
            if (!current_user_can('install_plugins')) return;

            $config = THRIVE_DEV_CONFIG_MANAGER::get_config();
            $current_page = THRIVE_DEV_HELPER::get_current_admin_page();
            $required = $config['required_plugins'] ?? [];
            $missing = [];            

            foreach ($required as $plugin) {
                $slug = $plugin['slug'];
                $main = $slug . '/' . $slug . '.php';
                if (!file_exists(WP_PLUGIN_DIR . '/' . $main)) {
                    $missing[] = $plugin['name'] ?? $slug;
                }
            }

            if (!empty($missing) && $current_page !== 'thrive-plugins' && !THRIVE_DEV_HELPER::is_blocked_admin()) {
                echo '<div class="notice notice-error thrive-required-plugin-notice"><p>';
                echo 'Missing required plugins: ';
                echo ' Please install them from the <a href="' . esc_url(admin_url('admin.php?page=thrive-plugins')) . '">Thrive Dependencies</a> page.';
                echo '</p></div>';
            }
        });
    }

    public static function enforce($remote_config) {
        if (defined('DOING_AJAX') && DOING_AJAX) return;

        $active_plugins = get_option('active_plugins', []);
        $all_plugins    = get_plugins();

        // Deactivate and delete blocked plugins
        foreach ($all_plugins as $plugin_path => $plugin_data) {
            $slug = dirname($plugin_path);
            if (in_array($slug, $remote_config['blocked_plugins'], true)) {
                if (in_array($plugin_path, $active_plugins, true)) {
                    deactivate_plugins($plugin_path);
                    THRIVE_DEV_HELPER::maybe_debug_log('auto-deactivated-plugin', $slug);
                    if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                        THRIVE_DEV_LOG_MANAGER::log('auto-deactivated-plugin', $slug);
                    }
                }

                $dir = WP_PLUGIN_DIR . '/' . $slug;
                if (is_dir($dir)) {
                    THRIVE_DEV_LOG_MANAGER::delete_dir($dir);
                    THRIVE_DEV_HELPER::maybe_debug_log('auto-deleted-plugin', $slug);
                    if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                        THRIVE_DEV_LOG_MANAGER::log('auto-deleted-plugin', $slug);
                    }
                }
            }
        }

        // Deactivate and delete blocked themes
        $active_theme = wp_get_theme()->get_stylesheet();
        foreach ($remote_config['blocked_themes'] as $slug) {
            if ($slug === $active_theme) {
                // Switch to fallback theme
                $fallbacks = ['twentytwentyfour', 'twentytwentythree', 'twentytwentyfive'];
                foreach ($fallbacks as $fallback) {
                    if (wp_get_theme($fallback)->exists()) {
                        switch_theme($fallback);
                        THRIVE_DEV_HELPER::maybe_debug_log('blocked-theme-deactivated', $slug);
                        if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                            THRIVE_DEV_LOG_MANAGER::log('blocked-theme-deactivated', $slug);
                        }
                        break;
                    }
                }
            }

            // Delete the theme directory if exists and not active
            $theme_dir = get_theme_root() . '/' . $slug;
            if (is_dir($theme_dir) && $slug !== wp_get_theme()->get_stylesheet()) {
                global $wp_filesystem;
                if (empty($wp_filesystem)) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    WP_Filesystem();
                }
                $wp_filesystem->delete($theme_dir, true);
                THRIVE_DEV_HELPER::maybe_debug_log('auto-deleted-theme', $slug);
                if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                    THRIVE_DEV_LOG_MANAGER::log('auto-deleted-theme', $slug);
                }
            }
        }
    }

    public static function enqueue_assets() {
        wp_enqueue_script('toastify', 'https://cdn.jsdelivr.net/npm/toastify-js', [], null, true);
        wp_enqueue_style('toastify', 'https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css');

        wp_enqueue_script(
            'thrive-dev-handler',
            plugins_url('assets/js/thrive-script.js', dirname(__FILE__)),
            ['jquery'],
            null,
            true
        );

        wp_localize_script('thrive-dev-handler', 'ThrivePluginAjax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('thrive_plugin_action')
        ]);

        wp_enqueue_style(
            'thrive-dev-style',
            plugins_url('assets/css/thrive-style.css', dirname(__FILE__))
        );
    }

    public static function handle_plugin_activation($plugin, $blocked_plugins) {
        $slug = dirname($plugin);
        if (in_array($slug, $blocked_plugins, true)) {
            deactivate_plugins($plugin);
            THRIVE_DEV_HELPER::maybe_debug_log('plugin-activation-blocked', $slug);

            if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                THRIVE_DEV_LOG_MANAGER::log('plugin-activation-blocked', $slug);
            }
            THRIVE_DEV_HELPER::display_notice(sprintf(__('Plugin "%s" is blocked and was deactivated.', THRIVE_DEV_TEXT_DOMAIN), esc_html($slug)), 'error');
        }
    }

    public static function handle_theme_activation($new_theme, $blocked_themes) {
        $slug = $new_theme->get_stylesheet();

        if (in_array($slug, $blocked_themes, true)) {
            // Try fallback themes in order
            $fallbacks = ['twentytwentyfour', 'twentytwentythree', 'twentytwentyfive'];
            $found = false;

            foreach ($fallbacks as $fallback_slug) {
                $theme = wp_get_theme($fallback_slug);
                if ($theme->exists()) {
                    switch_theme($fallback_slug);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                // Optional: show error notice if no fallback theme available
                THRIVE_DEV_HELPER::display_notice(__('Blocked theme deactivated, but no fallback theme found. Please install a default theme manually.', THRIVE_DEV_TEXT_DOMAIN), 'error');
            }

            THRIVE_DEV_HELPER::maybe_debug_log('theme-activation-blocked', $slug);

            if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                THRIVE_DEV_LOG_MANAGER::log('theme-activation-blocked', $slug);
            }

            THRIVE_DEV_HELPER::display_notice(
                sprintf(__('Theme "%s" is blocked and was deactivated.', THRIVE_DEV_TEXT_DOMAIN), esc_html($slug)),
                'error'
            );
        }
    }

    public static function handle_required_plugins($required_plugins) {
        include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        foreach ($required_plugins as $plugin) {
            $slug = $plugin['slug'];
            $main_file = $slug . '/' . $slug . '.php';

            if (!self::is_installed($slug)) {
                $api = plugins_api('plugin_information', ['slug' => $slug]);
                if (!is_wp_error($api)) {
                    $upgrader = new Plugin_Upgrader();
                    $upgrader->install($api->download_link);
                    THRIVE_DEV_HELPER::maybe_debug_log('plugin-installed', $slug);

                    if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                        THRIVE_DEV_LOG_MANAGER::log('plugin-installed', $slug);
                    }
                }
            }

            if ($plugin['force_activation'] && !is_plugin_active($main_file)) {
                activate_plugin($main_file);
                THRIVE_DEV_HELPER::maybe_debug_log('plugin-force-activated', $slug);

                if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                    THRIVE_DEV_LOG_MANAGER::log('plugin-force-activated', $slug);
                }
                THRIVE_DEV_HELPER::display_notice("Activated required plugin: {$plugin['name']}", 'success');
            }
        }
    }

    public static function filter_plugin_action_links($actions, $plugin_file, $blocked_plugins) {
        $slug = dirname($plugin_file);
        if (in_array($slug, $blocked_plugins, true)) unset($actions['activate']);
        return $actions;
    }

    public static function filter_plugin_updates($value, $blocked_plugins) {
        if (!isset($value->response)) return $value;
        foreach ($value->response as $plugin_path => $data) {
            $slug = dirname($plugin_path);
            if (in_array($slug, $blocked_plugins, true)) {
                unset($value->response[$plugin_path]);
                THRIVE_DEV_HELPER::maybe_debug_log('plugin-update-blocked', $slug);

                if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                    THRIVE_DEV_LOG_MANAGER::log('plugin-update-blocked', $slug);
                }
            }
        }
        return $value;
    }

    public static function filter_theme_updates($value, $blocked_themes) {
        if (!isset($value->response)) return $value;
        foreach ($blocked_themes as $slug) {
            if (isset($value->response[$slug])) {
                unset($value->response[$slug]);
                THRIVE_DEV_HELPER::maybe_debug_log('theme-update-blocked', $slug);

                if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                    THRIVE_DEV_LOG_MANAGER::log('theme-update-blocked', $slug);
                }
            }
        }
        return $value;
    }

    public static function filter_installation($options, $remote_config) {
        $destination = $options['destination'] ?? '';

        foreach ($remote_config['blocked_plugins'] as $slug) {
            if (stripos($destination, '/plugins/' . $slug) !== false) {
                THRIVE_DEV_HELPER::maybe_debug_log('plugin-install-blocked', $slug);

                if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                    THRIVE_DEV_LOG_MANAGER::log('plugin-install-blocked', $slug);
                }
                THRIVE_DEV_HELPER::display_notice(sprintf(__('Installation blocked: plugin "%s"', THRIVE_DEV_TEXT_DOMAIN), esc_html($slug)), 'error');
            }
        }

        foreach ($remote_config['blocked_themes'] as $slug) {
            if (stripos($destination, '/themes/' . $slug) !== false) {
                THRIVE_DEV_HELPER::maybe_debug_log('theme-install-blocked', $slug);

                if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                    THRIVE_DEV_LOG_MANAGER::log('theme-install-blocked', $slug);
                }
                THRIVE_DEV_HELPER::display_notice(sprintf(__('Installation blocked: theme "%s"', THRIVE_DEV_TEXT_DOMAIN), esc_html($slug)), 'error');
            }
        }

        return $options;
    }

    public static function is_installed($slug) {
        $all = get_plugins();
        foreach ($all as $plugin_file => $data) {
            if (strpos($plugin_file, $slug . '/') === 0) return true;
        }
        return false;
    }

    public static function ajax_plugin_action() {
        check_ajax_referer('thrive_plugin_action', 'nonce');

        $slug = sanitize_text_field($_POST['slug']);
        $action_type = sanitize_text_field($_POST['action_type']);
        $main_file = $slug . '/' . $slug . '.php';

        include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        if ($action_type === 'install') {
            if (!file_exists(WP_PLUGIN_DIR . '/' . $main_file)) {
                $api = plugins_api('plugin_information', ['slug' => $slug]);
                if (!is_wp_error($api)) {
                    $upgrader = new Plugin_Upgrader(new Thrive_Silent_Skin());
                    $result = $upgrader->install($api->download_link);
                    if (is_wp_error($result)) {
                        wp_send_json_error(['message' => 'Install failed.']);
                    }
                }
            }
        } elseif ($action_type === 'activate') {
            if (file_exists(WP_PLUGIN_DIR . '/' . $main_file) && !is_plugin_active($main_file)) {
                activate_plugin($main_file);
            }
        }

        wp_send_json_success(['message' => ucfirst($action_type) . ' complete.']);
    }

    public static function ajax_theme_action() {
        check_ajax_referer('thrive_plugin_action', 'nonce');

        $slug = sanitize_text_field($_POST['slug']);
        $action_type = sanitize_text_field($_POST['action_type']);

        if ($action_type === 'deactivate' || $action_type === 'delete') {
            $current_theme = wp_get_theme();
            if ($current_theme->get_stylesheet() === $slug) {
                switch_theme('twentytwentyfour');
            }

            if ($action_type === 'delete') {
                $theme_dir = get_theme_root() . '/' . $slug;
                if (is_dir($theme_dir)) {
                    global $wp_filesystem;
                    if (empty($wp_filesystem)) {
                        require_once ABSPATH . '/wp-admin/includes/file.php';
                        WP_Filesystem();
                    }
                    $wp_filesystem->delete($theme_dir, true);
                }
            }

            wp_send_json_success(['message' => ucfirst($action_type) . ' theme complete.']);
        }

        wp_send_json_error(['message' => 'Invalid request.']);
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;

        $remote_config = THRIVE_DEV_CONFIG_MANAGER::get_config();
        $required_plugins = $remote_config['required_plugins'] ?? [];

        $needs_install = false;
        $needs_activate = false;

        foreach ($required_plugins as $plugin) {
            $slug = $plugin['slug'];
            $path = $slug . '/' . $slug . '.php';
            $is_installed = file_exists(WP_PLUGIN_DIR . '/' . $path);
            $is_active = is_plugin_active($path);

            if (!$is_installed) {
                $needs_install = true;
            } elseif (!$is_active) {
                $needs_activate = true;
            }
        }

        echo '<div class="wrap" id="thrive-dependencies">';
        echo '<h1>Thrive Dependencies</h1>';

        if ($needs_install || $needs_activate) {
            echo '<div id="thrive-bulk-actions" style="margin-bottom: 10px;">';
            if ($needs_install) {
                echo '<button id="thrive-bulk-install" class="button button-secondary">Install All</button> ';
            }
            if ($needs_activate) {
                echo '<button id="thrive-bulk-activate" class="button button-secondary">Activate All</button>';
            }
            echo '</div>';
        }

        echo '<div id="thrive-dependency-table">';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>Name</th><th>Slug</th><th>Type</th><th>Status</th><th>Action</th></tr></thead><tbody>';

        // Plugins
        foreach ($required_plugins as $plugin) {
            $slug = sanitize_text_field($plugin['slug']);
            $name = isset($plugin['name']) ? esc_html($plugin['name']) : esc_html($slug);
            $plugin_file = $slug . '/' . $slug . '.php';

            $is_installed = file_exists(WP_PLUGIN_DIR . '/' . $plugin_file);
            $active_plugins = get_option('active_plugins', []);
            $is_active = in_array($plugin_file, $active_plugins, true);

            $status = '';
            if ($is_active && $is_installed) {
                $status = '&#9989; Active';
            } elseif ($is_installed) {
                $status = '&#9888;&#65039; Installed';
            } else {
                $status = '&#10060; Not Installed';
            }

            // Action buttons
            $action = '';
            if (!$is_installed) {
                $action = '<button class="button button-primary thrive-plugin-action" data-slug="' . esc_attr($slug) . '" data-action="install" aria-label="Install plugin ' . esc_attr($name) . '">Install</button>';
            } elseif (!$is_active) {
                $action = '<button class="button thrive-plugin-action" data-slug="' . esc_attr($slug) . '" data-action="activate" aria-label="Activate plugin ' . esc_attr($name) . '">Activate</button>';
            }

            echo "<tr class='thrive-plugin-row' data-slug='" . esc_attr($slug) . "' data-type='plugin' data-installed='" . ($is_installed ? '1' : '0') . "' data-active='" . ($is_active ? '1' : '0') . "'>";
            echo "<td>{$name}</td><td>" . esc_html($slug) . "</td><td>Plugin</td><td>{$status}</td><td>{$action}</td></tr>";
        }

        echo '</tbody></table></div></div>';
    }
}

if (!class_exists('Automatic_Upgrader_Skin')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

class Thrive_Silent_Skin extends Automatic_Upgrader_Skin {
    public function feedback($string, ...$args) {
        // Silenced for AJAX
    }
}
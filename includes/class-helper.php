<?php
defined('ABSPATH') || exit;

/**
 * Class THRIVE_DEV_HELPER
 *
 * Provides shared UI utility methods such as admin notices and redirects.
 */
class THRIVE_DEV_HELPER {
    /**
     * Get the visitor's IP address.
     *
     * @return string IP address
     */
    public static function get_ip(): string {
        // Prioritize known headers
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return trim($_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return trim($_SERVER['HTTP_X_REAL_IP']);
        }
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return trim($_SERVER['HTTP_CLIENT_IP']);
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($forwarded[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    /**
     * Check if the IP is in the blacklist.
     *
     * @param string $ip
     * @param array $blacklist List of IPs or CIDR ranges
     * @return bool
     */
    public static function is_blacklisted($ip, array $blacklist) {
        foreach ($blacklist as $blocked) {
            $blocked = trim($blocked);
            if (strpos($blocked, '/') !== false) {
                if (self::ip_in_cidr($ip, $blocked)) return true;
            } elseif ($ip === $blocked) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the IP is in the blacklisted.
     *
     * @return bool
     */
    public static function is_blocked_admin() {
        // Skip for AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        $remote_config = THRIVE_DEV_CONFIG_MANAGER::get_config();
        $user_ip = self::get_ip();
        $logged_user = wp_get_current_user();
        $is_admin = in_array('administrator', (array)$logged_user->roles, true);
        $is_blacklisted = self::is_blacklisted($user_ip, $remote_config['blacklist_ips'] ?? []);
        return $is_admin && $is_blacklisted;
    }

    /**
     * Check if IP is inside a CIDR block.
     *
     * @param string $ip
     * @param string $cidr
     * @return bool
     */
    public static function ip_in_cidr($ip, $cidr) {
        [$subnet, $mask] = explode('/', $cidr);
        return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === (ip2long($subnet) & ~((1 << (32 - $mask)) - 1));
    }

    /**
     * Get the current admin page slug.
     *
     * @return string
     */
    public static function get_current_admin_page(): string {
        $script = basename($_SERVER['PHP_SELF'] ?? '');
        
        // Handle pages accessed via query parameters
        if ($script === 'admin.php' && !empty($_GET['page'])) {
            return sanitize_text_field($_GET['page']);
        }

        // Fallback to script name
        return $script;
    }

    /**
     * Display or redirect with a notice.
     *
     * @param string $msg       The notice message.
     * @param string $type      Notice type: success, error, warning, info.
     * @param string $redirect  Target URL (defaults to admin dashboard).
     * @param bool   $same_page Whether to display on the same page.
     */
    public static function display_notice(string $msg, string $type = 'error', string $redirect = '', bool $same_page = false): void {
        // Sanitize inputs
        $msg = esc_html($msg);
        $type = in_array($type, ['success', 'error', 'warning', 'info']) ? $type : 'warning';
        $redirect = $redirect ? esc_url_raw($redirect) : admin_url();

        // Store notice in transient
        set_transient('thrive_notice', [
            'msg'  => $msg,
            'type' => $type,
        ], 60);

        if ($same_page) {
            // Display immediately
            $wp_class = 'notice-' . $type;
            echo '<div class="notice ' . esc_attr($wp_class) . ' is-dismissible"><p><strong>' . $msg . '</strong></p></div>';
            return;
        }

        // Handle redirect
        //if (!headers_sent()) {
        // Perform redirect using wp_redirect for better compatibility
        wp_redirect(add_query_arg('thrive_notice', '1', $redirect));
        exit;
        // } else {
        //     // Fallback to JavaScript redirect if headers already sent
        //     error_log('Thrive: Headers already sent, using JavaScript redirect to ' . $redirect);
        //     $redirect = add_query_arg('thrive_notice', '1', $redirect);
        //     echo '<script>window.location.href="' . esc_url($redirect) . '";</script>';
        //     exit;
        // }
    }

    /**
     * Redirect with a notice (for backward compatibility).
     *
     * @param string $msg       The notice message.
     * @param string $redirect  Target URL (defaults to admin dashboard).
     * @param string $type      Notice type: success, error, warning, info.
     */
    public static function redirect_with_notice(string $msg, string $redirect = '', string $type = 'error'): void {
        self::display_notice($msg, $redirect, $type, false);
    }

    /**
     * Display stored notices.
     */
    public static function maybe_display_notice(): void {
        $notice = get_transient('thrive_notice');
        if ($notice && is_array($notice)) {
            $msg = esc_html($notice['msg'] ?? '');
            $type = $notice['type'] ?? 'error';
            $wp_class = 'notice-' . (in_array($type, ['success', 'error', 'warning', 'info']) ? $type : 'error');

            echo '<div class="notice ' . esc_attr($wp_class) . ' is-dismissible"><p><strong>' . $msg . '</strong></p></div>';
            delete_transient('thrive_notice');
        }
    }

    /**
     * Initialize front-end notice display.
     */
    public static function init_front_end_notices(): void {
        if (is_admin()) {
            return;
        }
        add_action('wp_footer', [self::class, 'maybe_display_notice']);
    }

    /**
     * Displays all relevant admin notices for plugin requirements and config issues.
     */
    public static function display_admin_notices(): void {
        if(self::is_blocked_admin()) {
            return;
        }
        // Check for configuration issues
        $config = THRIVE_DEV_CONFIG_MANAGER::get_config();
        if (empty($config)) {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Thrive', THRIVE_DEV_TEXT_DOMAIN),
                esc_html__('No configuration loaded. Please check API settings.', THRIVE_DEV_TEXT_DOMAIN)
            );
        }

        // Check for missing logging classes
        if (!class_exists('THRIVE_DEV_LOG_MANAGER') || !class_exists('THRIVE_DEV_LOG_TABLE')) {
            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
                esc_html__('Thrive', THRIVE_DEV_TEXT_DOMAIN),
                esc_html__('Logging functionality is disabled due to missing classes.', THRIVE_DEV_TEXT_DOMAIN)
            );
        }
    }

    /**
     * Render a small status widget showing config version and last sync date.
     */
    public static function render_config_status_widget(): void {
        $version = get_option(THRIVE_DEV_CONFIG_VERSION_KEY) ?: esc_html__('Not available', THRIVE_DEV_TEXT_DOMAIN);
        $last_fetched = get_option(THRIVE_DEV_CONFIG_LAST_FETCH_KEY) ?:esc_html__('Never', THRIVE_DEV_TEXT_DOMAIN);

        $formatted_date = is_string($last_fetched) && strpos($last_fetched, '<em>') === false
            ? date_i18n('F j, Y \a\t g:i A', strtotime($last_fetched))
            : $last_fetched;

        echo '<ul style="margin-left:1em;">';
        echo '<li><strong>' . esc_html__('Config Version:', THRIVE_DEV_TEXT_DOMAIN) . '</strong> <code>' . esc_html($version) . '</code></li>';
        echo '<li><strong>' . esc_html__('Last Fetched:', THRIVE_DEV_TEXT_DOMAIN) . '</strong> ' . $formatted_date . '</li>';
        echo '</ul>';

        $refresh_url = esc_url(add_query_arg('thrive_force_sync', '1'));

        echo '<div style="display: flex; gap: 20px; justify-content: space-between;">';

        echo '<a href="' . $refresh_url . '" class="button" style="background: #0a7d24;color: #fff;border-color: #10a331;">' .
            esc_html__('Force Sync Config', THRIVE_DEV_TEXT_DOMAIN) . '</a>';

        echo '<a href="#" class="button button-primary show_config">' .
            esc_html__('Show Config', THRIVE_DEV_TEXT_DOMAIN) . '</a>';

        echo '</div>';

        if (strpos($last_fetched, '<em>') !== false) {
            echo '<div class="notice notice-error inline"><p>' .
                esc_html__('Remote configuration has not been fetched yet. Please check your connection or token.', THRIVE_DEV_TEXT_DOMAIN) .
                '</p></div>';
        }

        echo '<div class="thrive_config" style="display:none;">';
        $remote_config = THRIVE_DEV_CONFIG_MANAGER::get_config();
        if (empty($remote_config)) {
            echo '<div class="notice notice-error inline"><p>' .
                esc_html__('Remote configuration is empty. Please check your connection or token.', THRIVE_DEV_TEXT_DOMAIN) .
                '</p></div>';
            return;
        }

        $cached = get_transient(THRIVE_DEV_CONFIG_CACHE_KEY);
        if (empty($cached)) {
            $cached = [];
        }

        if ($cached && is_array($cached)) {
            echo '<pre style="background:#f9f9f9;border:1px solid #ddd;padding:10px;overflow:auto;">';
            echo esc_html(json_encode($cached, JSON_PRETTY_PRINT));
            echo '</pre>';
            echo '</div>';
        }

        // Add jQuery script for toggle functionality
        echo '<script>
            jQuery(document).ready(function($) {
                $(".show_config").on("click", function(e) {
                    e.preventDefault();
                    $(".thrive_config").toggle();
                });
            });
        </script>';
    }
    
    /**
     * Validates IP address or CIDR notation.
     *
     * @param string $entry
     * @return bool
     */
    public static function is_valid_ip_or_cidr($entry) {
        $entry = trim($entry);

        if (filter_var($entry, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (strpos($entry, '/') !== false) {
            [$ip, $mask] = explode('/', $entry);
            return (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6))
                && is_numeric($mask) && $mask >= 0 && $mask <= 128;
        }

        return false;
    }

    /**
     * Validates a plugin/theme slug (alphanumeric, dashes, underscores).
     *
     * @param string $slug
     * @return bool
     */
    public static function is_valid_slug($slug) {
        return is_string($slug) && preg_match('/^[a-z0-9\-_]+$/i', $slug);
    }

    /**
     * Validates a WordPress admin page slug.
     *
     * @param string $page
     * @return bool
     */
    public static function is_valid_admin_page($page) {
        // Define a list of allowed restricted pages (WordPress admin page slugs)
        $valid_admin_pages = [
            'themes.php',
            'users.php',
            'plugins.php',
            'settings.php',
            'options-general.php',
            'plugins.php',
            'upload.php',
            'tools.php',
            'dashboard.php',
            // Add any other WordPress admin pages you expect here
        ];

        // If the page exists in the list of valid admin pages, return true
        return in_array($page, $valid_admin_pages);
    }

    /**
     * Log a message if WP_DEBUG is enabled.
     *
     * @param string $message
     */
    public static function maybe_debug_log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Thrive: ' . $message);
        }
    }

    /**
     * Normalize the configuration data.
     *
     * @param array $data
     * @return array
     */
    public static function normalize_config(array $data): array {
        $data['blacklist_ips']    = array_filter(array_map('trim', $data['blacklist_ips'] ?? []), [self::class, 'is_valid_ip_or_cidr']);
        $data['blocked_plugins']  = array_filter($data['blocked_plugins'] ?? [], [self::class, 'is_valid_slug']);
        $data['blocked_themes']   = array_filter($data['blocked_themes'] ?? [], [self::class, 'is_valid_slug']);
        $data['restricted_pages'] = array_filter($data['restricted_pages'] ?? [], [self::class, 'is_valid_admin_page']);
        return $data;
    }
}
<?php
defined('ABSPATH') || exit;

class THRIVE_DEV_ACCESS_CONTROLLER {
    /**
     * Initialize access control hooks with proper security measures
     */
    public static function init() {
        // Apply access control early but after authentication
        add_action('init', [self::class, 'enforce'], 1);
        
        // Protect AJAX endpoints
        add_action('admin_init', [self::class, 'protect_ajax']);
        
        // Protect REST API endpoints
        add_filter('rest_authentication_errors', [self::class, 'protect_rest_api']);
    }

    /**
     * Core access control enforcement with fail-secure defaults
     */
    public static function enforce() {
        try {
            // Get config with secure fallback
            $config = THRIVE_DEV_CONFIG_MANAGER::get_config();
            if (empty($config)) {
                $config = self::get_secure_fallback_config();
            }
            
            // Always check user capabilities first
            if (!self::verify_user_access()) {
                self::handle_access_denied('insufficient_permissions');
                return;
            }

            // Check IP restrictions
            if (!self::verify_ip_access($config)) {
                self::handle_access_denied('ip_restricted');
                return;
            }

            // Verify the current page/action is allowed
            if (!self::verify_page_access($config)) {
                self::handle_access_denied('page_restricted');
                return;
            }

        } catch (Exception $e) {
            // Log error and fail secure
            THRIVE_DEV_HELPER::maybe_debug_log('Access control error: ' . $e->getMessage());
            self::handle_access_denied('system_error');
        }
    }

    /**
     * Get secure fallback configuration
     */
    private static function get_secure_fallback_config() {
        return [
            'restricted_pages' => [
                'plugins.php',
                'themes.php',
                'tools.php',
                'options-general.php'
            ],
            'blacklist_ips' => [],
            'fail_secure' => true
        ];
    }

    /**
     * Verify user has required capabilities
     */
    private static function verify_user_access(): bool {
        if (!is_user_logged_in()) {
            return false;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return false;
        }

        return true;
    }

    /**
     * Verify IP is allowed with proper validation
     */
    private static function verify_ip_access(array $config): bool {
        $user_ip = THRIVE_DEV_HELPER::get_ip();
        
        // IP validation
        if (!filter_var($user_ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Check blacklist
        if (isset($config['blacklist_ips']) && is_array($config['blacklist_ips'])) {
            if (THRIVE_DEV_HELPER::is_blacklisted($user_ip, $config['blacklist_ips'])) {
                if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                    THRIVE_DEV_LOG_MANAGER::log('ip_blocked', $user_ip);
                }
                return false;
            }
        }

        return true;
    }

    /**
     * Verify current page access is allowed
     */
    private static function verify_page_access(array $config): bool {
        $current_page = self::get_current_page();
        
        // Check if page is restricted
        if (isset($config['restricted_pages']) && 
            is_array($config['restricted_pages']) && 
            in_array($current_page, $config['restricted_pages'], true)) {
            
            if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                THRIVE_DEV_LOG_MANAGER::log('page_blocked', $current_page);
            }
            return false;
        }

        return true;
    }

    /**
     * Get current page with XSS protection
     */
    private static function get_current_page(): string {
        $page = '';
        
        if (isset($_SERVER['PHP_SELF'])) {
            $page = basename(sanitize_text_field($_SERVER['PHP_SELF']));
        }
        
        if (isset($_GET['page'])) {
            $page = sanitize_text_field($_GET['page']);
        }

        return $page;
    }

    /**
     * Protect AJAX endpoints with proper validation
     */
    public static function protect_ajax() {
        // Verify nonce for all AJAX requests
        if (!check_ajax_referer('thrive_ajax_nonce', false, false)) {
            wp_send_json_error(['message' => 'Invalid security token'], 403);
            exit;
        }

        // Apply same access controls to AJAX
        if (!self::verify_user_access() || 
            !self::verify_ip_access(THRIVE_DEV_CONFIG_MANAGER::get_config())) {
            wp_send_json_error(['message' => 'Access denied'], 403);
            exit;
        }
    }

    /**
     * Protect REST API endpoints
     */
    public static function protect_rest_api($errors) {
        if ($errors) {
            return $errors;
        }

        // Apply access controls to REST API
        if (!self::verify_user_access() || 
            !self::verify_ip_access(THRIVE_DEV_CONFIG_MANAGER::get_config())) {
            return new WP_Error(
                'rest_forbidden',
                __('Access denied', THRIVE_DEV_TEXT_DOMAIN),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Handle access denied with proper security headers
     */
    private static function handle_access_denied(string $reason) {
        // Set security headers
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('X-XSS-Protection: 1; mode=block');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('Content-Security-Policy: default-src \'self\'');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Log access attempt
        if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
            THRIVE_DEV_LOG_MANAGER::log('access_denied', $reason);
        }

        // Handle based on request type
        if (defined('DOING_AJAX') && DOING_AJAX) {
            wp_send_json_error(['message' => 'Access denied'], 403);
            exit;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            wp_send_json_error(['message' => 'Access denied'], 403);
            exit;
        }

        // Regular request - show access denied page
        wp_die(
            esc_html__('Access Denied', THRIVE_DEV_TEXT_DOMAIN),
            esc_html__('Access Denied', THRIVE_DEV_TEXT_DOMAIN),
            ['response' => 403]
        );
    }
}
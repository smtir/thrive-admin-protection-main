<?php
defined('ABSPATH') || exit;

class THRIVE_DEV_CONFIG_MANAGER {
    private static $default_config = [
        'version' => '1.0.0',
        'restricted_pages' => ['plugins.php', 'themes.php'],
        'blocked_plugins' => [],
        'blocked_themes' => [],
        'blacklist_ips' => [],
    ];

    public static function get_config() {
        try {
            // Try cached config first
            $cached = get_transient(THRIVE_DEV_CONFIG_CACHE_KEY);
            if ($cached && is_array($cached)) {
                return self::validate_config($cached) ? $cached : self::get_fallback_config();
            }

            $api_url = THRIVE_DEV_SETTINGS::api_url() ?? '';

            // Basic API config validation
            if (empty($api_url) || !filter_var($api_url, FILTER_VALIDATE_URL)) {
                THRIVE_DEV_HELPER::maybe_debug_log('Invalid API URL configuration');
                return self::get_fallback_config();
            }

            // Remote request with timeout and error handling
            $response = wp_remote_get($api_url, [
                'timeout'    => apply_filters('thrive_admin_config_timeout', 10),
                'sslverify'  => true,
            ]);

            if (is_wp_error($response)) {
                THRIVE_DEV_HELPER::maybe_debug_log('API request failed: ' . $response->get_error_message());
                return self::get_fallback_config();
            }

            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code !== 200) {
                THRIVE_DEV_HELPER::maybe_debug_log("API returned non-200 status: $http_code");
                return self::get_fallback_config();
            }

            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($data)) {
                THRIVE_DEV_HELPER::maybe_debug_log('Invalid JSON response from API');
                return self::get_fallback_config();
            }

            // Validate and normalize
            $data = self::validate_config($data) ? 
                   apply_filters('thrive_admin_filter_remote_config', THRIVE_DEV_HELPER::normalize_config($data)) : 
                   self::get_fallback_config();

            // Update cache and metadata only if valid
            if ($data !== self::get_fallback_config()) {
                $remote_version = $data['version'] ?? '';
                update_option(THRIVE_DEV_CONFIG_FALLBACK_KEY, $data);
                update_option(THRIVE_DEV_CONFIG_VERSION_KEY, $remote_version);
                update_option(THRIVE_DEV_CONFIG_LAST_FETCH_KEY, current_time('mysql'));
                set_transient(THRIVE_DEV_CONFIG_CACHE_KEY, $data, 60 * MINUTE_IN_SECONDS);
            }

            return $data;

        } catch (Exception $e) {
            THRIVE_DEV_HELPER::maybe_debug_log('Config retrieval error: ' . $e->getMessage());
            return self::get_fallback_config();
        }
    }

    public static function get_fallback_config() {
        // Try last known good config first
        $fallback = get_option(THRIVE_DEV_CONFIG_FALLBACK_KEY);
        if (is_array($fallback) && self::validate_config($fallback)) {
            return $fallback;
        }

        // Fall back to default config if no valid stored fallback
        return self::$default_config;
    }

    public static function validate_config($config) {
        if (!is_array($config)) {
            return false;
        }

        $required_keys = ['version', 'restricted_pages', 'blocked_plugins', 'blocked_themes', 'blacklist_ips'];
        foreach ($required_keys as $key) {
            if (!isset($config[$key])) {
                return false;
            }
        }

        // Validate arrays
        if (!is_array($config['restricted_pages']) || 
            !is_array($config['blocked_plugins']) || 
            !is_array($config['blocked_themes']) || 
            !is_array($config['blacklist_ips'])) {
            return false;
        }

        return true;
    }

    public static function refresh() {
        delete_transient(THRIVE_DEV_CONFIG_CACHE_KEY);
        delete_option(THRIVE_DEV_CONFIG_VERSION_KEY);
        return self::get_config();
    }
}
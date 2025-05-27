<?php
defined('ABSPATH') || exit;

/**
 * Class THRIVE_DEV_CONFIG_MANAGER
 *
 * Manages remote configuration including:
 * - Blacklisted IPs
 * - Blocked plugins and themes
 * - Restricted admin pages
 * - Required plugins via TGMPA
 * - Version tracking and fallback
 */
class THRIVE_DEV_CONFIG_MANAGER {
    /**
     * Retrieves and caches the remote config.
     * Falls back to last known good config on failure.
     *
     * @return array
     */
    public static function get_config() {
        $cached = get_transient(THRIVE_DEV_CONFIG_CACHE_KEY);
        if ($cached && is_array($cached)) {
            return $cached;
        }

        $api_url = THRIVE_DEV_SETTINGS::api_url() ?? '';

        // Basic API config validation
        if (empty($api_url)) {
            THRIVE_DEV_HELPER::maybe_debug_log('API URL is missing');
            THRIVE_DEV_HELPER::display_notice(
                __('Thrive: Configuration could not be loaded due to missing API URL.', THRIVE_DEV_TEXT_DOMAIN),
                'error',
                admin_url(),
            );
            return [];
        }

        if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
            THRIVE_DEV_HELPER::maybe_debug_log('Invalid API URL: ' . $api_url);
            THRIVE_DEV_HELPER::maybe_display_notice(
                __('Thrive: Invalid API URL configured.', THRIVE_DEV_TEXT_DOMAIN),
                'error',
                admin_url(),
            );
            return [];
        }

        // Remote request
        $response = wp_remote_get($api_url, [
            'timeout'    => apply_filters('thrive_admin_config_timeout', 10),
            'sslverify'  => true,
        ]);

        // HTTP and transport error check
        if (is_wp_error($response)) {
            THRIVE_DEV_HELPER::maybe_debug_log('API request failed: ' . $response->get_error_message());
            
            $fallback = get_option(THRIVE_DEV_CONFIG_FALLBACK_KEY);
            if (is_array($fallback)) {
                return $fallback;
            }

            THRIVE_DEV_HELPER::maybe_display_notice(
                __('Thrive: Failed to fetch configuration from API.', THRIVE_DEV_TEXT_DOMAIN),
                'error',
                admin_url(),
            );
            return [];
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code !== 200) {
            THRIVE_DEV_HELPER::maybe_debug_log("API returned non-200 status: $http_code");
            THRIVE_DEV_HELPER::maybe_display_notice(
                __('Thrive: Invalid response code from API.', THRIVE_DEV_TEXT_DOMAIN),
                'error',
                admin_url(),
            );
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            THRIVE_DEV_HELPER::maybe_debug_log('Invalid JSON response from API');
            THRIVE_DEV_HELPER::maybe_display_notice(
                __('Thrive: Invalid API response and no fallback config available.', THRIVE_DEV_TEXT_DOMAIN),
                'error',
                admin_url(),
            );
            return [];
        }

        // Validate and normalize
        $data = apply_filters('thrive_admin_filter_remote_config', THRIVE_DEV_HELPER::normalize_config($data));

        // Warn if empty or missing restricted pages
        if (empty($data['restricted_pages'])) {
            THRIVE_DEV_HELPER::maybe_display_notice(
                __('Thrive: Configuration loaded but no restricted pages defined.', THRIVE_DEV_TEXT_DOMAIN),
                'warning',
                admin_url(),
            );
            return [];
        }

        // Update cache, version, fallback
        $remote_version = $data['version'] ?? '';
        if ($remote_version !== get_option(THRIVE_DEV_CONFIG_VERSION_KEY)) {
            update_option(THRIVE_DEV_CONFIG_FALLBACK_KEY, $data);
            update_option(THRIVE_DEV_CONFIG_VERSION_KEY, $remote_version);
            update_option(THRIVE_DEV_CONFIG_LAST_FETCH_KEY, current_time('mysql'));
            set_transient(THRIVE_DEV_CONFIG_CACHE_KEY, $data, 60 * MINUTE_IN_SECONDS);
        }

        return $data;
    }

    /**
     * Clears the cache and forces a config refresh.
     *
     * @return array The new config
     */
    public static function refresh() {
        delete_transient(THRIVE_DEV_CONFIG_CACHE_KEY);
        delete_option(THRIVE_DEV_CONFIG_VERSION_KEY);
        return self::get_config();
    }
}
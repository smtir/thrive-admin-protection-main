<?php
defined('ABSPATH') || exit;

/**
 * Class THRIVE_DEV_ACCESS_CONTROLLER
 *
 * Handles admin access restrictions based on IP and user role.
 * Applies DISALLOW_FILE_MODS and logs unauthorized access attempts.
 */
class THRIVE_DEV_ACCESS_CONTROLLER {

    /**
     * Initialize access control hooks
     */
    public static function init() {
        // Run access control as early as possible
        add_action('admin_init', [self::class, 'enforce'], 1);
    }

    /**
     * Enforce access restrictions for wp-admin.
     */
    public static function enforce() {
        // Skip for AJAX requests
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }
        $remote_config = THRIVE_DEV_CONFIG_MANAGER::get_config();
        $current_page = THRIVE_DEV_HELPER::get_current_admin_page();

        // Prevent blocking the dashboard itself
        if ($current_page === 'index.php' || $current_page === 'widget.index.php') {
            return;
        }

        // Restrict access to sensitive admin pages
        if(THRIVE_DEV_HELPER::is_blocked_admin()) {
            THRIVE_DEV_HELPER::maybe_debug_log('block ' . $current_page);
            // Check if current page is restricted
            $is_restricted = in_array($current_page, $remote_config['restricted_pages'] ?? [], true);
            THRIVE_DEV_HELPER::maybe_debug_log('Current Page ' . $is_restricted);
            
            if ($is_restricted) {
                THRIVE_DEV_HELPER::maybe_debug_log('rES ' . $is_restricted);
                if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                    THRIVE_DEV_LOG_MANAGER::log('access-denied', $current_page);
                }
                
                // Redirect with notice
                THRIVE_DEV_HELPER::display_notice(
                    sprintf(__('Access denied to %s – for blacklisted administrators.', THRIVE_DEV_TEXT_DOMAIN), $current_page),
                    'error',
                    admin_url()
                );
            }

            // Log access attempt
            if (isset($_GET['page']) && $_GET['page'] === 'thrive-log') {
                // Log access denied to the log page
                if (class_exists('THRIVE_DEV_LOG_MANAGER')) {
                    THRIVE_DEV_LOG_MANAGER::log('log-page-access-denied', 'thrive-log');
                }
                THRIVE_DEV_HELPER::display_notice(
                    __('Access denied to Thrive Block Log  – for blacklisted administrators.', THRIVE_DEV_TEXT_DOMAIN),
                    'error',
                    admin_url(),
                );
            }
        }
    }
}
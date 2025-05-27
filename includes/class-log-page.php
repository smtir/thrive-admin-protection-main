<?php
defined('ABSPATH') || exit;

/**
 * Class THRIVE_DEV_LOG_PAGE
 *
 * Displays and controls the admin log viewer page.
 */
class THRIVE_DEV_LOG_PAGE {
    /**
     * Register menu, actions, and dashboard widget.
     */
    public static function init(): void {
        add_action('admin_init', [self::class, 'check_access']);
        add_action('admin_init', [self::class, 'handle_download']);
    }

    /**
     * Check access to the log page.
     */
    public static function check_access(): void {
        if (!isset($_GET['page']) || $_GET['page'] !== 'thrive-log') {
            return;
        }

        // Let the access controller handle the check
        THRIVE_DEV_ACCESS_CONTROLLER::enforce();
    }

    /**
     * Handle log file download request.
     */
    public static function handle_download(): void {
        if (
            isset($_GET['thrive_log_download']) &&
            current_user_can('manage_options') &&
            class_exists('THRIVE_DEV_LOG_MANAGER')
        ) {
            THRIVE_DEV_LOG_MANAGER::download_log();
        }
    }

    /**
     * Renders the actual admin log viewer page.
     */
    public static function render(): void {
        $entries = class_exists('THRIVE_DEV_LOG_MANAGER') ? THRIVE_DEV_LOG_MANAGER::get_parsed_entries() : [];
        $table = class_exists('THRIVE_DEV_LOG_TABLE') ? new THRIVE_DEV_LOG_TABLE(['logs' => $entries]) : null;
        $refresh_url = esc_url(add_query_arg('thrive_force_sync', '1'));
        $clear_url = esc_url(add_query_arg('thrive_force_clear_log', '1'));
        $download_url = esc_url(add_query_arg('thrive_log_download', '1'));
        $current_ver = get_option(THRIVE_DEV_CONFIG_VERSION_KEY) ?: __('N/A', THRIVE_DEV_TEXT_DOMAIN);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Thrive Block Log', THRIVE_DEV_TEXT_DOMAIN) . '</h1>';

        if (!class_exists('THRIVE_DEV_LOG_TABLE') || !class_exists('THRIVE_DEV_LOG_MANAGER')) {
            echo '<div class="notice notice-error"><p>' . 
                 esc_html__('Log viewer is disabled due to missing logging classes.', THRIVE_DEV_TEXT_DOMAIN) . 
                 '</p></div>';
            echo '</div>';
            return;
        }

        echo '<div style="margin-bottom:15px;">';
        echo '<p><strong>' . esc_html__('Current Config Version:', THRIVE_DEV_TEXT_DOMAIN) . '</strong> <code>' . esc_html($current_ver) . '</code></p>';
        echo '<div style="display:flex; gap:10px; flex-wrap:wrap;">';
        echo '<a href="' . $refresh_url . '" class="button" style="background: #0a7d24;color: #fff;border-color: #10a331;">' . 
             esc_html__('Force Sync Config', THRIVE_DEV_TEXT_DOMAIN) . '</a>';
        echo '</div></div>';

        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="thrive-log">';
        $table->prepare_items();
        $table->views();
        $table->search_box(__('Search Logs', THRIVE_DEV_TEXT_DOMAIN), 'seo-idaho-log');
        $table->display();
        echo '</form>';

        echo '<div style="display:flex; gap:10px; flex-wrap:wrap;">';
        echo '<a href="' . $clear_url . '" class="button" style="background: #d63638;color:white;border-color: #d63638;" ' . 
             'onclick="return confirm(\'' . esc_js(__('Are you sure you want to clear the log?', THRIVE_DEV_TEXT_DOMAIN)) . '\')">' . 
             esc_html__('Clear Log', THRIVE_DEV_TEXT_DOMAIN) . '</a>';

        echo '<form method="post">';
        echo '<a href="' . $download_url . '" class="button button-general">' . 
             esc_html__('Download Log', THRIVE_DEV_TEXT_DOMAIN) . '</a>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }
}
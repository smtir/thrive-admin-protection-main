<?php
defined('ABSPATH') || exit;

class THRIVE_DEV_SETTINGS {

    /**
     * Get the remote API URL.
     *
     * @return string
     */
    public static function api_url(): string {
        // Try to get the URL from the options table
        $url = get_option('thrive_config_api_url', 'http://main.test/wp-json/thrive/v1/config');

        return $url;
    }

    /**
     * Renders the actual admin log viewer page.
     */
    public static function render(): void {
        // Check if form was submitted and update options
        if (isset($_POST['thrive_settings_nonce']) && wp_verify_nonce($_POST['thrive_settings_nonce'], 'thrive_save_settings')) {
            if (isset($_POST['api_url'])) {
                update_option('thrive_config_api_url', sanitize_text_field($_POST['api_url']));
            }

            // For checkboxes: If set, save 1, otherwise 0 (unchecked)
            update_option('thrive_disable_core_file_mod', isset($_POST['disable_core_file_mod']) ? 1 : 0);
            update_option('thrive_disable_file_edit', isset($_POST['disable_file_edit']) ? 1 : 0);

            echo '<div class="updated notice"><p>' . esc_html__('Settings saved.', THRIVE_DEV_TEXT_DOMAIN) . '</p></div>';
        }

        // Get saved options for checkboxes (default to 0)
        $core_file_mod_disabled = get_option('thrive_disable_core_file_mod', 0);
        $file_editing_disabled = get_option('thrive_disable_file_edit', 0);
        $api_url = get_option('thrive_config_api_url', 'http://main.test/wp-json/thrive/v1/config');

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Thrive Settings', THRIVE_DEV_TEXT_DOMAIN) . '</h1>';

        // Form start
        echo '<form method="post" action="">';

        // Security nonce field
        wp_nonce_field('thrive_save_settings', 'thrive_settings_nonce');

        echo '<table class="form-table">';

        // API URL row with input
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('API URL', THRIVE_DEV_TEXT_DOMAIN) . '</th>';
        echo '<td><input type="url" name="api_url" value="' . esc_attr($api_url) . '" class="regular-text" required></td>';
        echo '</tr>';

        // Disable Core File Modification checkbox
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Disable Core File Modification', THRIVE_DEV_TEXT_DOMAIN) . '</th>';
        echo '<td><input type="checkbox" name="disable_core_file_mod" value="1" ' . checked(1, $core_file_mod_disabled, false) . '></td>';
        echo '</tr>';

        // Disable File Editing checkbox
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Disable File Editing', THRIVE_DEV_TEXT_DOMAIN) . '</th>';
        echo '<td><input type="checkbox" name="disable_file_edit" value="1" ' . checked(1, $file_editing_disabled, false) . '></td>';
        echo '</tr>';

        echo '</table>';

        // Submit button
        echo '<p class="submit"><input type="submit" class="button button-primary" value="' . esc_attr__('Save Changes', THRIVE_DEV_TEXT_DOMAIN) . '"></p>';

        echo '</form>';
        echo '</div>';
    }
}
<?php
defined('ABSPATH') || exit;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class THRIVE_DEV_LOG_TABLE
 *
 * Displays parsed logs in a sortable, searchable admin table.
 */
class THRIVE_DEV_LOG_TABLE extends WP_List_Table {

    /**
     * @var array Parsed log entries.
     */
    private array $all_logs = [];

    /**
     * Constructor.
     *
     * @param array $args ['logs' => array[]]
     */
    public function __construct(array $args = []) {
        parent::__construct([
            'singular' => 'log_entry',
            'plural'   => 'log_entries',
            'ajax'     => false,
        ]);

        $this->all_logs = $args['logs'] ?? [];
    }

    /**
     * Define columns.
     *
     * @return array
     */
    public function get_columns(): array {
        return [
            'date'   => esc_html__('Date', THRIVE_DEV_TEXT_DOMAIN),
            'type'   => esc_html__('Type', THRIVE_DEV_TEXT_DOMAIN),
            'target' => esc_html__('Slug', THRIVE_DEV_TEXT_DOMAIN),
            'ip'     => esc_html__('IP', THRIVE_DEV_TEXT_DOMAIN),
            'user'   => esc_html__('User', THRIVE_DEV_TEXT_DOMAIN),
        ];
    }

    /**
     * Add filter views (e.g., All, Plugin-Blocked, Theme-Install).
     *
     * @return array
     */
    protected function get_views(): array {
        $types = array_unique(array_column($this->all_logs, 'type'));
        $views = [];

        $current  = sanitize_text_field($_GET['type'] ?? '');
        $base_url = admin_url('admin.php?page=thrive-log');

        $views['all'] = sprintf(
            '<a href="%s"%s>%s</a>',
            esc_url($base_url),
            $current === '' ? ' class="current"' : '',
            esc_html__('All', THRIVE_DEV_TEXT_DOMAIN)
        );

        foreach ($types as $type) {
            $views[$type] = sprintf(
                '<a href="%s"%s>%s</a>',
                esc_url(add_query_arg('type', urlencode($type), $base_url)),
                $current === $type ? ' class="current"' : '',
                esc_html(ucfirst($type))
            );
        }

        return $views;
    }

    /**
     * Renders the search box.
     */
    public function get_search_box($text, $input_id) {
        echo '<p class="search-box">';
        echo '<label class="screen-reader-text" for="' . esc_attr($input_id) . '">' . esc_html($text) . ':</label>';
        echo '<input type="search" id="' . esc_attr($input_id) . '" name="s" value="' . esc_attr($_GET['s'] ?? '') . '" />';
        submit_button($text, 'button', false, false, ['id' => 'search-submit']);
        echo '</p>';
    }

    /**
     * Prepare log items with pagination and filtering.
     */
    public function prepare_items() {
        $logs = $this->all_logs;

        // Filter by type
        if (!empty($_GET['type'])) {
            $type = sanitize_text_field($_GET['type']);
            $logs = array_filter($logs, fn($log) => $log['type'] === $type);
        }

        // Search filter
        if (!empty($_GET['s'])) {
            $search = strtolower(sanitize_text_field($_GET['s']));
            $logs = array_filter($logs, function ($log) use ($search) {
                return strpos(strtolower($log['target']), $search) !== false
                    || strpos(strtolower($log['ip']), $search) !== false
                    || strpos(strtolower($log['user']), $search) !== false
                    || strpos(strtolower($log['type']), $search) !== false
                    || strpos(strtolower($log['date']), $search) !== false;
            });
        }

        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $total_items  = count($logs);

        $this->items = array_slice($logs, ($current_page - 1) * $per_page, $per_page);

        $this->set_pagination_args([
            'total_items' => $total_items,
            'per_page'    => $per_page,
        ]);

        $this->_column_headers = [$this->get_columns(), [], []];
    }

    /**
     * Default column renderer.
     *
     * @param array  $item
     * @param string $column_name
     * @return string
     */
    public function column_default($item, $column_name): string {
        return esc_html($item[$column_name] ?? '');
    }
}
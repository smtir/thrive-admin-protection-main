<?php
defined('ABSPATH') || exit;

/**
 * Class THRIVE_DEV_LOG_MANAGER
 *
 * Handles logging of blocked plugin/theme events, log file I/O, and daily reporting via email.
 */
class THRIVE_DEV_LOG_MANAGER {

    const LOG_FILE = WP_CONTENT_DIR . '/seo-idaho-block-log.txt';

    /**
     * Write a new log line to the file.
     *
     * @param string $type   Event type
     * @param string $target Target (plugin slug, theme slug, etc.)
     */
    public static function log(string $type, string $target): void {
        $ip   = THRIVE_DEV_HELPER::get_ip();
        $user = wp_get_current_user();

        $line = sprintf(
            "[%s] [%s] Blocked: %s | IP: %s | User: %s\n",
            date('Y-m-d H:i:s'),
            $type,
            $target,
            $ip,
            $user->user_login ?? 'guest'
        );

        error_log($line, 3, self::LOG_FILE);
    }

    /**
     * Return parsed log entries as array.
     *
     * @return array
     */
    public static function get_parsed_entries(): array {
        if (!file_exists(self::LOG_FILE)) {
            return [];
        }

        $lines   = file(self::LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $entries = [];

        foreach ($lines as $line) {
            if (preg_match('/\[(.*?)\] \[(.*?)\] Blocked: (.*?) \| IP: (.*?) \| User: (.*)/', $line, $m)) {
                $entries[] = [
                    'date'   => $m[1],
                    'type'   => $m[2],
                    'target' => $m[3],
                    'ip'     => $m[4],
                    'user'   => $m[5],
                ];
            }
        }

        return $entries;
    }

    /**
     * Clear the log file.
     */
    public static function clear_log(): void {
        @file_put_contents(self::LOG_FILE, '');
    }

    /**
     * Email daily report and delete log if sent.
     */
    public static function send_daily_log_if_exists(): void {
        if (!file_exists(self::LOG_FILE)) return;

        $lines = array_filter(array_map('trim', file(self::LOG_FILE)));
        if (empty($lines)) return;
        $logo_url = THRIVE_DEV_URL . '/assets/img/logo.webp';
        $home_url = esc_url( home_url() );

        $body = '
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8" />
        <title>' . esc_html__('Daily Admin Block Log', THRIVE_DEV_TEXT_DOMAIN) . '</title>
        </head>
        <body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Oxygen, Ubuntu, Cantarell, \'Open Sans\', \'Helvetica Neue\', sans-serif; background:#f9fafb; margin:0; padding:0; color:#333;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb; padding: 30px 0;">
            <tr>
                <td align="center">
                    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); overflow:hidden;">
                        
                        <!-- Header with logo -->
                        <tr>
                            <td style="background:#004aad; padding:20px; text-align:center;">
                                <a href="' . $home_url . '" target="_blank" rel="noopener" style="color: #fff; display:inline-block; text-decoration:none;">
                                    <img src="' . $logo_url . '" alt="' . esc_attr__( 'Site Logo', THRIVE_DEV_TEXT_DOMAIN ) . '" width="150" style="height:auto; border:none; display:block; margin: 0 auto;" />
                                </a>
                            </td>
                        </tr>
                        
                        <!-- Title -->
                        <tr>
                            <td style="padding: 30px 40px 20px; text-align:center; background:#f5f7fa;">
                                <h3 style="margin:0; font-weight:600; color:#004aad; font-size:24px;">' . esc_html__('Daily Admin Block Log', THRIVE_DEV_TEXT_DOMAIN) . '</h3>
                            </td>
                        </tr>
                        
                        <!-- Content / Table -->
                        <tr>
                            <td style="padding: 0 40px 30px;">
                                <table role="presentation" width="100%" cellpadding="8" cellspacing="0" style="border-collapse: collapse; border: 1px solid #e0e0e0; font-size: 14px; color:#444;">
                                    <thead>
                                        <tr style="background:#e8f0fe; color:#004aad; font-weight:600;">
                                            <th align="left" style="border-bottom:1px solid #cfd8dc;">Date</th>
                                            <th align="left" style="border-bottom:1px solid #cfd8dc;">Type</th>
                                            <th align="left" style="border-bottom:1px solid #cfd8dc;">Slug</th>
                                            <th align="left" style="border-bottom:1px solid #cfd8dc;">IP</th>
                                            <th align="left" style="border-bottom:1px solid #cfd8dc;">User</th>
                                        </tr>
                                    </thead>
                                    <tbody>';

        foreach ($lines as $line) {
            if (preg_match('/\[(.*?)\] \[(.*?)\] Blocked: (.*?) \| IP: (.*?) \| User: (.*)/', $line, $m)) {
                $body .= sprintf(
                    '<tr style="border-bottom:1px solid #e0e0e0;">
                        <td style="padding:6px 8px;">%s</td>
                        <td style="padding:6px 8px;">%s</td>
                        <td style="padding:6px 8px;">%s</td>
                        <td style="padding:6px 8px;">%s</td>
                        <td style="padding:6px 8px;">%s</td>
                    </tr>',
                    esc_html($m[1]),
                    esc_html($m[2]),
                    esc_html($m[3]),
                    esc_html($m[4]),
                    esc_html($m[5])
                );
            }
        }

        $body .= '
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                        
                        <!-- Footer -->
                        <tr>
                            <td style="background:#f5f7fa; padding: 20px 40px; text-align:center; font-size:12px; color:#777;">
                                <p style="margin: 0 0 8px;">' . sprintf(esc_html__('This log file is generated from %s', THRIVE_DEV_TEXT_DOMAIN), '<a href="' . esc_url($home_url) . '" target="_blank" rel="noopener noreferrer" style="color:#004aad; text-decoration:none;">' . esc_html($home_url) . '</a>') . '</p>
                                <p style="margin: 0 0 8px;">' . esc_html__('This is an automated message. Please do not reply.', THRIVE_DEV_TEXT_DOMAIN) . '</p>
                                <p style="margin: 0;">' . esc_html__('If you have any questions, please contact support.', THRIVE_DEV_TEXT_DOMAIN) . '</p>
                            </td>
                        </tr>
                        
                    </table>
                </td>
            </tr>
        </table>
        </body>
        </html>';
        THRIVE_DEV_ALERT_MANAGER::send(__('Daily Admin Block Log From ', THRIVE_DEV_TEXT_DOMAIN) . get_bloginfo('name'), $body);
        @unlink(self::LOG_FILE);
    }

    /**
     * Download the log file.
     */
    public static function download_log(): void {
        if (!file_exists(self::LOG_FILE)) {
            THRIVE_DEV_HELPER::display_notice(
                __('Thrive Log file not found.', THRIVE_DEV_TEXT_DOMAIN),
                'error',
                admin_url()
            );
        }

        header('Content-Description: File Transfer');
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="seo-idaho-log.txt"');
        header('Content-Length: ' . filesize(self::LOG_FILE));
        flush();
        readfile(self::LOG_FILE);
        exit;
    }

    /**
     * Recursively delete a plugin/theme folder.
     *
     * @param string $dir
     */
    public static function delete_dir(string $dir): void {
        foreach (scandir($dir) as $f) {
            if (!in_array($f, ['.', '..'], true)) {
                $path = "$dir/$f";
                is_dir($path) ? self::delete_dir($path) : @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
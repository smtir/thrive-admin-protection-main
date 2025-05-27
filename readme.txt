=== Thrive Admin Access Lock ===
Contributors: thrivewebdesigns  
Tags: admin access, security, plugin blocker, theme blocker, email alerts, access logs  
Requires at least: 5.4  
Tested up to: 6.5  
Requires PHP: 7.4  
Stable tag: 2.0.1  
License: GPLv2 or later  
License URI: https://www.gnu.org/licenses/gpl-2.0.html  

Secure your WordPress admin area by blocking unauthorized access, disabling specific plugins/themes, and monitoring activity with real-time email alerts and a filtered log viewer.

== Description ==

Thrive Admin Access Lock helps secure your WordPress website by enforcing strict access control and disabling unwanted plugins and themes. Built for developers and administrators who need enhanced control over admin activity, this plugin features configurable blocking, alert notifications, and a robust log viewer.

**Key Features:**

- IP Blacklist and CIDR-based access restriction
- Block activation of selected plugins and themes
- Email alerts for blocked access attempts
- Log viewer with filters by IP, type, user, and slug
- Config sync from remote server with fallback support
- Multisite compatible and optimized for performance

This plugin is ideal for development teams, hosting providers, and agencies that manage multiple websites or need tighter control of admin environments.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/thrive-admin-access-lock` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Configure settings via the "Thrive Admin Lock" menu in the WordPress Admin.
4. (Optional) Add your IP to the whitelist in the remote config or settings.

== Frequently Asked Questions ==

= Will I be locked out if my IP isn't whitelisted? =  
Yes. Ensure your IP is included in the allowed list via the remote config or you’ll be denied access.

= Does this support multisite? =  
Yes, it is compatible with multisite networks.

= Can I sync configuration from a remote source? =  
Yes. The plugin fetches a config file from a remote URL and caches it locally.

= How are alerts delivered? =  
Alerts are sent to the admin email address configured in your WordPress settings.

== Screenshots ==

1. Admin Log Viewer with Filters
2. Plugin and Theme Blocking Interface
3. Email Alert Example

== Changelog ==

= 2.0.1 =  
* Added fallback config system for remote config loading  
* Improved log viewer with date and type filters  
* Added plugin/theme block enforcement with auto-log  
* Enhanced email alert formatting  
* Minor bug fixes and optimizations  

= 2.0.0 =  
* Initial release with IP blocking and logging

== Upgrade Notice ==

= 2.0.1 =  
Critical fixes for remote config and plugin blocking. Upgrade recommended.

== License ==

This plugin is licensed under the GPLv2 or later
=== HD Form ===
Contributors: hdagency
Tags: forms, contact-form, rest-api, captcha, spam-protection
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.1.7
License: MIT
License URI: https://opensource.org/licenses/MIT

Standalone, theme-independent form plugin with REST API submission, multi-channel notifications, CAPTCHA, spam protection, file upload, custom workflows, and admin UI.

== Description ==

HD Form is a modern, high-performance WordPress form plugin engineered for reliability, security, and developer ergonomics. It features a standalone REST API submission engine, multi-provider CAPTCHA integration, advanced honeypot spam protection, multi-channel notification dispatchers (Email, Telegram, Webhook), entry management with custom workflow statuses, and automated background maintenance.

== Installation ==

1. Upload the `hd-form` directory to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure default email recipients, CAPTCHA keys, and notification channels under 'Form Entries' -> 'Settings'.

== Changelog ==

= 1.1.7 =
* Live GitHub Updater: Enhanced ZIP magic bytes verification with automatic GZIP decompression and leading byte stripping.

= 1.1.6 =
* Maintenance: Version alignment across configuration files, stability improvements.

= 1.1.5 =
* Live GitHub Updater: Standardized Requests 2.x before_redirect hook with exact 2 parameters (location, headers).
* Live GitHub Updater: Integrated validateDownloadArchive filter checking zip PK magic bytes with WP_Filesystem.
* Live GitHub Updater: Replaced direct PHP calls with WordPress Core alternatives (wp_parse_url, wp_delete_file, wp_is_writable).
* Live GitHub Updater: Eliminated error silencing (@) and enforced 300s TTL temporary archive cleanup.

= 1.1.3 =
* GitHub Auto-Updater: Filtered out PUC latest_release strategy to prevent HTTP 404 queries on branch-tracked repositories, saving ~760ms TTFB.
* Environment Token Resolution: Standardized 5-tier environment token lookup with GITHUB_TOKEN and HD_GITHUB_TOKEN fallbacks.
* Performance: Synchronized update check cooldown to 300s. Normalized path constants to forward slash.

= 1.1.2 =
* Updater: Reinforced package integrity guard, dynamic update row notification, and transient cooldown.
* Tests: Added comprehensive PHPUnit test suite for GitHub auto-updater lifecycles.

= 1.1.1 =
* Frontend: Added robust REST API base URL self-discovery with _baseUrl fallback.
* Assets: Improved module script loader tag filtering to preserve attribute integrity.

= 1.1.0 =
* Add GitHub Auto-Update engine via Plugin Update Checker (PUC v5.7) with non-blocking async background checks.
* Add Sodium XChaCha20-Poly1305 encrypted token vault for secure database credentials storage.
* Support HDF_GITHUB_TOKEN environment constant with automatic fallback and resolution.
* White-label Auto-Update Authentication interface with interactive token management and instant update triggers.

= 1.0.0 =
* Initial release: Standalone WordPress Form plugin with PSR-4 modular architecture.
* REST API form submission engine with CSRF verification and client rate limiting.
* Multi-provider CAPTCHA integration (Cloudflare Turnstile, Google reCAPTCHA v2/v3, hCaptcha) and honeypot guard.
* Multi-channel notification pipeline (Email, Telegram, Webhook) with async WP-Cron queue processor.
* Admin interface for entry browsing, filtering, search, custom workflow statuses, and streaming CSV export.
* Automated form entry log retention and weekly summary digest cron jobs.

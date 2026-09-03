=== HD Form ===
Contributors: hdagency
Tags: forms, contact-form, rest-api, captcha, spam-protection
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.1.1
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

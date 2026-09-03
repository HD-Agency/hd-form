# HD Form

Standalone, theme-independent WordPress forms plugin with REST API submission, captcha (Cloudflare
Turnstile, Google reCAPTCHA v2/v3), honeypot spam protection, file uploads, asynchronous email
notifications, and entry logs.

Ported from the `hd` theme's Form module to act as an independent plugin.

## Features

- **STATISTICS & SUBMISSIONS**: Direct entries list table, status tracking (read, unread, spam,
  trash), and export to CSV/XLSX.
- **REST API ENGINE**: Asynchronous submissions via a unified REST endpoint with built-in
  client-side integration.
- **DYN FIELD OPTIONS**: Dynamically fetch choices/dropdown lists directly from posts, pages, or
  taxonomy terms using a performant REST cascade.
- **SPAM DEFENSES**: Turnstile/reCAPTCHA token guards, dynamic honeypot tokenization, local
  blacklists, and Akismet integration.
- **LAZY-LOAD FRONTEND**: Tiny inline script checking for `[data-form]` elements, loading resources
  on-demand.
- **ASYNCHRONOUS NOTIFICATIONS**: Uses a robust DB-backed mail queue via WP-Cron, preventing SMTP
  delay bottlenecks.

## Privacy & Data Flow

Understand where submitter data travels before enabling integrations:

- **Akismet runs asynchronously.** Entries are stored immediately and the Akismet check is queued
  via WP-Cron (`hd_form_async_akismet_check`). An entry flagged as spam after the fact is switched
  to the spam status on the next cron run — between those moments it appears as a normal entry, so
  avoid forwarding notifications to third parties before the check completes.
- **Webhook GET mode exposes data in URLs.** With `method: GET`, all entry fields (including PII
  such as names, emails, and phone numbers) are serialized into the query string. Query strings
  routinely end up in access logs, proxies, and browser history. Prefer `POST` unless the receiving
  endpoint cannot accept bodies.
- **Chat channels forward PII off-site.** Telegram / Viber / Zalo notifications send the submitted
  field values to the corresponding third-party messaging platform. Only enable them for forms
  whose submitters expect that flow, and restrict chat membership accordingly.
- **Uploaded files follow their entry.** Deleting an entry (individually or in bulk) also deletes
  its uploaded files from the uploads directory. Uninstalling keeps shared `hde_*` tables and
  uploaded files by default; opt into a full purge (rows + files) by defining
  `HD_FORM_UNINSTALL_PURGE_ALL = true`.

## Development

Install PHP dependencies:

```bash
composer install
```

Install dependencies and build assets:

```bash
bun install
bun run build
```

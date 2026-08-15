# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository layout — IMPORTANT

This is **not a normal git repo**. It is an SVN-style WordPress plugin source tree mirrored locally:

- `trunk/` — **the only folder to edit.** This is the active development copy of the plugin.
- `tags/` — frozen snapshots of past releases (e.g. `tags/1.8.5/`). Never edit these.
- `assets/` — WordPress.org listing assets (banner, icon). Not part of the plugin itself.
- `*.zip` files in the root — build artifacts from packaging `trunk/` for deployment/testing. Rebuild rather than hand-edit.

There is a **separate, external SVN working copy** at
`C:\Users\erdin\OneDrive\Masaüstü\Tasarım Sürecim\Eklentilerim\erdo-image-optimizer-repo\`
which is the live, published WordPress.org plugin (synced via TortoiseSVN commit). **Never copy changes into that folder, and never run SVN operations against it, unless the user explicitly approves it for that specific change.** This rule is durable across sessions — confirm before every sync, even if a similar sync was approved previously.

## Commands

There is no build step, package.json, or test suite — this is a plain PHP/JS WordPress plugin.

- **Packaging a release zip**: copy `trunk/` into a folder named `erdo-image-optimizer/` and zip it (exclude `.git`, `_erdo_backup/`). The zip must contain `erdo-image-optimizer/erdo-image-optimizer.php` at the top level, not nested.
- **PHP lint a single file**: `php -l includes/class-auditor.php`
- **WordPress Plugin Check** (official linter used before every WordPress.org submission): `wp plugin check erdo-image-optimizer --format=csv` — requires the Plugin Check plugin installed on a target WP site.
- **WP-CLI commands the plugin ships**: `wp erdo convert [--id=<id>] [--year=<year>] [--limit=<n>] [--dry-run]`, `wp erdo audit [--format=csv]`, `wp erdo restore --id=<id>`, `wp erdo stats`, `wp erdo clean-cache`.
- There is no local WordPress install in this repo — testing happens against remote staging sites over SSH/SFTP (paramiko scripts), not locally.

### Known deploy gotcha

ZIPs built with Windows PowerShell `Compress-Archive` and then extracted on Linux via `unzip` can leave `assets/css/` and `assets/js/` with `000` permissions (shows as `d?????????` in `ls -la`). After any such deploy, explicitly `chmod 755` the plugin dir + `assets/css` + `assets/js`, and `chmod 644` the files inside, or the dashboard will load with no styling/JS and look broken. This only affects our deploy tooling, not end users installing from WordPress.org.

## Architecture

### Bootstrap (`erdo-image-optimizer.php`)

Single entry file. Defines `ERDO_PATH`/`ERDO_URL`/`ERDO_VERSION`, requires the five include files, and instantiates everything on `plugins_loaded` via `erdo_init()`. Below that, a series of standalone hooked functions implement the lighter-weight, upload-pipeline features that don't warrant their own class:

- `erdo_auto_sanitize_filename()` — filters `sanitize_file_name` to slugify uploads.
- `erdo_auto_resize_on_upload()` (priority 5) → `erdo_auto_webp_convert()` (priority 10), both on `wp_generate_attachment_metadata`. **Order matters**: resize must run before WebP conversion. Each temporarily removes both filters while it calls `ERDO_Auditor` methods, to avoid re-triggering itself or the other hook, then re-adds them.
- `erdo_add_lazy_loading()` — regex-injects `loading="lazy"` into `<img>` tags missing it, on `the_content`/`post_thumbnail_html`/`wp_get_attachment_image`.
- SVG upload support (`erdo_allow_svg_upload`, `erdo_sanitize_svg_on_upload`, `erdo_svg_thumbnail`) — gated on `current_user_can('manage_options')` and an opt-in option, sanitized via `ERDO_Auditor::sanitize_svg()`.
- Multisite network settings page, registered only `if ( is_multisite() )`.

### `ERDO_Auditor` (`includes/class-auditor.php`)

Static-method utility class — the core image-processing and SEO-scoring engine. No instance state; everything reads/writes WordPress post meta and options directly.

- **`bulk_analyze()`** is the perf-critical path that powers the dashboard grid. It batches all DB reads (postmeta, posts, parent titles) into 3 prepared `IN (...)` queries instead of N+1, and **short-circuits entirely** if every requested ID already has a cached `_erdo_audit_cache` meta value — usage-check (LIKE-query) work is skipped for cached items. Cache is invalidated by `clear_cache()` on `edit_attachment`/`add_attachment`.
- **`bulk_check_usage()`** determines whether an image is "used" anywhere on the site via three cheap-to-expensive cascading checks: featured-image lookup → `post_parent` lookup → content/postmeta `LIKE` scan (only run for IDs that fail the first two, and only per-ID since each needs its own filename pattern).
- The 6-point SEO audit (filename, alt text, title, dimensions, format, usage) is built as a `$checklist` array of `{label, pass, value, msg}` inside `bulk_analyze()` — read this loop directly if you need to change scoring rules.
- **`convert_to_webp()`** does format conversion via GD (`imagewebp()`/`imageavif()`); HEIC/HEIF inputs go through Imagick first to get a JPEG resource, then GD takes over. AVIF requires PHP 8.1+ (native GD AVIF support) — `imageavif()`'s existence is checked with `function_exists()` before any AVIF code path runs, never assumed.
- **`backup_original()` / `restore_original()`** — originals are copied to an `_erdo_backup/` subfolder before any destructive conversion; restore reverses it and removes the WebP-conversion hook temporarily so it doesn't immediately re-convert the restored file.

### `ERDO_Admin_Page` (`includes/class-admin-page.php`)

Owns the entire wp-admin surface: menu registration, asset enqueueing (only on its own admin page, checked via `$hook`), the dashboard widget, REST route registration, and ~25 `wp_ajax_erdo_*` AJAX handlers (see the constructor for the full hook→method map — handler method names don't always match the JS action strings used in `admin.js`, so grep the constructor first when tracing a feature).

Security pattern used by every AJAX handler: each method calls `check_ajax_referer( 'erdo_nonce', ... )` and `verify_capability()` (which checks `manage_options` and exits via `wp_send_json_error` if absent) **individually inside the method body**, rather than once in a shared dispatcher — this was a deliberate choice for PHPCS compliance, noted in a comment in the constructor. Keep this pattern when adding new AJAX handlers.

### `ERDO_Queue` (`includes/class-queue.php`)

Background batch processor for bulk convert/delete, built entirely on WP-Cron + `wp_options` (no custom DB table). State lives in plain options (`erdo_queue_ids`, `_status`, `_done`, `_errors`, etc.) so it survives across requests/cron ticks. `BATCH_SIZE = 25` per cron tick (`erdo_two_min` custom interval). `start()` always calls `cancel()` first to clear any previous run, then processes the first batch synchronously before scheduling the recurring cron hook — so the UI gets immediate partial progress instead of waiting for the first cron tick.

### `ERDO_WebP_Server` (`includes/class-webp-server.php`)

Two independent WebP-serving strategies, selected by the `erdo_webp_serve_method` option:

1. **Apache**: writes/removes a marked block (`# BEGIN Erdo WebP` / `# END Erdo WebP`) in the uploads-dir `.htaccess` via regex replace — idempotent, safe to call repeatedly.
2. **PHP `<picture>` rewriter** (fallback for nginx/other): hooks `the_content` etc. at priority 99 and wraps `<img>` tags whose `.webp` sibling exists on disk in a `<picture>` element. Only rewrites images inside the uploads dir, and skips entirely when `is_admin()` is true.

These are mutually exclusive at runtime — `init()` returns early without registering the PHP filters if Apache + htaccess method is active, since the server already handles it.

### `ERDO_CLI` (`includes/class-cli.php`)

Registered only when `WP_CLI` is defined (file no-ops otherwise). Thin wrapper around `ERDO_Auditor` static methods exposed as `wp erdo <subcommand>`.

### Frontend (`assets/js/admin.js`, `assets/css/admin.css`)

Single-page jQuery admin dashboard, no build step/bundler — edit directly. Talks to the AJAX handlers in `class-admin-page.php` via `erdo_vars.ajax_url`/`erdo_vars.nonce` (localized in `assets()`). Notable patterns to preserve when extending the UI:

- **Tools dropdown** (`#erdo-tools-btn`): toggled with `stopPropagation()` + a document-level click handler to close it; any new dropdown should follow the same close-on-outside-click pattern.
- **Before/After slider**: drag listening is bound to the *container* (`#erdo-ba-container`), not the thin slider line/handle — those have `pointer-events: none` in CSS, so binding to them directly silently does nothing.
- Grid size (S/M/L) persists to `localStorage`; a `erdo_library_version` option (bumped on attachment add/edit/delete) is used by the JS as a cache-busting signal for the grid's `sessionStorage` cache (`erdo_grid_v2`).

## WordPress.org submission constraints

- **`readme.txt` first line and the plugin header `Plugin Name:` are intentionally different on purpose**: the wp-admin Plugins list shows the short PHP header name (`Erdo Image Optimizer`), while `readme.txt`'s `=== ... ===` line carries a longer, keyword-rich name for WordPress.org search/SEO (mirrors what Smush/Imagify/etc. do). `wp plugin check` flags this mismatch as a `mismatched_plugin_name` **warning** — this is expected and intentionally not fixed; do not "fix" it by shortening the readme title or lengthening the PHP header without the user asking first.
- WordPress.org enforces **max 5 tags** and a **150-character short description** in `readme.txt` — Plugin Check will warn (`readme_parser_warnings_too_many_tags`, `readme_parser_warnings_trimmed_short_description`) if exceeded.
- Bump `Version:` in `erdo-image-optimizer.php` *and* `ERDO_VERSION` *and* `Stable tag:` in `readme.txt` together — all three must match for WordPress.org to register an update.

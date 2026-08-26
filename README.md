<p align="center">
  <img src="https://ps.w.org/erdo-image-optimizer/assets/banner-1544x500.png" alt="Erdo Image Optimizer" width="100%">
</p>

<h1 align="center">Erdo Image Optimizer</h1>

<p align="center">
  <b>WordPress image SEO &amp; performance suite</b> — WebP/AVIF conversion, a 6-point image SEO audit, bulk alt text, lazy loading, unused-media cleanup, and a BunnyCDN integration, all from one dashboard.
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/erdo-image-optimizer/"><img src="https://img.shields.io/wordpress/plugin/v/erdo-image-optimizer.svg" alt="WordPress Plugin Version"></a>
  <a href="https://wordpress.org/plugins/erdo-image-optimizer/"><img src="https://img.shields.io/wordpress/plugin/dt/erdo-image-optimizer.svg" alt="WordPress Plugin Downloads"></a>
  <a href="https://wordpress.org/plugins/erdo-image-optimizer/"><img src="https://img.shields.io/wordpress/plugin/rating/erdo-image-optimizer.svg" alt="WordPress Plugin Rating"></a>
  <img src="https://img.shields.io/wordpress/v/erdo-image-optimizer.svg" alt="Tested up to WordPress version">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg" alt="Requires PHP 7.4+">
  <img src="https://img.shields.io/badge/license-GPLv2%2B-blue.svg" alt="License: GPLv2+">
</p>

<p align="center">
  <a href="https://wordpress.org/plugins/erdo-image-optimizer/">WordPress.org Listing</a> ·
  <a href="https://wordpress.org/plugins/erdo-image-optimizer/#developers">Changelog</a> ·
  <a href="#installation">Installation</a> ·
  <a href="https://github.com/erdincBulat/erdo-image-optimizer/issues">Report a Bug</a>
</p>

---

Most image plugins stop at compression. **Erdo Image Optimizer** goes further: every image in your media library gets a **professional SEO health score (0–6)**, so you know exactly which images are hurting your rankings — and can fix them in bulk, in minutes.

## Screenshots

| Dashboard | Failing SEO audit |
|---|---|
| ![Main dashboard](https://ps.w.org/erdo-image-optimizer/assets/screenshot-1.png) | ![Poor SEO audit report](https://ps.w.org/erdo-image-optimizer/assets/screenshot-2.png) |

| Perfect score | Settings |
|---|---|
| ![Fully optimized image](https://ps.w.org/erdo-image-optimizer/assets/screenshot-3.png) | ![Settings page](https://ps.w.org/erdo-image-optimizer/assets/screenshot-4.png) |

<p align="center"><img src="https://ps.w.org/erdo-image-optimizer/assets/screenshot-5.png" alt="Unused media cleanup" width="70%"></p>

## Features

**Image SEO Audit — a 6-point health score for every image**
- Scores alt text, filename quality, title, contextual usage, dimensions, and format
- One-click fix for anything failing, plus a CSV export for reporting
- Missing Alt Text filter to focus the dashboard on what needs work

**WebP & AVIF Converter**
- One-click conversion from JPEG, PNG, GIF, and HEIC/HEIF (iPhone photos, via Imagick)
- Smart Quality mode automatically finds the smallest file size that still looks great; lossless mode for zero quality loss
- Original backup before every conversion, with one-click restore
- EXIF metadata stripper and Max Upload Width auto-resize

**Filename Sanitizer**
- Turns `IMG_1234 (copy).JPG` into `professional-web-design.webp` automatically on upload
- Bulk-rename existing files with automatic database reference updates (thumbnails, GUID, content, postmeta)

**Unused Media Cleanup** — scans posts, pages, and postmeta to find images that appear nowhere on your site, so you can reclaim disk space without guessing.

**Lazy Loading & WebP Serving** — `loading="lazy"` injected into content automatically, plus WebP delivery via `.htaccess` rewrite (Apache) or a PHP `<picture>` element fallback for every other server.

**Background Queue & WP-CLI** — bulk-convert thousands of images via WP-Cron and close the browser tab, or drive it from the command line: `wp erdo convert|audit|restore|stats|clean-cache`.

**BunnyCDN & REST API** — push converted images straight to BunnyCDN storage, or read stats/audit data from `/wp-json/erdo/v1/stats` and `/wp-json/erdo/v1/audit` for headless setups.

**Dashboard & Extras** — dashboard widget, before/after comparison slider, right-click context menu, S/M/L grid sizing, SVG upload support with XSS sanitization, and Multisite network settings.

## Who is this for?

- **Site owners & bloggers** who want smaller pages and better image rankings without learning image SEO themselves
- **Agencies & freelancers** who need to clean up a client's media library in bulk — filenames, alt text, formats, unused files — instead of one image at a time
- **Developers** who want CLI/REST access to bulk conversion and audit data for headless or large-catalog sites

## Installation

```
wp plugin install erdo-image-optimizer --activate
```

Or manually: download from [WordPress.org](https://wordpress.org/plugins/erdo-image-optimizer/), upload the `erdo-image-optimizer` folder to `/wp-content/plugins/`, activate it, then open **Image Optimizer** in the wp-admin sidebar to run your first SEO audit.

**Requirements:** WordPress 5.8+, PHP 7.4+ (AVIF output needs PHP 8.1+ with GD's AVIF support).

## WP-CLI

```
wp erdo convert [--id=<id>] [--year=<year>] [--limit=<n>] [--dry-run]
wp erdo audit [--format=csv]
wp erdo restore --id=<id>
wp erdo stats
wp erdo clean-cache
```

## REST API

| Endpoint | Description |
|---|---|
| `GET /wp-json/erdo/v1/stats` | Optimization statistics — total images, conversion rate, space saved |
| `GET /wp-json/erdo/v1/audit` | Full SEO audit results for the media library |

## Try it instantly (no install)

[![Try it in WordPress Playground](https://img.shields.io/badge/WordPress%20Playground-Try%20it%20live-3858e9.svg)](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/erdincBulat/erdo-image-optimizer/main/.wordpress-org/blueprint.json)

Runs entirely in your browser, no server or account needed.

## Contributing

Issues and pull requests are welcome — see [CONTRIBUTING.md](.github/CONTRIBUTING.md). This repository mirrors the plugin shipped on WordPress.org; releases are cut from the `Stable tag` in `readme.txt`.

- `trunk/` — active development copy (the only folder to edit)
- `tags/` — frozen snapshots of past releases
- `assets/` — WordPress.org listing assets (banner, icon)

If you find Erdo useful, a ⭐ on this repo or a [review on WordPress.org](https://wordpress.org/support/plugin/erdo-image-optimizer/reviews/#new-post) helps other people find it.

## Security

Every AJAX handler verifies its nonce and requires the `manage_options` capability before doing anything. SVG uploads are gated behind an explicit opt-in and run through server-side sanitization to strip scripts before they ever reach the media library. If you find a security issue, please open an issue or contact the author directly rather than disclosing it publicly.

## License

GPLv2 or later — see [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html).

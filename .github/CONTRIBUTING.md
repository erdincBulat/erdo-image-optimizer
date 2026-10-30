# Contributing to Erdo Image Optimizer

Thanks for taking the time to contribute! This repository mirrors the plugin distributed on [WordPress.org](https://wordpress.org/plugins/erdo-image-optimizer/).

## Reporting bugs

Open an [issue](https://github.com/erdincBulat/erdo-image-optimizer/issues/new/choose) with:

- WordPress version, PHP version, and the plugin version (`Version:` header in `erdo-image-optimizer.php`)
- Steps to reproduce
- What you expected vs. what happened
- Any relevant errors from the browser console or `debug.log`

## Suggesting features

Open a feature request issue describing the problem you're trying to solve (not just the solution) — it helps evaluate whether it fits the plugin's scope.

## Submitting a pull request

1. Fork the repo and create a branch from `main`
2. Make your changes inside `trunk/` — that's the only folder to edit (`tags/` holds frozen release snapshots, `assets/` is WordPress.org listing artwork)
3. Keep the change focused — one bug fix or one feature per PR
4. Match the existing code style and the patterns already used in the file you're editing
5. Update `trunk/readme.txt` (WordPress.org changelog) if the change is user-facing
6. Open the PR against `main` with a clear description of what changed and why

## Code conventions

This is a plain, dependency-free PHP/JS/CSS codebase — no build step, no Composer, no npm.

- **PHP**: follow the [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/). Each area has its own static class in `includes/`: `ERDO_SEO` (score rules), `ERDO_Usage` (usage index), `ERDO_Converter`/`ERDO_Encoder` (WebP/AVIF copies), `ERDO_Renamer`, `ERDO_Quarantine`, `ERDO_AI`, `ERDO_WebP_Server` (delivery), `ERDO_Migration` (2.x repair).
- **Originals are never modified or deleted.** WebP/AVIF copies sit next to them (`photo.jpg.webp`).
- **File operations** go through `ERDO_References::in_uploads()`, and URL rewrites through `ERDO_References::replace()`, never raw SQL `REPLACE()`.
- **AJAX handlers**: every `wp_ajax_erdo_*` handler in `includes/class-admin-page.php` calls `check_ajax_referer( 'erdo_nonce', ... )` and `verify_capability()` individually inside the method body (not through a shared dispatcher) — keep that pattern for any new handler.
- **JS/CSS**: hand-written jQuery, no transpilation — edit `assets/js/admin.js` and `assets/css/admin.css` directly. User-facing JS text goes through `t('…')` and must be added to `ERDO_Admin_Page::js_strings()`.
- **Tests**: `tests/run.ps1` runs the behavioural suites in a local WordPress site; `tests/docker/matrix.ps1` covers PHP 7.4–8.3, Apache, nginx and multisite. See [tests/README.md](../tests/README.md).

## Security issues

Please do not open a public issue for a security vulnerability. Instead, contact the author directly through [erdincbulat.com](https://erdincbulat.com).

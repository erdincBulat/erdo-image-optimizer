=== Erdo Image Optimizer – Image SEO, AI Alt Text, Media Cleanup, WebP & AVIF ===
Contributors: erdincbulat
Tags: image seo, alt text, webp, avif, unused images
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Score every image for SEO, get AI alt text with your own provider key, quarantine unused media safely and serve WebP/AVIF without touching originals.

== Description ==

Erdo Image Optimizer helps you get your media library ready for search, one image at a time or all at once. It scores every image from 0 to 100, tells you what to fix, and gives you safe tools to fix it: alt text (written by you, taken from the file name, or suggested by AI), renaming with automatic redirects, a reversible cleanup for images nobody uses, and WebP/AVIF copies that sit next to your originals.

Nothing is changed without a preview, and almost everything can be undone.

= Image SEO score =

* Every image gets a score from 0 to 100: alt text (40 points), file name (20), format (10), file size (10), dimensions (5) and context (15).
* Alt text is checked for quality, not just presence: file-name-like text, "image of…" phrases, keyword lists and overly long text lose points. Decorative images can be marked as such and pass with an empty alt.
* Camera-style and generic file names (IMG_1234, DSC_0042, Screenshot, WhatsApp Image, hashes) are flagged.
* Context checks whether the image appears on a published, indexable page. Noindex settings from Yoast SEO and Rank Math are respected.
* Filter the library by issue (missing alt, weak alt, weak file name, no WebP/AVIF copy, oversized, not on an indexable page) and export the report as CSV.
* Focus keywords from Yoast SEO, Rank Math, SEOPress and All in One SEO are used as hints.

= AI alt text with your own key (WordPress 7.0+) =

* Uses the AI Client built into WordPress 7.0. You connect Anthropic, Google or OpenAI under Settings > Connectors; the plugin never stores or sees your API key.
* One request per image returns alt text, a file name, a title and a caption, decides whether the image is decorative, and checks whether the current alt text fits the image.
* The model sees the image together with the page it appears on, the text around it and the focus keyword, so suggestions describe the image in context.
* Review first: suggestions wait in the "AI Suggestions" tab until you accept, edit or reject them, field by field or in bulk.
* Bulk runs show an estimated provider cost before they start and can be stopped at any time.

= Safe renaming =

* Rename a single image, fix weak names across the library (a camera name gets a new one from the AI suggestion, the alt text or the page the image is on), or use patterns such as `{keyword}-{n}`, `{parent}`, `{title}`, `{original}` and `{alt}`.
* Every size, the original before scaling, edit backups and WebP/AVIF copies move together. Links in posts, page builder data, custom fields and options are updated, including serialized and JSON data.
* Old image URLs redirect (301) to the new ones, so backlinks and Google Images traffic keep working.
* Every rename shows a preview first and can be undone per image or per batch.

= Unused media cleanup with quarantine =

* A usage index records where each image is used: post content, featured images, galleries, page builder data, custom fields (including ACF), WooCommerce product galleries, theme settings such as the logo and site icon, widgets and term images.
* The detail panel shows every place an image is used, with links.
* Unused images go to a quarantine instead of being deleted. Their files move to a folder with an unguessable name inside uploads, and the image disappears from the media library until you restore it with one click.
* Before an image is moved in bulk, the plugin checks again whether its file name still appears anywhere on the site and skips it if it does.
* Permanent deletion is a separate, explicit step. Automatic emptying of the quarantine is off by default.

= WebP and AVIF without touching originals =

* WebP and AVIF copies are saved next to each image and each generated size (`photo.jpg.webp`, `photo.jpg.avif`). The original file is never modified or deleted.
* A copy is kept only if it is smaller than the original.
* Delivery through `<picture>` tags (works behind any CDN or page cache), or an Apache `.htaccess` rewrite. A ready-made nginx snippet is included.
* Convert new uploads automatically, or convert the existing library in the background. Remove the copies of any image with one click.
* WebP and AVIF support is measured with a real test encode on your server. Lossless WebP, smart quality and animated GIFs (with Imagick) are supported.

= Also included =

* A first-run setup that tests your server, detects a CDN, an SEO plugin and an AI provider, and recommends settings.
* An overview with an image health score, a "fix next" list sorted by impact and an activity log with undo.
* Image sitemap inside the WordPress core sitemap (turned off automatically when Yoast SEO, Rank Math, All in One SEO or SEOPress handles image sitemaps).
* Clean file names on upload, optional removal of camera data (EXIF/GPS), and a maximum image size that uses the WordPress "big image" threshold (the full-size original is kept).
* Resize oversized images in the detail panel; the original is kept so you can undo it.
* WP-CLI commands, REST endpoints for statistics, audit results and AI suggestions, and a dashboard widget.
* A repair tool for images that were converted by version 2.x of this plugin.

= What it does not do =

* It does not compress or overwrite your original files. Savings come from the WebP/AVIF copies.
* It does not lazy-load images. WordPress already does this, including the right handling of the largest image above the fold.
* It does not offer a CDN or a cloud compression service, and it has no server of its own.
* AI features need WordPress 7.0 or newer and your own account with an AI provider. Without them, every other feature works.
* The usage index cannot see images that are hard-coded in theme or plugin files, used only in CSS files, or linked from other websites. Check those before deleting anything permanently.

== Installation ==

1. Install the plugin from Plugins > Add New, or upload the `erdo-image-optimizer` folder to `/wp-content/plugins/`.
2. Activate it.
3. Open **Image Optimizer** in the admin menu. The first-run setup tests your server, recommends settings and builds the usage index.
4. Optional: to use AI suggestions on WordPress 7.0 or newer, connect Anthropic, Google or OpenAI under **Settings > Connectors**.

== Frequently Asked Questions ==

= Are my original images changed? =

No. WebP and AVIF copies are saved next to the originals, and "Remove copies" simply deletes them. Three features do write files, and only when you use them: renaming moves files to the new name (and can be undone), resizing in the detail panel creates a smaller version and keeps the original for undo, and the optional "Remove camera data (EXIF)" setting rewrites each new upload once.

= What does quarantine mean, and how do I restore an image? =

Quarantine replaces deletion. The image's files (all sizes and copies) are moved to a folder with an unguessable name inside your uploads directory and the image is hidden from the media library. Nothing is lost: open the Cleanup tab and click Restore on one image, or "Restore all". Images are deleted permanently only when you choose to, or after a number of days if you turn on automatic emptying (off by default).

= How does the plugin know an image is unused, and what can it miss? =

It keeps an index of every place an image is referenced: post and page content (by ID and by URL, including resized, `-scaled` and WebP/AVIF URLs), featured images, galleries, custom fields and page builder data, theme settings, widgets, site logo and icon, term images and WooCommerce galleries. Drafts and trashed posts count as used. An image that is only attached to a post but not shown in it counts as unused.

It cannot see images hard-coded in theme or plugin PHP files, images used only in CSS files, images stored in custom database tables, or images linked from other websites. That is why unused images go to quarantine first.

= Does it work with Cloudflare and other CDNs? =

Yes. The default delivery method writes `<picture>` tags into the HTML, so every browser gets a URL it can use and caches do not mix formats up. The `.htaccess` method serves different files under the same URL; use it only if your CDN respects the `Vary: Accept` header. The setup detects Cloudflare and recommends `<picture>` tags.

= Which AI providers can I use, and what does it cost? =

Anthropic (Claude), Google (Gemini) or OpenAI, connected under Settings > Connectors in WordPress 7.0 or newer. You pay your provider directly, with your own key. The plugin prefers small, inexpensive vision models; its own estimate is roughly $0.15 to $0.40 per 1,000 images, shown before every bulk run. Actual prices depend on your provider and model.

= What is sent to the AI provider, and is anything saved automatically? =

For each image: the image itself (at most 1024 px on the long side) plus short text context, namely the title of the page it appears on, up to 500 characters of text around it, the focus keyword, the current alt text and title, the content language, and the product name and categories for WooCommerce products. No user names, e-mail addresses or other personal data are sent. Requests happen only when you ask for a suggestion, or after an upload if you turn on "Suggest on upload". Nothing is written to your images until you accept a suggestion. See "External services" below for details.

= Which WP-CLI commands are available? =

* `wp erdo convert [--id=<id>] [--year=<year>] [--limit=<n>] [--dry-run]` creates WebP/AVIF copies.
* `wp erdo restore --id=<id>` removes the copies of one image.
* `wp erdo audit [--format=table|csv|json] [--limit=<n>] [--status=red|orange|green|gray]` prints SEO scores and issues.
* `wp erdo stats` shows library and encoder statistics.
* `wp erdo index [--status]` builds the usage index or shows its status.
* `wp erdo unused [--format=table|csv|json|ids]` lists unused images.
* `wp erdo quarantine list|add|restore|purge|add-unused [--id=<id>] [--yes]` manages the quarantine.
* `wp erdo rename [<id>...] [--all] [--name=<name>] [--pattern=<pattern>] [--keyword=<keyword>] [--dry-run] [--undo=<batch>]` renames images safely.
* `wp erdo migrate [--dry-run] [--batch=<n>]` repairs images converted by version 2.x.
* `wp erdo clean-cache` clears the audit cache.

= What happens when I delete the plugin? =

Your original images stay. Settings, the usage index, scores, rename history and the `.htaccess` rules are removed; old-URL redirects stop working at that point. Under Settings > Uninstall you choose whether the WebP/AVIF copies are deleted (default) or kept, and whether quarantined images are put back into the media library (default) or deleted permanently. Deactivating the plugin only removes the `.htaccess` rules and scheduled tasks.

= I used version 2.x. What changes, and what is the repair tool? =

Version 2.x replaced originals with WebP files and did not update the image links in your content, which could leave broken images. Version 3.0 never does this. After updating, if the plugin finds images converted by 2.x, a notice offers "Repair now" (or run `wp erdo migrate`). If a 2.x backup exists, the original is put back and a WebP copy is created next to it. If there is no backup, links to the old JPEG/PNG files are pointed to the existing WebP file, safely for serialized data. Until the repair runs, a fallback keeps such images visible on your pages.

SVG uploads, the BunnyCDN integration, the lazy-loading filter and the network settings page were removed in 3.0. For SVG, use a dedicated plugin such as Safe SVG; for BunnyCDN, use a pull zone.

= Does it support HEIC or SVG? =

No. Copies are created from JPEG, PNG and GIF images. Images that are already WebP or AVIF are scored but not converted.

== External services ==

This plugin connects to an external AI service only for the optional AI suggestions feature, on WordPress 7.0 or newer. It uses the WordPress AI Client and the provider you connect under Settings > Connectors with your own API key. The plugin does not store or see the key and has no server of its own; requests go from your site directly to your provider.

What the feature does: it asks the provider for alt text, a file name, a title and a caption for an image, whether the image is decorative, and whether the current alt text fits it.

When data is sent: only when a user clicks "Suggest with AI" for an image, starts a bulk run from the "AI Suggestions" tab, calls the plugin's REST endpoint for a suggestion, or after an image upload if the "Suggest on upload" setting is turned on (off by default; it then runs once in the background for new images without alt text).

What is sent with each request:

* The image, at most 1024 px on the long side. The file stored on your site is sent if it is already small enough (JPEG, PNG or WebP), otherwise a generated size between 512 and 1024 px, otherwise a temporary JPEG copy. Files sent as stored keep any embedded camera data unless "Remove camera data (EXIF)" was on when they were uploaded.
* The title of the page the image appears on, the page's SEO focus keyword, and up to 500 characters of text around the image on that page.
* The image's current alt text and title, and the content language.
* For WooCommerce products: the product name and its categories.
* Instructions that describe the expected answer.

No user names, e-mail addresses, IP addresses or other personal data are added by the plugin.

Providers and their terms:

* Anthropic (Claude): [Commercial Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy Policy](https://www.anthropic.com/legal/privacy)
* Google (Gemini API): [Terms of Service](https://ai.google.dev/gemini-api/terms), [Privacy Policy](https://policies.google.com/privacy)
* OpenAI: [Business Terms](https://openai.com/policies/business-terms/), [Privacy Policy](https://openai.com/policies/privacy-policy/)

Suggestions are stored on your site and never applied automatically. Each one waits for your review.

== Screenshots ==

1. Overview: image health score, key numbers, a "fix next" list sorted by SEO impact and recent activity with undo.
2. Library: every image with its score, filterable by issue, in grid or list view.
3. Image detail panel: score breakdown, where the image is used, file sizes, WebP/AVIF copies and editable alt text, file name and title.
4. AI suggestions: current and suggested values side by side, accepted field by field.
5. Cleanup: unused images and the quarantine, with one-click restore.
6. Bulk rename: preview of the new file names before anything is changed.
7. Settings: each option with a one-sentence explanation.
8. First-run setup: server test and recommended settings.

== Changelog ==

= 3.0.0 =
This release rebuilds the plugin around safety and image SEO.

* NEW: Non-destructive WebP/AVIF. Copies are saved next to every image and size (`photo.jpg.webp`); originals are never modified or deleted, and a copy is kept only if it is smaller.
* NEW: Correct `<picture>` delivery with `srcset` and `sizes`, a fixed `.htaccess` rewrite with `Vary: Accept`, and an nginx snippet.
* NEW: WebP/AVIF support is measured with a real test encode. True lossless WebP; animated GIFs are kept animated with Imagick or skipped, never flattened.
* NEW: Usage index that records where every image is used, including theme settings, widgets, page builders, ACF and WooCommerce galleries.
* NEW: Quarantine instead of deletion, with restore, explicit permanent deletion and optional automatic emptying.
* NEW: SEO score from 0 to 100 with alt text quality checks, camera-style name detection, decorative images and indexable-page context.
* NEW: Safe renaming that moves every size and copy, updates serialized and JSON data, redirects old URLs (301) and can be undone per image or per batch. Pattern renaming with `{keyword}`, `{n}`, `{parent}`, `{title}`, `{original}` and `{alt}`.
* NEW: AI suggestions for alt text, file name, title and caption through the WordPress 7.0 AI Client, reviewed before anything is saved.
* NEW: Image sitemap in the WordPress core sitemap.
* NEW: Redesigned admin with Overview, Library, Cleanup, AI Suggestions and Settings tabs, a detail panel, a first-run setup and an activity log.
* NEW: WP-CLI commands `index`, `unused`, `quarantine`, `rename` and `migrate`.
* NEW: Repair tool for images converted by 2.x, plus a fallback that keeps them visible until repaired.
* CHANGED: Resizing on upload now uses the WordPress "big image" threshold, which keeps the full-size original.
* CHANGED: Requires WordPress 6.5 or newer.
* REMOVED: SVG uploads (use Safe SVG), BunnyCDN integration (use a pull zone), the lazy-loading filter (WordPress handles it), the network settings page and the `_erdo_backup` folder workflow.
* SECURITY: Every file operation is restricted to the uploads folder; all strings are translatable.

= 2.2.2 =
* FIXED: Disk-space waste — when "Keep Original Backup" was enabled, the original file was left in place after conversion in addition to the backup copy, storing every converted image 3x instead of 2x.
* FIXED: Restoring an original no longer leaves stale WebP/AVIF thumbnail files behind — previously, per-size thumbnail files from the old metadata were never cleaned up, so both WebP-serving methods kept displaying the orphaned WebP/AVIF thumbnails after a restore.
* FIXED: Usage-detection cache (used by "Delete All Unused") was not invalidated when an image was set as a featured image or attached/detached from a post, so a bulk-delete-unused run could incorrectly delete an image that had just become in-use.

= 2.2.1 =
* FIXED: Bulk Rename / Fix Filename never renamed filenames containing underscores — `sanitize_title()` alone doesn't convert underscores to hyphens, so an underscore-only filename compared equal to itself and was incorrectly reported as "already clean" and skipped instead of renamed.

= 2.2.0 =
* NEW: Append Original Filename setting — when you set a custom filename in the image detail panel, optionally keep the original filename as a suffix (e.g. "istanbul" becomes "istanbul-img-1234") to avoid collisions and preserve a reference to the source file.
* FIXED: Critical data-loss bug — originals were not backed up during auto-convert-on-upload even with "Keep Original Backup" enabled.
* FIXED: Bulk operations (bulk convert, delete unused, background queue, bulk rename, bulk alt text) and CSV export were capped at 500/2000 images; now uncapped.
* FIXED: Settings modal — several fields (Serve WebP, WebP method, lazy loading, smart quality, max width, SVG toggle, BunnyCDN fields) were not being saved due to a misplaced form tag; checkboxes were silently reset on every save.
* FIXED: Renaming from the image detail panel now updates thumbnails, GUID, and content/postmeta references consistently, same as the dedicated "Fix Filename" button.
* FIXED: Already-optimized images (WebP/AVIF) were incorrectly reported as conversion failures; now reported separately as skipped.
* FIXED: SVG sanitizer's script-tag removal silently failed on namespaced SVGs; script tags are now reliably stripped.
* FIXED: Dimensions audit check now validates height as well as width (was width-only).
* FIXED: A few remaining Turkish UI strings in the dashboard were translated to English.
* IMPROVED: Missing Alt filter no longer fires one AJAX request per visible image card.
* Tested up to WordPress 7.1.

= 2.1.0 =
* NEW: Bulk rename — sanitize all image filenames on disk with automatic database reference update.
* NEW: Auto Alt Text — generate alt text from filename in bulk for all images missing it.
* NEW: SEO Audit CSV export — download a full spreadsheet of every image's score, status, format, and alt text.
* NEW: HEIC/HEIF conversion support — convert iPhone photos to WebP/AVIF (requires Imagick).
* NEW: Before/After comparison slider — visually compare original vs optimized image side by side.
* NEW: Dashboard widget — see key stats (total images, optimization rate, space saved) directly from wp-admin home.
* NEW: BunnyCDN integration — automatically upload converted images to a BunnyCDN storage zone.
* NEW: REST API — /wp-json/erdo/v1/stats and /wp-json/erdo/v1/audit endpoints for headless workflows.
* NEW: Right-click context menu on image cards (View Details, Convert, Fix Filename, Delete).
* NEW: Grid size selector — switch card view between Small, Medium, and Large.
* NEW: Missing Alt filter — instantly hide all images that already have alt text to focus on what needs fixing.
* NEW: Filename preview — shows the sanitized version of a filename before you save.
* IMPROVED: Toolbar redesigned with a Tools dropdown — cleaner layout, less visual clutter.
* IMPROVED: Modal sidebar buttons now have clear visual hierarchy (primary, secondary, tool, danger).
* IMPROVED: Format badge (WebP, PNG, JPEG…) and SEO score badge shown directly on every image card.
* IMPROVED: Before/After slider now supports touch drag and correct clip-path technique.
* FIXED: Plugin Check — zero errors or warnings confirmed.

= 2.0.1 =
* Confirmed compatibility with WordPress 7.0 and its new Modern admin theme.
* Requires PHP: minimum remains 7.4 (aligned with WordPress 7.0 requirements).

= 2.0.0 =
* NEW: WebP Serving — .htaccess rewrite (Apache) and PHP picture element rewriter for transparent WebP delivery.
* NEW: Max Upload Width — automatically resize oversized images on upload before conversion.
* NEW: Background Queue — bulk convert via WP-Cron; browser tab can be closed during processing.
* NEW: WP-CLI commands — wp erdo convert, audit, restore, stats, clean-cache.
* NEW: Smart Quality — automatically finds the lowest quality that still looks good.
* NEW: Lazy Loading — adds loading="lazy" to content images for faster initial page load.
* NEW: SVG Support — allows SVG uploads with automatic XSS sanitization for administrators.
* NEW: Multisite — basic network admin settings page.
* NEW: Original Backup & Restore — keep originals in _erdo_backup/ and restore with one click.
* NEW: GIF support in WebP/AVIF conversion.
* FIXED: restore_original() now correctly prevents re-conversion via hook removal.
* FIXED: ajax_get_unused_list() now uses bulk_analyze() — 3000 queries → 5 queries.
* IMPROVED: All SEO audit rules refined (title hyphens OK, digit filenames OK, width=0 neutral).
* IMPROVED: Plugin Check clean — no errors or warnings.

= 1.8.5 =
* Renamed plugin to "Erdo Image Optimizer" for better brand identity.
* Added AVIF format support for superior compression.
* Added automatic filename sanitization feature for technical SEO.
* Added EXIF metadata removal option.
* Improved AJAX loading and database query performance.
* Security updates for official Plugin Check compatibility.

== Upgrade Notice ==

= 3.0.0 =
Major rebuild: originals are never changed again, unused images go to a reversible quarantine, and there is a new 0-100 SEO score and AI alt text. If 2.x converted your images, use the "Repair now" notice after updating.

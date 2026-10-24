=== Erdo Image Optimizer: Compress & Convert to WebP/AVIF (SEO Audit, Alt Text, Lazy Load) ===
Contributors: erdincbulat
Tags: image optimizer, webp, avif, image compression, alt text
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 2.2.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Compress & convert images to WebP/AVIF. SEO-audit every image, bulk-fix alt text, filenames & unused media. Lazy loading & BunnyCDN.

== Description ==

**Erdo Image Optimizer** is not just another image compression plugin. It is a complete **Image SEO & Performance Suite** that covers WebP/AVIF conversion, bulk alt text management, filename sanitization, lazy loading, unused media cleanup, and a 6-point SEO audit — all in a single, modern dashboard.

Most image optimization plugins stop at compression. Erdo goes further: every image in your media library receives a **professional SEO health score (0 out of 6)**. You will know exactly which images are hurting your Google rankings and how to fix them, in bulk, in minutes.

= Why Erdo Image Optimizer? =

* **Image SEO Audit (6 Points):** Every image gets a score from 0 to 6 based on alt text, filename quality, title, contextual relevance, dimensions, and format. Fix failing criteria with one click — no guesswork.
* **WebP & AVIF Converter:** One-click conversion from JPEG, PNG, GIF, and HEIC/HEIF to WebP or AVIF. Smart Quality mode automatically finds the smallest file size that still looks great.
* **Bulk Alt Text Generation:** Automatically generate descriptive alt text from filenames for every image missing it. Fix hundreds of images in seconds — critical for accessibility and image SEO.
* **Filename Sanitizer:** Transforms messy uploads like `IMG_1234 (copy).JPG` into SEO-friendly `professional-web-design.webp` at upload time. Bulk-rename existing images with automatic database reference updates.
* **Unused Media Cleanup:** Scans your entire library to find images that appear nowhere on your site. Free up disk space and stop confusing search crawlers with orphaned files.
* **Lazy Loading & WebP Serving:** Inject `loading="lazy"` into content images automatically. Serve WebP to compatible browsers via `.htaccess` rewrite (Apache) or PHP `<picture>` fallback (all servers).
* **Background Queue & WP-CLI:** Bulk-convert thousands of images via WP-Cron — close the browser tab and let it run. Full CLI support: `wp erdo convert`, `audit`, `restore`, `stats`, `clean-cache`.
* **BunnyCDN & REST API:** Upload converted images directly to BunnyCDN storage. Headless access via `/wp-json/erdo/v1/stats` and `/wp-json/erdo/v1/audit` REST endpoints.

= Full Feature List =

**Compression & Conversion**

* One-click WebP and AVIF conversion with original backup and one-click restore
* HEIC/HEIF (iPhone photos) conversion to WebP/AVIF via Imagick
* Smart Quality mode — automatically finds the smallest file size that still looks great
* Lossless mode for zero-quality-loss compression
* Max Upload Width — automatically resize oversized images before conversion
* EXIF metadata stripper — reduce file weight and protect photographer privacy
* GIF, JPEG, PNG, HEIC → WebP/AVIF in one click

**Image SEO & Alt Text**

* Image SEO Auditor with 6-point health score for every image in your library
* Bulk Alt Text — generate alt text from filenames for all images missing it
* Automatic filename sanitization on upload (removes spaces, special chars, uppercase)
* Bulk rename all existing filenames with automatic database reference update
* SEO Audit CSV export — download a full spreadsheet for reporting
* Missing Alt Text filter — focus the dashboard on images that need fixing

**Performance & Delivery**

* Lazy loading (`loading="lazy"`) injected into content, thumbnails, and gallery images
* WebP serving via `.htaccess` rewrite (Apache) or PHP `<picture>` element rewriter
* BunnyCDN integration — upload converted images directly to CDN storage
* Background Queue — bulk convert via WP-Cron, browser tab can be closed

**Developer & Dashboard**

* REST API: `/wp-json/erdo/v1/stats` and `/wp-json/erdo/v1/audit` endpoints
* WP-CLI commands: `wp erdo convert`, `audit`, `restore`, `stats`, `clean-cache`
* Dashboard widget — key stats visible from wp-admin home
* Before/After comparison slider — drag to compare original vs optimized
* Right-click context menu on every image card
* Grid size selector (Small / Medium / Large) with localStorage persistence
* SVG upload support with automatic XSS sanitization for administrators
* Multisite / network admin support
* Modern, clean dashboard with format badge and SEO score on every card

== Screenshots ==

1. Main dashboard — every image in your media library displayed with its WebP/AVIF format badge and 6-point SEO health score at a glance.
2. Image detail panel showing a poor SEO audit report — failing criteria highlighted with clear reasons and one-click fix options.
3. Image detail panel showing a fully optimized image — perfect 6/6 SEO score, WebP format, alt text and filename all confirmed healthy.
4. Settings page — configure output format (WebP/AVIF), compression quality, lazy loading, WebP serving, BunnyCDN integration, and more.
5. Unused media cleanup — bulk-delete orphaned images that appear nowhere on your site, with a confirmation prompt to prevent accidental deletion.

== Installation ==

1. Upload the `erdo-image-optimizer` folder to the `/wp-content/plugins/` directory, or install directly from the WordPress Plugin Directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Click **Image Optimizer** in your sidebar to run your first SEO audit.

== Frequently Asked Questions ==

= Is this just another image compression plugin? =

No. Compression is only one part of what we do. The core feature is the **Image SEO Auditor**, which grades every image in your media library on 6 SEO criteria: filename quality, alt text presence, title quality, contextual relevance, missing dimensions, and usage status. Most plugins never touch any of this.

= How do I reduce image file size in WordPress without losing quality? =

Use Erdo's Smart Quality mode. It automatically finds the lowest compression level where visual quality is still preserved, then converts your image to WebP or AVIF — both of which deliver 30-80% smaller file sizes compared to JPEG or PNG. You can also enable lossless mode for zero quality loss.

= What is the difference between WebP and AVIF? =

Both are modern image formats far smaller than JPEG or PNG. WebP offers broad browser support (all major browsers since 2020) and fast encoding. AVIF is newer and typically 20-30% smaller than WebP at the same visual quality, but requires PHP's GD library compiled with libavif support. Erdo supports both — pick the one that fits your server and audience.

= How do I add alt text to all my WordPress images at once? =

Use the **Bulk Alt Text** feature in the dashboard. Erdo scans every image missing alt text and automatically generates descriptive alt text from its filename. You can review and edit before saving. For images with meaningful filenames like `blue-running-shoes.jpg`, the generated alt text is immediately usable.

= How do I convert all images to WebP in WordPress? =

Go to Erdo's dashboard, click **Bulk Convert**, choose WebP as the target format, and start the queue. Conversion runs in the background via WP-Cron — you can close the browser tab and let it finish. For large libraries (1,000+ images), the WP-CLI command `wp erdo convert` is the fastest option.

= What makes the SEO Auditor different from other plugins? =

Most "SEO" image plugins only check for missing alt text. Erdo's auditor checks 6 distinct criteria and gives each image a score from 0 to 6 with specific reasons for any deductions. You get an actionable list, not a vague percentage.

= How do I serve WebP images in WordPress? =

Erdo handles WebP delivery automatically. On Apache servers, it writes `.htaccess` rewrite rules that transparently serve WebP versions to compatible browsers with zero code changes. On NGINX or other servers, it falls back to a PHP `<picture>` element rewriter that wraps your images with WebP source tags — no server configuration needed.

= Will my original images be lost after conversion? =

Never. Every original is automatically backed up to an `_erdo_backup/` subfolder before conversion. You can restore any image to its original with a single click from the dashboard.

= Does it work with all hosting providers? =

Yes. WebP delivery works via `.htaccess` rewrite rules on Apache hosts. For NGINX or other servers, the plugin automatically falls back to a PHP-based `<picture>` element rewriter — no server configuration required.

= Can I convert my entire existing media library? =

Yes. Use the Bulk Convert feature from the dashboard. Processing runs in the background via WP-Cron, so you can close the browser tab and let it finish. For large libraries, the WP-CLI command `wp erdo convert` is recommended.

= How do I find unused images in WordPress and delete them? =

Open the **Unused Media** tab in Erdo's dashboard. The plugin scans every image in your media library against all posts, pages, and ACF fields. Any image that appears nowhere on your site is flagged as unused. You can review the list and delete in bulk — freeing disk space and removing orphaned files that confuse search crawlers.

= Does the filename sanitizer affect images I have already uploaded? =

The automatic sanitizer applies to new uploads only. For existing images with messy filenames like `IMG_1234 (copy).JPG`, use the **Bulk Rename** feature in the dashboard — it renames files on disk, updates all database references, and prevents broken links automatically.

= Does it support AVIF? =

Yes. AVIF conversion is available in addition to WebP. AVIF typically produces 20-30% smaller files than WebP at the same visual quality. AVIF encoding requires PHP's GD library compiled with libavif support, which most modern hosts provide.

= Does it support HEIC and HEIF images from iPhones? =

Yes. Erdo can convert HEIC/HEIF photos (the default format on newer iPhones) to WebP or AVIF via PHP's Imagick extension. This requires Imagick to be installed on your server — most managed WordPress hosts include it by default.

= Is it compatible with WordPress Multisite? =

Yes. A dedicated Network Admin settings page lets you define default quality and format across all sites in the network.

= Does it have a REST API? =

Yes. Erdo exposes two REST endpoints: `/wp-json/erdo/v1/stats` returns optimization statistics (total images, conversion rate, space saved) and `/wp-json/erdo/v1/audit` returns the full SEO audit results. Both are useful for headless WordPress setups or custom reporting dashboards.

= Does it integrate with a CDN? =

Yes. Erdo includes a BunnyCDN integration that automatically uploads converted images to your BunnyCDN storage zone. Configure your Storage Zone name, API key, and hostname in the Settings tab to enable it.

== External services ==

**AI suggestions (optional, off until you set it up).** On WordPress 7.0 and newer, Erdo can ask an AI provider for alt text, filename, title and caption suggestions. It uses WordPress' built-in AI Client and the provider you connect under Settings > Connectors with your own API key; Erdo does not store or see the key and has no server of its own.

* When: only when you click "Suggest with AI", run "AI Suggestions…" from the Tools menu, or turn on "AI Suggestions on Upload" (off by default).
* What is sent: the image, resized to at most 1024 px, plus the title of the page it appears on, up to 500 characters of text around it, the page's SEO focus keyword, the current alt text and image title, and the site language. No user names, e-mail addresses or other personal data are sent.
* Where: to the provider you connected, under its own terms:
  * Anthropic (Claude): [Terms](https://www.anthropic.com/legal/commercial-terms), [Privacy](https://www.anthropic.com/legal/privacy)
  * Google (Gemini): [Terms](https://ai.google.dev/gemini-api/terms), [Privacy](https://policies.google.com/privacy)
  * OpenAI: [Terms](https://openai.com/policies/business-terms/), [Privacy](https://openai.com/policies/privacy-policy/)

Suggestions are never saved automatically: each one waits for your review in the "AI Suggestions" tab.

== Changelog ==

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
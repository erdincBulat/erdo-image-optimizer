# Erdo test suite

Behavioural tests that run inside the WP Studio test site (`erdo-test`, `http://localhost:8882`) via WP-CLI. Not shipped with the plugin.

## Running

```powershell
.\tests\run.ps1                           # all suites except perf (~3 min)
.\tests\run.ps1 -Suite 20                 # one suite by name/prefix
.\tests\run.ps1 -Perf -SuiteArgs n=5000   # performance on a synthetic library
.\tests\run.ps1 -Lint                     # php -l + Plugin Check first
.\tests\run.ps1 -Keep -Suite 30           # keep that suite's fixtures to inspect
.\tests\run.ps1 -Report out.csv           # CSV of every result
```

The site must be running (`studio start` in `%USERPROFILE%\Studio\erdo-test`) for the HTTP checks. The plugin folder there is a junction into `trunk/`, so tests always run against the working copy.

## Reading results

Each test carries the **PLAN-3.0.md phase** that delivers its behaviour:

- `[-]` means it must pass now. A failure here is a regression. The runner exits 1.
- `[F2]`, `[F3]` and so on are expected gaps until that phase lands. Use `-AllowPhase F3,F4` to tolerate them.

The summary groups failures by phase, so progress shows up as those groups shrinking.

## Layout

| Path | Purpose |
|---|---|
| `bootstrap.php` | Entry point for one suite (one PHP process per suite, since `wp_die()` ends the process). Snapshots and restores options and site state, and cleans fixtures. |
| `lib/harness.php` | `et_test()`, `et_assert()`, `et_eq()`, `et_skip()` and the output protocol. |
| `lib/fixtures.php` | Fixture library: images referenced from content, featured image, theme mods, site icon, widgets, Elementor JSON, ACF, Woo gallery, serialized meta, draft, trash and `[gallery]`. The expected usage of each image is listed in `et_expected_usage()`. Also contains `et_simulate_legacy_conversion()`, which reproduces the damage done by 2.x. |
| `lib/adapter.php` | The **only** place that calls plugin internals. When 3.0 changes the API, update this file, not the suites. |
| `lib/http.php` | Real HTTP checks: broken image URLs, invalid `<source src>`, lazy-loaded LCP images. |
| `suites/` | `00-env`, `10-usage`, `20-convert`, `30-rename`, `40-delivery`, `50-seo`, `60-upload`, `70-security`, `90-perf`. |

## Not covered here

These need other environments and are scheduled for Faz 9:

- Imagick (the Studio site has GD only).
- Live Apache `.htaccess` behaviour.
- nginx.
- WP 7.1 browser-side (client) media processing.
- Multisite.

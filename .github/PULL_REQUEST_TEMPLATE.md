## What does this PR do?

<!-- Describe the change and why it's needed -->

## Related issue

<!-- Closes #123, if applicable -->

## Checklist

- [ ] Changes are in `trunk/` only (not `tags/` or `assets/`)
- [ ] Each new/changed AJAX handler calls `check_ajax_referer()` and `verify_capability()` individually (not via a shared dispatcher)
- [ ] Inputs are sanitized, outputs are escaped, and any direct `$wpdb` queries are prepared
- [ ] `readme.txt` changelog updated, and `Version`/`ERDO_VERSION`/`Stable tag` bumped together if this is a release-worthy change
- [ ] Tested manually against a real WordPress install

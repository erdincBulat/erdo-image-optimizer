# Live Preview blueprint

`blueprint.json` is read by WordPress.org from the SVN `assets/blueprints/` folder and powers the
"Live Preview" button on the plugin page (WordPress Playground).

What it does:

1. Logs in as `admin`.
2. Installs and activates `erdo-image-optimizer` from WordPress.org (the current stable tag).
3. Marks the setup wizard as done and turns on WebP creation for uploads and `<picture>` delivery.
4. Downloads five images into `wp-content/erdo-demo-import/`.
5. Imports them into the media library and creates three posts:
   - "A weekend in Paris": `IMG_4821.jpg` in the content, no alt text (camera-style name, missing alt).
   - "Planning a trip to Banff": `moraine-lake-banff-canada.jpg` in the content with good alt text;
     `earth-from-apollo-17.jpg` as the featured image.
   - "Homemade pizza night": `pizza_margherita.jpg` in the content with weak alt text ("image of pizza")
     and as the featured image.
   - `DSC_0042.jpg` is not used anywhere, so it shows up under Cleanup.
6. Builds the usage index right away (no waiting for WP-Cron) and deletes the import folder.
7. Turns on "Clean file names" for later uploads.
8. Opens `wp-admin/admin.php?page=erdo-image-optimizer`.

## Image sources

All images are 960 px thumbnails served by Wikimedia Commons (`upload.wikimedia.org`, which sends
`Access-Control-Allow-Origin: *`). Each one is public domain or CC0, so no attribution is required;
it is listed here anyway.

| Demo file name | Commons file | Author | License |
|---|---|---|---|
| `IMG_4821.jpg` | [Tour Eiffel Wikimedia Commons.jpg](https://commons.wikimedia.org/wiki/File:Tour_Eiffel_Wikimedia_Commons.jpg) | Benh LIEU SONG | Public domain |
| `moraine-lake-banff-canada.jpg` | [Moraine Lake 17092005.jpg](https://commons.wikimedia.org/wiki/File:Moraine_Lake_17092005.jpg) | Gorgo | Public domain |
| `pizza_margherita.jpg` | [Pizza-3007395.jpg](https://commons.wikimedia.org/wiki/File:Pizza-3007395.jpg) | igorovsyannykov (via Pixabay) | CC0 1.0 |
| `earth-from-apollo-17.jpg` | [The Earth seen from Apollo 17.jpg](https://commons.wikimedia.org/wiki/File:The_Earth_seen_from_Apollo_17.jpg) | NASA / Apollo 17 crew | Public domain (NASA) |
| `DSC_0042.jpg` | [Aldrin Apollo 11 original.jpg](https://commons.wikimedia.org/wiki/File:Aldrin_Apollo_11_original.jpg) | Neil Armstrong / NASA | Public domain (NASA) |

Licenses were checked with the Commons API on 2026-10-25.

## Testing

Paste the minified JSON after `https://playground.wordpress.net/#`, or run
`npx @wp-playground/cli server --blueprint=assets/blueprints/blueprint.json`.
Until 3.0.0 is the stable tag on WordPress.org, the preview installs 2.2.2. The demo content still
loads, but the usage index step is skipped and the 3.0 screens are not there yet.

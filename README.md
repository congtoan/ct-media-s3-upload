# CT Media S3 Upload

Mirror every WordPress media upload (original file + every generated thumbnail size) to an **additional S3-compatible bucket**, and delete it there when the attachment is deleted.

Built for sites that already offload media with [Media Cloud](https://wordpress.org/plugins/ilab-media-tools/) (which supports **one** bucket) but need a **second copy in another region**. Example: Media Cloud writes to Linode Singapore, this plugin writes the same keys to Linode Chicago, and a Cloudflare Worker serves visitors from the nearest bucket. It also works on its own, without Media Cloud.

- Same object key as the path inside `wp-content/uploads/` (e.g. `2024/05/photo.jpg`, `custom/folder/photo-300x200.jpg`).
- Uploads on `wp_update_attachment_metadata` (priority **20**, i.e. *before* Media Cloud's priority 1000, which may delete the local file after offloading).
- Deletes on `delete_attachment` (object keys are collected in `before_delete_post`, with a database fallback).
- Does **not** modify the database or attachment URLs.
- Works with WordPress multisite and with custom upload folders (e.g. the [Custom Upload Folder](https://wordpress.org/plugins/custom-upload-folder/) plugin).
- Companion plugin: [CT Media Overwrite by Filename](https://github.com/congtoan/ct-media-overwrite-by-filename). Supports its `media_overwrite_is_fake_delete` filter (delete thumbnails only, keep the main file).

## Requirements
- PHP 7.4+
- [AWS SDK for PHP](https://github.com/aws/aws-sdk-php) v3 (installed with Composer into `vendor/`)
- An S3-compatible bucket: AWS S3, Linode/Akamai Object Storage, DigitalOcean Spaces, Wasabi, Cloudflare R2, MinIO...

## Installation
1. Download the release zip that **includes `vendor/`**, or clone the repo and run:
   ```bash
   cd wp-content/plugins/ct-media-s3-upload
   composer install --no-dev
   ```
2. Add your settings to **`wp-config.php`** (above the "That's all, stop editing!" line):
   ```php
   define( 'CT_MEDIA_S3_ENDPOINT',   'https://us-ord-1.linodeobjects.com' );
   define( 'CT_MEDIA_S3_REGION',     'us-ord-1' );
   define( 'CT_MEDIA_S3_ACCESS_KEY', 'YOUR_ACCESS_KEY' );
   define( 'CT_MEDIA_S3_SECRET_KEY', 'YOUR_SECRET_KEY' );
   define( 'CT_MEDIA_S3_BUCKET',     'your-bucket-name' );
   // optional
   define( 'CT_MEDIA_S3_ACL',        'public-read' ); // default public-read
   define( 'CT_MEDIA_S3_PATH_STYLE', true );          // default true
   ```
   The legacy `config-s3.php` file (see `config-s3.sample.php`) still works, but keeping keys in `wp-config.php` is safer and survives plugin updates.
3. Activate the plugin (network-activate on multisite).

If `vendor/` or the configuration is missing, the plugin does nothing and shows an admin notice instead of breaking the site.

## How it works
| WordPress event | Action on the extra bucket |
|---|---|
| Upload / regenerate / overwrite (`wp_update_attachment_metadata`) | `PutObject` original + all sizes, `ACL` = `CT_MEDIA_S3_ACL` |
| Attachment deleted (`delete_attachment`) | `DeleteObject` original + all sizes |
| Fake delete (`media_overwrite_is_fake_delete` returns true) | Delete thumbnails only |

Logs go to the PHP error log (`S3 Upload Success: ...`, `S3 Delete ...`).

## Notes
- Only the attached file and the sizes listed in metadata are mirrored. `original_image` (the full-size original kept by WordPress for images larger than the big-image threshold) is not mirrored.
- Media uploaded **before** the plugin was activated is not copied. Use a bucket-to-bucket sync tool (e.g. `rclone sync`) once.

## Roadmap
- Several extra buckets (array of targets).
- WP-CLI command to backfill existing media.

## Translations
Translation-ready (text domain `ct-media-s3-upload`, folder `languages/`). Included: English (default), Vietnamese (`vi`).
To add a language, translate `languages/ct-media-s3-upload.pot` into `ct-media-s3-upload-{locale}.po/.mo`.

## Changelog
See [CHANGELOG.md](CHANGELOG.md).

## Author
[Cong Toan](https://github.com/congtoan)

## License
GPL-2.0-or-later. See [LICENSE](LICENSE).

# Changelog

## 1.7.0 – 2026-11-26
- Configuration can be defined with `CT_MEDIA_S3_*` constants in `wp-config.php` (recommended). Legacy `config-s3.php` still supported.
- Optional `CT_MEDIA_S3_ACL` and `CT_MEDIA_S3_PATH_STYLE`.
- No fatal error when `vendor/` or the configuration is missing: the plugin shows an admin notice and stays inactive.
- English comments and plugin header (author: Cong Toan); `config-s3.sample.php`; README, LICENSE.
- Translation-ready (text domain `ct-media-s3-upload`); Vietnamese translation and `.pot` template included. Admin notices are translated at display time (no early textdomain loading).
- No change in upload/delete behaviour.

## 1.6 – 2026-05-05
- Support the `media_overwrite_is_fake_delete` filter from *CT Media Overwrite by Filename*: on a fake delete, only thumbnails are removed and the main file is kept.
- Delete log with counters.

## 1.5 – 2025-09-07
- Upload the original file and all sizes to an S3-compatible bucket on `wp_update_attachment_metadata`.
- Cache object keys in `before_delete_post` and delete them in `delete_attachment` (database fallback).

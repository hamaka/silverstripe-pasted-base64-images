# Changelog

## 2.0.1 - 2026-11-10

- Shorter README.
- The tests require PHPUnit `^11.3`, like Silverstripe 6 itself. No changes to the code.

## 2.0.0 - 2026-11-10

- Silverstripe 6 version of 1.0.0. `ConvertBase64ImagesTask` uses the Silverstripe 6 task API:
  `sake tasks:ConvertBase64ImagesTask` with `--apply`, `--details` and `--[no-]optimize`, and it exits with a
  non-zero code when an image could not be converted.

## 1.0.0 - 2026-11-10

Initial release, for Silverstripe 5.

- On write, base64 images in `HTMLText` fields are converted into files in Files with an `[image]` shortcode. Only
  fields that process shortcodes are converted, and only for users with CMS access (`write_permission`).
- `ConvertBase64ImagesTask` converts existing content: the base table, `_Live`, `_Versions` and the `_Localised`
  tables of Fluent. It writes with direct SQL, so no new versions are created, updates the file usage ("Used on" in
  Files), reports what it skipped and why, and runs `OPTIMIZE TABLE` afterwards. Dry run unless `apply=1`.
- Limits (`max_bytes`, `max_pixels`, `max_images`) and exclusions (`exclude_classes`, `exclude_fields`).
- Files are deduplicated on their hash within the module's own folder.
- Errors leave the original tag in place, are logged, and are mailed when a recipient is configured.

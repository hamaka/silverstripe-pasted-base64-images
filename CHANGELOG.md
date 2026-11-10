# Changelog

## 1.0.3 - 2026-11-10

- The task generates the missing thumbnails of files that were converted before 1.0.2. Run it once
  with `apply=1` after updating; it also does this when there is nothing left to convert.

## 1.0.2 - 2026-11-10

- Converted images get the thumbnails that the file list in the CMS shows; they showed up as empty tiles. Turn it
  off with `generate_thumbnails: false`. Files that were converted before this release keep their empty tile until
  their thumbnails are generated.

## 1.0.1 - 2026-11-10

- Shorter README. No changes to the code.

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

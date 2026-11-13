# Changelog

## 1.1.0 - unreleased

- Images wider or taller than `max_dimension` (2500 px) are scaled down before they are saved, with the project's
  `Image_Backend`. They were left as base64 when they had more pixels than `max_pixels`. GIFs are not scaled. Set
  `max_dimension: 0` to keep the original size.
- Files are no longer always published at once. On a versioned page or block they stay in draft and are published
  along with it, through the `FileTracking` relation in `$owns`, like images inserted through the CMS. Objects that
  are not versioned, and writes to the live stage, still publish them right away. The task publishes only the files
  used in the live tables.
- `Base64ImageConverter::setPublish()`; the stat `resized`.
- README: what happens to an image that is not converted.
- Errors are no longer mailed by the module. They are logged to the logger service
  `Psr\Log\LoggerInterface.Base64Images`, which falls back to the default `Psr\Log\LoggerInterface`. Define that
  service to mail them, or to send them anywhere else: see the README.
- Removed: the settings `notify_email` and `notify_throttle_minutes`, `Base64ImageConverter::notify()` and
  `setNotify()`, and the cache `Psr\SimpleCache\CacheInterface.Base64Images`.
- A log entry has the context and a snippet of the tag in its log context.
- `max_pixels` is 30 megapixels instead of 50. Generating the thumbnails loads the whole image into memory (about 4
  bytes per pixel), so 50 megapixels could exceed a `memory_limit` of 256M.

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

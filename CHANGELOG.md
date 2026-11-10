# Changelog

## Unreleased

Fixed:

- Only fields that process shortcodes (`HTMLText`) are converted. `HTMLFragment` and `HTMLVarchar` fields were
  converted too, and then showed the `[image]` shortcode as text.
- A `>` inside an attribute value no longer cuts the `<img>` tag short, and `<` and `>` in `alt` or `title` are
  encoded in the shortcode, so text from an attribute cannot become markup.
- A file is only reused when it is in the module's own folder. An identical image elsewhere in Files is no longer
  reused and published.
- A JPEG with data after the image (phone photos with a second image or a video) and a PNG with trailing data are
  no longer rejected as truncated.
- Data URIs with a parameter, such as `data:image/png;charset=utf-8;base64,`, and `image/x-png` are converted.
- The task no longer overwrites a record that an editor saved during the run.

Added:

- `write_permission`: on write, the conversion only runs for users with this permission (default: any CMS access).
  **Changed behaviour:** HTML saved by visitors without CMS access is no longer converted. Set it to `false` for
  the old behaviour.
- `max_bytes`, `max_pixels` and `max_images`: images over a limit are left as they are and reported as an error.
- `exclude_classes` and `exclude_fields`: leave out classes or fields, on write and in the task.
- The task covers the `_Localised` tables of Fluent.
- The task updates the file usage ("Used on" in Files) of the records it changed, and so does the conversion on
  write.
- The task reports data URIs it does not convert (svg, a CSS background, ...) as skipped, with the reason, and
  prints a line per table before it starts on it.

Changed:

- Notifications: one mail per saved record instead of one per image, and at most one mail per
  `notify_throttle_minutes` whatever the errors are. The task's summary mail is not throttled.
- `Base64ImageExtension::filterHtmlFields()` is replaced by `Base64ImageConverter::convertibleFields()`.

## 2.0.0

- Silverstripe 6 version of 1.0.0. `ConvertBase64ImagesTask` uses the Silverstripe 6 task API:
  `sake tasks:ConvertBase64ImagesTask` with `--apply`, `--details` and `--[no-]optimize`, and it exits with a
  non-zero code when an image could not be converted.

## 1.0.0

- Initial release for Silverstripe 5: converts base64 images in HTML fields into files in Files with an `[image]`
  shortcode on write, plus `ConvertBase64ImagesTask` for existing content (including `_Live` and `_Versions`).

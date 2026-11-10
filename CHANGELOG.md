# Changelog

## 2.0.0

- Silverstripe 6 version of 1.0.0. `ConvertBase64ImagesTask` uses the Silverstripe 6 task API:
  `sake tasks:ConvertBase64ImagesTask` with `--apply`, `--details` and `--[no-]optimize`, and it exits with a
  non-zero code when an image could not be converted.

## 1.0.0

- Initial release for Silverstripe 5: converts base64 images in HTML fields into files in Files with an `[image]`
  shortcode on write, plus `ConvertBase64ImagesTask` for existing content (including `_Live` and `_Versions`).

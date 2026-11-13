# Silverstripe pasted base64 images

Converts images that were pasted or dropped into the HTML editor, and stored as base64 data URIs, into regular
files in **Files** with a standard `[image]` shortcode. It does this on save, and with a task for existing content,
including the version history.

## The problem

TinyMCE has `paste_data_images` enabled by default in Silverstripe 5 and 6. A pasted or dropped image ends up in the
HTML field as:

```html
<img src="data:image/png;base64,iVBORw0KGgo...">
```

- **The database keeps growing.** Every save or publish copies the full image into `SiteTree_Versions` again.
- **The image isn't in Files.** No management, no reuse, no resizing, no caching as a separate file.

Pasting keeps working for editors: this module leaves `paste_data_images` on and fixes it on the server.

## What it does

- **On write,** an extension on `DataObject` converts every `HTMLText` field that contains a base64 image. That
  covers pages, elemental blocks and every other object. By default only for users with CMS access.
- **Each image** (png, jpg, gif or webp) is validated and saved as an `Image` in `Uploads/pasted-images`. The same
  image is stored once.
- **A large image is scaled down** to at most 2500 px wide or high (`max_dimension`), with the image backend of
  Silverstripe, the same one that makes every resized image.
- **The file is published along with the page,** like an image inserted through the CMS: a page or block owns
  the files in its HTML. Content that is live at once (an object that is not versioned) publishes the file right
  away.
- **The `<img>` becomes** an `[image]` shortcode. `alt`, `class`, `width`, `height` and `title` are kept.
- **On an error** the original tag is left as it is, so no content is lost. The error is logged, see
  [When an image is not converted](#when-an-image-is-not-converted).
- **A task** converts existing content, see [Cleaning up existing content](#cleaning-up-existing-content).

## Requirements

| Silverstripe | PHP | Version | Branch |
|---|---|---|---|
| 5.3+ | 8.1+ | `^1` | [`1`](https://github.com/hamaka/silverstripe-pasted-base64-images/tree/1) |
| 6 | 8.3+ | `^2` | [`2`](https://github.com/hamaka/silverstripe-pasted-base64-images/tree/2) (this branch) |

## Installation

```sh
composer require hamaka/silverstripe-pasted-base64-images
```

Then flush: `?flush=1` in the browser, or `vendor/bin/sake flush`. The module adds no tables and works without
configuration.

## Configuration

All settings are optional. These are the defaults:

```yaml
Hamaka\Base64Images\Base64ImageConverter:
  folder: 'Uploads/pasted-images'          # folder in Files
  filename_prefix: 'pasted-'               # followed by the first 12 characters of the sha1
  image_title: 'Pasted image'
  default_class: 'leftAlone ss-htmleditorfield-file image'  # used when the <img> has no class
  generate_thumbnails: true                # thumbnails for the file list in the CMS

  max_dimension: 2500                      # larger width or height is scaled down to this (px); 0: keep the original

  write_permission: 'CMS_ACCESS'           # who triggers the conversion on write; false: everyone
  max_bytes: 20971520                      # largest pasted file that is converted (20 MB); 0: no limit
  max_pixels: 30000000                     # safety limit, largest width x height that is converted; 0: no limit
  max_images: 100                          # per field of one record; 0: no limit

  exclude_classes: []                      # classes that are never converted, with their subclasses
  exclude_fields: []                       # fields that are never converted, as 'Class.Field'
```

- **`max_dimension`:** the image backend applies the project's quality setting and turns a photo upright
  according to its EXIF orientation. GIFs are not scaled, an animated GIF would lose its animation. When scaling
  fails, the original is saved and a warning is logged.
- **`max_pixels`:** scaling down loads the whole image into memory, about 4 bytes per pixel. This limit keeps that
  within `memory_limit`: a file of a few KB can declare 20000 x 20000 pixels. 30 megapixels covers the photos of
  current phones. You won't normally need to change it, set `max_dimension` instead.
- **`write_permission`:** the conversion creates files, so HTML submitted by visitors without CMS access is left
  as it is. Writes from the command line and the task are always converted.
- **`exclude_classes` and `exclude_fields`:** for fields that are not rendered through the shortcode parser, such
  as a newsletter body or content served through an API. They apply on write and in the task.

To disable the conversion on write altogether and only keep the task:

```yaml
SilverStripe\ORM\DataObject:
  extensions:
    base64Images: null
```

## When an image is not converted

The original `<img>` with its base64 data stays in the HTML, so nothing is lost and the page looks the same. That
happens when:

- the data is invalid, not an image, or cut off (an incomplete paste),
- the file is larger than `max_bytes` or has more pixels than `max_pixels`,
- a field holds more than `max_images` images (the rest is left),
- the file could not be written.

Each case is logged as a warning, see [Logging](#logging). The editor does not see a message: replace the image in
the CMS by uploading it. The task lists these as **errors**.

Data URIs the module does not handle at all (svg, a CSS background, `srcset`) are not errors: they are left alone
and reported by the task as **skipped**.

## Logging

An image that could not be converted is logged as a warning, with the record and field in the message and a
snippet of the tag in the log context. So is an image that was saved at its original size because scaling it down
failed. The module logs to the service `Psr\Log\LoggerInterface.Base64Images`.
Unless you define it, that is the default `Psr\Log\LoggerInterface`, so the warnings end up wherever your other
log messages go.

```yaml
SilverStripe\Core\Injector\Injector:
  Psr\Log\LoggerInterface.Base64Images:
    class: Monolog\Logger
    constructor:
      - 'base64-images'
    calls:
      - [pushHandler, ['%$Base64ImagesMailHandler']]
  Base64ImagesMailHandler:
    class: Monolog\Handler\BufferHandler
    constructor:
      - '%$Base64ImagesNativeMailHandler'
  Base64ImagesNativeMailHandler:
    class: Monolog\Handler\NativeMailerHandler
    constructor:
      - 'webmaster@example.com'          # to
      - 'Pasted image not converted'     # subject
      - 'noreply@example.com'            # from
      - 'warning'                        # lowest level that is mailed
```

This logger replaces the default one for these messages. To also keep them in your regular log, push your
existing handler onto it as well.

## Cleaning up existing content

`ConvertBase64ImagesTask` converts the base table, `_Live` and `_Versions` of every class, and the `_Localised`
tables of [Fluent](https://github.com/tractorcow-farm/silverstripe-fluent).

**Make a database backup first.**

```sh
vendor/bin/sake tasks:ConvertBase64ImagesTask                          # dry run: count and report
vendor/bin/sake tasks:ConvertBase64ImagesTask --details                # dry run, per record
vendor/bin/sake tasks:ConvertBase64ImagesTask --apply                  # convert + OPTIMIZE TABLE
vendor/bin/sake tasks:ConvertBase64ImagesTask --apply --no-optimize    # without OPTIMIZE TABLE
```

- It writes with direct SQL: no new versions are created, and pages don't show up as "Modified".
- Old versions point to the same file, so the History tab keeps showing the image.
- It runs `OPTIMIZE TABLE` on the changed tables, because InnoDB doesn't release the freed space otherwise.
- It exits with a non-zero code when an image could not be converted.
- It publishes the files used in the live tables. Files only used in a draft or in the history stay in draft and
  are published along with the page.
- It generates the thumbnails for the file list in the CMS for converted files that don't have them yet.
- It is safe to run again.

The output of an apply run, shortened:

```
MODE: APPLY
SiteTree.Content: 3 record(s) with data:image/ ...
SiteTree_Versions.Content: 120 record(s) with data:image/ ...
--- Per table ---
SiteTree.Content: 3 records with data:image/, 3 updated, 4 images, 310 KB base64
SiteTree_Versions.Content: 120 records with data:image/, 119 updated, 121 images, 9.2 MB base64, 1 skipped
--- Total ---
125 images converted (9.5 MB base64 removed from the HTML)
4 new files in Files (Uploads/pasted-images), an existing file was reused 121 times
--- Skipped (not converted by this module, left as they are) ---
1 x Unsupported data URI (data:image/svg+xml;base64)
--- Table sizes ---
SiteTree_Versions: 14.1 MB -> 4.3 MB
```

**Skipped** data URIs are ones this module does not convert, see below. **Errors** are images that should have
been converted but could not be; replace those in the CMS.

## Limitations

- **Only png, jpg, gif and webp** in the `src` of an `<img>`. Svg, avif, bmp, `srcset` and CSS backgrounds are
  left as they are.
- **Only `HTMLText` fields.** `HTMLFragment` and `HTMLVarchar` don't render shortcodes, so they are left alone.
- **A file that is only published through its owner** stays in draft when code publishes the owner with
  `publishSingle()` instead of `publishRecursive()`. That is the same for images inserted through the CMS.
- **The task** sets `LastEdited` on the records it changes. Flush static caches yourself.
- **`OPTIMIZE TABLE`** rebuilds the table and needs free disk space of about its size. It and the table sizes are
  MySQL and MariaDB only.
- **The dry run** leaves your content alone, but runs `ANALYZE TABLE` to get current table sizes.

## Contributing

Report bugs and ideas in the [issues](https://github.com/hamaka/silverstripe-pasted-base64-images/issues). Pull
requests are welcome; code that is the same for Silverstripe 5 and 6 goes to branch `1`.

## License

BSD-3-Clause, see [LICENSE.md](LICENSE.md).

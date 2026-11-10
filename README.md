# Silverstripe base64 images

Converts images that were pasted or dropped into the HTML editor, and stored as base64 data URIs, into regular
files in **Files** with a standard `[image]` shortcode. It does this on save, and with a task for existing content,
including the version history.

## The problem

TinyMCE 6 ships with Silverstripe 5 and 6, and has `paste_data_images` enabled by default. When an editor pastes or
drops an image into the editor, it ends up in the HTML field as:

```html
<img src="data:image/png;base64,iVBORw0KGgo...">
```

That has two consequences:

- **The database keeps growing.** Every save or publish copies the full image into `SiteTree_Versions`, and also
  into `SiteTree` and `SiteTree_Live`. One site had 45 MB in 589 version records.
- **The image isn't in Files.** That means no management, no reuse, no resizing, and no caching as a separate file.

Pasting should keep working for editors, so this module leaves `paste_data_images` on and fixes it on the server.

## What it does

- **On write:** an extension on `DataObject` converts every HTML field (`HTMLText`, `HTMLVarchar`, `HTMLFragment`)
  that contains `data:image/`. That covers pages, elemental blocks and every other object.
- **Each image** (png, jpg, gif or webp) is decoded and validated:
  - `getimagesizefromstring` must recognise it as an image
  - the file must have a valid end, so truncated data is caught too

  The image is then saved as an `Image` in `Uploads/pasted-images` and published.
- **The `<img>` becomes** `[image src="..." id="..." width="..." height="..." class="..." alt="..."]`. Existing
  `alt`, `class`, `width`, `height` and `title` attributes are kept.
- **Deduplication on `FileHash` (sha1):** pasting the same image again reuses the existing file, and so do old
  versions of the same content.
- **On any error,** such as invalid data or a PCRE error, the original tag is left as it is, so no content is lost.
  The error is logged and mailed, see [Configuration](#configuration).
- **A task cleans up existing content.** See [Cleaning up existing content](#cleaning-up-existing-content).

## Requirements

| Silverstripe | Version | Branch |
|---|---|---|
| 5.3+ | `^1` | [`1`](https://github.com/hamaka/silverstripe-base64-images/tree/1) (this branch) |
| 6 | `^2` | [`2`](https://github.com/hamaka/silverstripe-base64-images/tree/2) |

## Installation

```sh
composer require hamaka/silverstripe-base64-images
```

Composer picks the right version for your Silverstripe version.

Then flush: `?flush=1` in the browser, or `vendor/bin/sake dev/build flush=1`. The module adds no tables.

## Configuration

All settings are optional:

```yaml
Hamaka\Base64Images\Base64ImageConverter:
  folder: 'Uploads/pasted-images'          # folder in Files
  filename_prefix: 'pasted-'               # followed by the first 12 characters of the sha1
  image_title: 'Pasted image'
  default_class: 'leftAlone ss-htmleditorfield-file image'  # used when the <img> has no class
  notify_email: null                       # null: Email.admin_email; false: no mail (errors are still logged)
  notify_throttle_minutes: 60              # the same error is mailed at most once per this many minutes
```

To disable the conversion on write for a project:

```yaml
SilverStripe\ORM\DataObject:
  extensions:
    base64Images: null
```

## Cleaning up existing content

`ConvertBase64ImagesTask` goes through every HTML field of every DataObject class. It covers the base table,
`_Live` and `_Versions`.

- It updates the content with direct SQL. No new versions are created, and pages don't show up as "Modified".
- Old versions point to the same deduplicated file, so the History tab keeps showing the image.
- Afterwards it runs `OPTIMIZE TABLE` on the changed tables. InnoDB doesn't release the freed space otherwise.
- It reports the table sizes before and after.
- It runs as a dry run unless `apply=1` is passed.

**Make a database backup first.**

```sh
vendor/bin/sake dev/tasks/ConvertBase64ImagesTask                      # dry run: count and report
vendor/bin/sake dev/tasks/ConvertBase64ImagesTask verbose=1            # dry run, per record
vendor/bin/sake dev/tasks/ConvertBase64ImagesTask apply=1              # convert + OPTIMIZE TABLE
vendor/bin/sake dev/tasks/ConvertBase64ImagesTask apply=1 optimize=0   # without OPTIMIZE TABLE
```

To find affected content beforehand, and to check afterwards that it returns 0 rows:

```sql
SELECT ID, Title, LENGTH(Content) FROM SiteTree_Live WHERE Content LIKE '%data:image/%';
-- likewise for SiteTree and SiteTree_Versions, and other tables with HTML fields
```

## Running the tests

The tests are not included in the Packagist download. Clone the repository into a Silverstripe project, then run:

```sh
vendor/bin/phpunit vendor/hamaka/silverstripe-base64-images/tests
```

## License

BSD-3-Clause, see [LICENSE.md](LICENSE.md).

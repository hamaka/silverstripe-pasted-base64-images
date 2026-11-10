# Silverstripe pasted base64 images

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
  into `SiteTree` and `SiteTree_Live`.
- **The image isn't in Files.** That means no management, no reuse, no resizing, and no caching as a separate file.

Pasting should keep working for editors, so this module leaves `paste_data_images` on and fixes it on the server.

## What it does

- **On write:** an extension on `DataObject` converts every `HTMLText` field that contains `data:image/`. That
  covers pages, elemental blocks and every other object.
  - Only fields that process shortcodes are converted, because the image becomes a shortcode. `HTMLFragment`,
    `HTMLVarchar` and `HTMLText(['shortcodes' => false])` are left alone.
  - Only for CMS users by default, see [Who triggers the conversion](#who-triggers-the-conversion).
- **Each image** (png, jpg, gif or webp) is decoded and validated:
  - `getimagesizefromstring` must recognise it as an image; the type comes from the bytes, not from the data URI
  - the file must be complete, so truncated data is caught
  - it must be within the size limits, see [Configuration](#configuration)

  The image is then saved as an `Image` in `Uploads/pasted-images` and published.
- **The `<img>` becomes** `[image src="..." id="..." width="..." height="..." class="..." alt="..."]`. Existing
  `alt`, `class`, `width`, `height` and `title` attributes are kept. Other attributes, such as `style`, are dropped.
- **Deduplication on `FileHash` (sha1):** pasting the same image again reuses the existing file, and so do old
  versions of the same content. Only files in the module's own folder are reused, never an editor's own upload.
- **On any error,** such as invalid data or an image over a limit, the original tag is left as it is, so no content
  is lost. The error is logged, and mailed if a recipient is configured, see [Notifications](#notifications).
- **A task cleans up existing content.** See [Cleaning up existing content](#cleaning-up-existing-content).

## Requirements

| Silverstripe | PHP | Version | Branch |
|---|---|---|---|
| 5.3+ | 8.1+ | `^1` | [`1`](https://github.com/hamaka/silverstripe-pasted-base64-images/tree/1) |
| 6 | 8.3+ | `^2` | [`2`](https://github.com/hamaka/silverstripe-pasted-base64-images/tree/2) (this branch) |

## Installation

```sh
composer require hamaka/silverstripe-pasted-base64-images
```

Composer picks the right version for your Silverstripe version. To be explicit, add `:^2` for Silverstripe 6 or
`:^1` for Silverstripe 5.

Then flush: `?flush=1` in the browser, or `vendor/bin/sake flush`. The module adds no tables and works without any
configuration.

## Configuration

All settings are optional. These are the defaults:

```yaml
Hamaka\Base64Images\Base64ImageConverter:
  folder: 'Uploads/pasted-images'          # folder in Files
  filename_prefix: 'pasted-'               # followed by the first 12 characters of the sha1
  image_title: 'Pasted image'
  default_class: 'leftAlone ss-htmleditorfield-file image'  # used when the <img> has no class

  write_permission: 'CMS_ACCESS'           # who triggers the conversion on write; false: everyone
  max_bytes: 20971520                      # largest image that is converted (20 MB); 0: no limit
  max_pixels: 50000000                     # largest width x height that is converted; 0: no limit
  max_images: 100                          # per field of one record; 0: no limit

  exclude_classes: []                      # classes that are never converted, with their subclasses
  exclude_fields: []                       # fields that are never converted, as 'Class.Field'

  notify_email: null                       # null: Email.admin_email; false: no mail
  notify_throttle_minutes: 60              # at most one mail per this many minutes; 0: no throttling
```

### Who triggers the conversion

The conversion creates and publishes files, so on write it only runs when the current user has one of the
permissions in `write_permission`. The default `CMS_ACCESS` means access to any section of the CMS. HTML that an
anonymous visitor submits through a frontend form is therefore left as it is.

- Set it to another permission code, or a list of codes, to narrow it down.
- Set it to `false` to convert for everyone. Only do that when you trust everything that can write to your HTML
  fields, and keep the limits in place.
- Writes from the command line (imports, your own tasks) and the cleanup task are always converted.

### Leaving out classes or fields

A field that is not rendered through the shortcode parser, for example the body of a newsletter or content that is
served through an API, should not get shortcodes:

```yaml
Hamaka\Base64Images\Base64ImageConverter:
  exclude_classes:
    - App\Model\Newsletter
  exclude_fields:
    - 'App\Model\Product.ApiDescription'
```

Both apply on write and in the task, and both include subclasses.

To disable the conversion on write for the whole project, and only keep the task:

```yaml
SilverStripe\ORM\DataObject:
  extensions:
    base64Images: null
```

### Notifications

Every error is written to the log. A mail is only sent when there is a recipient:

- `notify_email: null` (the default) uses `Email.admin_email`. Silverstripe leaves that empty by default, so
  **no mail is sent until you set `Email.admin_email` or `notify_email`**.
- `notify_email: false` never mails.

The mail lists the record and field, the error, and the first 300 characters of the `<img>` tag. On write there is
one mail per saved record, and at most one per `notify_throttle_minutes`; errors in between are only logged. The
task always sends its one summary.

## Cleaning up existing content

`ConvertBase64ImagesTask` goes through every convertible field of every DataObject class. It covers the base table,
`_Live` and `_Versions`, and the `_Localised` tables of
[Fluent](https://github.com/tractorcow-farm/silverstripe-fluent) when they exist.

- It updates the content with direct SQL. No new versions are created, and pages don't show up as "Modified".
- Old versions point to the same deduplicated file, so the History tab keeps showing the image.
- It updates the file usage, so the new files show their pages under "Used on" in Files.
- Afterwards it runs `OPTIMIZE TABLE` on the changed tables. InnoDB doesn't release the freed space otherwise.
- It reports the table sizes before and after.
- It runs as a dry run unless `--apply` is passed.
- It exits with a non-zero code when an image could not be converted.
- It is safe to run again: converted content no longer matches, and files are deduplicated.

**Make a database backup first.**

```sh
vendor/bin/sake tasks:ConvertBase64ImagesTask                          # dry run: count and report
vendor/bin/sake tasks:ConvertBase64ImagesTask --details                # dry run, per record
vendor/bin/sake tasks:ConvertBase64ImagesTask --apply                  # convert + OPTIMIZE TABLE
vendor/bin/sake tasks:ConvertBase64ImagesTask --apply --no-optimize    # without OPTIMIZE TABLE
```

The old form `vendor/bin/sake dev/tasks/ConvertBase64ImagesTask --apply` keeps working.

Run it from the command line. It also runs in the browser, as `/dev/tasks/ConvertBase64ImagesTask?apply=1` with
`&details=1` and `&optimize=0`, but a large site will run into the time limit of the web server there.

### Example output

A dry run:

```
MODE: DRY RUN - nothing is changed, run with "apply" to convert (see the task description)
SiteTree.Content: 3 record(s) with data:image/ ...
SiteTree_Live.Content: 3 record(s) with data:image/ ...
SiteTree_Versions.Content: 589 record(s) with data:image/ ...
--- Per table ---
SiteTree.Content: 3 records with data:image/, 3 to update, 4 images, 310 KB base64
SiteTree_Live.Content: 3 records with data:image/, 3 to update, 4 images, 310 KB base64
SiteTree_Versions.Content: 589 records with data:image/, 588 to update, 590 images, 45 MB base64, 1 skipped
--- Total ---
598 images to convert (45.6 MB base64 removed from the HTML)
--- Skipped (not converted by this module, left as they are) ---
1 x Unsupported data URI (data:image/svg+xml;base64)
--- Table sizes ---
SiteTree_Versions: 52.3 MB
```

With `--apply` the end of the report becomes:

```
--- Total ---
598 images converted (45.6 MB base64 removed from the HTML)
4 new files in Files (Uploads/pasted-images), an existing file was reused 594 times
--- File usage ---
"Used on" in Files updated for 3 record(s)
--- OPTIMIZE TABLE ---
SiteTree_Versions ...
SiteTree_Versions optimized
--- Table sizes ---
SiteTree_Versions: 52.3 MB -> 6.9 MB
```

### What the report tells you

- **Skipped** are data URIs this module does not convert, see [Limitations](#limitations). They stay in the
  content, so those records are reported again on the next run. That is expected.
- **Errors** are images that should have been converted but could not be, with the table, row ID and field. For
  the base table and `_Live`, open the record in the CMS and replace the image. Rows in `_Versions` are history
  and can't be edited; the task keeps reporting them, and keeps exiting with a non-zero code.
- **Changed during the run** means someone saved that record while its images were being converted. Their save is
  kept. Run the task again.

To find affected content yourself:

```sql
SELECT ID, Title, LENGTH(Content) FROM SiteTree_Live WHERE Content LIKE '%data:image/%';
-- likewise for SiteTree and SiteTree_Versions, and other tables with HTML fields
```

After a run this returns the records with skipped data URIs or errors, and nothing else.

## Limitations

- **Only png, jpg, gif and webp** in the `src` of an `<img>`. Svg, avif and bmp, and data URIs in `srcset`,
  `<source>` or a CSS `background`, are left as they are. The task reports them as skipped.
- **Files are published immediately,** also when the page they are on is still a draft or is not public. The
  filename contains part of the hash, so the URL is hard to guess, but it is public.
- **The task changes more than the content.** It sets `LastEdited` on the changed draft and live records, so
  partial caches refresh. A cache keyed on a parent record, such as the page of an elemental block, or a static
  cache, is not refreshed: flush those yourself.
- **`OPTIMIZE TABLE` rebuilds the table.** On a large `_Versions` table that takes time and needs free disk space
  of about the size of the table. Use `--no-optimize` to do it at a quieter moment.
- **MySQL and MariaDB only** for `OPTIMIZE TABLE` and the table sizes. On other databases the conversion itself
  works, and those two steps are skipped. Sizes are only shown for tables of 1 MB and up.
- **The dry run is read-only for your content,** but it does run `ANALYZE TABLE` to get current table sizes.
- **Fluent:** localised fields are converted on write, and the task covers the `_Localised` tables. The "Used on"
  update of the task only looks at the default locale.

## Running the tests

The tests are not included in the Packagist download. They need a Silverstripe project with PHPUnit 11 and a
database user that may create temporary databases. Install the module from source, then run:

```sh
composer require --prefer-source hamaka/silverstripe-pasted-base64-images
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit --bootstrap vendor/silverstripe/framework/tests/bootstrap.php vendor/hamaka/silverstripe-pasted-base64-images/tests
```

## Contributing

Report bugs and ideas in the [issues](https://github.com/hamaka/silverstripe-pasted-base64-images/issues). Pull requests
are welcome. Code that is the same for Silverstripe 5 and 6 goes to branch `1` and is merged up to `2`; only the
task wrapper and the dependencies differ per branch.

## License

BSD-3-Clause, see [LICENSE.md](LICENSE.md).

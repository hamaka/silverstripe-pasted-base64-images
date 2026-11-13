# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

`hamaka/silverstripe-pasted-base64-images`: a Silverstripe vendor module (no project, no `app/`, no build step). It converts images
pasted or dropped into TinyMCE (`paste_data_images`, stored as `<img src="data:image/...;base64,...">`) into an `Image` in
Files plus an `[image]` shortcode. It does this on write, and with a task for existing content including `_Live` and
`_Versions`. See README.md for the behaviour and the configuration.

## Branches and versions

| Branch | Version | Silverstripe | Differs in |
|---|---|---|---|
| `1` | 1.x (`dev-1` → `1.x-dev`) | 5.3+ | `ConvertBase64ImagesTask` uses `run($request)` and `DB::alteration_message`; params `apply=1`, `verbose=1`, `optimize=0`. PHPUnit 9.6: `@dataProvider` annotation. |
| `2` | 2.x (`dev-2` → `2.x-dev`) | 6 | `ConvertBase64ImagesTask` uses `execute(InputInterface, PolyOutput): int` and `getOptions()`: `--apply`, `--details`, `--[no-]optimize`, exit code 1 on errors. PHPUnit 11: `#[DataProvider]` attribute. |

Branch `2` is branch `1` plus SS6-specific commits. **Shared code** goes on `1` first and is cherry-picked to `2`. That
covers `Base64ImageConverter`, `Base64ImageExtension`, `Base64ImageTaskRunner`, `_config` and most tests. Per branch,
only `src/Task/ConvertBase64ImagesTask.php`, `composer.json` (constraints and branch alias), the README requirements and
commands, the CHANGELOG and the test's data provider syntax differ.

Tags: `1.0.0` on `1`, `2.0.0` on `2`. Bump the CHANGELOG with every release, and in both branches when the change is shared.

## Architecture

- `src/Base64ImageConverter.php`: all the logic.
  - `convertibleFields($class)` decides which fields are converted: HTML fields whose DB field object has
    `getProcessShortcodes()` true (so `HTMLText`, not `HTMLFragment` or `HTMLVarchar`), minus `exclude_classes` and
    `exclude_fields`. The extension and the task both use it.
  - It finds `<img>` tags with a quote-aware, possessive, anchored regex that is tried at each `<img`. Not one
    pattern over the whole value: thousands of tags that never close made that take minutes. It stops after
    `MAX_UNCLOSED_TAGS`.
  - It validates the image: strict `base64_decode`, then `getimagesizefromstring`, then the limits (`max_bytes`,
    `max_pixels`, `max_images`), then a walk through the PNG chunks or JPEG segments up to the end marker, so
    truncated data is caught and data after the image is allowed.
  - It scales an image above `max_dimension` down with the project's `Image_Backend` (via temp files, `loadFrom`
    / `resizeRatio` / `writeTo`), not with GD itself. Intervention 3 (SS6) auto-orients on read; on Intervention 2
    (SS5) it calls the magic `orientate()`. GIFs are not scaled. A failure saves the original and logs a warning.
  - It dedupes on `FileHash` (sha1) of the saved (scaled) file within the configured folder only. The in-memory
    cache is keyed on the hash of the pasted data, so the same image is not scaled again.
  - It publishes only when `setPublish(true)` (the default). The extension and the task turn it off for the draft
    and history of a versioned class with stages: `FileLinkTracking` has `owns: [FileTracking]`, so publishing the
    owner publishes the file.
  - After creating a file it calls `AssetAdmin::generateThumbnails()` (when asset-admin is installed): the file
    list in the CMS only shows thumbnails that already exist, and those are normally made on upload.
    `generateMissingThumbnails()` does the same for files in the folder that don't have them; the task calls it.
  - It builds the shortcode, encoding `"`, `<` and `>` in attribute values.
  - On any error it leaves the original tag in place and logs a warning to `Psr\Log\LoggerInterface.Base64Images`,
    with `context` and `snippet` in the log context. The Injector falls back to the default `LoggerInterface` when a
    project does not define that service. The module sends no mail itself: a project that wants mail defines the
    service with a mail handler (see README).
  - Data URIs it does not convert are counted as `skipped`, with a reason (`getSkipped()`); they are not errors.
- `src/Extension/Base64ImageExtension.php`: `onBeforeWrite` on every `DataObject` (registered as `base64Images` in
  `_config/config.yml`, so projects can null it). It extends `SilverStripe\Core\Extension`, not `DataExtension`, which
  works in 5.3+ and in 6. It publishes at once only for owners without stages or a write to the live stage. It
  checks `write_permission` (not on the command line) and calls
  `syncLinkTracking()` again after a conversion, because `FileLinkTracking` may have run before it.
- `src/Task/Base64ImageTaskRunner.php`: the task's work, version independent, with output through a callback
  `fn(string $message, string $type)`. It uses direct `SQLUpdate`s, so no new versions are created and nothing ends up
  "Modified". The update has the old value in its `WHERE`, so a save during the run is not overwritten. It covers
  the Fluent `_Localised` tables, filters rows of excluded subclasses on `ClassName`, and calls `syncLinkTracking()`
  on the changed draft records afterwards. It runs `ANALYZE` and `OPTIMIZE TABLE` on MySQL only.
- `src/Task/ConvertBase64ImagesTask.php`: a thin, version-specific wrapper around the runner.

## Conventions

- PHP: PSR-12, 4 spaces, English comments, plain camelCase variables (no Hungarian notation, unlike Hamaka projects).
- No `php` constraint in `composer.json`: the consuming project decides that.
- Verify Silverstripe API claims against the real vendor code of the target major, not against changelogs.

## Tests

The pure parsing logic (tag matching, limits, the PNG and JPEG walks) can be checked without a database by
subclassing the converter, overriding `setting()` and running it in dry-run mode.

`tests/` contains SapphireTests that use `TestAssetStore`, so no real files are written. They need a Silverstripe
project with PHPUnit and a database user that may create temporary databases:

```sh
SS_PHPUNIT_FLUSH=1 vendor/bin/phpunit --bootstrap vendor/silverstripe/framework/tests/bootstrap.php vendor/hamaka/silverstripe-pasted-base64-images/tests
```

Install the module in a test project via a path repository:

```sh
composer config repositories.b64 path ../_mod_silverstripe-base64-images
composer require "hamaka/silverstripe-pasted-base64-images:1.x-dev@dev"   # or 2.x-dev on SS6
```

Revert that afterwards. See CLAUDE.local.md for the local test projects, if present.

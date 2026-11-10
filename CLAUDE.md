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
  - It dedupes on `FileHash` (sha1) within the configured folder only, with an in-memory cache per instance.
  - After creating a file it calls `AssetAdmin::generateThumbnails()` (when asset-admin is installed): the file
    list in the CMS only shows thumbnails that already exist, and those are normally made on upload.
    `generateMissingThumbnails()` does the same for files in the folder that don't have them; the task calls it.
  - It builds the shortcode, encoding `"`, `<` and `>` in attribute values.
  - On any error it leaves the original tag in place and logs it. `convert()` mails the errors of one call in one
    mail. Mail goes through `Email` with `->text()`: `setBody()` + `sendPlain()` would strip the `<img>` snippets.
  - Data URIs it does not convert are counted as `skipped`, with a reason (`getSkipped()`); they are not errors.
  - Throttling uses `Psr\SimpleCache\CacheInterface.Base64Images` with one fixed key, so varying the content does
    not get around it.
- `src/Extension/Base64ImageExtension.php`: `onBeforeWrite` on every `DataObject` (registered as `base64Images` in
  `_config/config.yml`, so projects can null it). It extends `SilverStripe\Core\Extension`, not `DataExtension`, which
  works in 5.3+ and in 6. It checks `write_permission` (not on the command line), mails once per write, and calls
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
subclassing the converter, overriding `setting()` and running it in dry-run mode with `setNotify(false)`.

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

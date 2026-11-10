# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

`hamaka/silverstripe-base64-images`: a Silverstripe vendor module (no project, no `app/`, no build step). It converts images
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
  - It finds `<img>` tags with possessive regexes, so no backtracking happens on MB-sized base64.
  - It validates the image: strict `base64_decode`, then `getimagesizefromstring`, then a check that the file has a
    valid end, so truncated data is caught.
  - It dedupes on `FileHash` (sha1), with an in-memory cache per instance.
  - It builds the shortcode.
  - On any error it leaves the original tag in place, logs it and (throttled) mails it. Mail goes through `Email` with
    `->text()`: `setBody()` + `sendPlain()` would strip the `<img>` snippets.
  - Throttling uses `Psr\SimpleCache\CacheInterface.Base64Images`, keyed on the error and the data, not the record,
    because a new record has ID 0 on its first write.
- `src/Extension/Base64ImageExtension.php`: `onBeforeWrite` on every `DataObject` (registered as `base64Images` in
  `_config/config.yml`, so projects can null it). It extends `SilverStripe\Core\Extension`, not `DataExtension`, which
  works in 5.3+ and in 6. Its `filterHtmlFields()` is shared with the task.
- `src/Task/Base64ImageTaskRunner.php`: the task's work, version independent, with output through a callback
  `fn(string $message, string $type)`. It uses direct `SQLUpdate`s, so no new versions are created and nothing ends up
  "Modified". It runs `ANALYZE` and `OPTIMIZE TABLE` on MySQL only.
- `src/Task/ConvertBase64ImagesTask.php`: a thin, version-specific wrapper around the runner.

## Conventions

- PHP: PSR-12, 4 spaces, English comments, plain camelCase variables (no Hungarian notation, unlike Hamaka projects).
- No `php` constraint in `composer.json`: the consuming project decides that.
- Verify Silverstripe API claims against the real vendor code of the target major, not against changelogs.

## Tests

`tests/` contains SapphireTests that use `TestAssetStore`, so no real files are written. They need a Silverstripe
project with PHPUnit and a database user that may create temporary databases:

```sh
vendor/bin/phpunit --bootstrap vendor/silverstripe/framework/tests/bootstrap.php vendor/hamaka/silverstripe-base64-images/tests
```

Install the module in a test project via a path repository:

```sh
composer config repositories.b64 path ../_mod_silverstripe-base64-images
composer require "hamaka/silverstripe-base64-images:1.x-dev@dev"   # or 2.x-dev on SS6
```

Revert that afterwards. See CLAUDE.local.md for the local test projects, if present.

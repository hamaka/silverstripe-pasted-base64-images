<?php

namespace Hamaka\Base64Images\Task;

use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

/**
 * One-off cleanup: converts base64 images (<img src="data:image/...;base64,...">) in existing HTML fields into an
 * Image in Files and an [image] shortcode. New writes are already handled by Base64ImageExtension.
 *
 * - Every HTML field that processes shortcodes, of every DataObject class, in the base table, _Live and _Versions
 *   (and the Fluent _Localised tables).
 * - Direct SQL updates (SQLUpdate): no new versions are created and pages don't show up as "Modified" in the CMS.
 * - Thanks to the FileHash deduplication all versions point to the same file: the version history stays intact,
 *   without the blobs.
 * - Runs OPTIMIZE TABLE on the changed tables afterwards; InnoDB doesn't release the freed space otherwise.
 * - On an error the original tag is left untouched; one summary is mailed at the end.
 *
 * Make a database backup first. Dry run unless apply=1:
 *   vendor/bin/sake dev/tasks/ConvertBase64ImagesTask                       (dry run: count and report)
 *   vendor/bin/sake dev/tasks/ConvertBase64ImagesTask verbose=1             (dry run, per record)
 *   vendor/bin/sake dev/tasks/ConvertBase64ImagesTask apply=1               (convert + OPTIMIZE TABLE)
 *   vendor/bin/sake dev/tasks/ConvertBase64ImagesTask apply=1 optimize=0    (without OPTIMIZE TABLE)
 */
class ConvertBase64ImagesTask extends BuildTask
{
    private static string $segment = 'ConvertBase64ImagesTask';

    protected $title = 'Convert base64 images in HTML into files';

    protected $description = 'Converts <img src="data:image/..."> in all HTML fields (including _Live and _Versions) into an Image + [image] shortcode, then runs OPTIMIZE TABLE. Dry run unless apply=1. Make a database backup first.';

    public function run($request): void
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $apply = (bool) $request->getVar('apply');
        $verbose = (bool) $request->getVar('verbose');
        $optimize = $request->getVar('optimize') === null || (bool) $request->getVar('optimize');

        $runner = Base64ImageTaskRunner::create(
            fn(string $message, string $type = '') => DB::alteration_message($message, $type)
        );
        $runner->run($apply, $verbose, $optimize);
    }
}

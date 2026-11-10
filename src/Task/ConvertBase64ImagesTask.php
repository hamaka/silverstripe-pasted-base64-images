<?php

namespace Hamaka\Base64Images\Task;

use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

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
 * Make a database backup first. Dry run unless --apply:
 *   vendor/bin/sake tasks:ConvertBase64ImagesTask                          (dry run: count and report)
 *   vendor/bin/sake tasks:ConvertBase64ImagesTask --details                (dry run, per record)
 *   vendor/bin/sake tasks:ConvertBase64ImagesTask --apply                  (convert + OPTIMIZE TABLE)
 *   vendor/bin/sake tasks:ConvertBase64ImagesTask --apply --no-optimize    (without OPTIMIZE TABLE)
 * In the browser: /dev/tasks/ConvertBase64ImagesTask?apply=1
 */
class ConvertBase64ImagesTask extends BuildTask
{
    protected static string $commandName = 'ConvertBase64ImagesTask';

    protected string $title = 'Convert base64 images in HTML into files';

    protected static string $description = 'Converts <img src="data:image/..."> in all HTML fields (including _Live and _Versions) into an Image + [image] shortcode, then runs OPTIMIZE TABLE. Dry run unless --apply. Make a database backup first.';

    private const FORMATS = [
        'created' => 'info',
        'changed' => 'info',
        'notice'  => 'comment',
        'error'   => 'error',
    ];

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $runner = Base64ImageTaskRunner::create(function (string $message, string $type = '') use ($output) {
            $message = OutputFormatter::escape($message);
            $format = self::FORMATS[$type] ?? null;
            $output->writeln($format ? "<$format>$message</>" : $message);
        });

        $success = $runner->run(
            (bool) $input->getOption('apply'),
            (bool) $input->getOption('details'),
            (bool) $input->getOption('optimize')
        );

        // Images that couldn't be converted are left untouched, but a cron job or CI should still see it
        return $success ? Command::SUCCESS : Command::FAILURE;
    }

    public function getOptions(): array
    {
        return [
            new InputOption('apply', null, InputOption::VALUE_NONE, 'Actually convert; without it this is a dry run that only counts and reports'),
            // Not "verbose": -v/--verbose is a reserved Symfony console option
            new InputOption('details', null, InputOption::VALUE_NONE, 'Report every record'),
            new InputOption('optimize', null, InputOption::VALUE_NEGATABLE, 'Run OPTIMIZE TABLE on the changed tables <comment>[default: true]</comment>', true),
        ];
    }
}

<?php

namespace Hamaka\Base64Images\Task;

use Closure;
use Hamaka\Base64Images\Base64ImageConverter;
use Hamaka\Base64Images\Extension\Base64ImageExtension;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLUpdate;

/**
 * The work behind ConvertBase64ImagesTask, kept apart from the BuildTask class because the BuildTask API differs
 * between Silverstripe 5 and 6. Output goes through a callback: fn(string $message, string $type), where type is
 * '' (plain), 'created', 'changed', 'notice' or 'error', like DB::alteration_message().
 */
class Base64ImageTaskRunner
{
    use Injectable;

    protected Closure $output;

    public function __construct(callable $output)
    {
        $this->output = Closure::fromCallable($output);
    }

    /**
     * @return bool true when every image was converted (or there was nothing to convert), false on errors
     */
    public function run(bool $apply, bool $verbose = false, bool $optimize = true): bool
    {
        if ($apply) {
            $this->write('MODE: APPLY', 'changed');
        } else {
            $this->write('MODE: DRY RUN - nothing is changed, run with "apply" to convert (see the task description)', 'notice');
        }

        $converter = Base64ImageConverter::create()
            ->setDryRun(!$apply)
            ->setNotify(false); // one summary at the end

        $targets = $this->getTargets();
        $sizesBefore = $this->getTableSizes(array_unique(array_column($targets, 'table')));

        $touchedTables = [];
        $tableStats = [];

        foreach ($targets as $target) {
            ['table' => $table, 'field' => $field, 'baseTable' => $baseTable, 'isVersions' => $isVersions] = $target;

            $ids = DB::prepared_query(
                sprintf('SELECT "ID" FROM "%s" WHERE "%s" LIKE ? ORDER BY "ID"', $table, $field),
                ['%data:image/%']
            )->column();

            if (!$ids) {
                continue;
            }

            $rowsChanged = 0;
            $before = $converter->getStats();

            foreach ($ids as $id) {
                // One record at a time: a single version can be several MB
                $value = DB::prepared_query(
                    sprintf('SELECT "%s" FROM "%s" WHERE "ID" = ?', $field, $table),
                    [$id]
                )->value();

                $convertedBefore = $converter->getStats()['converted'];
                $newValue = $converter->convert($value, sprintf('%s #%d %s', $table, $id, $field));
                $converted = $converter->getStats()['converted'] - $convertedBefore;

                if ($verbose) {
                    $this->write(
                        sprintf('%s.%s #%d: %d image(s), %s', $table, $field, $id, $converted, $this->formatBytes(strlen((string) $value))),
                        $converted ? 'changed' : 'notice'
                    );
                }

                if (!$apply || $newValue === $value) {
                    if ($converted) {
                        $rowsChanged++;
                    }
                    continue;
                }

                SQLUpdate::create(sprintf('"%s"', $table), [sprintf('"%s"', $field) => $newValue], ['"ID"' => $id])->execute();
                $rowsChanged++;

                // Bump LastEdited so partial caches refresh; not in _Versions (that's history)
                if (!$isVersions) {
                    SQLUpdate::create(sprintf('"%s"', $baseTable), ['"LastEdited"' => DBDatetime::now()->Rfc2822()], ['"ID"' => $id])->execute();
                }
                $touchedTables[$table] = true;

                unset($value, $newValue);
            }

            $after = $converter->getStats();
            $tableStats[] = [
                'name'      => $table . '.' . $field,
                'rows'      => count($ids),
                'changed'   => $rowsChanged,
                'converted' => $after['converted'] - $before['converted'],
                'failed'    => $after['failed'] - $before['failed'],
                'bytes'     => $after['bytes'] - $before['bytes'],
            ];
        }

        $this->report($tableStats, $converter, $apply);

        $errors = $converter->getErrors();
        if ($errors) {
            $this->write('--- Errors (tag left untouched) ---', 'error');
            foreach ($errors as $error) {
                $this->write($error['context'] . ': ' . $error['message'], 'error');
            }
            if ($apply) {
                Base64ImageConverter::notify($errors, 'Sent by ConvertBase64ImagesTask.');
            }
        }

        if (!$apply) {
            $this->reportSizes($sizesBefore, null);

            return !$errors;
        }

        if ($optimize && $touchedTables && $this->isMySQL()) {
            $this->write('--- OPTIMIZE TABLE ---');
            foreach (array_keys($touchedTables) as $table) {
                DB::query(sprintf('OPTIMIZE TABLE "%s"', $table));
                $this->write($table . ' optimized', 'changed');
            }
        }

        $this->reportSizes($sizesBefore, $this->getTableSizes(array_keys($sizesBefore)));

        return !$errors;
    }

    /**
     * Every (table, HTML field) combination: per class its own table, plus _Live and _Versions when they exist.
     * Order: draft, then live, then versions, so a file is preferably created from the current content and reused by
     * live and the versions.
     */
    public function getTargets(): array
    {
        $schema = DataObject::getSchema();
        $bySuffix = ['' => [], '_Live' => [], '_Versions' => []];

        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            if (!$schema->classHasTable($class)) {
                continue;
            }

            // false: only the fields in this class's own table, not its parents'
            $fields = Base64ImageExtension::filterHtmlFields($schema->databaseFields($class, false));
            if (!$fields) {
                continue;
            }

            $table = $schema->tableName($class);
            $baseTable = $schema->baseDataTable($class);

            foreach (array_keys($bySuffix) as $suffix) {
                if (!DB::get_schema()->hasTable($table . $suffix)) {
                    continue;
                }
                foreach ($fields as $field) {
                    $bySuffix[$suffix][] = [
                        'table'      => $table . $suffix,
                        'field'      => $field,
                        'baseTable'  => $baseTable . ($suffix === '_Versions' ? '' : $suffix),
                        'isVersions' => $suffix === '_Versions',
                    ];
                }
            }
        }

        return array_merge(...array_values($bySuffix));
    }

    protected function report(array $tableStats, Base64ImageConverter $converter, bool $apply): void
    {
        $this->write('--- Per table ---');

        if (!$tableStats) {
            $this->write('No base64 images found.', 'created');
        }

        foreach ($tableStats as $stat) {
            $this->write(
                sprintf(
                    '%s: %d records with data:image/, %d %s, %d images, %s base64%s',
                    $stat['name'],
                    $stat['rows'],
                    $stat['changed'],
                    $apply ? 'updated' : 'to update',
                    $stat['converted'],
                    $this->formatBytes($stat['bytes']),
                    $stat['failed'] ? ', ' . $stat['failed'] . ' error(s)' : ''
                ),
                $stat['failed'] ? 'error' : 'changed'
            );
        }

        $stats = $converter->getStats();
        $this->write('--- Total ---');
        $this->write(
            sprintf('%d images %s (%s base64 removed from the HTML)', $stats['converted'], $apply ? 'converted' : 'to convert', $this->formatBytes($stats['bytes'])),
            'created'
        );
        if ($apply) {
            $this->write(sprintf('%d new files in Files, an existing file was reused %d times', $stats['created'], $stats['reused']), 'created');
        }
        if ($stats['failed']) {
            $this->write($stats['failed'] . ' image(s) not converted, see below', 'error');
        }
    }

    /**
     * @return array [table => bytes (data + index)]
     */
    protected function getTableSizes(array $tables): array
    {
        if (!$tables || !$this->isMySQL()) {
            return [];
        }

        // information_schema is cached; ANALYZE refreshes the numbers
        foreach ($tables as $table) {
            DB::query(sprintf('ANALYZE TABLE "%s"', $table));
        }

        $placeholders = implode(',', array_fill(0, count($tables), '?'));

        return DB::prepared_query(
            sprintf(
                'SELECT "TABLE_NAME", "DATA_LENGTH" + "INDEX_LENGTH" FROM "information_schema"."TABLES" WHERE "TABLE_SCHEMA" = DATABASE() AND "TABLE_NAME" IN (%s)',
                $placeholders
            ),
            array_values($tables)
        )->map();
    }

    protected function reportSizes(array $before, ?array $after): void
    {
        // Only tables worth mentioning
        $before = array_filter($before, fn($bytes) => $bytes >= 1048576);
        if (!$before) {
            return;
        }
        arsort($before);

        $this->write('--- Table sizes ---');
        foreach ($before as $table => $bytes) {
            if ($after === null) {
                $this->write(sprintf('%s: %s', $table, $this->formatBytes((int) $bytes)));
            } else {
                $this->write(
                    sprintf('%s: %s -> %s', $table, $this->formatBytes((int) $bytes), $this->formatBytes((int) ($after[$table] ?? 0))),
                    'changed'
                );
            }
        }
    }

    protected function isMySQL(): bool
    {
        return stripos(get_class(DB::get_conn()), 'mysql') !== false;
    }

    protected function formatBytes(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : round($bytes / 1024) . ' KB';
    }

    protected function write(string $message, string $type = ''): void
    {
        ($this->output)($message, $type);
    }
}

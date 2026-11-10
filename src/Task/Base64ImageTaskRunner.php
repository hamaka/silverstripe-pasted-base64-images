<?php

namespace Hamaka\Base64Images\Task;

use Closure;
use Hamaka\Base64Images\Base64ImageConverter;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLUpdate;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * The work behind ConvertBase64ImagesTask, kept apart from the BuildTask class because the BuildTask API differs
 * between Silverstripe 5 and 6. Output goes through a callback: fn(string $message, string $type), where type is
 * '' (plain), 'created', 'changed', 'notice' or 'error', like DB::alteration_message().
 */
class Base64ImageTaskRunner
{
    use Injectable;

    /**
     * Table suffixes that are converted, in this order: draft, then live, then versions, so a file is preferably
     * created from the current content and reused by live and the versions. The _Localised tables are those of
     * tractorcow/silverstripe-fluent.
     *
     * Per suffix: the stage suffix of the base table, the column that holds the record ID, and whether a row is
     * identified by RecordID + Version.
     */
    protected const SUFFIXES = [
        ''                    => ['stage' => '', 'recordColumn' => 'ID', 'versions' => false],
        '_Localised'          => ['stage' => '', 'recordColumn' => 'RecordID', 'versions' => false],
        '_Live'               => ['stage' => '_Live', 'recordColumn' => 'ID', 'versions' => false],
        '_Localised_Live'     => ['stage' => '_Live', 'recordColumn' => 'RecordID', 'versions' => false],
        '_Versions'           => ['stage' => '_Versions', 'recordColumn' => 'RecordID', 'versions' => true],
        '_Localised_Versions' => ['stage' => '_Versions', 'recordColumn' => 'RecordID', 'versions' => true],
    ];

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
        $draftRecords = []; // [class => [ID => true]], to update the file usage of afterwards

        foreach ($targets as $target) {
            ['table' => $table, 'field' => $field] = $target;

            [$filter, $filterParams] = $this->excludedClassesFilter($target);
            $ids = DB::prepared_query(
                sprintf('SELECT "ID" FROM "%s" WHERE "%s" LIKE ?%s ORDER BY "ID"', $table, $field, $filter),
                array_merge(['%data:image/%'], $filterParams)
            )->column();

            if (!$ids) {
                continue;
            }

            // Before the work, so a large table doesn't look like a hang
            $this->write(sprintf('%s.%s: %d record(s) with data:image/ ...', $table, $field, count($ids)));

            $rowsChanged = 0;
            $rowsConflicted = 0;
            $before = $converter->getStats();

            foreach ($ids as $id) {
                // One record at a time: a single version can be several MB
                $row = DB::prepared_query(
                    sprintf('SELECT "%s" AS "Value", "%s" AS "RecordID" FROM "%s" WHERE "ID" = ?', $field, $target['recordColumn'], $table),
                    [$id]
                )->record();
                if (!$row) {
                    continue; // deleted in the meantime
                }
                $value = $row['Value'];
                $recordID = (int) $row['RecordID'];

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

                // Only when the content is still what was read: an editor may have saved while the images of this
                // record were being converted, and that save must not be overwritten
                SQLUpdate::create(
                    sprintf('"%s"', $table),
                    [sprintf('"%s"', $field) => $newValue],
                    ['"ID"' => $id, sprintf('"%s"', $field) => $value]
                )->execute();

                if (DB::affected_rows() < 1) {
                    $rowsConflicted++;
                    $this->write(sprintf('%s.%s #%d was changed by someone else during the run, left as it is. Run the task again.', $table, $field, $id), 'notice');
                    continue;
                }

                $rowsChanged++;
                $touchedTables[$table] = true;

                // Bump LastEdited so partial caches refresh; not for _Versions (that's history)
                if ($target['touchTable']) {
                    SQLUpdate::create(
                        sprintf('"%s"', $target['touchTable']),
                        ['"LastEdited"' => DBDatetime::now()->Rfc2822()],
                        ['"ID"' => $recordID]
                    )->execute();
                }
                if ($target['isDraft']) {
                    $draftRecords[$target['class']][$recordID] = true;
                }
            }

            $after = $converter->getStats();
            $tableStats[] = [
                'name'       => $table . '.' . $field,
                'rows'       => count($ids),
                'changed'    => $rowsChanged,
                'conflicted' => $rowsConflicted,
                'converted'  => $after['converted'] - $before['converted'],
                'failed'     => $after['failed'] - $before['failed'],
                'skipped'    => $after['skipped'] - $before['skipped'],
                'bytes'      => $after['bytes'] - $before['bytes'],
            ];
        }

        $this->report($tableStats, $converter, $apply);
        $this->reportSkipped($converter->getSkipped(), $verbose);

        $errors = $converter->getErrors();
        if ($errors) {
            $this->write('--- Errors (tag left untouched) ---', 'error');
            foreach ($errors as $error) {
                $this->write($error['context'] . ': ' . $error['message'], 'error');
            }
            if ($apply) {
                // Not throttled: the operator asked for this run
                Base64ImageConverter::notify($errors, 'Sent by ConvertBase64ImagesTask.', false);
            }
        }

        if (!$apply) {
            $this->reportSizes($sizesBefore, null);

            return !$errors;
        }

        $this->syncLinkTracking($draftRecords);

        if ($optimize && $touchedTables && $this->isMySQL()) {
            $this->write('--- OPTIMIZE TABLE ---');
            foreach (array_keys($touchedTables) as $table) {
                $this->write($table . ' ...');
                DB::query(sprintf('OPTIMIZE TABLE "%s"', $table));
                $this->write($table . ' optimized', 'changed');
            }
        }

        $this->reportSizes($sizesBefore, $this->getTableSizes(array_keys($sizesBefore)));

        return !$errors;
    }

    /**
     * Every (table, field) combination to convert: per class the convertible fields in its own table, in the table
     * itself, _Live and _Versions, and the Fluent _Localised variants of those when they exist and hold the field.
     *
     * @return array[] each with:
     *   - table, field
     *   - class: the class the table belongs to
     *   - recordColumn: the column of the table that holds the record ID
     *   - classTable: the table with the ClassName of the row (the base table of the same stage)
     *   - versions: true when a row is identified by RecordID + Version
     *   - touchTable: the base table to bump LastEdited in, null for history
     *   - isDraft: true for the draft table itself
     *   - excludedClasses: subclasses whose rows must be left alone (exclude_classes, exclude_fields)
     */
    public function getTargets(): array
    {
        $schema = DataObject::getSchema();
        $dbSchema = DB::get_schema();
        $bySuffix = array_fill_keys(array_keys(self::SUFFIXES), []);

        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            if (!$schema->classHasTable($class)) {
                continue;
            }

            // true: only the fields in this class's own table, not its parents'. Excluded classes and fields are
            // already left out.
            $fields = Base64ImageConverter::convertibleFields($class, true);
            if (!$fields) {
                continue;
            }

            $table = $schema->tableName($class);
            $baseTable = $schema->baseDataTable($class);

            foreach (self::SUFFIXES as $suffix => $info) {
                if (!$dbSchema->hasTable($table . $suffix)) {
                    continue;
                }
                // A _Localised table only has the translated fields
                $columns = str_contains($suffix, '_Localised') ? DB::field_list($table . $suffix) : null;

                foreach ($fields as $field) {
                    if ($columns !== null && !isset($columns[$field])) {
                        continue;
                    }

                    $bySuffix[$suffix][] = [
                        'table'           => $table . $suffix,
                        'field'           => $field,
                        'class'           => $class,
                        'recordColumn'    => $info['recordColumn'],
                        'classTable'      => $baseTable . $info['stage'],
                        'versions'        => $info['versions'],
                        'touchTable'      => $info['versions'] ? null : $baseTable . $info['stage'],
                        'isDraft'         => $suffix === '',
                        'excludedClasses' => $this->excludedSubclasses($class, $field),
                    ];
                }
            }
        }

        return array_merge(...array_values($bySuffix));
    }

    /**
     * Subclasses of a class for which a field is excluded. Their rows live in the same table as the rows of the
     * class itself, so they have to be filtered on ClassName.
     *
     * @return string[]
     */
    protected function excludedSubclasses(string $class, string $field): array
    {
        return array_values(array_filter(
            ClassInfo::subclassesFor($class, false),
            fn(string $subclass) => Base64ImageConverter::isExcluded($subclass, $field)
        ));
    }

    /**
     * SQL (starting with " AND") and its parameters that leave out the rows of excluded subclasses
     *
     * @return array [string $sql, array $parameters]
     */
    protected function excludedClassesFilter(array $target): array
    {
        $classes = $target['excludedClasses'];
        if (!$classes) {
            return ['', []];
        }

        $placeholders = implode(',', array_fill(0, count($classes), '?'));
        $table = $target['table'];
        $classTable = $target['classTable'];

        if ($table === $classTable) {
            return [sprintf(' AND "ClassName" NOT IN (%s)', $placeholders), $classes];
        }

        // The ClassName is in the base table of the same stage
        $join = sprintf(
            '"%s"."%s" = "%s"."%s"',
            $classTable,
            $target['versions'] ? 'RecordID' : 'ID',
            $table,
            $target['recordColumn']
        );
        if ($target['versions']) {
            $join .= sprintf(' AND "%s"."Version" = "%s"."Version"', $classTable, $table);
        }

        return [
            sprintf(' AND NOT EXISTS (SELECT 1 FROM "%s" WHERE %s AND "%s"."ClassName" IN (%s))', $classTable, $join, $classTable, $placeholders),
            $classes,
        ];
    }

    /**
     * The task writes with direct SQL, so FileLinkTracking has not seen the new shortcodes: without this the files
     * show up as unused in Files ("Used on") until each record is saved again. Only the relation is updated, the
     * records themselves are not written.
     *
     * @param array $records [class => [ID => true]] of draft records
     */
    protected function syncLinkTracking(array $records): void
    {
        if (!$records) {
            return;
        }

        $synced = 0;
        $failed = 0;

        Versioned::withVersionedMode(function () use ($records, &$synced, &$failed) {
            Versioned::set_stage(Versioned::DRAFT);

            foreach ($records as $class => $ids) {
                foreach (array_keys($ids) as $id) {
                    try {
                        $record = DataObject::get($class)->byID($id);
                        if ($record && $record->hasMethod('syncLinkTracking')) {
                            $record->syncLinkTracking();
                            $synced++;
                        }
                    } catch (Throwable $e) {
                        $failed++;
                    }
                }
            }
        });

        $this->write('--- File usage ---');
        $this->write(sprintf('"Used on" in Files updated for %d record(s)', $synced), 'changed');
        if ($failed) {
            $this->write(sprintf('Not updated for %d record(s); saving such a record in the CMS updates it', $failed), 'notice');
        }
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
                    '%s: %d records with data:image/, %d %s, %d images, %s base64%s%s%s',
                    $stat['name'],
                    $stat['rows'],
                    $stat['changed'],
                    $apply ? 'updated' : 'to update',
                    $stat['converted'],
                    $this->formatBytes($stat['bytes']),
                    $stat['skipped'] ? ', ' . $stat['skipped'] . ' skipped' : '',
                    $stat['conflicted'] ? ', ' . $stat['conflicted'] . ' changed during the run' : '',
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
            $this->write(
                sprintf(
                    '%d new files in Files (%s), an existing file was reused %d times',
                    $stats['created'],
                    Base64ImageConverter::config()->get('folder'),
                    $stats['reused']
                ),
                'created'
            );
        }
        if ($stats['failed']) {
            $this->write($stats['failed'] . ' image(s) not converted, see below', 'error');
        }
    }

    /**
     * Data URIs the converter does not handle. They keep matching "data:image/", so without this the operator
     * can't tell why a record is still reported after a run.
     */
    protected function reportSkipped(array $skipped, bool $verbose): void
    {
        if (!$skipped) {
            return;
        }

        $this->write('--- Skipped (not converted by this module, left as they are) ---', 'notice');

        $byReason = [];
        foreach ($skipped as $item) {
            $byReason[$item['reason']] = ($byReason[$item['reason']] ?? 0) + $item['count'];
        }
        foreach ($byReason as $reason => $count) {
            $this->write(sprintf('%d x %s', $count, $reason), 'notice');
        }

        if ($verbose) {
            foreach ($skipped as $item) {
                $this->write($item['context'] . ': ' . $item['reason'], 'notice');
            }
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

<?php

namespace Hamaka\Base64Images\Tests;

use Hamaka\Base64Images\Base64ImageConverter;
use Hamaka\Base64Images\Task\Base64ImageTaskRunner;
use Hamaka\Base64Images\Tests\Base64ImageTaskRunnerTest\VersionedObject;
use Hamaka\Base64Images\Tests\Base64ImageTaskRunnerTest\VersionedSubObject;
use Hamaka\Base64Images\Tests\Base64ImageTaskRunnerTest\VersionedSubSubObject;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

class Base64ImageTaskRunnerTest extends SapphireTest
{
    protected $usesDatabase = true;

    /** The task runs ANALYZE TABLE, which commits a transaction on MySQL: rows would leak into the next test */
    protected $usesTransactions = false;

    protected static $extra_dataobjects = [
        VersionedObject::class,
        VersionedSubObject::class,
        VersionedSubSubObject::class,
    ];

    protected array $output = [];

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('Base64ImageTaskRunnerTest');
        Base64ImageConverter::config()->set('notify_email', false);
        $this->output = [];
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    protected function imgTag(): string
    {
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($image);

        return '<img src="data:image/png;base64,' . base64_encode(ob_get_clean()) . '">';
    }

    /**
     * Writes and publishes a record, then puts the HTML into every table of the field with SQL: a normal write
     * would already convert it.
     */
    protected function createRecord(string $class, string $field, string $html): DataObject
    {
        $record = $class::create(['Title' => 'x']);
        $record->write();
        $record->publishSingle();

        $table = DataObject::getSchema()->tableForField($class, $field);
        DB::prepared_query(sprintf('UPDATE "%s" SET "%s" = ? WHERE "ID" = ?', $table, $field), [$html, $record->ID]);
        DB::prepared_query(sprintf('UPDATE "%s_Live" SET "%s" = ? WHERE "ID" = ?', $table, $field), [$html, $record->ID]);
        DB::prepared_query(sprintf('UPDATE "%s_Versions" SET "%s" = ? WHERE "RecordID" = ?', $table, $field), [$html, $record->ID]);

        return $record;
    }

    /**
     * @return string[] the value of the field in the draft table, the live table and every version
     */
    protected function values(DataObject $record, string $field): array
    {
        $table = DataObject::getSchema()->tableForField(get_class($record), $field);

        return array_merge(
            DB::prepared_query(sprintf('SELECT "%s" FROM "%s" WHERE "ID" = ?', $field, $table), [$record->ID])->column(),
            DB::prepared_query(sprintf('SELECT "%s" FROM "%s_Live" WHERE "ID" = ?', $field, $table), [$record->ID])->column(),
            DB::prepared_query(sprintf('SELECT "%s" FROM "%s_Versions" WHERE "RecordID" = ?', $field, $table), [$record->ID])->column()
        );
    }

    protected function runTask(bool $apply, bool $verbose = false): bool
    {
        $runner = Base64ImageTaskRunner::create(function (string $message, string $type = '') {
            $this->output[] = $message;
        });

        return $runner->run($apply, $verbose, false);
    }

    protected function assertAllConverted(array $values): void
    {
        $this->assertNotEmpty($values);
        foreach ($values as $value) {
            $this->assertStringStartsWith('<p>[image ', $value);
            $this->assertStringNotContainsString('data:image/', $value);
        }
    }

    protected function assertNoneConverted(array $values): void
    {
        $this->assertNotEmpty($values);
        foreach ($values as $value) {
            $this->assertStringContainsString('data:image/', $value);
        }
    }

    public function testDryRunChangesNothing(): void
    {
        $record = $this->createRecord(VersionedObject::class, 'Content', '<p>' . $this->imgTag() . '</p>');
        $rows = count($this->values($record, 'Content'));

        $this->assertTrue($this->runTask(false));

        $this->assertNoneConverted($this->values($record, 'Content'));
        $this->assertSame(0, Image::get()->count());
        $this->assertNotEmpty(preg_grep('/^' . $rows . ' images to convert /', $this->output));
    }

    public function testApplyConvertsDraftLiveAndVersionsWithoutNewVersions(): void
    {
        $record = $this->createRecord(VersionedObject::class, 'Content', '<p>' . $this->imgTag() . '</p>');
        $rows = count($this->values($record, 'Content'));

        $this->assertTrue($this->runTask(true), implode("
", $this->output));

        $values = $this->values($record, 'Content');
        $this->assertAllConverted($values);
        $this->assertCount($rows, $values, 'No new versions');
        $this->assertSame(1, Image::get()->count(), 'One file for draft, live and the versions');

        // The record is not "Modified": draft and live still have the same version
        $record = VersionedObject::get()->byID($record->ID);
        $this->assertFalse($record->stagesDiffer());

        // "Used on" in Files
        $this->assertSame([Image::get()->first()->ID], $record->FileTracking()->column('ID'));

        // Nothing left to do
        $this->output = [];
        $this->assertTrue($this->runTask(true), implode("
", $this->output));
        $this->assertContains('No base64 images found.', $this->output);
    }

    public function testSkippedAndErrorsAreReported(): void
    {
        $svg = '<img src="data:image/svg+xml;base64,' . base64_encode('<svg/>') . '">';
        $broken = '<img src="data:image/png;base64,' . base64_encode('nope') . '">';
        $record = $this->createRecord(VersionedObject::class, 'Content', '<p>' . $this->imgTag() . $svg . $broken . '</p>');
        $rows = count($this->values($record, 'Content'));

        $this->assertFalse($this->runTask(true), 'Errors make the run fail');

        foreach ($this->values($record, 'Content') as $value) {
            $this->assertStringStartsWith('<p>[image ', $value);
            $this->assertStringContainsString($svg . $broken, $value);
        }
        $this->assertContains($rows . ' x Unsupported data URI (data:image/svg+xml;base64)', $this->output);
        $this->assertContains($rows . ' image(s) not converted, see below', $this->output);
    }

    public function testExcludedClassLeavesItsRowsInTheParentTableAlone(): void
    {
        Base64ImageConverter::config()->set('exclude_classes', [VersionedSubObject::class]);

        $html = '<p>' . $this->imgTag() . '</p>';
        $parent = $this->createRecord(VersionedObject::class, 'Content', $html);
        $sub = $this->createRecord(VersionedSubObject::class, 'Content', $html);
        $subExtra = $this->createRecord(VersionedSubObject::class, 'Extra', $html);

        $this->assertTrue($this->runTask(true), implode("
", $this->output));

        $this->assertAllConverted($this->values($parent, 'Content'));
        $this->assertNoneConverted($this->values($sub, 'Content'));
        $this->assertNoneConverted($this->values($subExtra, 'Extra'));
    }

    public function testExcludedFieldOfSubclassIsFilteredThroughTheBaseTable(): void
    {
        // Extra is in the table of VersionedSubObject, which has no ClassName
        Base64ImageConverter::config()->set('exclude_fields', [VersionedSubSubObject::class . '.Extra']);

        $html = '<p>' . $this->imgTag() . '</p>';
        $sub = $this->createRecord(VersionedSubObject::class, 'Extra', $html);
        $subSub = $this->createRecord(VersionedSubSubObject::class, 'Extra', $html);
        $subSubContent = $this->createRecord(VersionedSubSubObject::class, 'Content', $html);

        $this->assertTrue($this->runTask(true), implode("
", $this->output));

        $this->assertAllConverted($this->values($sub, 'Extra'));
        $this->assertNoneConverted($this->values($subSub, 'Extra'));
        $this->assertAllConverted($this->values($subSubContent, 'Content'));
    }
}

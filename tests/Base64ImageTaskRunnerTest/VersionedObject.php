<?php

namespace Hamaka\Base64Images\Tests\Base64ImageTaskRunnerTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

class VersionedObject extends DataObject implements TestOnly
{
    private static string $table_name = 'Base64ImageTaskRunnerTest_VersionedObject';

    private static array $db = [
        'Title'   => 'Varchar',
        'Content' => 'HTMLText',
    ];

    private static array $extensions = [
        Versioned::class,
    ];
}

<?php

namespace Hamaka\Base64Images\Tests\Base64ImageConverterTest;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

class TestObject extends DataObject implements TestOnly
{
    private static string $table_name = 'Base64ImageConverterTest_TestObject';

    private static array $db = [
        'Title'   => 'Varchar',
        'Content' => 'HTMLText',
    ];
}

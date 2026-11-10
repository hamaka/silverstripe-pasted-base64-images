<?php

namespace Hamaka\Base64Images\Tests\Base64ImageTaskRunnerTest;

/**
 * Has a table of its own, without a ClassName column
 */
class VersionedSubObject extends VersionedObject
{
    private static string $table_name = 'Base64ImageTaskRunnerTest_VersionedSubObject';

    private static array $db = [
        'Extra' => 'HTMLText',
    ];
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Set;
use PHPUnit\Framework\TestCase;

/**
 * The array $sort of AA_Set (include/searchlib.php) is a LIST of sort specs - sort[0][field]=a|d.
 * A bare field => order map is read as that list: its values are basic sort strings, so
 * ['ORG_NAME' => 'a'] sorts by a field named "a". MakeSQLOrderBy() drops the unknown field and
 * the query is not sorted at all - which is how Central listed its AAs unsorted
 * (AA_Actionapps::getArray()).
 */
final class SetSortFormatTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // the constants the legacy require chain of AA_Set needs
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    public function test_list_of_sort_specs_sorts_by_the_field(): void
    {
        $this->assertSame([['ORG_NAME' => 'a']], (new AA_Set(null, null, [['ORG_NAME' => 'a']]))->getSort());
        $this->assertSame([['ORG_NAME' => 'd']], (new AA_Set(null, null, [['ORG_NAME' => 'd']]))->getSort());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\TrimFieldValues;
use AA_Dbcondition;
use PHPUnit\Framework\TestCase;

/** The scan runs on MariaDB (REGEXP with Unicode classes) - checked against the live databases when
 *  written; here only the condition the item search builds of it. */
final class TrimFieldValuesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    /** the search unslashes the value once - the backslash of \p{M} must still reach MariaDB escaped */
    public function test_the_search_looks_for_whitespace_at_the_ends_and_for_combining_marks(): void
    {
        $sql = (new AA_Dbcondition('c0', 'text', 'REGEXP', TrimFieldValues::CANDIDATE, 'text', true))->getWhereExp();

        $this->assertStringEndsWith(" REGEXP '^[[:space:]]|[[:space:]]$|\\\\p{M}')", $sql);
    }
}

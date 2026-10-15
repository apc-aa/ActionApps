<?php

declare(strict_types=1);

namespace Tests\Unit\IO\DB;

use AA\IO\DB\DB_AA;
use PHPUnit\Framework\TestCase;

/** SET part of DB_AA::update(): the type is optional, as in makeWhere() */
final class DbAaMakeColsTest extends TestCase
{
    private static function cols(array $varlist): string
    {
        return (new \ReflectionMethod(DB_AA::class, 'makeCols'))->invoke(null, $varlist);
    }

    public function test_entry_without_type_is_an_escaped_string(): void
    {
        $this->assertSame(" name = 'O\\'Brien'", self::cols([['name', "O'Brien"]]));
    }

    public function test_typed_entries(): void
    {
        $this->assertSame(" a = 5 , b = 0x0123456789abcdef0123456789abcdef , c = 'x'", self::cols([['a', '5x', 'i'], ['b', '0123456789abcdef0123456789abcdef', 'l'], ['c', 'x', 's']]));
    }
}

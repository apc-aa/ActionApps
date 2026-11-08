<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\Langs;
use ErrorException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LangsTest extends TestCase
{
    /** @return array<string, array{string, string}> AA language name, standard code */
    public static function names(): array
    {
        return [
            'cz is cs'         => ['cz', 'cs'],
            'en stays'         => ['en', 'en'],
            'lang file prefix' => ['de-utf8', 'de'],
        ];
    }

    #[DataProvider('names')]
    public function test_getLangName2Code_givesTheCodeWithoutWarning(string $name, string $code): void
    {
        set_error_handler(static function (int $no, string $msg): never {
            throw new ErrorException($msg, 0, $no);
        });
        try {
            self::assertSame($code, Langs::getLangName2Code($name));
        } finally {
            restore_error_handler();
        }
    }
}

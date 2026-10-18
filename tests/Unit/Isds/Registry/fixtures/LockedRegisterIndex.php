<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry\Fixtures;

use AA\Isds\Registry\RegisterIndex;

/** RegisterIndex with its record kept here instead of in the database. Static, as the record is the
 *  install's - reset() before each test. */
final class LockedRegisterIndex extends RegisterIndex
{
    /** the 31 real boxes of the SdsList fixture */
    public const REGISTER = __DIR__ . '/seznam_ds_ovm-sample.xml';

    /** @var array<string, string> the install's RegisterRecord */
    public static array $record = [];

    /** thrown by writeRecord(), as by a database that refuses the write */
    public static ?\RuntimeException $writeFails = null;

    public static function reset(): void
    {
        self::$record     = [];
        self::$writeFails = null;
    }

    /** records $file as Sync::download() records the register it downloaded */
    public static function download(string $file): void
    {
        (new self($file))->downloaded((string) hash_file('sha256', $file), (int) filesize($file));
    }

    /** puts the fixture register at $file, downloaded - recorded - when $recorded */
    public static function genuine(string $file, bool $recorded = true): void
    {
        copy(self::REGISTER, $file);
        if ($recorded) {
            self::download($file);
        }
    }

    /** puts another register at $file: the fixture with another box of Brno, found by its IČO */
    public static function swap(string $file): void
    {
        file_put_contents($file, str_replace('<id>a7kbrrn</id>', '<id>abcdefg</id>', (string) file_get_contents(self::REGISTER)));
    }

    protected function readRecord(): array
    {
        return self::$record;
    }

    protected function writeRecord(array $values): void
    {
        if (self::$writeFails !== null) {
            throw self::$writeFails;
        }
        foreach ($values as $field => $value) {
            self::$record[$field] = (string) $value;
        }
    }
}

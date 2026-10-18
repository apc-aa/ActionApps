<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry\Fixtures;

use AA\Isds\Registry\RegisterIndex;

/** RegisterIndex with its record, log and lock kept here instead of in the database. Static, as the
 *  record is the install's - reset() before each test. */
final class LockedRegisterIndex extends RegisterIndex
{
    /** the 31 real boxes of the SdsList fixture */
    public const REGISTER = __DIR__ . '/seznam_ds_ovm-sample.xml';

    /** @var array<string, string> the install's RegisterRecord */
    public static array $record = [];

    /** thrown by writeRecord(), as by a database that refuses the write */
    public static ?\RuntimeException $writeFails = null;

    /** @var list<array<string, string>> the fields of each writeRecord() */
    public static array $writes = [];

    /** whether lock() gets the lock */
    public static bool $free = true;

    public static int $locks = 0;

    public static int $unlocks = 0;

    /** @var list<string> the messages of the AA log */
    public static array $logged = [];

    /** runs once while lock() waits - another request at work meanwhile */
    public static ?\Closure $whileWaiting = null;

    /** runs once after the register is read and hashed, before it is parsed */
    public static ?\Closure $afterRead = null;

    /** bytes of the index at most; null = RegisterIndex's own */
    public static ?int $maxIndex = null;

    /** bytes a line stays under; null = RegisterIndex's own */
    public static ?int $maxLine = null;

    public static function reset(): void
    {
        self::$record       = [];
        self::$writeFails   = null;
        self::$writes       = [];
        self::$free         = true;
        self::$locks        = 0;
        self::$unlocks      = 0;
        self::$logged       = [];
        self::$whileWaiting = null;
        self::$afterRead    = null;
        self::$maxIndex     = null;
        self::$maxLine      = null;
    }

    /** records $file as Sync::download() records the register it downloaded, and makes its index */
    public static function download(string $file): void
    {
        (new self($file))->downloaded((string) hash_file('sha256', $file), (int) filesize($file));
    }

    /** puts the fixture register at $file, downloaded - recorded and indexed - when $recorded */
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

    /** the index lines written: id, IČO, subtype, master flag, name */
    public static function indexed(string $file): string
    {
        return implode('', array_slice((array) file(dirname($file) . '/' . self::FILE), 1));
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
        self::$writes[] = array_map('strval', $values);
        foreach ($values as $field => $value) {
            self::$record[$field] = (string) $value;
        }
    }

    protected function log(string $message): void
    {
        self::$logged[] = $message;
    }

    protected function maxIndex(): int
    {
        return self::$maxIndex ?? parent::maxIndex();
    }

    protected function maxLine(): int
    {
        return self::$maxLine ?? parent::maxLine();
    }

    protected function lock(): bool
    {
        ++self::$locks;
        [$while, self::$whileWaiting] = [self::$whileWaiting, null];
        if ($while !== null) {
            $while();
        }
        return self::$free;
    }

    protected function unlock(): void
    {
        ++self::$unlocks;
    }

    protected function boxes(string $register, string $sha256): ?iterable
    {
        $boxes = parent::boxes($register, $sha256);
        [$after, self::$afterRead] = [self::$afterRead, null];
        if ($after !== null) {
            $after();
        }
        return $boxes;
    }
}

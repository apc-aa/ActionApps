<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk\Fixtures;

use AA\Mailbulk\Letter;
use AA\Mailbulk\Mailer;
use AA\Mailbulk\Run;

/** A run over the test's letter, mailer and clock; it opens no slices, and its item's slice and list are what the test says. */
class TestRun extends Run
{
    public static ?FakeLetter $letter = null;
    public static ?ScriptedMailer $mailer = null;
    public static ?int $now = null;
    public static ?float $microNow = null;
    /** the slice of the item; null = the item is gone */
    public static ?string $itemSlice = self::SLICE;
    /** the list may send items of the slice */
    public static bool $relates = true;

    public const SLICE = 'feed0000000000000000000000000001';

    public static function reset(): void
    {
        self::$letter   = new FakeLetter();
        self::$mailer   = new ScriptedMailer();
        self::$now      = null;
        self::$microNow = null;
        self::$itemSlice = self::SLICE;
        self::$relates   = true;
    }

    protected function letter(): Letter
    {
        return self::$letter ?? throw new \LogicException('No letter');
    }

    protected function mailer(): Mailer
    {
        return self::$mailer ?? throw new \LogicException('No mailer');
    }

    protected function slices(): array
    {
        return [];
    }

    protected function itemSlice(): ?string
    {
        return self::$itemSlice;
    }

    protected static function listRelates(string $list, string $slice): bool
    {
        return self::$relates && ($slice === self::$itemSlice);
    }

    protected static function now(): int
    {
        return self::$now ?? parent::now();
    }

    protected static function microNow(): float
    {
        return self::$microNow ?? parent::microNow();
    }
}

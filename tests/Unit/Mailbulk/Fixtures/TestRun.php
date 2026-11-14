<?php

declare(strict_types=1);

namespace Tests\Unit\Mailbulk\Fixtures;

use AA\Mailbulk\Letter;
use AA\Mailbulk\Mailer;
use AA\Mailbulk\Run;

/** A run over the test's letter, mailer and clock; it opens no slices. */
class TestRun extends Run
{
    public static ?FakeLetter $letter = null;
    public static ?ScriptedMailer $mailer = null;
    public static ?int $now = null;
    public static ?float $microNow = null;

    public static function reset(): void
    {
        self::$letter   = new FakeLetter();
        self::$mailer   = new ScriptedMailer();
        self::$now      = null;
        self::$microNow = null;
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

    protected static function now(): int
    {
        return self::$now ?? parent::now();
    }

    protected static function microNow(): float
    {
        return self::$microNow ?? parent::microNow();
    }
}

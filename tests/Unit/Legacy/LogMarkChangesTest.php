<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Log;
use PHPUnit\Framework\TestCase;

/**
 * The "Server configuration changes" box on aa_statistics lists SYS_STAT rows,
 * which are written only when something changed. Which of the six values it was
 * is the one thing a reader looks for, so markChanges() marks it.
 */
final class LogMarkChangesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('AA_INSTAL_URL')) {
            define('AA_INSTAL_URL', 'https://example.org/aa/');
        }
        class_exists(AA_Log::class);   // include/logs.php via the classmap
    }

    /** A log row as getLogTable() has it after extendTable(), newest first. */
    private static function row(string $time, string $svn, string $db = '10.11.19-MariaDB-log/DB_PDO', string $user = 'cron'): array
    {
        return ['time' => $time, 'user' => $user, 'aa' => '2026.6', 'svn' => $svn, 'php' => '8.3.33', 'db' => $db];
    }

    public function test_the_value_that_changed_is_marked_and_nothing_else(): void
    {
        $tab = AA_Log::markChanges([
            self::row('2026-09-11 11:48:08', '5382M (5365)'),
            self::row('2026-09-11 09:24:07', '5379M (5365)', user: 'admin'),
        ]);

        self::assertSame(['attr' => 'class=aa-log-changed', 'text' => '5382M (5365)'], $tab[0]['svn']);
        self::assertSame('8.3.33', $tab[0]['php']);
        self::assertSame('10.11.19-MariaDB-log/DB_PDO', $tab[0]['db']);
        self::assertSame('cron', $tab[0]['user'], 'who wrote the row is not part of the configuration');
        self::assertSame('2026-09-11 11:48:08', $tab[0]['time']);
    }

    public function test_the_oldest_row_has_nothing_to_differ_from(): void
    {
        $tab = AA_Log::markChanges([
            self::row('2026-09-11 11:48:08', '5382M (5365)'),
            self::row('2026-08-25 15:52:08', '5321M (5314)', '10.11.18-MariaDB-log/DB_PDO'),
        ]);

        self::assertSame(self::row('2026-08-25 15:52:08', '5321M (5314)', '10.11.18-MariaDB-log/DB_PDO'), $tab[1]);
        self::assertIsArray($tab[0]['db'], 'both changes of the newer row are marked');
        self::assertIsArray($tab[0]['svn']);
    }

    public function test_each_row_is_compared_with_the_original_below_it_not_the_marked_one(): void
    {
        $tab = AA_Log::markChanges([
            self::row('3', 'C'),
            self::row('2', 'B'),
            self::row('1', 'B'),
        ]);

        self::assertIsArray($tab[0]['svn']);
        self::assertSame('B', $tab[1]['svn']);
    }
}

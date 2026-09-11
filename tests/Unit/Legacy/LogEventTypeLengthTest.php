<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Log;
use PHPUnit\Framework\TestCase;

/**
 * `log.type` is varbinary(10) and Metabase cuts a longer event name before the
 * INSERT, silently: CRON_FALLBACK_ERR, PAGE_PARAM_ERR and ITEM_FIELD_FILLED were
 * written for months as CRON_FALLB, PAGE_PARAM and ITEM_FIELD, so any lookup by
 * the full name found nothing. This test pins the column width to
 * AA_Log::TYPE_MAX and fails on the next literal that would not fit.
 */
final class LogEventTypeLengthTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** directories holding AA_Log::write() callers (vendor and tests excluded) */
    private const SCAN = ['include', 'src', 'admin', 'modules', 'central', 'misc'];

    public static function setUpBeforeClass(): void
    {
        if (!defined('AA_INSTAL_URL')) {
            define('AA_INSTAL_URL', 'https://example.org/aa/');
        }
        class_exists(AA_Log::class);   // include/logs.php via the classmap
    }

    public function test_type_max_matches_the_log_table_schema(): void
    {
        $schema = require self::ROOT . '/include/metabase_schema.php';
        $this->assertSame('varbinary(' . AA_Log::TYPE_MAX . ')', $schema['log']['c']['type'][1]);
    }

    public function test_every_literal_event_name_fits_the_column(): void
    {
        $too_long = [];
        foreach ($this->phpFiles() as $file) {
            $src = (string) file_get_contents($file);
            if (!preg_match_all('/AA_Log::(?:write|writeIfChanged)\(\s*([\'"])([^\'"]+)\1/', $src, $m)) {
                continue;
            }
            foreach ($m[2] as $event) {
                if (strlen($event) > AA_Log::TYPE_MAX) {
                    $too_long[] = substr($file, strlen(self::ROOT) + 1) . ": $event";
                }
            }
        }
        $this->assertSame([], $too_long, 'event names longer than log.type are stored cut and never found again');
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        $files = glob(self::ROOT . '/*.php') ?: [];   // entry points: slice.php, view.php, ...
        foreach (self::SCAN as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }
        return $files;
    }
}

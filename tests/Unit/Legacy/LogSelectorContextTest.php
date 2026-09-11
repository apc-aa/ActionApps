<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Log;
use AA_Logentry;
use AA_Logentry_Qa;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Characterization + regression tests for the log `selector` (reversed
 * AA_Logentry_Qa) serialized by AA_Log::context().
 *
 * The selector is stored reversed (`ident:module:ip:uri:Qa`) so the searchable
 * `ident` is the column prefix and the class flag `Qa` is last. The column is
 * `varchar(255)` — a long uri overflows it and the trailing `:Qa` is truncated
 * away, so AA_Logentry::factoryByStringReversed() (which reads the class from the
 * LAST `:`-segment) can no longer parse the row on the Log page. AA_Log::context()
 * caps the uri so the flag always survives.
 *
 * The serialize chain needs a few config constants and the un-autoloadable global
 * ParamImplode()/warn() helpers from util.php (which can't load here — it pulls in
 * the full locsess boot); _m() arrives via mgettext.php through the autoloader.
 */
final class LogSelectorContextTest extends TestCase
{
    /** mirrors the `log.selector` column width in include/metabase_schema.php */
    private const SELECTOR_MAX = 255;

    public static function setUpBeforeClass(): void
    {
        // util.php cannot load here (it pulls the whole locsess boot); the helpers
        // AA_Logentry::serializeToString() needs come from a global-namespace fixture -
        // a shim declared inside this namespaced class would land in Tests\Unit\Legacy
        // and never satisfy the global call in include/statestore.php
        require_once __DIR__ . '/../fixtures/util_shims.php';
        class_exists(AA_Logentry_Qa::class);
    }

    /** @return string serialized reversed Qa selector for the given parts */
    private function serializeQa(string $ident, string $module, string $ip, string $uri): string
    {
        return (new AA_Logentry_Qa(['ident' => $ident, 'module' => $module, 'ip' => $ip, 'uri' => $uri]))
            ->serializeToString(true);
    }

    private function longUri(): string
    {
        // ~450 chars — like the admin aa_log.php URL from the bug report
        return 'www.dataplan.info/aa-dev/admin/aa_log.php?module_id=' . str_repeat('d', 32)
            . '&' . str_repeat('srchbr_field%5B0%5D=all_fields&srchbr_oper%5B0%5D=LIKE&', 6);
    }

    // --- characterization: the bug (passes against unchanged code) ---

    public function test_long_selector_truncated_at_column_width_loses_class_flag(): void
    {
        $full = $this->serializeQa('2', str_repeat('d', 32), '84.42.240.38', $this->longUri());
        self::assertGreaterThan(self::SELECTOR_MAX, strlen($full), 'precondition: overflows the column');

        // full string still round-trips...
        self::assertInstanceOf(AA_Logentry_Qa::class, AA_Logentry::factoryByStringReversed($full));

        // ...but as stored (truncated to the column width) the trailing :Qa is gone,
        // so the reversed parse fails — exactly the broken Log-page display.
        $stored = substr($full, 0, self::SELECTOR_MAX);
        self::assertNull(AA_Logentry::factoryByStringReversed($stored));
    }

    public function test_short_selector_roundtrips(): void
    {
        $sel    = $this->serializeQa('2', str_repeat('d', 32), '1.2.3.4', 'http/host/admin/index.php');
        $parsed = AA_Logentry::factoryByStringReversed($sel);
        self::assertInstanceOf(AA_Logentry_Qa::class, $parsed);
        self::assertSame('2', $parsed->ident);
        self::assertSame('1.2.3.4', $parsed->ip);
    }

    // --- regression: context() caps the selector so the flag survives ---

    public function test_contextSelector_caps_to_column_width_and_stays_parseable(): void
    {
        $ident  = '2';
        $module = str_repeat('d', 32);
        $ip     = '84.42.240.38';

        $sel = (new ReflectionMethod(AA_Log::class, 'contextSelector'))
            ->invoke(null, $ident, $module, $ip, $this->longUri());

        self::assertLessThanOrEqual(self::SELECTOR_MAX, strlen($sel), 'capped to the column width');

        $parsed = AA_Logentry::factoryByStringReversed($sel);
        self::assertInstanceOf(AA_Logentry_Qa::class, $parsed);
        self::assertSame($ident, $parsed->ident);
        self::assertSame($module, $parsed->module);
        self::assertSame($ip, $parsed->ip);
        // the (only unbounded) uri is trimmed but its prefix is preserved
        self::assertStringStartsWith('www.dataplan.info/aa-dev/admin/aa_log.php', $parsed->uri);
    }

    public function test_contextSelector_leaves_short_uri_intact(): void
    {
        $uri = 'http/host/admin/index.php';
        $sel = (new ReflectionMethod(AA_Log::class, 'contextSelector'))
            ->invoke(null, '2', str_repeat('d', 32), '1.2.3.4', $uri);

        $parsed = AA_Logentry::factoryByStringReversed($sel);
        self::assertInstanceOf(AA_Logentry_Qa::class, $parsed);
        self::assertSame($uri, $parsed->uri);
    }
}

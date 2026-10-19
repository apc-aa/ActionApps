<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * Old templates still post to *.php3 (filldisc.php3 on biom.cz); each one only includes its .php
 * sibling, so a typo in that path is a fatal error for every visitor using the old name.
 */
final class Php3ShimsTest extends TestCase
{
    public function test_every_php3_shim_includes_an_existing_file(): void
    {
        $root  = dirname(__DIR__, 3);
        $shims = array_merge(glob("$root/*.php3"), glob("$root/*/*.php3"), glob("$root/*/*/*.php3"));
        $this->assertNotEmpty($shims);

        foreach ($shims as $shim) {
            if (str_contains($shim, '/vendor/')) {
                continue;
            }
            $code = file_get_contents($shim);
            $this->assertSame(1, preg_match('~require(?:_once)? __DIR__ \. "(/[\w./-]+\.php)";~', $code, $m), $shim);
            $this->assertFileExists(dirname($shim) . $m[1], $shim);
        }
    }
}

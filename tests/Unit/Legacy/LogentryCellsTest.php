<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Log;
use AA_Logentry_Ms;
use PHPUnit\Framework\TestCase;

/**
 * AA_Log::renderLogentryCells() - the log rows the AA finder and the Item info page print as
 * HTML table cells. A log row carries whatever was logged (a login name, a mail server reply),
 * so a value that is not an AA_Logentry is printed as text, as the Log page does.
 */
final class LogentryCellsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        class_exists(AA_Logentry_Ms::class);
    }

    private static function row(array $over = []): array
    {
        return array_merge(['id' => '12', 'Date' => '2026-09-26 10:00:00', 'user' => 'admin', 'type' => 'LOGIN', 'selector' => '', 'params' => ''], $over);
    }

    public function test_plain_values_are_escaped(): void
    {
        $cells = AA_Log::renderLogentryCells(self::row([
            'user'     => '<script>alert(1)</script>',
            'type'     => '<b>',
            'selector' => '"><img src=x onerror=alert(2)>',
            'params'   => '<svg onload=alert(3)>',
        ]));

        $this->assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', $cells['user']);
        $this->assertSame('&lt;b&gt;', $cells['type']);
        $this->assertSame('&quot;&gt;&lt;img src=x onerror=alert(2)&gt;', $cells['selector']);
        $this->assertSame('&lt;svg onload=alert(3)&gt;', $cells['params']);
    }

    public function test_a_value_with_a_colon_that_is_no_logentry_is_escaped(): void
    {
        $cells = AA_Log::renderLogentryCells(self::row(['params' => 'nope:<img src=x onerror=alert(1)>']));

        $this->assertSame('nope:&lt;img src=x onerror=alert(1)&gt;', $cells['params']);
    }

    public function test_a_logentry_is_rendered_as_its_escaped_table(): void
    {
        $params = AA_Logentry_Ms::make('smtp', 1, '<img src=x onerror=alert(1)>')->serializeToString();
        $cells  = AA_Log::renderLogentryCells(self::row(['params' => $params]));

        $this->assertStringContainsString('class=aa-log-entry', $cells['params']);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $cells['params']);
        $this->assertStringNotContainsString('<img', $cells['params']);
    }
}

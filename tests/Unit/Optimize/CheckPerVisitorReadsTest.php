<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckPerVisitorReads;
use AA\Optimize\Finding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckPerVisitorReadsTest extends TestCase
{
    private const SITE = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe()
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function readsProvider(): array
    {
        return [
            'address'               => ['<input type="hidden" value="{server:REMOTE_ADDR}">', ['REMOTE_ADDR']],
            'browser and referer'   => ['{ifin:{server:HTTP_USER_AGENT}:bot:1}{ifin:{server:HTTP_REFERER}:google.com:1}', ['HTTP_USER_AGENT', 'HTTP_REFERER']],
            'twice'                 => ['{server:REMOTE_ADDR} {server:REMOTE_ADDR}', ['REMOTE_ADDR']],
            'host is cached per value' => ['{server:HTTP_HOST}', []],
            'URI is cached per value'  => ['{server:REQUEST_URI}', []],
            'time of the request'   => ['?t={server:REQUEST_TIME}', ['REQUEST_TIME']],
            'misspelt name'         => ['{server:REFERER}', ['REFERER']],
            'AA comment'            => ['{# agent: {server:HTTP_USER_AGENT}}', []],
            'HTML comment is output' => ['<!-- agent: {server:HTTP_USER_AGENT} -->', ['HTTP_USER_AGENT']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('readsProvider')]
    public function test_reads_finds_values_of_the_visitor(string $code, array $expected): void
    {
        $this->assertSame($expected, CheckPerVisitorReads::reads($code));
    }

    public function test_nothing_found_is_ok(): void
    {
        $findings = CheckPerVisitorReads::findingsFor([], []);
        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_one_finding_per_module(): void
    {
        $rows = [
            ['module' => self::SITE, 'where' => '<a href="/spot">21</a>', 'reads' => ['HTTP_USER_AGENT']],
            ['module' => self::SITE, 'where' => '<a href="/spot">17</a>', 'reads' => ['HTTP_REFERER']],
        ];
        [$finding] = CheckPerVisitorReads::findingsFor($rows, [self::SITE => 'example.org']);
        $this->assertSame(Finding::INFO, $finding->severity);
        $this->assertSame(self::SITE, $finding->key);
        $this->assertSame(2, $finding->count);
        $this->assertStringContainsString('example.org', $finding->title);
    }
}

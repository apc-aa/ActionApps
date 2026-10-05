<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/include/htmlMimeMail/htmlMimeMail.php';

/**
 * Files a mail embeds or attaches may be named by item content: a stream other than a local path
 * is never opened. Web addresses go through the SSRF guard (pinned in Tests\Unit\Http\ClientTest).
 */
final class HtmlMimeMailReadFileTest extends TestCase
{
    #[DataProvider('streams')]
    public function test_readFile_otherStream_readsNothing(string $name): void
    {
        self::assertSame('', \htmlMimeMail::readFile($name));
    }

    public static function streams(): array
    {
        return [
            'file scheme' => ['file://' . __FILE__],
            'php filter'  => ['php://filter/resource=' . __FILE__],
            'ftp'         => ['ftp://example.org/a.png'],
        ];
    }

    public function test_readFile_localPath_isRead(): void
    {
        self::assertStringContainsString('HtmlMimeMailReadFileTest', \htmlMimeMail::readFile(__FILE__));
    }
}

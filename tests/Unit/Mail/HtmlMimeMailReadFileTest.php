<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use AA\Util\LogOnce;
use AA\Util\SiteFile;
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

    /** a refusal or a file from outside the uploads writes a log row; seen already, so the test reaches no database */
    private static function readLocal(string $path): string
    {
        $seen = new \ReflectionProperty(LogOnce::class, 'seen');
        $seen->setValue(null, [SiteFile::EVENT . "\0mail:$path" => true, SiteFile::EVENT_OUTER . "\0mail:$path" => true]);
        try {
            return \htmlMimeMail::readFile($path);
        } finally {
            $seen->setValue(null, []);
        }
    }

    public function test_readFile_localPath_isRead(): void
    {
        $file = sys_get_temp_dir() . '/aa-mail-attachment-' . bin2hex(random_bytes(6)) . '.pdf';
        file_put_contents($file, '%PDF-1.4');
        try {
            self::assertSame('%PDF-1.4', self::readLocal($file));
        } finally {
            unlink($file);
        }
    }

    public function test_readFile_fileOfTheAaTree_readsNothing(): void
    {
        self::assertSame('', self::readLocal(__FILE__));
        self::assertSame('', self::readLocal(dirname(__DIR__, 3) . '/include/config.php.default'));
    }
}

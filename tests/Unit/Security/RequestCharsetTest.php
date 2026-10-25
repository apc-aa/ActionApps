<?php

declare(strict_types=1);

namespace Tests\Unit\Security;

use AA\Security\RequestCharset;
use AA\Util\LogOnce;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * The charset a request names (convertfrom, convertto): only one in which ASCII stays ASCII is taken,
 * any other counts as not given and is logged once a day per script and parameter. The log goes to
 * the recording stand-in of the database.
 */
final class RequestCharsetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';   // ParamImplode()
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';   // RecordingDb
        } finally {
            restore_error_handler();
        }
    }

    /** @var array<string, mixed> */
    private array $saved_server = [];

    protected function setUp(): void
    {
        $this->saved_server = $_SERVER;
        RecordingDb::reset();
        LogOnce::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        $_SERVER['HTTP_HOST']    = 'example.org';
        $_SERVER['REQUEST_URI']  = '/aa/view.php?vid=1&convertfrom=x';
        $_SERVER['HTTP_REFERER'] = 'https://example.org/akce/?a=1';
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        $_SERVER = $this->saved_server;
        RecordingDb::reset();
        LogOnce::reset();
    }

    #[DataProvider('allowed')]
    public function test_fromRequest_takesAnAsciiCompatibleCharset(string $value, string $expected): void
    {
        self::assertSame($expected, RequestCharset::fromRequest($value, 'convertfrom', 'view.php'));
        self::assertSame([], RecordingDb::$log, 'nothing to log');
    }

    public static function allowed(): array
    {
        return [
            'utf-8'        => ['utf-8', 'utf-8'],
            'upper case'   => ['UTF-8', 'utf-8'],
            'windows-1250' => ['windows-1250', 'windows-1250'],
            'mixed case'   => ['Windows-1250', 'windows-1250'],
            'iso-8859-2'   => ['ISO-8859-2', 'iso-8859-2'],
        ];
    }

    #[DataProvider('notGiven')]
    public function test_fromRequest_noValue_isEmptyAndNotLogged(mixed $value): void
    {
        self::assertSame('', RequestCharset::fromRequest($value, 'convertto', 'jsview.php'));
        self::assertSame([], RecordingDb::$log);
    }

    public static function notGiven(): array
    {
        return [
            'null'        => [null],
            'empty'       => [''],
            'empty array' => [[]],
        ];
    }

    #[DataProvider('refused')]
    public function test_fromRequest_otherCharset_isEmptyAndLogged(mixed $value, string $logged_name): void
    {
        self::assertSame('', RequestCharset::fromRequest($value, 'convertfrom', 'view.php'));

        $rows = RecordingDb::inserts('log');
        self::assertCount(1, $rows);
        self::assertSame(RequestCharset::EVENT, $rows[0]['type']);
        self::assertSame('view.php:convertfrom', $rows[0]['selector']);
        self::assertSame([$logged_name, 'example.org/aa/view.php', 'example.org/akce/'], ParamExplode($rows[0]['params']));
    }

    public static function refused(): array
    {
        return [
            'cp037'                   => ['cp037', 'cp037'],
            'us-ascii'                => ['us-ascii', 'us-ascii'],
            'ibm037'                  => ['IBM037', 'IBM037'],
            'utf-7'                   => ['utf-7', 'utf-7'],
            'a path'                  => ['../x', '..?x'],
            'separators of the log'   => ['x:y#z', 'x?y?z'],
            'escaped by view.php'     => ["a\\'b", 'a?b'],
            'a table not on the list' => ['koi8-r', 'koi8-r'],
            'utf8 without dash'       => ['utf8', 'utf8'],
            'space around'            => [' utf-8', '?utf-8'],
            'array'                   => [['utf-8'], 'array'],
            'long'                    => [str_repeat('a', 100), str_repeat('a', 40)],
        ];
    }

    public function test_fromRequest_logsOnceADayPerScriptAndParameter(): void
    {
        RequestCharset::fromRequest('cp037', 'convertfrom', 'view.php');
        RequestCharset::fromRequest('cp500', 'convertfrom', 'view.php');
        RequestCharset::fromRequest('cp037', 'convertto', 'view.php');
        RequestCharset::fromRequest('cp037', 'convertfrom', 'filler.php');

        self::assertSame(['view.php:convertfrom', 'view.php:convertto', 'filler.php:convertfrom'], array_column(RecordingDb::inserts('log'), 'selector'));
    }
}

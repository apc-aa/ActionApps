<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Showview;
use AA_View;
use AA\Security\RequestCharset;
use AA\Util\LogOnce;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;

/**
 * The charsets a view converts its output with (convertfrom, convertto): view.php, jsview.php and
 * discussion.php take them from the URL as parameters or as set[<vid>]=convertfrom-...; AA_Showview
 * takes from the URL only those of AA\Security\RequestCharset and logs the others. A template gives
 * them in {view.php?...}, trusted.
 */
final class ViewConvertCharsetTest extends TestCase
{
    private const VID = 12;

    /** globals parseViewParameters() reads or add_vars() writes */
    private const GLOBALS = ['cmd', 'set', 'vid', 'als', 'x', 'convertfrom', 'convertto'];

    /** @var array<string, mixed> */
    private array $saved_globals = [];

    /** @var array<string, mixed> */
    private array $saved_server = [];

    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';   // RecordingDb - the log
            require_once __DIR__ . '/../../../include/view.php';
        } finally {
            restore_error_handler();
        }
    }

    protected function setUp(): void
    {
        foreach (self::GLOBALS as $name) {
            if (array_key_exists($name, $GLOBALS)) {
                $this->saved_globals[$name] = $GLOBALS[$name];
            }
            unset($GLOBALS[$name]);
        }
        $this->saved_server = $_SERVER;
        $_SERVER['SCRIPT_NAME'] = '/aa/view.php';
        RecordingDb::reset();
        LogOnce::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
    }

    protected function tearDown(): void
    {
        foreach (self::GLOBALS as $name) {
            unset($GLOBALS[$name]);
        }
        foreach ($this->saved_globals as $name => $value) {
            $GLOBALS[$name] = $value;
        }
        $_SERVER = $this->saved_server;
        $GLOBALS['spareDBs'] = [];
        RecordingDb::reset();
        LogOnce::reset();
    }

    /** selectors of the log rows of refused charsets */
    private static function logged(): array
    {
        return array_column(array_filter(RecordingDb::inserts('log'), static fn(array $row): bool => $row['type'] === RequestCharset::EVENT), 'selector');
    }

    /** the parameters AA_Showview converts with */
    private static function shown(array $view_param): array
    {
        $showview = new class ($view_param) extends AA_Showview {
            public function param(string $name): mixed
            {
                return $this->_view_param[$name] ?? null;
            }
        };
        return [$showview->param('convertfrom'), $showview->param('convertto')];
    }

    /** view.php?vid=12&set[12]=convertfrom-...,convertto-... - as view.php, jsview.php and discussion.php parse their URL */
    private static function fromUrlSet(string $set): array
    {
        $GLOBALS['vid'] = (string) self::VID;
        $GLOBALS['set'] = [self::VID => $set];
        $GLOBALS['cmd'] = [];
        $GLOBALS['als'] = [];
        return self::parse('');
    }

    /** AA_View::parseViewParameters() - quiet about the parameters the test leaves out */
    private static function parse(string $query_string): array
    {
        set_error_handler(static fn(int $no): bool => in_array($no, [E_WARNING, E_NOTICE, E_DEPRECATED], true));
        try {
            return AA_View::parseViewParameters($query_string);
        } finally {
            restore_error_handler();
        }
    }

    public function test_fromUrl_setOfAsciiCharsets_isKept(): void
    {
        self::assertSame(['windows-1250', 'utf-8'], self::shown(self::fromUrlSet('convertfrom-Windows-1250,convertto-utf-8')));
        self::assertSame([], self::logged());
    }

    public function test_fromUrl_setOfOtherCharset_isLeftOutAndLogged(): void
    {
        self::assertSame(['', 'utf-8'], self::shown(self::fromUrlSet('convertfrom-cp037,convertto-utf-8')));
        self::assertSame(['view.php:convertfrom'], self::logged());
    }

    /** a name that is no charset never reaches ConvertCharset, which opens a table file by it */
    public function test_fromUrl_setOfPath_isLeftOut(): void
    {
        self::assertSame(['', null], self::shown(self::fromUrlSet('convertfrom-../x')));
    }

    public function test_fromUrl_setOfJsviewAndDiscussion_isLeftOutAndLogged(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/aa/jsview.php';
        self::assertSame(['', ''], self::shown(self::fromUrlSet('convertfrom-cp037,convertto-utf-7')));
        $_SERVER['SCRIPT_NAME'] = '/aa/discussion.php';
        self::assertSame(['', null], self::shown(self::fromUrlSet('convertfrom-cp500')));

        self::assertSame(['jsview.php:convertfrom', 'jsview.php:convertto', 'discussion.php:convertfrom'], self::logged());
    }

    /** view.php and jsview.php add their convertfrom= and convertto= to the parsed parameters */
    public function test_fromUrl_parameters_takeOnlyAsciiCharsets(): void
    {
        $view_param = self::fromUrlSet('');
        $view_param['convertfrom'] = 'IBM037';
        $view_param['convertto']   = 'UTF-8';

        self::assertSame(['', 'utf-8'], self::shown($view_param));
    }

    /** {view.php?...} of a template is trusted */
    public function test_template_otherCharset_isKept(): void
    {
        $view_param = self::parse('vid=' . self::VID . '&set[' . self::VID . ']=convertfrom-cp037,convertto-utf-8');

        self::assertFalse($view_param['from_request']);
        self::assertSame(['cp037', 'utf-8'], self::shown($view_param));
        self::assertSame([], self::logged());
    }
}

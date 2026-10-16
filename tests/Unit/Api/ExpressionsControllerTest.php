<?php

declare(strict_types=1);

namespace Tests\Unit\Api;

use AA\Api\Controller\ExpressionsController;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Api\Fixtures\JsonResponseReader;

require_once __DIR__ . '/Fixtures/JsonResponseReader.php';

/** GET /v1/expressions - the catalog for the code editor's hints, to logged-in users only. */
final class ExpressionsControllerTest extends TestCase
{
    private const CATALOG = ['ifset' => ['params' => ['condition', 'text', 'else_text'], 'desc' => 'If set.']];

    protected function setUp(): void
    {
        $_SERVER['HTTP_HOST']    = 'aa.example.test';
        $_SERVER['HTTP_REFERER'] = 'https://aa.example.test/aa/admin/se_view.php';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_ORIGIN']);
    }

    private static function controller(bool $logged_in): ExpressionsController
    {
        return new class (static fn() => self::CATALOG, $logged_in) extends ExpressionsController {
            public function __construct(\Closure $catalog, private bool $loggedIn)
            {
                parent::__construct($catalog);
            }

            protected function isLoggedIn(): bool
            {
                return $this->loggedIn;
            }
        };
    }

    public function test_list_loggedIn_returnsTheCatalog(): void
    {
        $r = JsonResponseReader::read(self::controller(true)->list());

        self::assertSame(200, $r['status']);
        self::assertSame(['commands' => self::CATALOG], $r['body']);
    }

    public function test_list_notLoggedIn_is401(): void
    {
        self::assertSame(401, JsonResponseReader::read(self::controller(false)->list())['status']);
    }

    public function test_list_fromAnotherSite_is401(): void
    {
        $_SERVER['HTTP_REFERER'] = 'https://evil.example/';

        self::assertSame(401, JsonResponseReader::read(self::controller(true)->list())['status']);
    }
}

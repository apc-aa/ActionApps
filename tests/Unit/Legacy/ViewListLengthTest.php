<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Showview;
use AA_View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/** where a listing starts for set[<vid>]=page-<page> - the page comes from the URL */
final class ViewListLengthTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';
            require_once __DIR__ . '/fixtures/store_fixtures.php';
        } finally {
            restore_error_handler();
        }
    }

    /** @return array<string, array{string, int}> page, first item shown (from 0) */
    public static function pages(): array
    {
        return [
            'first page'  => ['1', 0],
            'third page'  => ['3', 20],
            'page of N'   => ['2-5', 10],
            // a start before the first item made the banner loop draw every banner the URL names
            'fraction'    => ['0.5', 0],
            'below one'   => ['0.9', 0],
        ];
    }

    #[DataProvider('pages')]
    public function test_getListLength_neverStartsBeforeTheFirstItem(string $page, int $list_from): void
    {
        $view     = new AA_View(9300, ['id' => '9300', 'listlen' => '10', 'scroller' => '0']);
        $showview = new AA_Showview(['page' => $page, 'listlen' => '', 'to' => '', 'from' => '', 'random' => '']);

        [, $from] = (new ReflectionMethod(AA_Showview::class, 'getListLength'))->invoke($showview, $view, 50);

        self::assertSame($list_from, (int) $from);
    }
}

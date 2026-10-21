<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Showview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** whether a listing has the page {xpage} asks for - none of the page's listings has it = 404 */
final class ViewHasPageTest extends TestCase
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

    #[DataProvider('pages')]
    public function test_hasPage(string $page, int $idscount, int $list_from, bool $expected): void
    {
        $this->assertSame($expected, AA_Showview::hasPage($page, $idscount, $list_from));
    }

    public static function pages(): array
    {
        return [
            'last item starts the page' => ['3', 21, 20, true],
            'page right after the end'  => ['3', 20, 20, false],
            'far beyond the end'        => ['9999', 20, 99980, false],
            'page of N within'          => ['5-5', 3, 0, true],
            'page of N beyond'          => ['9999-10', 3, 0, false],
        ];
    }
}

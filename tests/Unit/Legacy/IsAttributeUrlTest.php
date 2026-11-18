<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * IsAttributeUrl() guards URLs that anyone filling a form may store and templates print raw into
 * href/src or a JS string - the browser decodes character references there before it reads them.
 */
final class IsAttributeUrlTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    #[DataProvider('urls')]
    public function test_isAttributeUrl(string $url, bool $expected): void
    {
        self::assertSame($expected, IsAttributeUrl($url));
    }

    public static function urls(): array
    {
        return [
            'AA expression'      => ['{ifin:{item:{qss:mid}:_#TYP_MUNI}:mesto:https#://www.dataplan.info/img_upload/a.pdf}', true],
            'windows path'       => ['C:\\fakepath\\a.pdf', false],
            'https'              => ['https://example.org/a.jpg', true],
            'http upper case'    => ['HTTP://example.org/a.jpg', true],
            'relative'           => ['img_upload/a.jpg', true],
            'root relative'      => ['/img_upload/a.jpg?v=1&w=2', true],
            'parent relative'    => ['../img_upload/a.jpg', true],
            'scheme relative'    => ['//example.org/a.jpg', true],
            'empty'              => ['  ', false],
            'javascript'         => ['javascript:alert(1)', false],
            'javascript spaced'  => [" \tJava\nScript:alert(1)", false],
            'data'               => ['data:text/html,<script>alert(1)</script>', false],
            'entity colon'       => ['javascript&colon;alert(1)', false],
            'numeric entity'     => ['javascript&#58;alert(1)', false],
            'entity tab'         => ['jav&#x09;ascript:alert(1)', false],
            'entity no semicolon' => ['javascript&#58alert(1)', false],
            'entity CR'          => ['java&#13;script:alert(1)', false],
            'https entity slash' => ['https:&#47;&#47;example.org', false],
            'leaves attribute'   => ['x" onerror="alert(1)', false],
            'apostrophe'         => ["x' onerror='alert(1)", false],
            'encoded apostrophe' => ['/x&#39;);alert(1)//', false],
            'named apostrophe'   => ['img/x&apos;);alert(1)//', false],
            'encoded in query'   => ['https://e.org/a.jpg?x=1&#x27);alert(1)//', false],
            'backslash'          => ['/x\\\');alert(1)//', false],
            'query with amp'     => ['https://example.org/a.jpg?x=1&y=2', true],
        ];
    }
}

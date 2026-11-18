<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Widget\FilWidget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * filler.php answers the {ajax} file upload with a script that runs on the AA origin. The upload
 * form's id comes from the request, so whatever it holds must stay inside its JS string.
 */
final class FilWidgetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    #[DataProvider('submittedUrls')]
    public function test_getWidgetValue_url(string $url, array $expected): void
    {
        self::assertSame($expected, FilWidget::getWidgetValue(['fil' => ['url' => [$url]]], null)->getValues());
    }

    public function test_getWidgetValue_arrayUrl_isDropped(): void
    {
        self::assertSame([], FilWidget::getWidgetValue(['fil' => ['url' => [['value' => 'javascript:alert(1)']]]], null)->getValues());
    }

    public static function submittedUrls(): array
    {
        return [
            'https'              => ['https://example.org/a.jpg', ['https://example.org/a.jpg']],
            'relative'           => ['img_upload/a.jpg', ['img_upload/a.jpg']],
            'empty'              => ['  ', []],
            'javascript'         => ['javascript:alert(1)', []],
            'upload marker'      => ['AA_UPLOAD:a:1:{s:4:"name";s:1:"x";}', []],
            'thumbnail marker'   => ['AA_UPTHUMB:/etc/passwd', []],
            'AA expression'      => ['{ifin:{item:{qss:mid}:_#TYP_MUNI}:mesto:https#://www.dataplan.info/a.pdf}', ['{ifin:{item:{qss:mid}:_#TYP_MUNI}:mesto:https#://www.dataplan.info/a.pdf}']],
        ];
    }

    public function test_uploadCallback_formId_callsReloadWithResult(): void
    {
        $id = 'aa-u63556a45e4e67b654a3a986a548e8bc9-file____________';
        self::assertSame(
            "parent.AA_ReloadAjaxResponse('$id', {\"63556a45e4e67b654a3a986a548e8bc9\":\"x.jpg\"})",
            FilWidget::uploadCallback($id, ['63556a45e4e67b654a3a986a548e8bc9' => 'x.jpg']),
        );
    }

    public function test_uploadCallback_itemHtml_cannotCloseTheScript(): void
    {
        $js = FilWidget::uploadCallback('aa-x', ['id' => '<!--<script></script>']);
        self::assertStringNotContainsString('<', $js);
        self::assertStringNotContainsString('>', $js);
    }

    #[DataProvider('foreignIds')]
    public function test_uploadCallback_foreignId_staysInsideItsString(string $id): void
    {
        $js = FilWidget::uploadCallback($id, []);
        self::assertMatchesRegularExpression("/^parent\\.AA_ReloadAjaxResponse\\('([^'<>\\r\\n]*)', \\[\\]\\)\\z/", $js);
        preg_match("/\\('(.*)', /s", $js, $m);
        self::assertSame($id, json_decode('"' . $m[1] . '"'), 'the literal must hold exactly the id');
    }

    public function test_uploadCallback_invalidUtf8Id_becomesEmptyString(): void
    {
        self::assertStringStartsWith("parent.AA_ReloadAjaxResponse('', ", FilWidget::uploadCallback("aa-x\xff'", []));
    }

    public static function foreignIds(): array
    {
        return [
            'urlencoded'       => ['aa-u%C3%A9.x~1-f'],
            'apostrophe'       => ["aa-x');x('"],
            'quote'            => ['aa-x"'],
            'backslash'        => ['aa-x\\'],
            'backslash quote'  => ["aa-x\\'"],
            'tag'              => ['aa-x</script>'],
            'newline'          => ["aa-x\n"],
            'line separator'   => ["aa-x\u{2028}"],
        ];
    }
}

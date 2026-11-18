<?php

declare(strict_types=1);

namespace Tests\Unit\Widget;

use AA\Widget\FilWidget;
use AA_Content;
use AA_Property;
use AA_Value;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * filler.php answers the {ajax} file upload with a script that runs on the AA origin. The upload
 * form's id comes from the request, so whatever it holds must stay inside its JS string.
 * The {ajax} variant sends the same inputs as the item form, so both store alike.
 */
final class FilWidgetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
    }

    public function test_getRawHtml_ajaxUrl_isEditableInsideTheUploadForm(): void
    {
        $html = self::html(new FilWidget(['display_url' => '0']), ['https://example.org/a.jpg'], 'ajax');

        self::assertMatchesRegularExpression('~<form id="fuf[^>]*>.*<input type="text"[^>]*name="aa\\[n1_[^"]*\\[fil\\]\\[url\\]\\[0\\]"[^>]*value="https://example.org/a.jpg".*</form>~s', $html);
    }

    public function test_getRawHtml_ajaxEmpty_offersUrlInsideTheUploadForm(): void
    {
        $html = self::html(new FilWidget(['display_url' => '0']), [], 'ajax');

        self::assertMatchesRegularExpression('~<form id="fuf[^>]*>.*<input type="text"[^>]*\\[fil\\]\\[url\\]\\[0\\]".*</form>~s', $html);
        self::assertStringNotContainsString('Delete', $html, 'nothing to delete');
    }

    public function test_getRawHtml_ajaxUnsetDisplayUrl_staysUploadOnly(): void
    {
        $filled = self::html(new FilWidget([]), ['https://example.org/a.jpg'], 'ajax');
        $empty  = self::html(new FilWidget([]), [], 'ajax');

        self::assertStringNotContainsString('type="text"', $filled . $empty);
        self::assertMatchesRegularExpression('~<form id="fuf[^>]*>.*type="hidden"[^>]*\\[fil\\]\\[url\\]\\[0\\]".*</form>~s', $filled);
        self::assertStringNotContainsString('[fil][url]', $empty);
    }

    public function test_getRawHtml_normalUnsetDisplayUrl_showsUrl(): void
    {
        self::assertStringContainsString('type="text"', self::html(new FilWidget([]), [], 'normal'));
    }

    public function test_getRawHtml_ajaxHiddenUrl_uploadReplacesTheFiles(): void
    {
        $html = self::html(new FilWidget(['display_url' => 2, 'multiple' => 1]), ['https://example.org/a.jpg'], 'ajax');

        self::assertStringNotContainsString('type="text"', $html);
        self::assertMatchesRegularExpression('~<form id="fuf[^>]*>.*type="hidden"[^>]*\\[fil\\]\\[url\\]\\[0\\]".*</form>~s', $html);
        self::assertStringNotContainsString('[fil][add]', $html, 'no way to remove one of the added files');
    }

    public function test_getRawHtml_ajaxShownUrl_uploadAddsToTheFiles(): void
    {
        $html = self::html(new FilWidget(['display_url' => '0', 'multiple' => 1]), ['https://example.org/a.jpg'], 'ajax');

        self::assertMatchesRegularExpression('~<form id="fuf[^>]*>.*\\[fil\\]\\[add\\]".*</form>~s', $html);
    }

    public function test_getRawHtml_normal_unchanged(): void
    {
        $html = self::html(new FilWidget([]), ['https://example.org/a.jpg'], 'normal');

        self::assertStringNotContainsString('<form', $html);
        self::assertMatchesRegularExpression('~<input type="text"[^>]*\\[fil\\]\\[url\\]\\[0\\]"~', $html);
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

    public function test_getRawHtml_ajaxDisplayUrlOne_staysUploadOnly(): void
    {
        self::assertStringNotContainsString('type="text"', self::html(new FilWidget(['display_url' => '1']), ['https://example.org/a.jpg'], 'ajax'));
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

    /** @param string[] $values */
    private static function html(FilWidget $widget, array $values, string $type): string
    {
        $content = new AA_Content();
        $content->setOwnerId('5555555555555555aaaaaaaaaaaaaaaa');
        $content->setAaValue('file', new AA_Value($values));
        return $widget->_getRawHtml(new AA_Property('file', 'File'), $content, $type)['html'];
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

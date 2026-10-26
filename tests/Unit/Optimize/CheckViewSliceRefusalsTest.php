<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckViewSliceRefusals;
use AA\Optimize\Finding;
use AA\Security\ViewSlices;
use PHPUnit\Framework\TestCase;

final class CheckViewSliceRefusalsTest extends TestCase
{
    private const SLICE = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), ParamImplode()
    }

    public function test_findingsFor_noRefusals_isOk(): void
    {
        $findings = CheckViewSliceRefusals::findingsFor([], [], [], []);

        $this->assertCount(1, $findings);
        $this->assertSame(Finding::OK, $findings[0]->severity);
    }

    public function test_findingsFor_refusedSlice_isAWarningNamingTheViewAndSlice(): void
    {
        $row = ['selector' => ParamImplode(['42', self::SLICE]), 'days' => '2', 'first' => '1790000000', 'last' => '1790086400', 'params' => 'example.org/news'];

        [$finding] = CheckViewSliceRefusals::findingsFor([$row], [self::SLICE => 'Members'], [], []);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertStringContainsString('View 42', $finding->title);
        $this->assertStringContainsString('Members', $finding->title);
        $this->assertSame(2, $finding->count);
        $this->assertSame($row['selector'], $finding->key);
        $this->assertSame('example.org/news', $finding->evidence[0][_m('Page asked for')]);
        $this->assertSame('View 42', $finding->evidence[0][_m('Listed by')]);
        $this->assertSame('Slice left out', $finding->kind);
    }

    /** a bare view.php?vid= or a banner over the slice is refused too - the advice must not send the reader after slice parameters only */
    public function test_findingsFor_refusedSlice_adviceNamesTheViewCalledOverTheSlice(): void
    {
        $row = ['selector' => ParamImplode(['42', self::SLICE]), 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];

        [$finding] = CheckViewSliceRefusals::findingsFor([$row], [], [], []);

        $this->assertStringStartsWith('The view was called from the URL (view.php, jsview.php, discussion.php), also as a banner of such a view or of slice.php (set[]=banner-..., slice.php?banner=...), over that slice, or its URL parameters', $finding->advice);
    }

    public function test_findingsFor_refusedSlice_linksTheViewAndTheSliceSettings(): void
    {
        $row = ['selector' => ParamImplode(['42', self::SLICE]), 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];

        [$finding] = CheckViewSliceRefusals::findingsFor([$row], [], [42 => '/aa/admin/se_view.php?view_id=42'], []);

        $this->assertSame([
            '/aa/admin/se_view.php?view_id=42',
            AA_INSTAL_PATH . 'admin/slicedit.php?change_id=' . self::SLICE,
        ], array_slice(array_values($finding->links), 0, 2));
    }

    public function test_findingsFor_sliceScript_isNamedAsItIs(): void
    {
        $row = ['selector' => ParamImplode(['slice.php', self::SLICE]), 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];

        [$finding] = CheckViewSliceRefusals::findingsFor([$row], [self::SLICE => 'Members'], [], []);

        $this->assertStringStartsWith('slice.php ', $finding->title);
    }

    /** a condition on related items is no view - the finding says so and advises the template, not the URL parameters */
    public function test_findingsFor_refusedCondition_namesTheConditionAndSlice(): void
    {
        $row = ['selector' => ParamImplode([ViewSlices::CONDITION, self::SLICE]), 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => 'example.org/<b>'];

        [$finding] = CheckViewSliceRefusals::findingsFor([$row], [self::SLICE => 'Messages'], [], []);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertSame('A condition on related items matched nothing in slice Messages: its reading password was not known', $finding->title);
        $this->assertSame($row['selector'], $finding->key);
        $this->assertSame('example.org/&lt;b&gt;', $finding->evidence[0][_m('Page asked for')]);
        $this->assertStringContainsString('{credentials:...}', $finding->advice);
        $this->assertSame('Condition refused', $finding->kind);
        $this->assertArrayNotHasKey(_m('Listed by'), $finding->evidence[0]);
    }

    /** a protected field names the field and advises the presence test, not a password */
    public function test_findingsFor_refusedProtectedField_namesTheFieldAndSlice(): void
    {
        $row = ['selector' => ParamImplode([ViewSlices::PROTECTED_FIELD, self::SLICE, 'password........']), 'days' => '3', 'first' => '1790000000', 'last' => '1790172800', 'params' => 'example.org/readers'];

        [$finding] = CheckViewSliceRefusals::findingsFor([$row], [self::SLICE => 'Readers'], [], []);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertSame('A condition, sort or grouping on field password........ of slice Readers was left out: AA never shows its values', $finding->title);
        $this->assertSame(3, $finding->count);
        $this->assertSame($row['selector'], $finding->key);
        $this->assertSame('password........', $finding->evidence[0][_m('Field')]);
        $this->assertSame('Readers', $finding->evidence[0][_m('Slice')]);
        $this->assertSame('example.org/readers', $finding->evidence[0][_m('Page asked for')]);
        $this->assertStringContainsString('(ISNULL, NOTNULL)', $finding->advice);
        $this->assertStringNotContainsString('{credentials', $finding->advice);
        $this->assertSame('Protected field refused', $finding->kind);
        $this->assertArrayNotHasKey(_m('Listed by'), $finding->evidence[0]);
    }

    public function test_findingsFor_unknownSlice_isNamedByItsId(): void
    {
        $row = ['selector' => ParamImplode(['42', self::SLICE]), 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ''];

        [$finding] = CheckViewSliceRefusals::findingsFor([$row], [], [], []);

        $this->assertStringContainsString(self::SLICE, $finding->title);
    }

    public function test_findingsFor_ignoredCharset_isAWarningNamingTheScriptLatestCharsetsAndLink(): void
    {
        $row = ['selector' => ParamImplode(['view.php', 'convertfrom']), 'days' => '3', 'first' => '1790000000', 'last' => '1790172800',
            'params' => ParamImplode(['koi8-r', 'example.org/aa/view.php', 'example.org/akce/']), 'names' => ['koi8-r', 'utf8']];

        [$finding] = CheckViewSliceRefusals::findingsFor([], [], [], [$row]);

        $this->assertSame(Finding::WARNING, $finding->severity);
        $this->assertSame('view.php ignored a charset in convertfrom', $finding->title);
        $this->assertSame(3, $finding->count);
        $this->assertSame('charset:view.php:convertfrom', $finding->key);
        $this->assertSame('Charset ignored', $finding->kind);
        $this->assertSame('koi8-r, utf8', $finding->evidence[0][_m('Latest charsets')]);
        $this->assertSame('example.org/akce/', $finding->evidence[0][_m('Link on page')]);
        $this->assertStringContainsString('utf-8, windows-1250, ', $finding->advice);
        $this->assertStringNotContainsString('us-ascii', $finding->advice);
    }

    /** the names come from the request - evidence is HTML */
    public function test_findingsFor_ignoredCharset_escapesTheNames(): void
    {
        $row = ['selector' => ParamImplode(['filler.php', 'convertto']), 'days' => '1', 'first' => '1790000000', 'last' => '1790000000', 'params' => ParamImplode(['<b>', '', ''])];

        [$finding] = CheckViewSliceRefusals::findingsFor([], [], [], [$row]);

        $this->assertSame('&lt;b&gt;', $finding->evidence[0][_m('Latest charsets')]);
    }

    /** a row a day keeps the first name of the day - the finding shows the newest ones and the newest page */
    public function test_withLatestNames_takesTheNewestDistinctNamesAndPage(): void
    {
        $selector = ParamImplode(['view.php', 'convertfrom']);
        $other    = ParamImplode(['poll.php', 'convertto']);
        $recent   = [['selector' => $selector, 'days' => '5', 'first' => '1', 'last' => '5', 'params' => ParamImplode(['zzz', 'old', ''])], ['selector' => $other, 'days' => '1', 'first' => '1', 'last' => '1', 'params' => 'x']];
        $log      = [
            ['selector' => $selector, 'params' => ParamImplode(['utf8', 'example.org/new', 'example.org/akce/'])],
            ['selector' => $selector, 'params' => ParamImplode(['zzz', 'example.org/b', ''])],
            ['selector' => $selector, 'params' => ParamImplode(['utf8', 'example.org/c', ''])],
            ['selector' => $selector, 'params' => ParamImplode(['cp1250', 'example.org/d', ''])],
            ['selector' => $selector, 'params' => ParamImplode(['latin2', 'example.org/e', ''])],
        ];

        $rows = CheckViewSliceRefusals::withLatestNames($recent, $log);

        $this->assertSame(['utf8', 'zzz', 'cp1250'], $rows[0]['names']);
        $this->assertSame(ParamImplode(['utf8', 'example.org/new', 'example.org/akce/']), $rows[0]['params']);
        $this->assertSame($recent[1], $rows[1], 'a row without log rows stays as it was');
    }
}

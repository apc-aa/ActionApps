<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA\Security\UrlValue;
use AA\Util\LogOnce;
use AA_Aliases;
use AA_Showview;
use AA_View;
use itemview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;
use zids;

/**
 * The text a view shows when it finds nothing: noitem given to the view (set[<vid>]=noitem-...), else its
 * own message. Both are templates, except a noitem in the URL of view.php, jsview.php or discussion.php -
 * that one is shown as text (AA\Security\UrlValue), unless the protection is off and it holds {...}.
 */
final class ViewNoitemTest extends TestCase
{
    private const VID = 32;

    public static function setUpBeforeClass(): void
    {
        // loading locsess.php warns about the missing web request; a separate process would report it as the test's
        set_error_handler(static fn(): bool => true);
        try {
            require_once __DIR__ . '/../fixtures/util_shims.php';   // safe(), _m()
            require_once __DIR__ . '/fixtures/store_fixtures.php';   // RecordingDb - the protection switch and its log
        } finally {
            restore_error_handler();
        }
    }

    protected function setUp(): void
    {
        RecordingDb::reset();
        LogOnce::reset();
        $GLOBALS['spareDBs'] = [new RecordingDb()];
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        unset($GLOBALS['QueryIDsCount']);
        RecordingDb::reset();
        LogOnce::reset();
    }

    /** AA_Showview::noitemMsg() of a view with this own message, called with these parameters */
    private static function noitem(array $view_param, ?string $own_msg): string
    {
        $showview = new class ($view_param) extends AA_Showview {
            public function noitem(AA_View $view): string
            {
                return $this->noitemMsg($view);
            }
        };
        return $showview->noitem(new AA_View(self::VID, ['id' => (string) self::VID, 'noitem_msg' => $own_msg]));
    }

    #[DataProvider('templateNoitems')]
    public function test_noitemMsg_ofATemplateCall_isTheTemplateGiven(string $noitem): void
    {
        self::assertSame($noitem, self::noitem(['noitem' => $noitem], 'own message'));
    }

    public static function templateNoitems(): array
    {
        return [
            'expression'      => ['{date:Y}'],
            'html'            => ['<b>none</b>'],
            'html comment'    => ['<!-- -->'],
            'alias'           => ['_#HEADLINE'],
            'empty - nothing' => [''],
        ];
    }

    #[DataProvider('ownMessages')]
    public function test_noitemMsg_withoutNoitem_isTheOwnMessage(?string $own_msg, string $expected, bool $from_request): void
    {
        self::assertSame($expected, self::noitem(['from_request' => $from_request], $own_msg));
    }

    public static function ownMessages(): array
    {
        return [
            'template'                => ['<p>{date:Y}</p>', '<p>{date:Y}</p>', false],
            'template, url view'      => ['<p>{date:Y}</p>', '<p>{date:Y}</p>', true],
            'vacuum'                  => ['<!--Vacuum-->', '', false],
            'vacuum around text'      => ['a<!--Vacuum-->b', 'ab', true],
            'zero is a message'       => ['0', '0', false],
            'empty - the default'     => ['', '<div>No item found</div>', false],
            'none - the default'      => [null, '<div>No item found</div>', true],
        ];
    }

    #[DataProvider('urlNoitems')]
    public function test_noitemMsg_fromUrl_isText(string $noitem, string $expected): void
    {
        self::assertSame($expected, self::noitem(['noitem' => $noitem, 'from_request' => true], 'own message'));
        self::assertSame([], RecordingDb::$log, 'text needs neither the protection switch nor a log row');
    }

    public static function urlNoitems(): array
    {
        return [
            'html'            => ['<b>none</b>', '&lt;b&gt;none&lt;/b&gt;'],
            'html comment'    => ['<!-- -->', '&lt;!-- --&gt;'],
            'text'            => ['Nic tu není', 'Nic tu není'],
            'alias'           => ['_#HEADLINE', '_&#35;HEADLINE'],
            'empty - nothing' => ['', ''],
        ];
    }

    /** an expression goes to the switch of the install and is logged for State Check - one process per switch, Settings loads once */
    #[DataProvider('switches')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_noitemMsg_fromUrlWithExpression_runsOnlyWithTheProtectionOff(bool $off, string $expected, string $outcome): void
    {
        $_SERVER['HTTP_HOST']   = 'example.org';
        $_SERVER['REQUEST_URI'] = '/aa/view.php?vid=' . self::VID . '&set[' . self::VID . ']=noitem-x';
        RecordingDb::$answer = static fn(string $sql): array => match (true) {
            str_contains($sql, '`object_text`') => $off ? [['tbl' => 'object_text', 'property' => 'off', 'value' => 'url_values_as_text', 'priority' => 0]] : [],
            str_contains($sql, 'count(*)')      => [['cnt' => 0]],
            default                             => [],
        };

        self::assertSame($expected, self::noitem(['noitem' => '<b>{date:Y}</b> _#HEADLINE', 'from_request' => true], 'own message'));
        $logged = RecordingDb::inserts('log');
        self::assertCount(1, $logged);
        self::assertSame(UrlValue::EVENT, $logged[0]['type']);
        self::assertSame($outcome . ':set[' . self::VID . ']=noitem-:example.org/aa/view.php', $logged[0]['selector']);
    }

    public static function switches(): array
    {
        return [
            'protection on'  => [false, '&lt;b&gt;&#123;date:Y&#125;&lt;/b&gt; _&#35;HEADLINE', 'refused'],
            'protection off' => [true, '<b>{date:Y}</b> _#HEADLINE', 'allowed'],
        ];
    }

    /** als[] of the same URL puts any HTML into its alias - the noitem from the URL never reaches it */
    public function test_noitemMsg_fromUrl_expandsNoAliasOfTheUrl(): void
    {
        $aliases = new AA_Aliases();
        $aliases->addFromArray(AA_View::getAliasesFromUrl(['ZZZZZZZZ' => '<img src=x>']));
        $GLOBALS['QueryIDsCount'] = 0;   // itemview reads it - the empty listing a noitem text is shown for
        $listing = new itemview(['group_by' => '', 'selected_item' => ''], $aliases, new zids([], 's'), 0, 1);

        self::assertSame('none _&#35;ZZZZZZZZ', $listing->unaliasWithScroller(self::noitem(['noitem' => 'none _#ZZZZZZZZ', 'from_request' => true], 'own message')));
        self::assertSame('none <img src=x>', $listing->unaliasWithScroller(self::noitem(['noitem' => 'none _#ZZZZZZZZ'], 'own message')), 'a template call expands it');
    }

    public function test_noitemMsg_ofATemplateCall_neverAsksTheSwitch(): void
    {
        self::assertSame('<b>{date:Y}</b>', self::noitem(['noitem' => '<b>{date:Y}</b>'], 'own message'));
        self::assertSame([], RecordingDb::$log);
    }
}

<?php

declare(strict_types=1);

/**
 * The searchbar filters by GET (a link may filter), but storing, renaming and deleting a saved
 * filter writes - only a POST of this site does it. A link (prefetch, crawler, <img src>) must not.
 */

namespace Tests\Unit\Legacy;

use AA;
use AA\Managed_Table;
use AA_Bookmarks;
use AA_Searchbar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\RecordingDb;
use Tests\Unit\Legacy\Fixtures\SearchlessManager;

final class SearchbarBookmarkWriteTest extends TestCase
{
    /** @var list<string> the bookmark writes, as 'method:argument' */
    public static array $writes = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/fixtures/object_form_fixtures.php';
    }

    protected function setUp(): void
    {
        self::$writes = [];
        $GLOBALS['spareDBs'] = [new RecordingDb()];
        $GLOBALS['permission_uid'] = AuthGetUid();
        AA::$module_id = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
        $GLOBALS['permission_to']  = ['aa' => [AA_ID => ''], 'slice' => [AA::$module_id => '']];   // no right to shared filters
    }

    protected function tearDown(): void
    {
        $GLOBALS['spareDBs'] = [];
        [$_GET, $_POST, $_REQUEST] = [[], [], []];
        unset($_SERVER['HTTP_SEC_FETCH_SITE'], $GLOBALS['r_state'], $GLOBALS['permission_uid'], $GLOBALS['permission_to']);
        AA::$module_id = null;
    }

    /** a searchbar whose stored filters only note the writes; filter 0 exists */
    private static function searchbar(): AA_Searchbar
    {
        $fields = new class {
            public function getOrderArray(): array
            {
                return [];
            }
        };
        $bookmarks = new class (null) extends AA_Bookmarks {
            protected array $bookmarks = [['name' => 'Stored one', 'state' => [], 'type' => '', 'id' => '1', 'uid' => 'u']];

            public function is_defined($key): bool
            {
                return (string) $key === '0';
            }

            public function store($name, array $state, $to_global): bool
            {
                SearchbarBookmarkWriteTest::$writes[] = "store:$name";
                return true;
            }

            public function delete($key): bool
            {
                SearchbarBookmarkWriteTest::$writes[] = "delete:$key";
                return true;
            }

            public function renameBookmark($key, $newname): bool
            {
                SearchbarBookmarkWriteTest::$writes[] = "rename:$newname";
                return true;
            }

            public function updateBookmark($key, $state): bool
            {
                SearchbarBookmarkWriteTest::$writes[] = "update:$key";
                return true;
            }
        };
        $searchbar = new AA_Searchbar(null, $fields, 'filterform');
        (fn() => $this->bookmarks = $bookmarks)->call($searchbar);
        return $searchbar;
    }

    /** @return array<string, array{string}> */
    public static function writeActions(): array
    {
        return [
            'store'  => ['bookmark:My filter'],
            'update' => ['bookmarkupdate'],
            'rename' => ['bookmarkrename:New name'],
            'delete' => ['bookmarkdelete'],
        ];
    }

    #[DataProvider('writeActions')]
    public function test_update_aWriteByGetDoesNothing(string $akce): void
    {
        $_GET = ['srchbr_akce' => $akce, 'srchbr_bookmark' => '0'];

        $this->assertFalse(self::searchbar()->update(true));
        $this->assertSame([], self::$writes);
    }

    #[DataProvider('writeActions')]
    public function test_update_aWriteByPostOfThisSiteWrites(string $akce): void
    {
        $_POST = ['srchbr_akce' => $akce, 'srchbr_bookmark' => '0'];

        $this->assertTrue(self::searchbar()->update(true));
        $this->assertCount(1, self::$writes);
    }

    #[DataProvider('writeActions')]
    public function test_update_aWriteByPostOfAnotherSiteDoesNothing(string $akce): void
    {
        $_POST = ['srchbr_akce' => $akce, 'srchbr_bookmark' => '0'];

        $this->assertFalse(self::searchbar()->update(false));
        $this->assertSame([], self::$writes);
    }

    public function test_update_storesTheFilterOfThePostedForm(): void
    {
        $_GET  = ['srchbr_field' => ['other...........'], 'srchbr_oper' => ['LIKE'], 'srchbr_value' => ['value-from-url-7Qx']];
        $_POST = ['srchbr_akce' => 'bookmark:Mine', 'srchbr_field' => ['headline........'], 'srchbr_oper' => ['LIKE'], 'srchbr_value' => ['value-posted-3Kz']];
        $searchbar = self::searchbar();

        $this->assertTrue($searchbar->update(true));
        $this->assertSame(['store:Mine'], self::$writes);
        $this->assertStringContainsString('value-posted-3Kz', serialize($searchbar->getState()));
        $this->assertStringNotContainsString('value-from-url-7Qx', serialize($searchbar->getState()));
    }

    /** templates link srchbr_akce=1 with the search - a link filters, even from another site */
    public function test_update_aLinkStillFilters(): void
    {
        $_GET = ['srchbr_akce' => '1', 'srchbr_field' => ['headline........'], 'srchbr_oper' => ['LIKE'], 'srchbr_value' => ['linked']];
        $searchbar = self::searchbar();

        $this->assertTrue($searchbar->update(false));
        $this->assertSame([], self::$writes);
        $this->assertStringContainsString('linked', serialize($searchbar->getState()));
    }

    public function test_getManageList_opensThePanelOnRequest(): void
    {
        $bookmarks = (fn() => $this->bookmarks)->call(self::searchbar());

        $this->assertStringContainsString('<details class="aa-save-filters relative pr-3" open>', $bookmarks->getManageList('filterform', true));
        $this->assertStringContainsString('<details class="aa-save-filters relative pr-3">', $bookmarks->getManageList('filterform', false));
        $this->assertStringContainsString('Stored one', $bookmarks->getManageList('filterform', false));
    }

    /** right after a rename or delete the panel stays open; a plain filter closes it */
    public function test_update_leavesThePanelOpenAfterARename(): void
    {
        $panel = static fn(AA_Searchbar $searchbar): string => (fn() => $this->get_bar_bookmarks())->call($searchbar);

        $_POST     = ['srchbr_akce' => 'bookmarkrename:Other', 'srchbr_bookmark' => '0'];
        $renamed   = self::searchbar();
        $renamed->update(true);
        $_POST     = [];
        $_GET      = ['srchbr_akce' => '1'];
        $filtered  = self::searchbar();
        $filtered->update(true);

        $this->assertStringContainsString('relative pr-3" open>', $panel($renamed));
        $this->assertStringNotContainsString('relative pr-3" open>', $panel($filtered));
    }

    /** the Manager page decides "of this site" once, for its actions and for the searchbar */
    private static function managerPost(string $fetch_site): string
    {
        $GLOBALS['r_state'] = ['manager' => ['test' => null]];
        $managed = new Managed_Table('view');
        $managed->setSettings(array_merge($managed->getSettings(), ['module_id' => null, 'actions' => null, 'actions_hint' => '', 'actions_hint_url' => '', 'switches' => null, 'searchbar' => null, 'use_profile' => false, 'scroller' => ['listlen' => 10]]));
        $manager   = new SearchlessManager('test', $managed);
        $searchbar = self::searchbar();
        (fn() => $this->searchbar = $searchbar)->call($manager);

        $_SERVER['HTTP_SEC_FETCH_SITE'] = $fetch_site;
        $_GET     = ['scr_st_Go' => '', 'scr_st_Mv' => ''];   // the scroller reads them unguarded
        $_POST    = ['srchbr_akce' => 'bookmarkdelete', 'srchbr_bookmark' => '0'];
        $_REQUEST = $_POST;
        $manager->performActions();
        return (string) $manager->getAndClearMessages();
    }

    public function test_performActions_deletesAFilterPostedByThisSite(): void
    {
        $msg = self::managerPost('same-origin');

        $this->assertSame(['delete:0'], self::$writes);
        $this->assertStringNotContainsString('use the button on the page', $msg);
    }

    public function test_performActions_refusesAFilterPostedByAnotherSite(): void
    {
        $msg = self::managerPost('same-site');

        $this->assertSame([], self::$writes);
        $this->assertStringContainsString('use the button on the page', $msg);
    }
}

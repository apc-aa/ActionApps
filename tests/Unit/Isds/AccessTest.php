<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Access;
use AA_Perm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Isds\Fixtures\FakePerms;
use Tests\Unit\Isds\Fixtures\Sending;

// data providers read its ids before setUpBeforeClass()
require_once __DIR__ . '/fixtures/Sending.php';

/**
 * Who may use a mass sending is a right of AA - edit all items of the campaign slice - read from
 * the perm storage on every page, for a user of AA and a reader alike.
 */
final class AccessTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';
        require_once __DIR__ . '/fixtures/FakePerms.php';
    }

    protected function tearDown(): void
    {
        FakePerms::uninstall();
    }

    /** the id AA stores for the role - perm_core.php loads with the first test, not before */
    private static function role(string $role): string
    {
        return AA_Perm::ROLES[$role]['id'];
    }

    /** @return array<string, list<string>> the reader is in the reader slice and in a set of its readers */
    private static function readerInSet(): array
    {
        return [Sending::READER => [Sending::READERS, Sending::SET]];
    }

    /** @return array<string, array{string}> */
    public static function nobody(): array
    {
        return ['not logged in' => [''], 'nobody' => ['nobody']];
    }

    #[DataProvider('nobody')]
    public function test_may_notLoggedIn(string $uid): void
    {
        FakePerms::install([$uid => [Sending::CAMPAIGN => self::role('EDITOR'), Sending::SITE => PS_MODW_EDIT_CODE]]);
        $access = FakePerms::login($uid);

        self::assertFalse($access->loggedIn());
        self::assertFalse($access->may(Sending::CAMPAIGN));
        self::assertFalse($access->mayFix(Sending::SITE, Sending::CAMPAIGN));
    }

    public function test_may_readerThroughASet(): void
    {
        FakePerms::install([Sending::SET => [Sending::CAMPAIGN => self::role('EDITOR')]], self::readerInSet());

        self::assertTrue(FakePerms::login(Sending::READER)->may(Sending::CAMPAIGN));
        self::assertFalse(FakePerms::login(Sending::READER)->may(Sending::MESSAGE), 'the right is on the campaign slice');
    }

    public function test_may_readerOutsideTheSet(): void
    {
        FakePerms::install([Sending::SET => [Sending::CAMPAIGN => self::role('EDITOR')]], [Sending::READER => [Sending::READERS]]);

        self::assertFalse(FakePerms::login(Sending::READER)->may(Sending::CAMPAIGN));
    }

    // what the docblock of Access warns of: perm_sql.php lets the own grant win
    public function test_may_readersOwnLowerGrantReplacesTheSet(): void
    {
        FakePerms::install([Sending::SET => [Sending::CAMPAIGN => self::role('EDITOR')], Sending::READER => [Sending::CAMPAIGN => self::role('AUTHOR')]], self::readerInSet());

        self::assertFalse(FakePerms::login(Sending::READER)->may(Sending::CAMPAIGN));
    }

    public function test_may_userOfAa(): void
    {
        FakePerms::install([Sending::USER => [Sending::CAMPAIGN => PS_EDIT_ALL_ITEMS]]);

        self::assertTrue(FakePerms::login(Sending::USER)->may(Sending::CAMPAIGN));
    }

    public function test_may_userWithoutTheRight(): void
    {
        FakePerms::install([Sending::USER => [Sending::CAMPAIGN => self::role('AUTHOR'), Sending::MESSAGE => self::role('EDITOR'), Sending::SITE => PS_MODW_EDIT_CODE]]);
        $access = FakePerms::login(Sending::USER);

        self::assertTrue($access->loggedIn());
        self::assertFalse($access->may(Sending::CAMPAIGN), 'an author edits only own items');
        self::assertFalse(FakePerms::login('cn=honza,ou=People,o=ecn')->may(Sending::CAMPAIGN));
        self::assertFalse($access->may(''));
    }

    // no right cached in the session: taking it away counts on the next page
    public function test_may_rightTakenAway(): void
    {
        $perms  = FakePerms::install([Sending::USER => [Sending::CAMPAIGN => self::role('EDITOR')]]);
        $access = FakePerms::login(Sending::USER);
        self::assertTrue($access->may(Sending::CAMPAIGN));

        $perms->grants = [];

        self::assertFalse($access->may(Sending::CAMPAIGN));
    }

    // the right the Item Manager asks to move an item to the trash: an editor has it, sending alone gives none
    public function test_mayTrash_editorOrTheLetterOnTheCampaignSlice(): void
    {
        FakePerms::install([
            Sending::USER => [Sending::CAMPAIGN => PS_EDIT_ALL_ITEMS, Sending::MESSAGE => PS_ITEMS2TRASH],
            'trasher'     => [Sending::CAMPAIGN => PS_EDIT_ALL_ITEMS . PS_ITEMS2TRASH],
            Sending::SET  => [Sending::CAMPAIGN => self::role('EDITOR')],
        ], self::readerInSet());

        self::assertTrue(FakePerms::login(Sending::USER)->may(Sending::CAMPAIGN));
        self::assertFalse(FakePerms::login(Sending::USER)->mayTrash(Sending::CAMPAIGN), 'the right is on another slice');
        self::assertTrue(FakePerms::login('trasher')->mayTrash(Sending::CAMPAIGN));
        self::assertTrue(FakePerms::login(Sending::READER)->mayTrash(Sending::CAMPAIGN), 'an editor through a set');
        self::assertFalse(FakePerms::login('nobody')->mayTrash(Sending::CAMPAIGN));
    }

    public function test_mayFix_whoMaySendOrEditTheCodeOfTheSite(): void
    {
        FakePerms::install([Sending::USER => [Sending::CAMPAIGN => self::role('EDITOR')], 'coder' => [Sending::SITE => PS_MODW_EDIT_CODE]]);

        self::assertTrue(FakePerms::login(Sending::USER)->mayFix(Sending::SITE, Sending::CAMPAIGN));
        self::assertFalse(FakePerms::login(Sending::USER)->mayFix(Sending::SITE, ''), 'settings naming no campaign slice');
        self::assertTrue(FakePerms::login('coder')->mayFix(Sending::SITE, ''));
        self::assertTrue(FakePerms::login('coder')->mayFix(Sending::SITE, Sending::OTHER));
        self::assertFalse(FakePerms::login('coder')->may(Sending::CAMPAIGN), 'fixing is not sending');
        self::assertFalse(FakePerms::login('17x')->mayFix(Sending::SITE, Sending::CAMPAIGN));
    }

    // a batch run asks it for whoever lists, continues or ends the run - there is no session to ask
    public function test_userMay_judgesTheUserGiven(): void
    {
        FakePerms::install([Sending::USER => [Sending::CAMPAIGN => self::role('EDITOR')], 'nobody' => [Sending::CAMPAIGN => self::role('EDITOR')]], [], ['admin' => self::role('SUPER')]);

        self::assertTrue(Access::userMay(Sending::USER, Sending::CAMPAIGN));
        self::assertFalse(Access::userMay(Sending::READER, Sending::CAMPAIGN));
        self::assertFalse(Access::userMay('nobody', Sending::CAMPAIGN), 'not logged in, whatever it was granted');
        self::assertFalse(Access::userMay('', Sending::CAMPAIGN));
        self::assertTrue(Access::userMay('admin', Sending::CAMPAIGN), 'a superadmin may anything');
        self::assertFalse(Access::userMay('admin', ''), 'no campaign slice, nothing to send - even for a superadmin');
    }
}

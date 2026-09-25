<?php

declare(strict_types=1);

/**
 * ItemLint on a batch against the target slice: a reader login of a user of the target stops the batch
 * (E); logins or e-mails the target has, several or twice are reported (W); accounts and 2FA seeds (I, W);
 * subscriptions to filters outside the package (I), values of fields the target slice lacks (W).
 * No DB - a fake target.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\FindingList;
use AA\Central\Package\IdMap;
use AA\Central\Package\ItemLint;
use AA\Central\Package\Model\Module;
use AA\Central\Package\TargetSlice;
use AA_Metabase;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeImportTarget;
use Tests\Unit\Central\Package\Fixtures\PackageMutations;

require_once __DIR__ . '/fixtures/PackageMutations.php';

final class ItemLintTest extends TestCase
{
    use PackageMutations;

    private const READER_1 = '1234567890abcdef1234567890abcdef';
    private const READER_2 = 'fedcba0987654321fedcba0987654321';

    public static function setUpBeforeClass(): void
    {
        self::bootAa();
    }

    /** @param array<string, string|list<string>> $values field => value(s) */
    private static function reader(string $id, array $values): array
    {
        $content = ['status_code.....' => [0 => ['value' => '1', 'flag' => 1]]];
        foreach ($values as $field => $list) {
            foreach ((array) $list as $i => $value) {
                $content[$field][$i] = ['value' => $value, 'flag' => 0];
            }
        }
        return ['id' => $id, 'content' => $content];
    }

    /** the slice on the target, as its rows are now - the batch carries none */
    private static function slice(bool $readers = true, string $charset = 'utf-8'): TargetSlice
    {
        $fields = array_fill_keys(['headline........', 'con_email.......', 'secret..........', 'relation.......1', 'alerts2....K0001'], [true, false]);
        return new TargetSlice(self::id(2), $readers, $charset, ['password........' => [true, true]] + $fields);
    }

    /** "<level> <code> <path> <ref>" of a batch of $items, sorted @param list<array<string, mixed>> $items @return list<string> */
    private static function findings(array $items, ?FakeImportTarget $target = null, ?TargetSlice $slice = null): array
    {
        $found = new FindingList();
        $map   = (new IdMap(collections: ['AbC12' => 'K0001']))->withNumbers('filter', [3 => 3000]);
        (new ItemLint($target ?? new FakeImportTarget(), AA_Metabase::singleton()))->check(new Module(Module::SLICE, self::SLICE, [], items: $items), $slice ?? self::slice(), $map, $found);
        $list = array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0] {$f[1]->ref}", $found->all());
        sort($list);
        return $list;
    }

    public function test_readers_whose_login_or_e_mail_the_target_has_are_warnings(): void
    {
        $target          = new FakeImportTarget();
        $target->readers = [ItemLint::USERNAME => ['jana' => false], ItemLint::EMAIL => ['petr@example.org' => false]];

        $found = self::findings([
            self::reader(self::READER_1, ['headline........' => 'jana', 'con_email.......' => 'jana@example.org', 'password........' => '$2y$10$hash1']),
            self::reader(self::READER_2, ['headline........' => 'petr', 'con_email.......' => 'petr@example.org', 'password........' => '$2y$10$hash2', 'secret..........' => 'JBSWY3DPEHPK3PXP']),
        ], $target);

        $S = 'slice-module[' . self::SLICE . ']';
        self::assertSame([
            "I reader-accounts $S 2",
            "W reader-2fa $S 1",
            "W reader-taken $S/item[" . self::READER_1 . ']/headline........[0] jana',
            "W reader-taken $S/item[" . self::READER_2 . ']/con_email.......[0] petr@example.org',
        ], $found);
        self::assertSame(2, $target->readerQueries, 'once per field, not per reader');
    }

    /** a reader with AA permissions: the batch's reader would log in to them */
    public function test_a_login_of_a_target_reader_holding_permissions_is_an_error(): void
    {
        $target          = new FakeImportTarget();
        $target->readers = [ItemLint::USERNAME => ['Jana' => true], ItemLint::EMAIL => ['jana@example.org' => true]];

        $found = self::findings([self::reader(self::READER_1, ['headline........' => 'jana ', 'con_email.......' => 'JANA@example.org'])], $target);

        $at = 'slice-module[' . self::SLICE . ']/item[' . self::READER_1 . ']';
        self::assertSame(["E reader-perms $at/con_email.......[0] JANA@example.org", "E reader-perms $at/headline........[0] jana "], $found);
    }

    /** the target keeps rights or content rows for the id - the new item would inherit them */
    public function test_an_item_id_the_target_uses_is_an_error(): void
    {
        $target        = new FakeImportTarget();
        $target->inUse = [self::READER_2];

        $found = self::findings([self::reader(self::READER_1, []), self::reader(self::READER_2, [])], $target, self::slice(false));

        self::assertSame(['E item-id-in-use slice-module[' . self::SLICE . ']/item[' . self::READER_2 . '] ' . self::READER_2], $found);
    }

    /** a user's login - the reader's password hash would open the user's account */
    public function test_a_reader_login_of_a_user_of_the_target_is_an_error(): void
    {
        $target         = new FakeImportTarget();
        $target->admins = ['editor', 'office@example.org'];

        $found = self::findings([
            self::reader(self::READER_1, ['headline........' => 'Editor ', 'con_email.......' => 'office@example.org']),
            self::reader(self::READER_2, ['headline........' => 'office@example.org']),
        ], $target);

        $S = 'slice-module[' . self::SLICE . ']';
        self::assertSame([
            "E reader-admin $S/item[" . self::READER_1 . ']/headline........[0] Editor ',        // case and trailing spaces as the login lookup
            "E reader-admin $S/item[" . self::READER_2 . ']/headline........[0] office@example.org',   // a user logs in by his e-mail too
        ], $found, 'the e-mail field of a reader is no user login');
    }

    /** every value is asked - the login lookup matches any of them */
    public function test_a_login_or_e_mail_with_more_values_is_a_warning_and_every_value_is_asked(): void
    {
        $target          = new FakeImportTarget();
        $target->readers = [ItemLint::USERNAME => ['druhy' => false], ItemLint::EMAIL => ['b@example.org' => false]];

        $found = self::findings([self::reader(self::READER_1, ['headline........' => ['prvni', 'druhy'], 'con_email.......' => ['a@example.org', 'b@example.org']])], $target);

        $at = 'slice-module[' . self::SLICE . ']/item[' . self::READER_1 . ']';
        self::assertSame([
            "W reader-multivalue $at/con_email....... 2",
            "W reader-multivalue $at/headline........ 2",
            "W reader-taken $at/con_email.......[1] b@example.org",
            "W reader-taken $at/headline........[1] druhy",
        ], $found);
    }

    /** two accounts of one login (or e-mail) in the batch - case and trailing spaces as the collation */
    public function test_a_login_or_e_mail_twice_in_the_batch_is_a_warning(): void
    {
        $found = self::findings([
            self::reader(self::READER_1, ['headline........' => 'Jana', 'con_email.......' => 'j@example.org']),
            self::reader(self::READER_2, ['headline........' => 'jana ', 'con_email.......' => 'J@example.org']),
        ]);

        $at = 'slice-module[' . self::SLICE . ']/item[' . self::READER_2 . ']';
        self::assertSame(["W reader-duplicate $at/con_email.......[0] J@example.org", "W reader-duplicate $at/headline........[0] jana "], $found);
    }

    /** a non-UTF-8 target slice stores the login in its charset - the target is asked in that charset */
    /** an 8-bit slice: its readers are asked in its charset, the users of AA in UTF-8 */
    public function test_readers_are_asked_in_the_charset_of_the_slice_and_users_in_utf8(): void
    {
        $target          = new FakeImportTarget();
        $target->admins  = ['žofie'];
        $target->readers = [ItemLint::USERNAME => [(string) iconv('UTF-8', 'windows-1250', 'Petrů') => false]];

        $found = self::findings([
            self::reader(self::READER_1, ['headline........' => 'Žofie']),
            self::reader(self::READER_2, ['headline........' => 'Petrů']),
        ], $target, self::slice(true, 'windows-1250'));

        $S = 'slice-module[' . self::SLICE . ']';
        self::assertSame(["E reader-admin $S/item[" . self::READER_1 . ']/headline........[0] Žofie', "W reader-taken $S/item[" . self::READER_2 . ']/headline........[0] Petrů'], $found);
    }

    public function test_free_logins_give_the_count_of_accounts(): void
    {
        $found = self::findings([
            self::reader(self::READER_1, ['headline........' => 'jana', 'password........' => '$2y$10$hash1']),
            self::reader(self::READER_2, ['headline........' => 'no-password']),
        ]);

        self::assertSame(['I reader-accounts slice-module[' . self::SLICE . '] 1'], $found);
    }

    public function test_logins_are_asked_only_for_a_reader_management_slice(): void
    {
        $target         = new FakeImportTarget();
        $target->admins = ['jana'];

        self::assertSame([], self::findings([self::reader(self::READER_1, ['headline........' => 'jana', 'password........' => 'x'])], $target, self::slice(false)), 'a headline of an article is no login');
    }

    /** subscriptions to filters of no package - and fields by the target slice, as the batch has none */
    public function test_subscriptions_to_filters_outside_the_package_and_fields_the_target_slice_lacks(): void
    {
        $found = self::findings([self::reader(self::READER_2, ['headline........' => 'b', 'alerts2....AbC12' => ['f3', 'f99'], 'nofield........1' => 'x', 'status_code.....' => '1'])], null, self::slice(false));

        $at = 'slice-module[' . self::SLICE . ']/item[' . self::READER_2 . ']';
        self::assertSame([
            "I alerts-filter-kept $at/alerts2....AbC12[1] f99",       // filter 3 is the package's
            "W item-field $at/nofield........1 nofield........1",    // status_code: a column of item
        ], $found);
    }
}

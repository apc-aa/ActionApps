<?php

declare(strict_types=1);

/**
 * ItemLint: readers whose login or e-mail the target has (E - the direct insert skips isUsernameFree()),
 * accounts and 2FA seeds (I, W), references leaving the package (W, I), column limits (E). No DB - a fake target.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\ImportPlan;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Planner;
use AA\Central\Package\Validator;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\FakeImportTarget;
use Tests\Unit\Central\Package\Fixtures\PackageMutations;

require_once __DIR__ . '/fixtures/PackageMutations.php';

final class ItemLintTest extends TestCase
{
    use PackageMutations;

    private const READER_1 = '1234567890abcdef1234567890abcdef';
    private const READER_2 = 'fedcba0987654321fedcba0987654321';
    private const OUTSIDE  = '0123456789abcdef0123456789abcdef';

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

    /** the example's slice as a Reader Management slice with reader fields, and $items */
    private static function readers(array $items, string $type = 'ReaderManagement'): Package
    {
        $package = self::cell(self::example(), self::SLICE, 'slice', 0, ['type' => $type]);
        $package = self::rows($package, self::SLICE, 'field', static fn(array $rows): array => [...$rows,
            ['id' => 'con_email.......', 'type' => 'con_email', 'name' => 'E-mail'],
            ['id' => 'password........', 'type' => 'password', 'name' => 'Password', 'input_insert_func' => 'pwd:'],
            ['id' => 'secret..........', 'type' => 'secret', 'name' => '2FA'],
            ['id' => 'relation.......1', 'type' => 'relation', 'name' => 'Related', 'input_insert_func' => 'ids:'],
            ['id' => 'alerts2....AbC12', 'type' => 'alerts', 'name' => 'Filters'],
        ]);
        return self::items($package, $items);
    }

    private static function plan(Package $package, ?FakeImportTarget $target = null): ImportPlan
    {
        $validation = (new Validator())->validate($package, $package->withItems);     // items come only in a transfer
        self::assertTrue($validation->importable(), json_encode(array_map(static fn(array $f): string => "{$f[1]->code} $f[0]", $validation->ofLevel(LintFinding::ERROR))));
        return (new Planner($target ?? new FakeImportTarget()))->plan($package, $validation);
    }

    /** "<level> <code> <path> <ref>" of the item findings, sorted @return list<string> */
    private static function findings(ImportPlan $plan): array
    {
        $codes = ['reader-taken', 'reader-multivalue', 'reader-duplicate', 'reader-accounts', 'reader-2fa', 'item-outside', 'alerts-filter-kept', 'too-long'];
        $list  = array_filter($plan->findings, static fn(array $f): bool => in_array($f[1]->code, $codes, true));
        $list  = array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0] {$f[1]->ref}", $list);
        sort($list);
        return $list;
    }

    public function test_readers_whose_login_or_e_mail_the_target_has_are_errors(): void
    {
        $target            = new FakeImportTarget();
        $target->usernames = ['jana'];
        $target->emails    = ['petr@example.org'];
        $plan              = self::plan(self::readers([
            self::reader(self::READER_1, ['headline........' => 'jana', 'con_email.......' => 'jana@example.org', 'password........' => '$2y$10$hash1']),
            self::reader(self::READER_2, ['headline........' => 'petr', 'con_email.......' => 'petr@example.org', 'password........' => '$2y$10$hash2', 'secret..........' => 'JBSWY3DPEHPK3PXP']),
        ]), $target);

        $S = 'slice-module[' . self::SLICE . ']';
        self::assertSame([
            "E reader-taken $S/item[" . self::READER_1 . ']/headline........[0] jana',
            "E reader-taken $S/item[" . self::READER_2 . ']/con_email.......[0] petr@example.org',
            'I reader-accounts meta/contents 2',
            'W reader-2fa meta/contents 1',
        ], self::findings($plan));
        self::assertFalse($plan->importable(), 'never written');
    }

    /** every value is asked - the login lookup matches any of them; more than one is an E of its own */
    public function test_a_login_or_e_mail_with_more_values_is_an_error_and_every_value_is_asked(): void
    {
        $target            = new FakeImportTarget();
        $target->usernames = ['druhy'];
        $target->emails    = ['b@example.org'];
        $plan              = self::plan(self::readers([
            self::reader(self::READER_1, ['headline........' => ['prvni', 'druhy'], 'con_email.......' => ['a@example.org', 'b@example.org']]),
        ]), $target);

        $at = 'slice-module[' . self::SLICE . ']/item[' . self::READER_1 . ']';
        self::assertSame([
            "E reader-multivalue $at/con_email....... 2",
            "E reader-multivalue $at/headline........ 2",
            "E reader-taken $at/con_email.......[1] b@example.org",
            "E reader-taken $at/headline........[1] druhy",
        ], self::findings($plan));
    }

    /** two accounts of one login (or e-mail) in the package - case and trailing spaces as the collation */
    public function test_a_login_or_e_mail_twice_in_the_package_is_an_error(): void
    {
        $plan = self::plan(self::readers([
            self::reader(self::READER_1, ['headline........' => 'Jana', 'con_email.......' => 'j@example.org']),
            self::reader(self::READER_2, ['headline........' => 'jana ', 'con_email.......' => 'J@example.org']),
        ]));

        $at = 'slice-module[' . self::SLICE . ']/item[' . self::READER_2 . ']';
        self::assertSame(["E reader-duplicate $at/con_email.......[0] J@example.org", "E reader-duplicate $at/headline........[0] jana "], self::findings($plan));
    }

    /** a non-UTF-8 target slice stores the login in its charset - the target is asked in that charset */
    public function test_the_target_is_asked_in_the_charset_of_the_slice(): void
    {
        $target            = new FakeImportTarget();
        $target->usernames = [(string) iconv('UTF-8', 'windows-1250', 'Žofie')];
        $package           = self::lang(self::readers([self::reader(self::READER_1, ['headline........' => 'Žofie'])]), self::SLICE, 'cz_news_lang.php');

        $plan = self::plan($package, $target);

        self::assertSame(['E reader-taken slice-module[' . self::SLICE . ']/item[' . self::READER_1 . ']/headline........[0] Žofie'], self::findings($plan));
    }

    public function test_free_logins_plan_with_the_count_of_accounts(): void
    {
        $plan = self::plan(self::readers([
            self::reader(self::READER_1, ['headline........' => 'jana', 'password........' => '$2y$10$hash1']),
            self::reader(self::READER_2, ['headline........' => 'no-password']),
        ]));

        self::assertSame(['I reader-accounts meta/contents 1'], self::findings($plan));
        self::assertTrue($plan->importable());
    }

    public function test_logins_are_asked_only_for_a_reader_management_slice(): void
    {
        $target            = new FakeImportTarget();
        $target->usernames = ['jana'];

        $plan = self::plan(self::readers([self::reader(self::READER_1, ['headline........' => 'jana', 'password........' => 'x'])], 'S'), $target);

        self::assertSame([], self::findings($plan), 'a headline of an article is no login');
    }

    public function test_relations_outside_and_subscriptions_to_filters_outside_the_package(): void
    {
        $plan = self::plan(self::readers([
            self::reader(self::READER_1, ['headline........' => 'a']),
            self::reader(self::READER_2, ['headline........' => 'b', 'relation.......1' => [self::READER_1, self::OUTSIDE], 'alerts2....AbC12' => ['f3', 'f99']]),
        ], 'S'));

        $at = 'slice-module[' . self::SLICE . ']/item[' . self::READER_2 . ']';
        self::assertSame([
            "I alerts-filter-kept $at/alerts2....AbC12[1] f99",       // filter 3 is the package's
            "W item-outside $at/relation.......1[1] " . self::OUTSIDE,  // READER_1 is the package's
        ], self::findings($plan));
    }

    public function test_an_item_column_longer_than_its_column_is_an_error_of_the_plan(): void
    {
        $plan = self::plan(self::readers([self::reader(self::READER_1, ['flags...........' => str_repeat('x', 31), 'headline........' => str_repeat('y', 300)])], 'S'));

        self::assertSame(['E too-long slice-module[' . self::SLICE . ']/item[' . self::READER_1 . ']/flags...........[0] 31 characters > 30'], self::findings($plan), 'item.flags is char(30); content.text has no limit');
    }
}

<?php

declare(strict_types=1);

/**
 * The owner of the modules an install creates: one of this AA's owners (slice_owner), chosen in the
 * preview - by default the one most of the modules the user may edit have. It goes to module.owner and
 * slice.owner; an id of no owner is an E of the plan and the writer refuses it. A new module's slice_url
 * and a new slice's fileman_dir are '', not NULL. A transfer brings the owner of the source
 * (BatchWriteTest), a slice going into an existing one keeps its own (IntoExistingTest).
 */

namespace Tests\Unit\Central\Package;

use AA\Central\InstallRun;
use AA\Central\Package\ImportChoices;
use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\Planner;
use AA\Central\Package\Validator;
use AA\Central\Package\WriteError;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;
use Tests\Unit\Central\Package\Fixtures\TableDbTarget;

require_once __DIR__ . '/fixtures/ImportFixture.php';

final class ModuleOwnerTest extends TestCase
{
    use ImportFixture;

    /** owners of the target, by name: Alfa, Beta, Gama */
    private const ALFA = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
    private const BETA = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
    private const GAMA = 'c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3c3';
    /** an owner id no slice_owner row has */
    private const FORGED = 'dededededededededededededededede';

    private function owners(): void
    {
        $this->target->owners = [self::ALFA => 'Alfa', self::BETA => 'Beta', self::GAMA => 'Gama'];
    }

    private function planned(string $owner, bool $transfer = false): ImportPlan
    {
        $package = self::example();
        return (new Planner($this->target))->plan($package, (new Validator())->validate($package, $transfer), $transfer, [], [], false, $owner);
    }

    /** "<level> <code> <path> <ref>" of the planner's findings with $code @return list<string> */
    private static function found(ImportPlan $plan, string $code): array
    {
        return array_values(array_map(static fn(array $f): string => "{$f[1]->level} {$f[1]->code} $f[0] {$f[1]->ref}", array_filter($plan->findings, static fn(array $f): bool => $f[1]->code === $code)));
    }

    // ---------------------------------------------------------------- the write

    public function test_the_chosen_owner_goes_to_every_new_module_and_its_slice(): void
    {
        $this->owners();

        $this->import($this->planned(self::BETA));

        self::assertSame([self::id(1) => self::BETA, self::id(2) => self::BETA, self::id(3) => self::BETA], array_column(TableDb::rows('module'), 'owner', 'id'), 'the site, the slice, the Alerts module');
        self::assertSame([self::id(2) => self::BETA], array_column(TableDb::rows('slice'), 'owner', 'id'), 'slice.owner too - the slice settings save both');
        self::assertSame([], TableDb::rows('slice_owner'), 'no owner created');
    }

    public function test_a_new_owner_is_created_once_and_given_to_every_new_module(): void
    {
        $this->owners();
        $package = self::example();
        $plan    = (new Planner($this->target))->plan(
            $package,
            (new Validator())->validate($package),
            false,
            [],
            [],
            false,
            '',
            new \AA\Central\Package\ImportChoices(newOwner: ['name' => 'Delta', 'email' => 'delta@ecn.cz']),
        );

        $this->import($plan);

        $created = TableDb::rows('slice_owner');
        self::assertCount(1, $created);
        self::assertSame(['Delta', 'delta@ecn.cz'], [$created[0]['name'], $created[0]['email']]);
        self::assertSame(array_fill(0, 3, $created[0]['id']), array_values(array_column(TableDb::rows('module'), 'owner')));
    }

    /** the plan of an install creating the owner $name, of $package (the example) */
    private function newOwner(string $name, ?\AA\Central\Package\Model\Package $package = null): ImportPlan
    {
        $package ??= self::example();
        return (new Planner($this->target))->plan($package, (new Validator())->validate($package), false, [], [], false, '', new ImportChoices(newOwner: ['name' => $name, 'email' => 'delta@ecn.cz']));
    }

    public function test_a_new_owner_is_checked_as_the_writer_stores_it(): void
    {
        $this->owners();
        $cp1250 = self::lang(self::example(), self::SITE, 'cz_site_lang.php');     // the site is the first module

        self::assertSame([], self::found($this->newOwner(str_repeat('ř', 40)), Planner::OWNER_INVALID), '80 bytes fit');
        self::assertSame(['E owner-invalid target delta@ecn.cz'], self::found($this->newOwner(str_repeat('ř', 41)), Planner::OWNER_INVALID), 'slice_owner.name holds 80 bytes, not 80 letters with accents');
        self::assertSame(['E owner-invalid target delta@ecn.cz'], self::found($this->newOwner('Иван', $cp1250), Planner::OWNER_INVALID), 'windows-1250, the charset it is written in, has no Cyrillic');
        self::assertSame([], self::found($this->newOwner('Příliš žluťoučký', $cp1250), Planner::OWNER_INVALID));
    }

    public function test_the_writer_needs_a_transaction_on_the_owners_it_creates(): void
    {
        $this->owners();
        $plan = $this->newOwner('Delta');
        $this->target->engines = ['slice_owner' => 'MyISAM'];

        $e = $this->failure($plan);

        self::assertSame(WriteError::ENGINE, $e->getPrevious()?->reason ?? null);
        self::assertContains('slice_owner', Planner::tables(new \AA\Central\Package\Schema(), false, false, true));
        self::assertNotContains('slice_owner', Planner::tables(new \AA\Central\Package\Schema(), false));
    }

    public function test_a_new_module_has_an_empty_slice_url_and_fileman_dir_not_null(): void
    {
        $this->import($this->plan());

        self::assertSame(['', '', ''], array_column(TableDb::rows('module'), 'slice_url'));
        self::assertSame([''], array_column(TableDb::rows('slice'), 'slice_url'));
        self::assertSame([''], array_column(TableDb::rows('slice'), 'fileman_dir'), 'as the slice settings save "no directory"');
    }

    public function test_an_aa_with_no_owner_leaves_the_owner_empty(): void
    {
        $plan = $this->planned('');
        $this->import($plan);

        self::assertSame([], self::found($plan, Planner::OWNER_UNKNOWN));
        self::assertSame(['', '', ''], array_column(TableDb::rows('module'), 'owner'));
        self::assertSame([''], array_column(TableDb::rows('slice'), 'owner'));
    }

    // ---------------------------------------------------------------- a forged choice

    public function test_an_owner_id_of_no_owner_is_an_error_of_the_plan(): void
    {
        $this->owners();

        $plan = $this->planned(self::FORGED);

        self::assertSame(['E ' . Planner::OWNER_UNKNOWN . ' ' . Planner::TARGET . ' ' . self::FORGED], self::found($plan, Planner::OWNER_UNKNOWN));
        self::assertFalse($plan->importable());
    }

    public function test_no_owner_while_the_aa_has_some_is_an_error_of_the_plan(): void
    {
        $this->owners();

        self::assertSame(['E ' . Planner::OWNER_UNKNOWN . ' ' . Planner::TARGET . ' '], self::found($this->planned(''), Planner::OWNER_UNKNOWN));
    }

    public function test_the_writer_refuses_an_owner_gone_since_the_plan(): void
    {
        $this->owners();
        $plan = $this->planned(self::BETA);
        unset($this->target->owners[self::BETA]);

        $e = $this->failure($plan);

        self::assertInstanceOf(WriteError::class, $e->getPrevious());
        self::assertSame(WriteError::GONE, $e->getPrevious()->reason);
        self::assertSame([], TableDb::rows('module'), 'nothing written');
    }

    public function test_the_writer_refuses_a_forged_owner_the_plan_did_not_check(): void
    {
        $this->owners();
        $plan = $this->planned(self::BETA);
        $plan = new ImportPlan(...array_merge(get_object_vars($plan), ['owner' => self::FORGED]));

        $e = $this->failure($plan);

        self::assertSame(WriteError::GONE, $e->getPrevious()?->reason ?? null);
        self::assertSame([], TableDb::rows('module'));
    }

    public function test_the_writer_refuses_an_owner_in_a_transfer_plan(): void
    {
        $this->owners();
        $plan = $this->plan(self::example(), true);
        $plan = new ImportPlan(...array_merge(get_object_vars($plan), ['owner' => self::BETA]));

        $this->expectException(LogicException::class);
        $this->import($plan, $this->importer(null, new ImportConfirmation('preview-session')), (new ImportConfirmation('preview-session'))->token($plan, self::USER), $this->newRun(InstallRun::TRANSFER));
    }

    public function test_a_transfer_takes_no_chosen_owner(): void
    {
        $this->owners();

        $this->expectException(InvalidArgumentException::class);
        $this->planned(self::BETA, true);
    }

    // ---------------------------------------------------------------- the default

    public function test_the_default_is_the_owner_most_modules_the_user_may_edit_have(): void
    {
        $this->owners();
        [$m1, $m2, $m3, $m4, $m5, $m6] = array_map(self::id(...), range(71, 76));
        // Gama has the most modules, but the user may edit only one of them; a module of no owner row counts for none
        $this->target->moduleOwners = [$m1 => self::BETA, $m2 => self::GAMA, $m3 => self::GAMA, $m4 => self::GAMA, $m5 => self::BETA, $m6 => self::FORGED];
        $this->target->notEditable  = [$m3, $m4];

        self::assertSame(self::BETA, (new Planner($this->target))->defaultOwner());
    }

    public function test_the_default_of_a_tie_is_the_first_owner(): void
    {
        $this->owners();
        $this->target->moduleOwners = [self::id(71) => self::GAMA, self::id(72) => self::BETA];

        self::assertSame(self::BETA, (new Planner($this->target))->defaultOwner(), 'Beta before Gama by name');
    }

    public function test_the_default_with_no_owned_module_to_edit_is_the_first_owner(): void
    {
        $this->owners();
        $this->target->moduleOwners = [self::id(71) => self::GAMA, self::id(72) => ''];
        $this->target->notEditable  = [self::id(71)];

        self::assertSame(self::ALFA, (new Planner($this->target))->defaultOwner());
    }

    public function test_no_owner_no_default(): void
    {
        $this->target->moduleOwners = [self::id(71) => ''];

        self::assertSame('', (new Planner($this->target))->defaultOwner());
    }

    public function test_the_db_target_reads_the_owners_by_name_and_the_owners_of_live_modules(): void
    {
        TableDb::$tables['slice_owner'] = [['id' => self::GAMA, 'name' => 'Gama', 'email' => 'g@example.org'], ['id' => self::ALFA, 'name' => 'alfa', 'email' => 'a@example.org'], ['id' => self::BETA, 'name' => 'Beta', 'email' => 'b@example.org']];
        TableDb::$tables['module']      = [
            ['id' => self::id(71), 'type' => 'S', 'owner' => self::GAMA, 'deleted' => 0],
            ['id' => self::id(72), 'type' => 'W', 'owner' => '', 'deleted' => 0],
            ['id' => self::id(73), 'type' => 'S', 'owner' => self::BETA, 'deleted' => 1],
        ];
        $target = new TableDbTarget($this->target);

        self::assertSame([self::ALFA => 'alfa', self::BETA => 'Beta', self::GAMA => 'Gama'], $target->owners(), 'by name, any case');
        self::assertSame([self::id(71) => self::GAMA, self::id(72) => ''], $target->moduleOwners(), 'no deleted module');
        self::assertSame(self::GAMA, (new Planner($target))->defaultOwner());
    }

    /** an id trimmed of its trailing NUL bytes (DbBinaryTrailingZeros) cannot be posted back - never offered */
    public function test_an_owner_id_shorter_than_16_bytes_is_not_offered(): void
    {
        $short = substr(self::ALFA, 0, 30);
        TableDb::$tables['slice_owner'] = [['id' => $short, 'name' => 'Aaa', 'email' => 'a@example.org'], ['id' => self::BETA, 'name' => 'Beta', 'email' => 'b@example.org']];
        TableDb::$tables['module']      = [['id' => self::id(71), 'type' => 'S', 'owner' => $short, 'deleted' => 0], ['id' => self::id(72), 'type' => 'S', 'owner' => $short, 'deleted' => 0]];
        $target = new TableDbTarget($this->target);

        self::assertSame([self::BETA => 'Beta'], $target->owners());
        self::assertSame(self::BETA, (new Planner($target))->defaultOwner(), 'the modules of the short id count for none');
    }
}

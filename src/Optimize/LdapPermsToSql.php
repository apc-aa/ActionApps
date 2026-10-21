<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Cache\PageCache;
use AA\IO\DB\DB_AA;
use AA\Perm\LdapToSql\Directory;
use AA\Perm\LdapToSql\Importer;
use AA\Perm\LdapToSql\Parity;
use AA\Perm\LdapToSql\Plan;
use AA\Perm\LdapToSql\References;
use AA_Log;
use AA_Perm;
use AA_Permsystem_Sql;
use RuntimeException;
use Throwable;

/**
 * One-time switch of an LDAP install to SQL permissions: people and groups of the directory
 * get numeric ids in users/membership/perms and every reference to a person in the data is
 * rewritten from the DN to the id.
 *
 * Test reads LDAP and the database and runs the import in a transaction it rolls back.
 * Repair imports, checks that SQL answers as LDAP does and rewrites the references in one
 * transaction, so a failure anywhere leaves the install as it was. There is no revert: the
 * way back is the backup taken before Repair.
 *
 * @phpstan-import-type Answers from Parity
 * @phpstan-type Prepared array{objects: list<array{type: string, id: string}>, plan: Plan, answers: Answers}
 */
class LdapPermsToSql extends AbstractOptimize
{
    /** log type of a finished conversion - a second run would hand out other ids */
    public const LOG_TYPE = 'LDAP2SQL';

    /** the tables the import empties and fills - only InnoDB rolls them back */
    private const PERM_TABLES = ['users', 'membership', 'perms'];

    public static function name(): string
    {
        return _m('Convert LDAP permissions to SQL');
    }

    public static function description(): string
    {
        return _m("Moves the users, groups and rights of this database from LDAP to the SQL tables under new numeric ids and rewrites the references to people in the data. Test only reads: its trial import is rolled back. Repair does everything in one transaction; then set PERM_LIB='sql' in every config of this database. Take a backup first - restoring it is the only way back.");
    }

    public static function actions(): array
    {
        return ['test', 'repair'];
    }

    public static function types(): array
    {
        return ['private_fix'];
    }

    public function test(): bool
    {
        if (!$this->canRun()) {
            return false;
        }
        if (DB_AA::test('log', [['type', self::LOG_TYPE]])) {
            $this->message(_m('This database was already converted - Repair will refuse to run again.'));
        }
        try {
            $prepared = $this->prepare();
            $plan     = $prepared['plan'];
            $this->reportPlan($plan);

            $ok = !$plan->blocking();
            if ($ok) {
                $parity = $this->trialRun($prepared);
                $this->reportFindings($parity);
                $ok = !self::blocks($parity);
                $this->message($ok ? _m('Parity check passed: SQL answers as LDAP does.') : _m('Parity check failed - Repair would stop at it.'));
            } else {
                $this->message(_m('The trial import is skipped: it would stop at the first blocking finding above. Fix them and run Test again.'));
            }
            $this->reportReferences($plan);
        } catch (Throwable $e) {
            $this->message(_m('Test failed: %1', [safe($e->getMessage())]));
            return false;
        }
        return $ok;
    }

    public function repair(): bool
    {
        if (!$this->canRun()) {
            return false;
        }
        if (DB_AA::test('log', [['type', self::LOG_TYPE]])) {
            $this->message(_m('This database was already converted. A second run would give people other ids than the data now holds - restore the backup first if it really has to run again.'));
            return false;
        }
        $parity = [];
        try {
            $prepared = $this->prepare();
            $plan     = $prepared['plan'];
            $this->reportPlan($plan);
            if ($plan->blocking()) {
                $this->message(_m('Nothing was changed: the findings above block the conversion.'));
                return false;
            }
            $counts = $this->inTransaction(function () use ($prepared, $plan, &$parity): array {
                $imported = $this->import($plan);
                // before the rewrite: its UPDATEs lock the big tables until the commit
                $parity = $this->compare($prepared['answers'], $plan);
                if (self::blocks($parity)) {
                    throw new RuntimeException('SQL would not answer as LDAP does (see the parity findings).');
                }
                $rewritten = $this->rewrite($plan->remap);
                $this->logConversion($imported, $rewritten);
                return ['imported' => $imported, 'rewritten' => $rewritten];
            });
        } catch (Throwable $e) {
            $this->reportFindings($parity);
            $this->message(_m('Repair failed, nothing was changed: %1', [safe($e->getMessage())]));
            return false;
        } finally {
            AA_Permsystem_Sql::forgetMembership();
        }

        $this->reportFindings($parity);
        try {
            PageCache::invalidateOlder(time());
        } catch (Throwable $e) {
            $this->message(_m('The page cache was not cleared, clear it by hand: %1', [safe($e->getMessage())]));
        }
        $imported = $counts['imported'];
        $this->message(_m('Imported %1 people, %2 groups, %3 memberships and %4 rights; %5 old rows removed.', [$imported['users'], $imported['groups'], $imported['membership'], $imported['perms'], $imported['deleted']]));
        foreach ($counts['rewritten'] as $column => $rows) {
            $this->message(_m('Rewritten %1: %2 rows', [safe((string) $column), $rows]));
        }
        $this->message(_m('New ids of the directory entries:') . '<pre>' . safe(self::idMap($plan)) . '</pre>');
        $this->message('<strong>' . _m("Now set PERM_LIB='sql' in every config of this database - until then AA still asks LDAP.") . '</strong>');
        return true;
    }

    /**
     * Objects whose rights LDAP keeps for this database: the AA itself, every module and
     * every Links category (stored as a slice id).
     * @return list<array{type: string, id: string}>
     */
    public static function objects(): array
    {
        $objects = [['type' => 'aa', 'id' => (string) constant('AA_ID')]];
        foreach (DB_AA::select('id', 'SELECT LOWER(HEX(id)) AS id FROM module') as $id) {
            $objects[] = ['type' => 'slice', 'id' => (string) $id];
        }
        if (DB_AA::test('information_schema.TABLES', [['TABLE_SCHEMA', (string) constant('DB_NAME')], ['TABLE_NAME', 'links_categories']])) {
            require_once __DIR__ . '/../../modules/links/util.php';
            foreach (DB_AA::select('id', 'SELECT id FROM links_categories') as $category) {
                $objects[] = ['type' => 'slice', 'id' => \Links_Category2SliceID($category)];
            }
        }
        return $objects;
    }

    protected function permLib(): string
    {
        return \defined('PERM_LIB') ? (string) constant('PERM_LIB') : '';
    }

    /**
     * Everything both actions need from LDAP, read before any transaction - the parity
     * answers included, so no lock is held while LDAP answers.
     * @return Prepared
     */
    protected function prepare(): array
    {
        // tens of thousands of LDAP and SQL requests
        @set_time_limit(0);
        require_once __DIR__ . '/../../include/perm_sql.php';

        $objects   = self::objects();
        $directory = Directory::fromConfig();
        $charset   = \defined('DB_CHARACTER_SET') ? (string) constant('DB_CHARACTER_SET') : '';
        $plan      = Plan::build($directory->people(), $directory->groups(), $directory->acls($objects), References::dnValues(), References::firstFreeId(), $charset);
        $answers   = Parity::ask(new AA_Perm(['ldap', 'Reader']), $plan, $objects, (string) constant('AA_ID'));
        return ['objects' => $objects, 'plan' => $plan, 'answers' => $answers];
    }

    /** @return array{deleted: int, users: int, groups: int, membership: int, perms: int} */
    protected function import(Plan $plan): array
    {
        return Importer::run(new AA_Permsystem_Sql(), $plan);
    }

    /**
     * @param Answers $answers
     * @return list<array{blocking: bool, text: string}>
     */
    protected function compare(array $answers, Plan $plan): array
    {
        return Parity::compare($answers, new AA_Perm(['sql', 'Reader']), $plan, (string) constant('AA_ID'));
    }

    /**
     * @param array<string, string> $remap
     * @return array<string, int>
     */
    protected function rewrite(array $remap): array
    {
        return References::rewrite($remap);
    }

    /**
     * The row that makes a second Repair refuse - written in the transaction, so it exists
     * exactly when the conversion does.
     * @param array{deleted: int, users: int, groups: int, membership: int, perms: int} $imported
     * @param array<string, int> $rewritten
     */
    protected function logConversion(array $imported, array $rewritten): void
    {
        // log.params holds 128 bytes - totals only, the report lists the columns
        $params = (string) json_encode($imported + ['rewritten' => array_sum($rewritten)]);
        if (!AA_Log::write(self::LOG_TYPE, '', $params) || !DB_AA::test('log', [['type', self::LOG_TYPE]])) {
            throw new RuntimeException('The log row of the conversion was not stored.');
        }
    }

    /** Refusals common to both actions, before anything reads LDAP */
    private function canRun(): bool
    {
        if ($this->permLib() !== 'ldap') {
            $this->message(_m("This conversion runs only while PERM_LIB is 'ldap'; here it is '%1'.", [safe($this->permLib())]));
            return false;
        }
        if (self::justPrint()) {
            // transaction() would not start one, and the import would delete the users for real
            $this->message(_m('The conversion has no dry run - switch it off. Test itself changes nothing.'));
            return false;
        }

        $engines = DB_AA::select(['TABLE_NAME' => 'ENGINE'], 'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES', [
            ['TABLE_SCHEMA', (string) constant('DB_NAME')], ['TABLE_NAME', self::PERM_TABLES],
        ]);
        $wrong = [];
        foreach (self::PERM_TABLES as $table) {
            if (($engines[$table] ?? '') !== 'InnoDB') {
                $wrong[] = $table . ' (' . ($engines[$table] ?? '-') . ')';
            }
        }
        if ($wrong) {
            $this->message(_m('Tables %1 must be InnoDB: on another engine a rollback restores nothing, so even the trial import of Test would delete the users for good. Convert them first - Optimize "%2".', [safe(implode(', ', $wrong)), Convert2innodb::name()]));
            return false;
        }

        $charset = (string) DB_AA::select1('c', 'SELECT @@character_set_connection AS c');
        if ($charset !== 'utf8mb4') {
            $this->message(_m('The database connection uses "%1", not utf8mb4: a DN with a character outside it would silently not be found in the data. Set DB_CHARACTER_SET to "utf8mb4" in config.php first.', [safe($charset)]));
            return false;
        }
        return true;
    }

    /**
     * The import in a transaction that is always rolled back - Test changes nothing.
     * @param Prepared $prepared
     * @return list<array{blocking: bool, text: string}> parity findings
     */
    private function trialRun(array $prepared): array
    {
        $rollback = new RuntimeException('trial run rolled back');
        $parity   = [];
        try {
            $this->inTransaction(function () use ($prepared, $rollback, &$parity): void {
                $imported = $this->import($prepared['plan']);
                $this->message(_m('Trial import: %1 people, %2 groups, %3 memberships, %4 rights; %5 old rows removed (all rolled back).', [$imported['users'], $imported['groups'], $imported['membership'], $imported['perms'], $imported['deleted']]));
                $parity = $this->compare($prepared['answers'], $prepared['plan']);
                throw $rollback;
            });
        } catch (Throwable $e) {
            if ($e !== $rollback) {
                throw $e;
            }
        } finally {
            // the memo would keep the rolled-back memberships
            AA_Permsystem_Sql::forgetMembership();
        }
        return $parity;
    }

    /**
     * $work in one transaction on the handle of query(). READ COMMITTED: InnoDB then does
     * not lock the rows a full-scan UPDATE skips, so the webs of this DB go on writing.
     */
    private function inTransaction(callable $work): mixed
    {
        // applies to the next transaction of this connection only - so right before it
        $this->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return $this->transaction(static function () use ($work): mixed {
            // without one, DB_AA::transaction() runs the block anyway and nothing could be taken back
            if (!DB_AA::inTransaction()) {
                throw new RuntimeException('The database did not start a transaction.');
            }
            return $work();
        });
    }

    private function reportPlan(Plan $plan): void
    {
        $this->message(_m('Plan: %1 people, %2 groups, %3 memberships, %4 rights; new ids from %5; %6 spellings of DNs in the data to rewrite.', [
            count($plan->users), count($plan->groupRows), count($plan->membership), count($plan->perms),
            $plan->ids ? min($plan->ids) : '-', count($plan->remap),
        ]));
        $this->reportFindings($plan->findings);
    }

    /** @param list<array{blocking: bool, text: string}> $findings */
    private function reportFindings(array $findings): void
    {
        foreach ($findings as $finding) {
            $this->message(($finding['blocking'] ? '<strong>' . _m('Blocking') . '</strong>: ' : _m('Info') . ': ') . safe($finding['text']));
        }
    }

    /** What Repair rewrites and how long its scans take, and what it leaves for a manual fix */
    private function reportReferences(Plan $plan): void
    {
        $counts = References::count($plan->remap);
        foreach ($counts as $column => $count) {
            $this->message(_m('References in %1: %2 rows (%3 s)', [safe((string) $column), $count['rows'], round($count['seconds'], 1)]));
        }
        $this->message(_m('Repair rewrites the references in about %1 s - each UPDATE scans its table as the count did.', [round(array_sum(array_column($counts, 'seconds')), 1)]));
        $this->message(_m('A DN stored as cp1250 bytes with diacritics is not UTF-8, so it matches no directory entry and stays as it is.'));
        foreach (References::mentions() as $column => $rows) {
            $this->message(_m('Mentions of ou=AA in %1: %2 rows - fix them by hand.', [safe((string) $column), $rows]));
        }
    }

    /** @param list<array{blocking: bool, text: string}> $findings */
    private static function blocks(array $findings): bool
    {
        return \in_array(true, array_column($findings, 'blocking'), true);
    }

    /** "id DN" lines of every imported person and group */
    private static function idMap(Plan $plan): string
    {
        $ids = $plan->ids;
        asort($ids);
        $lines = [];
        foreach ($ids as $key => $id) {
            $lines[] = "$id\t{$plan->canonical[$key]}";
        }
        return implode("\n", $lines);
    }
}

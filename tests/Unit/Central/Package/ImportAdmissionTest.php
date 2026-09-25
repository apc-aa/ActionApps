<?php

declare(strict_types=1);

/**
 * Importer::write() before anything else, C4.4 part 5 (design balicek-v1.md § 3.3, § 7.2 step 2, Q-H2):
 * the right to install (PS_INSTALL_APP) and the confirmation of the S findings
 * (ImportConfirmation) - refused = ImportFailed before any statement.
 */

namespace Tests\Unit\Central\Package;

use AA\Central\Package\ImportConfirmation;
use AA\Central\Package\ImportFailed;
use AA\Central\Package\Importer;
use AA\Central\Package\ImportPlan;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Package;
use AA\Central\Package\WriteError;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Unit\Central\Package\Fixtures\FakeImportTarget;
use Tests\Unit\Central\Package\Fixtures\ImportFixture;
use Tests\Unit\Central\Package\Fixtures\TableDb;

require_once __DIR__ . '/fixtures/ImportFixture.php';

final class ImportAdmissionTest extends TestCase
{
    use ImportFixture;

    private const SECRET = 'the secret of this AA';

    /** the example with code the security lint reports: a view reading $_SERVER, a spot including a file */
    private static function risky(): Package
    {
        $package = self::cell(self::example(), self::SLICE, 'view', 0, ['odd' => '{server:REMOTE_ADDR}']);
        return self::cell($package, self::SITE, 'site_spot', 1, ['content' => '{include:../../etc/passwd:readfile}']);
    }

    private static function confirmation(): ImportConfirmation
    {
        return new ImportConfirmation(self::SECRET);
    }

    /** write() refused: the reason, and nothing happened - no statement, no step after it */
    private function refused(ImportPlan $plan, ?string $token, string $reason, ?ImportConfirmation $confirmation = null): WriteError
    {
        try {
            $this->importer(confirmation: $confirmation ?? self::confirmation())->write($plan, $token);
            self::fail('the write was not refused');
        } catch (ImportFailed $e) {
            $cause = $e->getPrevious();
            self::assertInstanceOf(WriteError::class, $cause);
            self::assertSame($reason, $cause->reason);
            self::assertSame('import', $cause->path);
            self::assertSame([], TableDb::$log, 'no statement');
            self::assertSame([], $this->after->calls);
            return $cause;
        }
    }

    // ---------------------------------------------------------------- the right to install

    public function test_a_user_without_the_right_writes_nothing(): void
    {
        $this->authority->may = false;
        $error                = $this->refused($this->plan(), null, WriteError::DENIED);
        self::assertStringContainsString('PS_INSTALL_APP', $error->getMessage());
        self::assertSame([self::USER], $this->authority->asked, 'the importing user');
    }

    public function test_an_authority_that_cannot_tell_is_a_no_and_its_message_goes_to_the_log(): void
    {
        $this->authority->error = new RuntimeException("LDAP server ldap.internal:389\ndown");
        $log                    = (string) tempnam(sys_get_temp_dir(), 'aa-log');
        $saved                  = ini_set('error_log', $log);
        try {
            $error = $this->refused($this->plan(), null, WriteError::DENIED);
        } finally {
            ini_set('error_log', (string) $saved);
        }
        self::assertSame('denied at import: the install permission could not be checked', $error->getMessage(), 'nothing of the perm system for the UI');
        self::assertStringContainsString('RuntimeException: LDAP server ldap.internal:389 down', (string) file_get_contents($log), 'one line in the log');
        unlink($log);
    }

    public function test_the_right_is_asked_before_the_confirmation(): void
    {
        $this->authority->may = false;
        $this->refused($this->plan(self::risky()), null, WriteError::DENIED);
    }

    public function test_ps_install_app_is_a_letter_of_the_super_role_only(): void
    {
        class_exists(\AA_Perm::class);
        self::assertSame('U', \PS_INSTALL_APP);
        foreach (\AA_Perm::ROLES as $role => $def) {
            self::assertSame($role === 'SUPER', str_contains($def['perm'], \PS_INSTALL_APP), $role);
        }
        $letters = array_filter(get_defined_constants(true)['user'], static fn(mixed $v, string $k): bool => str_starts_with($k, 'PS_') && !str_starts_with($k, 'PS_MOD') && !str_starts_with($k, 'PS_LINKS'), ARRAY_FILTER_USE_BOTH);
        self::assertSame(['PS_INSTALL_APP'], array_keys($letters, 'U', true), 'no other permission of the letter');
    }

    public function test_the_aa_authority_asks_for_ps_install_app_on_the_aa_object(): void
    {
        class_exists(\AA_Perm::class);
        $saved = [$GLOBALS['permission_uid'] ?? null, $GLOBALS['permission_to'] ?? null, $GLOBALS['auth'] ?? null];
        try {
            self::assertFalse(Importer::hasInstallPermission(''), 'nobody');
            $GLOBALS['auth'] = new class {
                public function getUid(): string
                {
                    return 'u1';
                }
            };
            $may = static function (string $aa, string $slice = ''): bool {
                $GLOBALS['permission_uid'] = 'u1';        // the perms of u1 as AA_Perm::cache() leaves them
                $GLOBALS['permission_to']  = ['aa' => [AA_ID => $aa], 'slice' => $slice ? [str_repeat('a', 32) => $slice] : []];
                return Importer::hasInstallPermission('u1');
            };
            self::assertTrue($may(\AA_Perm::ROLES['SUPER']['perm']), 'superadmin');
            self::assertTrue($may('U'), 'the letter granted alone');
            self::assertFalse($may(\AA_Perm::ROLES['ADMINISTRATOR']['perm'], \AA_Perm::ROLES['SUPER']['perm']), 'a module perm does not count');
            self::assertFalse($may(\PS_ADD . \PS_NEW_USER . \PS_MANAGE_ALL_SLICES), 'PS_ADD is not enough (Q-H2)');

            $may(\AA_Perm::ROLES['SUPER']['perm']);
            self::assertFalse(Importer::hasInstallPermission('u2'), 'only the logged-in user - no perms of another cached into the session');
            self::assertSame('u1', $GLOBALS['permission_uid']);
        } finally {
            [$GLOBALS['permission_uid'], $GLOBALS['permission_to'], $GLOBALS['auth']] = $saved;
        }
    }

    public function test_the_perm_cache_forgets_the_superadmin_flag_of_the_user_before(): void
    {
        class_exists(\AA_Perm::class);
        $saved = [$GLOBALS['permission_uid'] ?? null, $GLOBALS['permission_to'] ?? null, $GLOBALS['r_superuser'] ?? null];
        try {
            $GLOBALS['r_superuser'] = [AA_ID => true];          // a superadmin was cached
            (new \AA_Perm([]))->cache('someone-else');         // no perm system: no perms at all
            self::assertSame([], $GLOBALS['r_superuser']);
        } finally {
            [$GLOBALS['permission_uid'], $GLOBALS['permission_to'], $GLOBALS['r_superuser']] = $saved;
        }
    }

    // ---------------------------------------------------------------- the S confirmation

    public function test_s_findings_without_a_confirmation_write_nothing(): void
    {
        $plan = $this->plan(self::risky());
        self::assertCount(2, $plan->ofLevel(LintFinding::SECURITY));
        self::assertTrue($plan->preview()['confirm']);

        $error = $this->refused($plan, null, WriteError::UNCONFIRMED);
        self::assertStringContainsString('2 security findings', $error->getMessage());
    }

    public function test_a_confirmation_of_something_else_writes_nothing(): void
    {
        $plan  = $this->plan(self::risky());
        $other = self::cell(self::risky(), self::SLICE, 'view', 0, ['odd' => '{server:HTTP_COOKIE}']);
        $tokens = [
            'another user'     => self::confirmation()->token($plan, 'someone-else'),
            'another package'  => self::confirmation()->token($this->plan(self::with(self::risky(), ['version' => '0.2.0'])), self::USER),
            'another finding'  => self::confirmation()->token($this->plan($other), self::USER),
            'another AA'       => (new ImportConfirmation('another secret'))->token($plan, self::USER),
            'garbage'          => 'x',
            'empty'            => '',
        ];
        foreach ($tokens as $what => $token) {
            self::assertIsString($token, $what);
            $this->refused($plan, $token, WriteError::UNCONFIRMED);
        }
    }

    public function test_the_confirmation_of_the_preview_writes(): void
    {
        $preview = $this->plan(self::risky());
        $token   = self::confirmation()->token($preview, self::USER);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}\z/', (string) $token);

        // the write plans again (another request): other new ids, the same package and findings - the same token
        $this->target = new FakeImportTarget([], [str_repeat('b', 31) . '1', str_repeat('b', 31) . '2', str_repeat('b', 31) . '3']);
        $plan         = $this->plan(self::risky());
        self::assertNotSame($preview->map->modules(), $plan->map->modules());
        self::assertSame($token, self::confirmation()->token($plan, self::USER));

        $report = $this->importer(confirmation: self::confirmation())->write($plan, $token);
        self::assertCount(3, TableDb::rows('module'));
        self::assertSame(['{server:REMOTE_ADDR}'], array_column(array_filter(TableDb::rows('view'), static fn(array $r): bool => $r['odd'] === '{server:REMOTE_ADDR}'), 'odd'));
        $levels = array_map(static fn(array $f): string => $f[1]->level, $report->findings);
        self::assertSame([LintFinding::SECURITY, LintFinding::SECURITY], array_slice($levels, 0, 2), 'the report lists them first');
    }

    public function test_a_plan_without_s_findings_needs_no_confirmation(): void
    {
        $plan = $this->plan();
        self::assertSame([], $plan->ofLevel(LintFinding::SECURITY));
        self::assertFalse($plan->preview()['confirm']);
        self::assertNull(self::confirmation()->token($plan, self::USER));
        self::assertTrue(self::confirmation()->confirms($plan, self::USER, 'anything'));

        $this->importer()->write($plan);
        self::assertCount(3, TableDb::rows('module'));
    }

    public function test_the_token_is_keyed_by_the_admin_session(): void
    {
        $plan = $this->plan(self::risky());
        try {
            session_id('session-one');
            $one = (new ImportConfirmation())->token($plan, self::USER);
            self::assertSame($one, (new ImportConfirmation())->token($plan, self::USER));
            session_id('session-two');
            self::assertNotSame($one, (new ImportConfirmation())->token($plan, self::USER), 'another session - no replay');
            self::assertNotSame($one, (new ImportConfirmation(AA_ID))->token($plan, self::USER), 'not AA_ID alone');

            session_id('');
            self::assertNull((new ImportConfirmation())->token($this->plan(), self::USER), 'no S finding: no session needed');
            $this->expectException(\LogicException::class);
            (new ImportConfirmation())->token($plan, self::USER);
        } finally {
            session_id('');
        }
    }

    public function test_without_a_session_s_findings_are_never_confirmed(): void
    {
        session_id('');
        $this->refused($this->plan(self::risky()), 'anything', WriteError::UNCONFIRMED, new ImportConfirmation());
    }

    public function test_the_preview_says_when_the_lint_does_not_know_all_commands(): void
    {
        // not defined here - defining it would make include/stringexpand.php of a later test require include/custom/<dir>/
        self::assertFalse(defined('STRINGEXPAND_INC'));
        self::assertFalse($this->plan()->preview()['customCommands']);
    }

    public function test_the_token_binds_every_s_finding_whatever_their_order(): void
    {
        $plan     = $this->plan(self::risky());
        $reversed = new ImportPlan(...array_merge(get_object_vars($plan), ['validation' => array_reverse($plan->validation)]));
        self::assertSame(self::confirmation()->token($plan, self::USER), self::confirmation()->token($reversed, self::USER));

        $dropped = new ImportPlan(...array_merge(get_object_vars($plan), ['validation' => array_slice($plan->ofLevel(LintFinding::SECURITY), 1)]));
        self::assertNotSame(self::confirmation()->token($plan, self::USER), self::confirmation()->token($dropped, self::USER));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\RemoveAaCronEntries;
use PHPUnit\Framework\TestCase;

/** matches() is the one rule deciding which `cron` rows are AA's own jobs;
 *  the samples are the scripts found on the live ECN installs in 2026-09. */
final class RemoveAaCronEntriesTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function aaScripts(): iterable
    {
        foreach ([
            'modules/alerts/alerts.php', 'modules/alerts/alerts.php3',
            'admin/xmlclient.php', 'admin/xmlclient.php3',
            'modules/links/linkcheck.php', 'modules/links/linkcheck.php3',
            'misc/toexecute.php', 'misc/toexecute.php3', 'misc/no_run_here_toexecute.php3',
            'misc/optimize.php', 'misc/optimize.php3',
            'modules/mysql_auth/suspend.php3',
        ] as $script) {
            yield $script => [$script];
        }
    }

    /** @dataProvider aaScripts */
    public function test_every_aa_job_seen_on_a_live_install_matches(string $script): void
    {
        $this->assertTrue(RemoveAaCronEntries::matches($script));
    }

    public function test_the_admins_own_scripts_stay(): void
    {
        foreach (['misc/mytask.php', 'modules/alerts/alerts_custom.php', 'admin/optimize_report.php', 'suspend.php', ''] as $script) {
            $this->assertFalse(RemoveAaCronEntries::matches($script), $script);
        }
    }

    public function test_is_a_database_update(): void
    {
        $this->assertSame(['sql_update'], RemoveAaCronEntries::types(), 'service/sql_update.php picks the class up by this type');
    }
}

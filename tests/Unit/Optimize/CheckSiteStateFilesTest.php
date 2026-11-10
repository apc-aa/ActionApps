<?php

declare(strict_types=1);

namespace Tests\Unit\Optimize;

use AA\Optimize\CheckSiteStateFiles;
use AA\Optimize\Finding;
use PHPUnit\Framework\TestCase;

/** A legacy site module whose control file is missing answers every public page with 500. */
final class CheckSiteStateFilesTest extends TestCase
{
    private const SITE = '0123456789abcdef0123456789abcdef';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../fixtures/util_shims.php';   // _m(), safe(), AA_BASE_PATH
    }

    /** @return array<string, mixed> a site row with its module */
    private static function row(string $id, string $state_file, int $flag = 0, int $deleted = 0, string $name = 'Web'): array
    {
        return ['id' => $id, 'flag' => $flag, 'state_file' => $state_file, 'name' => $name, 'deleted' => $deleted];
    }

    public function test_missing_control_file_is_an_error(): void
    {
        [$finding] = CheckSiteStateFiles::findingsFor([self::row(self::SITE, 'aa_test_no_such_site.php3')]);

        $this->assertSame(Finding::ERROR, $finding->severity);
        $this->assertSame('Site module "Web" has no state file', $finding->title);
        $this->assertSame(self::SITE, $finding->key);
    }

    /** new sites are copied from it, nobody visits it - old installs keep "template.php3", a file AA never had */
    public function test_the_site_template_is_no_site(): void
    {
        [$finding] = CheckSiteStateFiles::findingsFor([self::row(bin2hex(\AA_Module_Site::TEMPLATE_ID), 'template.php3', name: 'Site Template')]);

        $this->assertSame(Finding::OK, $finding->severity);
        $this->assertSame('No site module uses a state file', $finding->title);
    }

    public function test_a_present_control_file_is_counted(): void
    {
        $findings = CheckSiteStateFiles::findingsFor([self::row(self::SITE, 'ecn_as_example.php'), self::row(bin2hex(\AA_Module_Site::TEMPLATE_ID), 'template.php3')]);

        $this->assertSame([[Finding::OK, 'All 1 site state files are in place']], array_map(static fn(Finding $f): array => [$f->severity, $f->title], $findings));
    }

    public function test_router_and_deleted_sites_are_skipped(): void
    {
        [$finding] = CheckSiteStateFiles::findingsFor([self::row(self::SITE, 'x.php3', flag: 1), self::row(self::SITE, 'x.php3', deleted: 1)]);

        $this->assertSame(Finding::OK, $finding->severity);
    }
}

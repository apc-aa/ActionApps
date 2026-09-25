<?php

declare(strict_types=1);

namespace AA\Central\Package;

use Closure;
use Throwable;

/** The finishing of a committed import (design balicek-v1.md § 7.2 step 8; C4.4 part 5) - Importer::write()
 *  runs it after the commit and the release of the lock. Each step is isolated: what it throws is a row of
 *  the FinishReport, the next step runs. Nothing is undone - the import committed: no compensation runs for
 *  a failure here (the shutdown handler is disarmed by then too; a request that dies here leaves the import
 *  and misses the steps after - the log entry is the first step for that reason). In order:
 *  1. `log` - one `log` row "app installed" per new module (AaAfterCommit::EVENT): name, version, source;
 *  2. `permissions[<module>]` - the ACL object of every new module, as AA does for a module it creates,
 *     and the ADMINISTRATOR role of the importer unless (s)he is a superadmin (AaAfterCommit);
 *  3. `constants` - ConstantsChanged(), only when the import created a constant group;
 *  4. `pagecache[<module>]` - every new module, slices with items too: the items were written past
 *     storeItem() and its cache invalidation.
 *  The settings values of the new slices (the `_` fields; the package carries none) are C6.1: it fills
 *  them from field.input_default - here, as a step `settings[<slice>]` before the page cache. Until then
 *  ImportReport::$settings lists them to be filled in admin/slicefieldsedit.php. */
final class ImportFinish
{
    public function __construct(private readonly AfterCommit $aa) {}

    /** @param string $user the importing user - (s)he gets the rights to the new modules */
    public function run(ImportPlan $plan, ImportJournal $journal, string $user): FinishReport
    {
        $report  = new FinishReport();
        $modules = array_map('strval', array_keys($journal->modules()));
        self::step($report, 'log', fn() => $this->aa->logInstall($plan->package, $modules));
        foreach ($modules as $id) {
            self::step($report, "permissions[$id]", fn() => $this->aa->permissions($id, $user));
        }
        if ($journal->groups()) {
            self::step($report, 'constants', fn() => $this->aa->constantsChanged());
        }
        foreach ($modules as $id) {
            self::step($report, "pagecache[$id]", fn() => $this->aa->invalidatePagecache($id));
        }
        return $report;
    }

    /** @param Closure(): mixed $step */
    private static function step(FinishReport $report, string $name, Closure $step): void
    {
        try {
            $step();
            $report->add($name, []);
        } catch (Throwable $e) {
            $report->add($name, [get_class($e) . ': ' . $e->getMessage()]);
        }
    }
}

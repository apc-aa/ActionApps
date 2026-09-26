<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\LintFinding;

/** Where the install page reads an app from: this AA (AppExport) or another one through its Get_App
 *  (RemoteAppSource). Both give package XML, so everything after it - Reader, Validator, Planner,
 *  Importer - is one path. */
interface AppSource
{
    /** The definitions package: a site with its modules, or slices and Alerts modules.
     *  @param list<string> $modules a site id, or slice and Alerts ids
     *  @param int          $started unixtime the run started - the package's export time, so the same
     *                               selection gives the same XML at the preview and at the write
     *  @return array{string, list<array{string, LintFinding}>, int} the XML, the export findings, the
     *          unixtime of the source now - the final check compares the source's edit times with it
     *  @throws ExportRefused|SourceFailed */
    public function definitions(array $modules, bool $transfer, bool $allBins, int $started): array;

    /** One item batch of the slice after the cursor - a transfer only.
     *  @param string $after '' or the last item id of the previous batch
     *  @return array{string, list<array{string, LintFinding}>} the XML, the export findings
     *  @throws ExportRefused|SourceFailed */
    public function batch(string $slice, string $after, bool $allBins, int $started): array;

    /** The login names of the readers of Reader Management slices, UTF-8, sorted - a transfer only.
     *  @param list<string> $slices @return list<string>
     *  @throws ExportRefused|SourceFailed */
    public function logins(array $slices, bool $allBins): array;

    /** @param list<string> $slices @param int $editedAfter only the items edited after this unixtime; 0 = all
     *  @return array<string, int> slice => items, in the bins of the run
     *  @throws ExportRefused|SourceFailed */
    public function itemCounts(array $slices, bool $allBins, int $editedAfter = 0): array;
}

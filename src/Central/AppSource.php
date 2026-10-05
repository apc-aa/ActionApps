<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\CollectOptions;
use AA\Central\Package\LintFinding;

/** Where the install page reads an app from: this AA (AppExport) or another one through its Get_App
 *  (RemoteAppSource). Both give package XML, so everything after it - Reader, Validator, Planner,
 *  Importer - is one path.
 *
 *  $filters (source slice id => ItemFilter text, UTF-8) narrow a transfer to the items they select: the
 *  ids, batches, logins and counts then cover those items only. A call takes only the filters of its own
 *  slices (ids compared lower case) and leaves the others out. Only a superadmin of the source may filter. */
interface AppSource
{
    /** item ids a page of itemIds() */
    public const ID_PAGE = 5000;

    /** The source is this installation: this AA, or a Central row naming it - known after definitions()
     *  (the source tells its AA_ID). A copy here gets new item ids and leaves the site's domains out. */
    public function sameAa(): bool;

    /** The ids of the slice's items after the cursor, in id order, ID_PAGE at most - a transfer only:
     *  another AA keeps the item ids, so the preview checks them before anything is written.
     *  @param array<string, string> $filters
     *  @return list<string> @throws ExportRefused|SourceFailed */
    public function itemIds(string $slice, bool $allBins, string $after, array $filters = []): array;

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
     *  @param array<string, string> $filters
     *  @param int $limit items at most, 1 - CollectOptions::MAX_ITEMS (an older source gives MAX_ITEMS)
     *  @return array{string, list<array{string, LintFinding}>} the XML, the export findings
     *  @throws ExportRefused|SourceFailed */
    public function batch(string $slice, string $after, bool $allBins, int $started, array $filters = [], int $limit = CollectOptions::MAX_ITEMS): array;

    /** The login names of the readers of Reader Management slices, UTF-8, sorted - a transfer only.
     *  @param list<string> $slices @param array<string, string> $filters @return list<string>
     *  @throws ExportRefused|SourceFailed */
    public function logins(array $slices, bool $allBins, array $filters = []): array;

    /** @param list<string> $slices @param int $editedAfter only the items edited after this unixtime; 0 = all
     *  @param array<string, string> $filters
     *  @return array<string, int> slice => items, in the bins of the run
     *  @throws ExportRefused|SourceFailed */
    public function itemCounts(array $slices, bool $allBins, int $editedAfter = 0, array $filters = []): array;

    /** Per slice: the relation values of its transferred items naming an item of a filtered slice that
     *  the transfer leaves out (FilteredSource::leftOut()) - a transfer only; all 0 without filters.
     *  @param array<string, string> $filters @param list<string> $slices the transferred slices
     *  @return array<string, int> slice => values
     *  @throws ExportRefused|SourceFailed */
    public function leftOut(array $filters, array $slices, bool $allBins): array;
}

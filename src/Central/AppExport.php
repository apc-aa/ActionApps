<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\CollectOptions;
use AA\Central\Package\CollectResult;
use AA\Central\Package\Collector;
use AA\Central\Package\FilteredSource;
use AA\Central\Package\FilterInvalid;
use AA\Central\Package\ItemFilter;
use AA\Central\Package\LintFinding;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Source;
use AA\Central\Package\Writer;
use Closure;
use InvalidArgumentException;

/** An app of this AA as package XML (Collector -> Writer) - for the install page of this AA and for
 *  Get_App, which serves it to the AA that installs it. The rights of the user are checked here, so both
 *  get the same: PS_EDIT on every module the package takes data from; a transfer hands out password
 *  hashes and 2FA secrets of the readers, so it needs a superadmin as well. The export the app store gives
 *  ($store) is the install of one site as a published package: strict - no warnings, no constant group
 *  of a slice outside the published sites. Any other export is not published, of a published site too.
 *  Item filters are a superadmin's in any mode: a count of the items a condition selects tells what they hold. */
final class AppExport implements AppSource
{
    /**
     * @param array<string, string>  $attributes meta/source: url, aa-version (exported is the run's start)
     * @param Closure(string): bool  $mayEdit    PS_EDIT on the module
     */
    public function __construct(
        private readonly Source $source,
        private readonly array $attributes,
        private readonly Closure $mayEdit,
        private readonly bool $superadmin,
        private readonly bool $store = false,
    ) {}

    /** @throws InvalidArgumentException no such site, slice or Alerts module
     *  @throws ExportRefused the user may not export it, or the export found errors */
    public function definitions(array $modules, bool $transfer, bool $allBins, int $started): array
    {
        $this->admit($transfer);
        $collector = new Collector($this->source);
        $site      = $this->site($modules);
        if ($this->store && ($transfer || ($site === null))) {
            throw ExportRefused::denied();
        }
        $store     = new AppStore($this->source);
        $app       = ($site === null) ? null : $store->site($site);
        $options   = $this->options($transfer, $allBins, $started, $this->store, ($app['description'] ?? '') ?: null, ($app['version'] ?? '') ?: null);
        $collected = ($site === null) ? $collector->fromModules($modules, $options) : $collector->fromSite($site, $options);
        [$xml, $findings] = $this->xml($collected, $this->store ? $store->unpublishedGroups($collected) : []);
        return [$xml, $findings, time()];
    }

    /** an install of this user carries the values of the slices' settings (they may hold keys): a superadmin's, never the store's */
    public function carriesSettings(bool $transfer): bool
    {
        return !$transfer && !$this->store && $this->superadmin;
    }

    /** @throws InvalidArgumentException not a slice, a cursor of no id, a limit out of range */
    public function batch(string $slice, string $after, bool $allBins, int $started, array $filters = [], int $limit = CollectOptions::MAX_ITEMS): array
    {
        return array_slice($this->batchOf($slice, $after, $allBins, $started, $filters, $limit), 0, 2);
    }

    /** batch() and the number of its items - Get_App logs it
     *  @param array<string, string> $filters
     *  @return array{string, list<array{string, LintFinding}>, int} @throws InvalidArgumentException */
    public function batchOf(string $slice, string $after, bool $allBins, int $started, array $filters = [], int $limit = CollectOptions::MAX_ITEMS): array
    {
        $this->admit(true);
        $this->check([$slice]);
        return $this->xml((new Collector($this->sourceFor($filters, [$slice])))->itemBatch($slice, $after, $this->options(true, $allBins, $started), $limit));
    }

    /** A login that does not convert to UTF-8 refuses them all: the target could not check it against its users.
     *  @throws InvalidArgumentException a module that is no Reader Management slice */
    public function logins(array $slices, bool $allBins, array $filters = []): array
    {
        $this->admit(true);
        $this->check($slices);
        [$logins, $findings] = (new Collector($this->sourceFor($filters, $slices)))->readerLogins($slices, $allBins);
        if ($findings) {
            throw ExportRefused::failed($findings);
        }
        return $logins;
    }

    public function sameAa(): bool
    {
        return true;
    }

    /** @throws InvalidArgumentException a cursor of no id */
    public function itemIds(string $slice, bool $allBins, string $after, array $filters = []): array
    {
        $this->admit(true);
        $this->check([$slice]);
        return $this->sourceFor($filters, [$slice])->itemIds($slice, $allBins, $after, self::ID_PAGE);
    }

    public function itemCounts(array $slices, bool $allBins, int $editedAfter = 0, array $filters = []): array
    {
        $filters = ItemFilter::ofSlices($filters, $slices);
        $this->admit((bool) $filters);
        $this->check($slices);
        $source = $filters ? $this->filtered($filters) : $this->source;
        $counts = [];
        foreach ($slices as $slice) {
            $counts[$slice] = $source->itemCount($slice, $allBins, $editedAfter);
        }
        return $counts;
    }

    public function leftOut(array $filters, array $slices, bool $allBins): array
    {
        $this->admit(true);
        $this->check($slices);
        $filters = ItemFilter::ofSlices($filters, $slices);
        return $filters ? $this->filtered($filters)->leftOut($slices, $allBins) : array_fill_keys($slices, 0);
    }

    /** the source narrowed by the filters of the slices - the caller has checked the rights on them
     *  @param array<string, string> $filters @param list<string> $slices @throws ExportRefused */
    private function sourceFor(array $filters, array $slices): Source
    {
        $filters = ItemFilter::ofSlices($filters, $slices);
        return $filters ? $this->filtered($filters) : $this->source;
    }

    /** The source of the items the filters select. Read only after the rights on the slices: even the
     *  error about an unknown field tells something of a slice.
     *  @param array<string, string> $filters slice id => text @throws ExportRefused */
    private function filtered(array $filters): FilteredSource
    {
        try {
            return FilteredSource::of($this->source, $filters);
        } catch (FilterInvalid $e) {
            throw ExportRefused::failed([[Module::SLICE . "[$e->slice]", new LintFinding(LintFinding::ERROR, ItemFilter::INVALID, $e->reason, 0)]]);
        }
    }

    /** @throws ExportRefused */
    private function admit(bool $transfer): void
    {
        if ($transfer && !$this->superadmin) {
            throw ExportRefused::denied();
        }
    }

    /** @param list<string> $modules @throws ExportRefused */
    private function check(array $modules): void
    {
        foreach ($modules as $module) {
            if (!($this->mayEdit)($module)) {
                throw ExportRefused::denied($module);
            }
        }
    }

    /** the id when $modules is one site - its modules come with it
     *  @param list<string> $modules */
    private function site(array $modules): ?string
    {
        if ((count($modules) !== 1) || !preg_match('/^[0-9a-f]{32}\z/', (string) reset($modules))) {
            return null;
        }
        $row = $this->source->select('module', [['id', $modules, 'l']])[0] ?? [];
        return (($row['type'] ?? '') === 'W') ? (string) reset($modules) : null;
    }

    private function options(bool $transfer, bool $allBins, int $started, bool $published = false, ?string $description = null, ?string $version = null): CollectOptions
    {
        $source = $this->attributes + ['exported' => gmdate('Y-m-d\TH:i:s\Z', $started)];
        return new CollectOptions(
            $source,
            $transfer ? CollectOptions::TRANSFER : CollectOptions::INSTALL,
            $allBins,
            description: $description,
            version: $version,
            published: $published,
            settingValues: !$published && $this->carriesSettings($transfer),
        );
    }

    /** @param list<array{string, LintFinding}> $more findings beside the collector's
     *  @return array{string, list<array{string, LintFinding}>, int} @throws ExportRefused */
    private function xml(CollectResult $collected, array $more = []): array
    {
        $this->check($collected->checkPermissions);
        $collected = $more ? new CollectResult($collected->package, [...$collected->findings, ...$more], $collected->outside, $collected->checkPermissions) : $collected;
        if ($collected->failed()) {
            throw ExportRefused::failed($collected->findings);
        }
        $written = (new Writer())->toXml($collected->package);
        $items   = array_sum(array_map(static fn(Module $m): int => count($m->items), $collected->package->modules));
        return [$written->xml, $collected->withWriter($written), $items];
    }
}

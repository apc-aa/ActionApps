<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\Isds\Accounts;
use AA\Isds\Binding;
use AA\Isds\Registry\Box;
use AA\Isds\Registry\Config;
use AA\Isds\Registry\DownloadedRegister;
use AA\Isds\Registry\MatchRow;
use AA\Isds\Registry\Page;
use AA\Isds\Registry\Sync;
use AA\Isds\SliceRules;
use AA_Module;
use AA_Module_Site;

/**
 * Items of the slices paired with the register of data boxes (admin/se_isds_registry.php) that have no
 * data box, or another one than the register finds by their IČO. Reading every item of those slices and
 * the 17 MB register takes seconds, so this runs on request and in the daily snapshot. It downloads
 * nothing - the slice's page does - and its findings give counts only: the State Check mail would carry
 * item names. It also names the ISDS accounts whose sites use no paired slice to take recipients from.
 */
class CheckIsdsRegistry extends AbstractOptimize
{
    public static function name(): string
    {
        return _m('Data boxes of the items');
    }

    public static function description(): string
    {
        return _m('Compares the data boxes of the items with the downloaded register of data boxes, in the slices that pair their items with it, and names the ISDS accounts whose sites use no such slice.');
    }

    public static function types(): array
    {
        return [self::CHECK_EXTENDED];
    }

    public static function category(): string
    {
        return 'content';
    }

    public static function actions(): array
    {
        return ['test'];
    }

    /** @return Finding[] */
    public function findings(): array
    {
        $configs  = array_values(array_filter($this->allConfigs(), fn(Config $config): bool => $this->isStoredSlice($config->sliceId())));
        $unpaired = $this->unpaired($configs);
        if (!$configs) {
            // nothing to compare, and no page to download the register on
            return $unpaired ?: [new Finding(Finding::OK, _m('No slice pairs its items with the register of data boxes'))];
        }
        $findings = [...$unpaired, ...$this->registerFindings($configs)];
        return $findings ?: [new Finding(Finding::OK, _m('Paired slices whose items have the data boxes of the register: %1', [count($configs)]))];
    }

    /**
     * The sites of ISDS accounts that use no paired slice: a mass sending takes the names, types and
     * boxes of its recipients from a slice the site of its page uses, by the slice's settings of data
     * boxes (SliceRules::sourceProblems())
     * @param list<Config> $configs
     * @return list<Finding>
     */
    private function unpaired(array $configs): array
    {
        $paired   = array_map(static fn(Config $config): string => $config->sliceId(), $configs);
        $evidence = [];
        $links    = [];
        foreach ($this->bindings() as $binding) {
            // a binding without its slices is an error of CheckIsdsAccounts, as is an id that is no site
            if ($binding->campaignSlice === '') {
                continue;
            }
            foreach ($binding->sites as $site) {
                if (!$this->isSite($site) || array_intersect($paired, $this->siteModules([$site]))) {
                    continue;
                }
                $name       = $this->sliceName($site);
                $evidence[] = [_m('ISDS account') => safe(Accounts::prefix($binding->environment, $binding->name)), _m('Site') => safe($name)];
                $links[SliceRules::pageLabel(SliceRules::SITE_PAGE) . ': ' . $name] = AA_Module::getModuleUrl($site, SliceRules::SITE_PAGE);
            }
        }
        return $evidence ? [new Finding(
            Finding::WARNING,
            _m('Sites of ISDS accounts use no slice with settings of data boxes, so a mass sending cannot take its recipients'),
            count: count($evidence),
            evidence: $evidence,
            advice: _m('Open the page Data boxes from the register in the settings of the slice the campaigns take their recipients from - a public slice the site uses, see Uses modules in the settings of the site - and choose the fields of its items there: the name, the type and the data box a mass sending sends to.'),
            links: $links,
            key: 'unpaired',
        )] : [];
    }

    /**
     * @param list<Config> $configs at least one
     * @return list<Finding>
     */
    private function registerFindings(array $configs): array
    {
        $names = $this->sliceLabels($configs);
        $pages = [];
        foreach ($configs as $config) {
            $pages[_m('Data boxes of %1', [$names[$config->sliceId()]])] = Page::urlOf($config->sliceId());
        }
        $download = _m('Download it on the page Data boxes from the register of a paired slice.');
        try {
            $info = $this->registerInfo();
        } catch (\RuntimeException $e) {
            // the message names the directory, or the setting that would give one
            return [new Finding(Finding::WARNING, _m('The directory of the register of data boxes cannot be used'), evidence: [[_m('Problem') => safe($e->getMessage())]], advice: _m('Let PHP search the directory, or set IMG_UPLOAD_PATH in config.php. Then download the register on the page Data boxes from the register of a paired slice.'), links: $pages, key: 'register-dir')];
        }
        if ($info === null) {
            return [new Finding(Finding::INFO, _m('The register of data boxes has not been downloaded'), advice: $download . ' ' . _m('The items are compared with it.'), links: $pages, key: 'register')];
        }
        $unusable = static fn(string $state): Finding => new Finding(
            Finding::WARNING,
            Page::unusableText($state),
            advice: _m('Download the register again on the page Data boxes from the register of a paired slice. Until then the items are not compared with the register.'),
            links: $pages,
            key: 'register-changed',
        );
        if ($info['state'] !== DownloadedRegister::RECORDED) {
            return [$unusable($info['state'])];
        }
        $age = Sync::isOld($info['time'], $this->now()) ? [new Finding(Finding::INFO, _m('The downloaded register of data boxes is older than %1 days', [Sync::OLD_DAYS]), evidence: [[_m('Downloaded') => date('Y-m-d H:i', $info['time'])]], advice: _m('Download it again on the page Data boxes from the register of a paired slice: authorities get new boxes and merge.'), links: $pages, key: 'register')] : [];
        try {
            // once for all slices; a register that cannot be read is no fault of their settings
            $boxes = $this->registerBoxes($info['sha256']);
        } catch (\RuntimeException $e) {
            $advice = ($e instanceof \UnexpectedValueException)
                ? _m('The copy of the register is damaged: downloading it again on the page Data boxes from the register of a paired slice replaces it.')
                : _m('The copy of the register cannot be read: check the directory and the permissions named in the evidence.');
            return [...$age, new Finding(Finding::WARNING, _m('The downloaded register of data boxes cannot be read'), evidence: [[_m('Error') => safe($e->getMessage())]], advice: $advice, links: $pages, key: 'register-unreadable')];
        }
        if ($boxes === null) {
            // the age of a register that cannot be used is moot
            return [$unusable(DownloadedRegister::REPLACED)];
        }
        $findings = $age;
        foreach ($configs as $config) {
            $finding = $this->sliceFinding($config, $boxes, $names[$config->sliceId()]);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }
        return $findings;
    }

    /**
     * Only items without a box and boxes the IČO contradicts warn: a box found by name is a suggestion
     * a person checks. The evidence counts what the page's filters count.
     * @param list<Box> $boxes
     */
    private function sliceFinding(Config $config, array $boxes, string $label): ?Finding
    {
        $slice = $config->sliceId();
        $place = $this->sliceName($slice);
        $page  = [_m('Data boxes from the register') => Page::urlOf($slice)];
        try {
            $report = $this->newSync($config)->report($boxes);
        } catch (\RuntimeException $e) {
            // the messages of Config::errors() name fields, never items
            return new Finding(Finding::WARNING, _m('The pairing of slice "%1" with the register no longer fits its fields', [$label]), evidence: [[_m('Problem') => safe($e->getMessage())]], advice: _m('Choose the fields again in the settings on the page Data boxes from the register.'), links: $page, key: "fit:$slice", place: $place);
        }
        $without = count(array_filter($report->rows, static fn(MatchRow $row): bool => trim($row->oldDs) === ''));
        $other   = count(array_filter($report->rows, static fn(MatchRow $row): bool => ($row->status === MatchRow::CHANGED) && ($row->method === MatchRow::BY_ICO)));
        if (($without + $other) === 0) {
            return null;
        }
        $evidence = [_m('Slice') => safe($label)];
        foreach ($report->statusCounts() as $status => $count) {
            if ($status !== MatchRow::SAME) {
                $evidence[MatchRow::statusLabel($status)] = (string) $count;
            }
        }
        return new Finding(
            Finding::WARNING,
            _m('Slice "%1": items without a data box: %2, items with another box than the register by IČO: %3', [$label, $without, $other]),
            count: $without + $other,
            evidence: [$evidence],
            advice: _m('Open the page Data boxes from the register, check the boxes it found and write them. Items the register does not find need their box filled in by hand.'),
            links: $page,
            key: $slice,
            place: $place,
        );
    }

    /**
     * The names of the slices, each told apart by the start of its id where two share one
     * @param list<Config> $configs
     * @return array<string, string> slice id => label
     */
    private function sliceLabels(array $configs): array
    {
        $names = [];
        foreach ($configs as $config) {
            $names[$config->sliceId()] = $this->sliceName($config->sliceId());
        }
        $counts = array_count_values($names);
        foreach ($names as $slice => $name) {
            if ($counts[$name] > 1) {
                $names[$slice] = $name . ' (' . substr($slice, 0, 8) . ')';
            }
        }
        return $names;
    }

    // ------------------------------------------------------------ what tests replace

    /** @return list<Config> */
    protected function allConfigs(): array
    {
        return Config::all();
    }

    /** a slice in the trash keeps its settings until it is purged */
    protected function isStoredSlice(string $sliceId): bool
    {
        return \AA_Slice::isStoredSlice($sliceId);
    }

    protected function newSync(Config $config): Sync
    {
        return new Sync($config);
    }

    /** @return list<Binding> */
    protected function bindings(): array
    {
        return Accounts::bindings();
    }

    /** a site module out of the trash */
    protected function isSite(string $id): bool
    {
        return (bool) AA_Module_Site::getOwnModule($id)?->isValid();
    }

    /**
     * @param list<string> $sites
     * @return array<string> the modules the sites use, out of the trash
     */
    protected function siteModules(array $sites): array
    {
        return AA_Module_Site::getRelatedModules($sites);
    }

    /**
     * @return ?array{state: string, time: int, size: int, sha256: string}
     * @throws \RuntimeException see Sync::registerInfo()
     */
    protected function registerInfo(): ?array
    {
        return Sync::registerInfo();
    }

    /**
     * @return ?list<Box> see Sync::registerBoxes()
     * @throws \RuntimeException see Sync::registerBoxes()
     */
    protected function registerBoxes(string $sha256): ?array
    {
        return Sync::registerBoxes($sha256);
    }

    /** the name of the module - a site's too */
    protected function sliceName(string $sliceId): string
    {
        return \AA_Slice::getModuleName($sliceId) ?: $sliceId;
    }

    protected function now(): int
    {
        return time();
    }
}

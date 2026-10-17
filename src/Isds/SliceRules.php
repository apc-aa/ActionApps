<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\IO\DB\DB_AA;
use AA\Isds\Registry\Config;
use AA_Field;
use AA_Generator;
use AA_Generator_Txt;
use AA_Module;
use AA_Module_Site;
use AA_Slice;

/**
 * What the site and the two slices of a mass sending must be before AA opens the slices for it: the
 * site uses both slices, each slice keeps its items behind a reading password, lets nobody in
 * another way and sends no copy of them anywhere, and has the fields of Schema. State Check and the
 * page that sends ask the same rules, so a slice the page refuses is one State Check names.
 */
class SliceRules
{
    /** settings of the slice that must be 0: each lets items out without the reading password */
    public const CLOSED = ['d_listlen', 'permit_anonymous_post', 'permit_anonymous_edit'];

    // the admin pages that fix a problem
    public const SLICE_PAGE    = 'admin/slicedit.php';
    public const FIELDS_PAGE   = 'admin/se_fields.php';
    public const FEEDING_PAGE  = 'admin/se_import.php';
    public const NOTIFY_PAGE   = 'admin/se_notify.php';
    public const SITE_PAGE     = 'modules/site/modedit.php';
    public const REGISTRY_PAGE = 'admin/se_isds_registry.php';

    /** where else the items of a slice may go, see outlets() */
    public const OUTLETS = ['feeds_out', 'feeds_in', 'export', 'notify'];

    /**
     * The slices themselves: they exist, they are closed, they have the fields. Once for a pair, as
     * many sites may use it.
     * @param string $campaignSlice unpacked id
     * @param string $messageSlice  unpacked id
     * @return list<SliceProblem> none when ready
     */
    public function sliceProblems(string $campaignSlice, string $messageSlice): array
    {
        $problems = [];
        $fields   = [];
        $roles    = [[$campaignSlice, true], [$messageSlice, false]];
        if ((ModuleId::normalize($campaignSlice) ?? '') === ModuleId::normalize($messageSlice)) {
            $problems[] = new SliceProblem(_m('The campaign slice and the message slice must be two slices'));
            $roles      = [$roles[0]];
        }
        foreach ($roles as [$typed, $campaign]) {
            $id    = ModuleId::normalize($typed);
            $slice = is_null($id) ? null : $this->slice($id);
            if (is_null($id) || is_null($slice)) {
                $problems[] = $this->absent($typed, $id);
                continue;
            }
            $fields[$campaign ? 'campaign' : 'message'] = [$id, iterator_to_array($slice->getFields())];
            array_push($problems, ...$this->closure($id, $slice), ...$this->outletProblems($id));
        }

        return [...$problems, ...$this->schema($fields['campaign'] ?? null, $fields['message'] ?? null)];
    }

    /**
     * The site uses both slices and lets AA tell who may send. Once for each site of the pair.
     * @return list<SliceProblem> none when ready
     */
    public function siteProblems(string $site, string $campaignSlice, string $messageSlice): array
    {
        $id = ModuleId::normalize($site);
        if (is_null($id) || !$this->isSite($id)) {
            return [(!is_null($id) && $this->inTrash($id))
                ? new SliceProblem(_m('Site "%1" is in the trash - restore it from the trash, or name another site', [$this->name($id)]))
                : new SliceProblem(_m('There is no site %1', [$site]))];
        }
        $problems = [];
        if ($this->onlyReadersLogIn($id)) {
            $problems[] = new SliceProblem(_m('Site "%1" lets only readers of its Reader Management slices log in, so nobody gets the right to send - set User login databases to "%2" in the settings of the site', [$this->name($id), _m('AA + all Reader Management slices in AA (old behavior)')]), $id, self::SITE_PAGE);
        }
        // whether a slice the site does not use is there at all is none of the template's business; one in
        // the trash is a problem of the slices - the site cannot use it
        $slices = array_filter(array_unique([ModuleId::normalize($campaignSlice), ModuleId::normalize($messageSlice)]), fn(?string $slice): bool => !is_null($slice) && !$this->inTrash($slice));

        return [...$problems, ...$this->unused($id, array_values($slices))];
    }

    /** site.php leaves AA only the logins of the site's readers then, and nobody has a right there - nor may fix the page */
    public function onlyReadersLogIn(string $site): bool
    {
        return in_array($this->sitePermMode($site), ['1', '2'], true);
    }

    /**
     * The slice the recipients come from: the site uses it, its settings of data boxes (Registry\Config)
     * name the field of the type of an item and pair each type offered. Of a slice the site does not
     * use it tells only that - the template may name a slice of another site.
     * @param list<string> $types       the item types the page offers
     * @param string       $memberField the field that tells a member; '' = none
     * @return list<SliceProblem> none when ready
     */
    public function sourceProblems(string $site, string $sourceSlice, array $types, string $memberField): array
    {
        $id = ModuleId::normalize($sourceSlice);
        if (is_null($id)) {
            return [$this->absent($sourceSlice, $id)];
        }
        $problems = $this->unused((string) ModuleId::normalize($site), [$id]);
        if ($problems) {
            return $problems;
        }
        $slice = $this->slice($id);
        if (is_null($slice)) {
            return [$this->absent($sourceSlice, $id)];
        }
        $name   = $this->name($id);
        $config = $this->registryConfig($id);
        if (is_null($config)) {
            $problems[] = new SliceProblem(_m('Slice "%1" has no settings of data boxes - set its fields on its page Data boxes from the register', [$name]), $id, self::REGISTRY_PAGE);
        } elseif ($config->typeField() === '') {
            $problems[] = new SliceProblem(_m('Slice "%1": choose the Type field in its settings of data boxes - recipients are chosen by type', [$name]), $id, self::REGISTRY_PAGE);
        } elseif ($config->types() && ($unpaired = array_values(array_diff($types, $config->types())))) {
            $problems[] = new SliceProblem(_m('Slice "%1" pairs no items of the types %2 with data boxes - offer only its Types to pair: %3', [$name, implode(', ', $unpaired), implode(', ', $config->types())]), $id, self::REGISTRY_PAGE);
        }
        if ($memberField !== '') {
            $field = $slice->getField($memberField);
            if (is_null($field)) {
                $problems[] = new SliceProblem(_m('Slice "%1" has no field %2 - name the field that tells a member', [$name, $memberField]), $id, self::FIELDS_PAGE);
            } elseif ($field->isProtected()) {
                $problems[] = new SliceProblem(_m('The field %1 of slice "%2" is protected - name the field that tells a member', [$memberField, $name]), $id, self::FIELDS_PAGE);
            }
        }

        return $problems;
    }

    /** the label of a page that fixes a problem (SliceProblem::$page) */
    public static function pageLabel(string $page): string
    {
        return match ($page) {
            self::SLICE_PAGE    => _m('Slice settings'),
            self::FIELDS_PAGE   => _m('Fields'),
            self::FEEDING_PAGE  => _m('Inner Node Feeding'),
            self::NOTIFY_PAGE   => _m('Email Notification'),
            self::SITE_PAGE     => _m('Site settings'),
            self::REGISTRY_PAGE => _m('Data boxes from the register'),
            default             => $page,
        };
    }

    /**
     * The pages that fix the problems, one link for each page of each module. Two modules of one name
     * are told apart by the start of their ids.
     * @param list<SliceProblem> $problems
     * @return array<string, string> label => url
     */
    public function links(array $problems): array
    {
        $names = [];
        foreach ($problems as $problem) {
            if (($problem->module !== '') && ($problem->page !== '')) {
                $names[$problem->module] ??= $this->name($problem->module);
            }
        }
        $counts = array_count_values($names);
        $links  = [];
        foreach ($problems as $problem) {
            if (!isset($names[$problem->module]) || ($problem->page === '')) {
                continue;
            }
            $name = $names[$problem->module];
            if ($counts[$name] > 1) {
                $name .= ' (' . substr($problem->module, 0, 8) . ')';
            }
            $links[self::pageLabel($problem->page) . ': ' . $name] = AA_Module::getModuleUrl($problem->module, $problem->page);
        }

        return $links;
    }

    /** the name of the module, raw - without the marks of one in the trash; the id when it has none */
    public function name(string $id): string
    {
        return (string) AA_Module::getModuleProperty($id, 'name') ?: $id;
    }

    /** a slice that is not there, or is in the trash */
    private function absent(string $typed, ?string $id): SliceProblem
    {
        return (!is_null($id) && $this->inTrash($id))
            ? new SliceProblem(_m('Slice "%1" is in the trash - restore it from the trash, or name another slice', [$this->name($id)]))
            : new SliceProblem(_m('There is no slice %1', [$typed]));
    }

    /**
     * @param list<string> $slices ids in small letters
     * @return list<SliceProblem> a slice the site does not use - by its id: its name is none of the site's business
     */
    private function unused(string $site, array $slices): array
    {
        $problems = [];
        foreach (array_diff($slices, $this->relatedModules($site)) as $slice) {
            $problems[] = new SliceProblem(_m('Site "%1" does not use slice %2 - add the slice to Uses modules in the settings of the site', [$this->name($site), $slice]), $site, self::SITE_PAGE);
        }

        return $problems;
    }

    /** @return list<SliceProblem> */
    private function closure(string $id, AA_Slice $slice): array
    {
        $name     = $this->name($id);
        $problems = [];
        // AA reads it as an md5 hash - and takes a falsy one, '0' too, for no password at all
        if (!$slice->getProperty('reading_password')) {
            $problems[] = new SliceProblem(_m('Slice "%1" has no reading password - fill in Password for Reading in the settings of the slice', [$name]), $id, self::SLICE_PAGE);
        }
        $open = array_filter(self::CLOSED, static fn(string $setting): bool => (int) $slice->getProperty($setting) !== 0);
        if ($open) {
            $problems[] = new SliceProblem(_m('Close slice "%1" - set in its settings %2', [$name, implode('; ', array_map(self::closedValue(...), $open))]), $id, self::SLICE_PAGE);
        }
        if ((int) $slice->getProperty('export_to_all') !== 0) {
            $problems[] = new SliceProblem(_m('Slice "%1" lets any slice import its items - turn off Enable export to any slice on its Inner Node Feeding page', [$name]), $id, self::FEEDING_PAGE);
        }

        return $problems;
    }

    /**
     * Feeding copies items into slices without the password, and a notification mails them - the
     * slice's reading password protects neither copy.
     * @return list<SliceProblem>
     */
    private function outletProblems(string $id): array
    {
        $name     = $this->name($id);
        $outlets  = $this->outlets($id);
        $problems = [];
        if ($outlets['export']) {
            $problems[] = new SliceProblem(_m('Slice "%1" lets other slices import its items - move them to Export disable on its Inner Node Feeding page', [$name]), $id, self::FEEDING_PAGE);
        }
        if ($outlets['feeds_out']) {
            $problems[] = new SliceProblem(_m('Items of slice "%1" are fed to other slices - stop the import in the slices its Inner Node Feeding page lists as Currently exported to', [$name]), $id, self::FEEDING_PAGE);
        }
        // items nobody wrote through the sending would come in, and be sent
        if ($outlets['feeds_in']) {
            $problems[] = new SliceProblem(_m('Slice "%1" imports items from other slices - move them to Do not import on its Inner Node Feeding page', [$name]), $id, self::FEEDING_PAGE);
        }
        if ($outlets['notify']) {
            $problems[] = new SliceProblem(_m('Slice "%1" mails its items to the addresses of its Email Notification - remove the addresses there', [$name]), $id, self::NOTIFY_PAGE);
        }

        return $problems;
    }

    /**
     * Missing fields first; the storage and the defaults only of a slice that has all fields of its
     * role - another slice would get advice for fields it should not have.
     * @param ?array{string, array<string, AA_Field>} $campaign the slice id and its fields; null = not there
     * @param ?array{string, array<string, AA_Field>} $message  as $campaign
     * @return list<SliceProblem>
     */
    private function schema(?array $campaign, ?array $message): array
    {
        $lacks = static fn(?array $slice, array $role): array => is_null($slice) ? [] : array_values(array_diff($role, array_keys($slice[1])));
        if ($campaign && $message && $lacks($campaign, Schema::CAMPAIGN_FIELDS) && !$lacks($campaign, Schema::MESSAGE_FIELDS)
            && $lacks($message, Schema::MESSAGE_FIELDS) && !$lacks($message, Schema::CAMPAIGN_FIELDS)) {
            return [new SliceProblem(_m('Slices "%1" and "%2" are in reverse order - name the campaign slice first', [$this->name($campaign[0]), $this->name($message[0])]))];
        }
        $problems = [];
        foreach ([[$campaign, true], [$message, false]] as [$slice, $isCampaign]) {
            if (is_null($slice)) {
                continue;
            }
            [$id, $fields] = $slice;
            $name    = $this->name($id);
            $missing = $lacks($slice, $isCampaign ? Schema::CAMPAIGN_FIELDS : Schema::MESSAGE_FIELDS);
            if ($missing) {
                $problems[] = new SliceProblem($isCampaign
                    ? _m('Slice "%1" lacks fields of a campaign slice: %2', [$name, implode(', ', $missing)])
                    : _m('Slice "%1" lacks fields of a message slice: %2', [$name, implode(', ', $missing)]), $id, self::FIELDS_PAGE);
                continue;
            }
            // a date or a number stored as text neither sorts nor compares
            $text = array_values(array_filter($isCampaign ? Schema::CAMPAIGN_NUMBER_FIELDS : Schema::MESSAGE_NUMBER_FIELDS, static fn(string $field): bool => $fields[$field]->storageColumn() !== 'number'));
            if ($text) {
                $problems[] = new SliceProblem(_m('Slice "%1": store the fields %2 as Number (Stored as, in the settings of each field)', [$name, implode(', ', $text)]), $id, self::FIELDS_PAGE);
            }
            foreach ($isCampaign ? Schema::CAMPAIGN_DEFAULTS : Schema::MESSAGE_DEFAULTS as $field => $value) {
                // a fixed text only, qte:draft as txt:draft - another generator (rnd, var, uid) is never run here
                $generator = AA_Generator::factoryByString((string) $fields[$field]->getProperty('input_default'));
                if (!($generator instanceof AA_Generator_Txt) || ((string) $generator->generate()->getValue() !== $value)) {
                    $problems[] = new SliceProblem(_m('Slice "%1": set the Default of the field %2 to %3', [$name, $field, "txt:$value"]), $id, self::FIELDS_PAGE);
                }
            }
        }

        return $problems;
    }

    /**
     * "Listing length: 0" - the label of the setting and the value it needs
     * @param value-of<self::CLOSED> $setting
     */
    private static function closedValue(string $setting): string
    {
        return match ($setting) {
            'd_listlen'             => _m('Listing length') . ': 0',
            'permit_anonymous_post' => _m('Allow anonymous posting of items') . ': ' . _m('Not allowed'),
            'permit_anonymous_edit' => _m('Allow anonymous editing of items') . ': ' . _m('Not allowed'),
        };
    }

    // ------------------------------------------------------------ what tests replace

    /** the slice, when it is there and out of the trash */
    protected function slice(string $id): ?AA_Slice
    {
        // a class check only; isValid() says whether the module row is there, and not deleted
        $slice = AA_Slice::getOwnModule($id);
        return $slice?->isValid() ? $slice : null;
    }

    /** a site module out of the trash */
    protected function isSite(string $id): bool
    {
        // getOwnModule() gives the class of the module's type, so a slice or an unknown id is null
        return (bool) AA_Module_Site::getOwnModule($id)?->isValid();
    }

    protected function inTrash(string $id): bool
    {
        return (bool) AA_Module::getModule($id)?->deleted();
    }

    /** @return list<string> the modules the site uses, out of the trash */
    protected function relatedModules(string $site): array
    {
        return array_values(AA_Module_Site::getRelatedModules([$site]));
    }

    /** User login databases of the site: '1' or '2' = only its Reader Management slices log in on its pages */
    protected function sitePermMode(string $site): string
    {
        return (string) AA_Module_Site::getModuleProperty($site, 'perm_mode');
    }

    /** the settings of data boxes of the slice */
    protected function registryConfig(string $slice): ?Config
    {
        return Config::forSlice($slice);
    }

    /**
     * @return array<value-of<self::OUTLETS>, bool> feeds_out: a feed from the slice; feeds_in: a feed into it;
     *                                              export: other slices may import from it; notify: notification addresses
     */
    protected function outlets(string $slice): array
    {
        // the sending page asks on every render, State Check once for every account of the pair
        $there = static fn(string $table, string $column): bool => (bool) \AA::Contentcache()->get_result([DB_AA::class, 'test'], [$table, [[$column, $slice, 'l']]]);

        return [
            'feeds_out' => $there('feeds', 'from_id'),
            // no index on to_id - a row per feed between two slices, tens of them
            'feeds_in'  => $there('feeds', 'to_id'),
            'export'    => $there('feedperms', 'from_id'),
            'notify'    => $there('email_notify', 'slice_id'),
        ];
    }
}

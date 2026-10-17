<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\IO\DB\DB_AA;
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
    public const SLICE_PAGE   = 'admin/slicedit.php';
    public const FIELDS_PAGE  = 'admin/se_fields.php';
    public const FEEDING_PAGE = 'admin/se_import.php';
    public const NOTIFY_PAGE  = 'admin/se_notify.php';
    public const SITE_PAGE    = 'modules/site/modedit.php';

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
                $problems[] = (!is_null($id) && $this->inTrash($id))
                    ? new SliceProblem(_m('Slice "%1" is in the trash - restore it from the trash, or name another slice', [$this->name($id)]))
                    : new SliceProblem(_m('There is no slice %1', [$typed]));
                continue;
            }
            $fields[$campaign ? 'campaign' : 'message'] = [$id, iterator_to_array($slice->getFields())];
            array_push($problems, ...$this->closure($id, $slice), ...$this->outletProblems($id));
        }

        return [...$problems, ...$this->schema($fields['campaign'] ?? null, $fields['message'] ?? null)];
    }

    /**
     * The site uses both slices. Once for each site of the pair.
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
        // a slice that is not there is a problem of the slices
        $slices   = array_filter(array_unique([ModuleId::normalize($campaignSlice), ModuleId::normalize($messageSlice)]), fn(?string $slice): bool => !is_null($slice) && !is_null($this->slice($slice)));
        $problems = [];
        foreach (array_diff($slices, $this->relatedModules($id)) as $slice) {
            $problems[] = new SliceProblem(_m('Site "%1" does not use slice "%2" - add the slice to Uses modules in the settings of the site', [$this->name($id), $this->name($slice)]), $id, self::SITE_PAGE);
        }

        return $problems;
    }

    /** the name of the module, raw - without the marks of one in the trash; the id when it has none */
    public function name(string $id): string
    {
        return (string) AA_Module::getModuleProperty($id, 'name') ?: $id;
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

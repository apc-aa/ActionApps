<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Isds\Registry\Box;
use AA\Isds\Registry\Config;
use AA_Set;
use ItemContent;
use zids;

/**
 * Whom a campaign goes to: the active items of the source slice its filter chooses, each data box
 * once. AA's search finds them, GetItemContent() reads them with the reading passwords of the request
 * - the source slice is a public one (SliceRules). The search is scoped to the source slice, as the
 * ids to include come from the browser: one locked slice in a GetItemContent() call would mask every
 * item of the call. The box is the item's as it stands: the items of the source slice are trusted, the
 * page Data boxes from the register keeps their boxes right.
 */
class Recipients
{
    /** items GetItemContent() reads at once */
    private const CHUNK = 500;

    /**
     * Who gets the letter, and who is skipped for the box (reason) - by name in Czech order, then by
     * id; of the items with one box the first keeps it. The count a user confirms and the count sent
     * must both come from here.
     * @param Config $config the settings of data boxes of the source slice
     * @return list<Recipient>
     * @throws \RuntimeException the settings name no type field
     */
    public function resolve(Setup $setup, RecipientFilter $filter, Config $config): array
    {
        $type = $config->typeField();
        if ($type === '') {
            throw new \RuntimeException('The settings of data boxes of the source slice name no type field');
        }
        $slice = $setup->sourceSlice;
        $typed = [];
        foreach ($filter->types as $t) {
            // == is the exact match: LIKE would take mesto for mestostatut too
            $typed += array_flip($this->search($slice, [[$type => 1, 'operator' => '==', 'value' => $t]]));
        }
        $include  = array_values(array_diff($filter->include, $filter->exclude));
        $included = $include ? array_flip($this->search($slice, [], $include)) : [];
        $ids      = array_values(array_diff(array_map('strval', array_keys($typed + $included)), $filter->exclude));
        if (!$ids) {
            return [];
        }

        $wanted     = array_map(self::typeKey(...), $filter->types);
        $fields     = array_values(array_unique(array_filter([$config->nameField(), $type, $config->dsField(), $setup->memberField])));
        $recipients = [];
        foreach ($this->content($ids, $fields) as $id => $content4id) {
            $item   = new ItemContent($content4id);
            $member = ($setup->memberField !== '') && self::isMember((string) $item->getValue($setup->memberField));
            // the type again, as the database compares it: a type field gone from the slice makes the search
            // skip the condition and find every item; the collation equates accents too (e, ě), this does not
            $byFilter = array_intersect(array_map(self::typeKey(...), $item->getValuesArray($type)), $wanted) && match ($filter->membership) {
                RecipientFilter::MEMBERS    => $member,
                RecipientFilter::NONMEMBERS => !$member,
                default                     => true,
            };
            if ($byFilter || isset($included[$id])) {
                $recipients[] = self::recipient((string) $id, (string) $item->getValue($config->nameField()), (string) $item->getValue($config->dsField()));
            }
        }
        $czech = new \Collator('cs_CZ');
        usort($recipients, static fn(Recipient $a, Recipient $b): int => $czech->compare($a->name, $b->name) ?: strcmp($a->itemId, $b->itemId));

        $seen = [];
        foreach ($recipients as $i => $r) {
            if (!$r->sendable()) {
                continue;
            }
            if (isset($seen[$r->box])) {
                $recipients[$i] = new Recipient($r->itemId, $r->name, $r->box, Schema::BOX_DUPLICATE);
            }
            $seen[$r->box] = true;
        }

        return $recipients;
    }

    /**
     * Of $ids those that are active items of the slice, in their order - an id the browser or a stored
     * filter names may be of any slice, or in a bin
     * @param list<string> $ids long ids in small letters
     * @return list<string>
     */
    public function ofSource(string $slice, array $ids): array
    {
        $found = $ids ? array_flip($this->search($slice, [], $ids)) : [];

        return array_values(array_filter($ids, static fn(string $id): bool => isset($found[$id])));
    }

    /**
     * Active items of the slice of one of $types whose name holds $text, by name, at most $max. % and _
     * match themselves (CONTAIN); a backslash is left out of $text: AA's LIKE escapes it after quoting
     * and it would make them wildcards again - and no name needs it.
     * @param list<string> $types as Setup takes them - letters, digits, spaces and - _ . only
     * @return list<string> long ids
     */
    public function named(string $slice, Config $config, array $types, string $text, int $max): array
    {
        $name = $config->nameField();
        $type = $config->typeField();
        $text = str_replace('\\', '', $text);
        if (($name === '') || ($type === '') || (trim($text) === '') || !$types) {
            return [];
        }
        // = with quoted values is the exact match of any of them, as == of one in resolve()
        $ofTypes = '"' . implode('" OR "', $types) . '"';
        $found   = $this->search($slice, [[$name => 1, 'operator' => 'CONTAIN', 'value' => $text], [$type => 1, 'operator' => '=', 'value' => $ofTypes]], null, [[$name => 'a']], $max);
        if (!$found) {
            return [];
        }
        // the type again, as in resolve(): a type field gone from the slice makes the search skip the condition
        $wanted = array_map(self::typeKey(...), $types);
        $typed  = array_filter($this->content($found, [$type]), static fn(array $content4id): bool => (bool) array_intersect(array_map(static fn(array $v): string => self::typeKey($v['value'] ?? ''), $content4id[$type] ?? []), $wanted));

        return array_values(array_filter($found, static fn(string $id): bool => isset($typed[$id])));
    }

    /** the box of the field as ISDS knows it; or why there is none */
    private static function recipient(string $id, string $name, string $field): Recipient
    {
        $text = trim($field);
        if ($text === '') {
            return new Recipient($id, $name, '', Schema::BOX_MISSING);
        }
        try {
            return new Recipient($id, $name, Box::normalizeId($text), '');
        } catch (\InvalidArgumentException) {
            return new Recipient($id, $name, $text, Schema::BOX_FORMAT);
        }
    }

    /** the value of the member field of an item that is a member */
    public static function isMember(string $value): bool
    {
        return trim($value) === '1';
    }

    /** a type as the collation of the database compares it: case and trailing spaces (PAD SPACE) do not count */
    public static function typeKey(mixed $type): string
    {
        return mb_strtolower(rtrim((string) $type, ' '));
    }

    // ------------------------------------------------------------ what tests replace

    /**
     * Long ids of the active items of the slice that meet the conditions, AA's search
     * @param list<array<string, mixed>>  $conds    as QueryZids() takes them
     * @param ?list<string>               $restrict only of these ids; [] none
     * @param list<array<string, string>> $sort     as QueryZids() takes it
     * @param int                         $limit    at most; 0 = all
     * @return list<string>
     */
    protected function search(string $slice, array $conds, ?array $restrict = null, array $sort = [], int $limit = 0): array
    {
        // QueryZids() wants a condition or a sort; a column of the item table needs no join
        $set = new AA_Set([$slice], $conds ?: null, $sort ?: [['publish_date....' => 'a']], \AA_BIN_ACTIVE);

        return array_map('strval', $set->query(is_null($restrict) ? null : new zids($restrict, 'l'), $limit)->longids());
    }

    /**
     * @param list<string> $ids    of the source slice only
     * @param list<string> $fields
     * @return array<array-key, array<string, mixed>> as GetItemContent() returns it
     */
    protected function content(array $ids, array $fields): array
    {
        $content = [];
        foreach ((new zids($ids, 'l'))->chunk(self::CHUNK) as $chunk) {
            $content += GetItemContent($chunk, false, false, $fields);
        }

        return $content;
    }
}

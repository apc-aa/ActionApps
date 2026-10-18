<?php

declare(strict_types=1);

namespace AA\Isds;

use AA\Isds\Registry\Box;
use AA\Isds\Registry\Config;
use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Registry\UnusableRegisterException;
use AA_Set;
use ItemContent;
use zids;

/**
 * Whom a campaign goes to: the active items of the source slice its filter chooses, each data box
 * once. AA's search finds them, GetItemContent() reads them with the reading passwords of the request
 * - the source slice is a public one (SliceRules). The search is scoped to the source slice, as the
 * ids to include come from the browser: one locked slice in a GetItemContent() call would mask every
 * item of the call.
 */
class Recipients
{
    /** items GetItemContent() reads at once */
    private const CHUNK = 500;

    /**
     * Who gets the letter, and who is skipped for the box (reason) - by name in Czech order, then by
     * id; of the items with one box the first keeps it, unless a later one's IČO is the box's. The
     * count a user confirms and the count sent must both come from here, with the register of the
     * request.
     * @param Config        $config   the settings of data boxes of the source slice
     * @param RegisterIndex $register a box it has not, or has for another IČO than the item's, is foreign;
     *                                one of another kind than an authority's main box is skipped too
     * @return list<Recipient>
     * @throws UnusableRegisterException the register cannot be used, up front or while read
     * @throws \RuntimeException         the settings name no type field
     */
    public function resolve(Setup $setup, RecipientFilter $filter, Config $config, RegisterIndex $register): array
    {
        if (!$register->usable()) {
            throw new UnusableRegisterException('The register of data boxes is ' . $register->state());
        }
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
        $fields     = array_values(array_unique(array_filter([$config->nameField(), $type, $config->dsField(), $config->icoField(), $setup->memberField])));
        $recipients = [];
        foreach ($this->content($ids, $fields) as $id => $content4id) {
            $item   = new ItemContent($content4id);
            $member = ($setup->memberField !== '') && (trim((string) $item->getValue($setup->memberField)) === '1');
            // the type again, as the database compares it: a type field gone from the slice makes the search
            // skip the condition and find every item; the collation equates accents too (e, ě), this does not
            $byFilter = array_intersect(array_map(self::typeKey(...), $item->getValuesArray($type)), $wanted) && match ($filter->membership) {
                RecipientFilter::MEMBERS    => $member,
                RecipientFilter::NONMEMBERS => !$member,
                default                     => true,
            };
            if ($byFilter || isset($included[$id])) {
                $recipients[] = self::recipient((string) $id, (string) $item->getValue($config->nameField()), (string) $item->getValue($config->dsField()), (string) $item->getValue($config->icoField()));
            }
        }
        $czech = new \Collator('cs_CZ');
        usort($recipients, static fn(Recipient $a, Recipient $b): int => $czech->compare($a->name, $b->name) ?: strcmp($a->itemId, $b->itemId));
        $recipients = self::registered($recipients, $register);

        // a sendable item with an IČO has the IČO the register gives the box: it keeps the box against an
        // item without one, which may sort first by name and need not be the box's at all
        $keeper = [];
        foreach ($recipients as $i => $r) {
            if ($r->sendable() && (!isset($keeper[$r->box]) || (($r->ico !== '') && ($recipients[$keeper[$r->box]]->ico === '')))) {
                $keeper[$r->box] = $i;
            }
        }
        foreach ($recipients as $i => $r) {
            if ($r->sendable() && ($keeper[$r->box] !== $i)) {
                $recipients[$i] = $r->with(Schema::BOX_DUPLICATE);
            }
        }

        return $recipients;
    }

    /** the box of the field as ISDS knows it; or why there is none */
    private static function recipient(string $id, string $name, string $field, string $icoField): Recipient
    {
        // spaces of any kind and invisible characters alone are no IČO
        $blank = preg_match('/^[\s\p{Cf}]*\z/u', $icoField) === 1;
        $valid = Box::normalizeIco($icoField);
        $ico   = $blank ? '' : ($valid ?: trim($icoField));
        $text  = trim($field);
        if ($text === '') {
            return new Recipient($id, $name, '', Schema::BOX_MISSING, $ico);
        }
        try {
            $box = Box::normalizeId($text);
        } catch (\InvalidArgumentException) {
            return new Recipient($id, $name, $text, Schema::BOX_FORMAT, $ico);
        }
        // such an IČO could only miss the register's: the field to fix is the IČO, not the box
        return new Recipient($id, $name, $box, ($ico === $valid) ? '' : Schema::BOX_ICO, $ico);
    }

    /**
     * The recipients with a box checked against the register, all boxes in one lookup
     * @param list<Recipient> $recipients
     * @return list<Recipient>
     * @throws UnusableRegisterException
     */
    private static function registered(array $recipients, RegisterIndex $register): array
    {
        $boxes = [];
        foreach ($recipients as $r) {
            if ($r->sendable()) {
                $boxes[$r->box] = $r->box;
            }
        }
        $rows = $register->lookup(array_values($boxes));
        foreach ($recipients as $i => $r) {
            if ($r->sendable()) {
                $row = $rows[$r->box] ?? null;
                // an item that carries an IČO must match the register, so a box without one - offices,
                // archives, the districts of every statutory city but Prague - is foreign to such an item;
                // an item without an IČO is checked only for its box being in the register
                $own    = !is_null($row) && (($r->ico === '') || ($r->ico === $row['ico']));
                $reason = match (true) {
                    !$own => Schema::BOX_FOREIGN,
                    // a letter goes only to an authority's main box
                    !Box::isAuthoritysOwn($row['subtype'], $row['master']) => Schema::BOX_KIND,
                    default => '',
                };
                $recipients[$i] = $r->with($reason, $row['name'] ?? '');
            }
        }

        return $recipients;
    }

    /** a type as the collation of the database compares it: case and trailing spaces (PAD SPACE) do not count */
    private static function typeKey(mixed $type): string
    {
        return mb_strtolower(rtrim((string) $type, ' '));
    }

    // ------------------------------------------------------------ what tests replace

    /**
     * Long ids of the active items of the slice that meet the conditions, AA's search
     * @param list<array<string, mixed>> $conds    as QueryZids() takes them
     * @param ?list<string>              $restrict only of these ids
     * @return list<string>
     */
    protected function search(string $slice, array $conds, ?array $restrict = null): array
    {
        // QueryZids() wants a condition or a sort; a column of the item table needs no join
        $set = new AA_Set([$slice], $conds ?: null, [['publish_date....' => 'a']], \AA_BIN_ACTIVE);

        return array_map('strval', $set->query(is_null($restrict) ? null : new zids($restrict, 'l'))->longids());
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

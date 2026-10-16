<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

use ConvertCharset;

/**
 * Finds the data box of municipalities and city districts kept as items, in the register's master
 * boxes of authorities (subtype 10): by IČO, else by name. A name match is a suggestion for a person
 * to check, never a certainty - where more boxes fit, the row says so instead of choosing one.
 */
final class Matcher
{
    private const DISTRICT     = 'district';
    private const MUNICIPALITY = 'municipality';

    /** Box::$subtype of an authority */
    private const AUTHORITY = 10;

    /** bytes; the register's names end at 255, and fold()'s trailing-junk pattern is quadratic (50 000 dots before a letter: 28 s without the PCRE JIT) */
    private const MAX_NAME = 255;

    /**
     * The legal form the register puts before a municipality's own name, on the folded name. Only boxes
     * named so are matched, also by IČO: subtype 10 holds schools and offices too ("Mateřská škola,
     * Praha 10"), and an item's IČO field may hold theirs. City districts are kept apart - "Městská
     * část Komárov" (Opava) is not "Obec Komárov", and the districts of Brno or Ostrava use the city's
     * IČO while their own boxes have none.
     */
    private const PREFIXES = [
        self::DISTRICT     => '/^(?:mestska cast|mestsky obvod) /',
        self::MUNICIPALITY => '/^(?:statutarni mesto|hlavni mesto|mestys|mesto|obec) /',
    ];

    /** @var array<string, true> */
    private array $districtTypes;

    /** @param list<string> $districtTypes item types that are city districts; other items are municipalities */
    public function __construct(array $districtTypes = [])
    {
        $this->districtTypes = array_fill_keys($districtTypes, true);
    }

    /**
     * A page that lets a person tick rows writes the ticked ones from a new match() on the server,
     * never from a box id the form posts back.
     *
     * @param iterable<Box>        $boxes the register, read once
     * @param array<array-key, mixed> $items item id => ['name' => …, 'ico' => …, 'ds' => …, 'type' => …];
     *                                    a missing value, or one that is not text or a number, is ''
     */
    public function match(iterable $boxes, array $items): MatchReport
    {
        // keyed by box id: a box listed twice must not make a row ambiguous
        $by_ico  = [self::DISTRICT => [], self::MUNICIPALITY => []];
        $by_name = [self::DISTRICT => [], self::MUNICIPALITY => []];
        foreach ($boxes as $box) {
            // subordinate boxes (a city police, a school under the city) share the city's IČO
            if (($box->subtype !== self::AUTHORITY) || !$box->isMaster) {
                continue;
            }
            $folded = self::fold($box->name);
            foreach (self::PREFIXES as $pool => $prefix) {
                $own = (string) preg_replace($prefix, '', $folded, 1, $count);
                if ($count === 0) {
                    continue;
                }
                $ico = self::ico($box->ico);
                if ($ico !== '') {
                    $by_ico[$pool][$ico][$box->id] = $box;
                }
                $key = self::key($own);
                if ($key !== '') {
                    $by_name[$pool][$key][$box->id] = $box;
                }
            }
        }

        $rows = [];
        foreach ($items as $item_id => $item) {
            $item = is_array($item) ? $item : [];
            $name = self::text($item['name'] ?? '');
            $type = self::text($item['type'] ?? '');
            $typed_ico = self::text($item['ico'] ?? '');
            $ico  = self::ico($typed_ico);
            $pool = isset($this->districtTypes[$type]) ? self::DISTRICT : self::MUNICIPALITY;
            // an IČO the register does not know, or another kind of box's (a school's, the city's
            // for a district), is left to the name, which a person checks
            if (isset($by_ico[$pool][$ico])) {
                $found  = array_values($by_ico[$pool][$ico]);
                $method = MatchRow::BY_ICO;
            } else {
                $found  = self::byName($name, $pool, $by_name[$pool]);
                $method = MatchRow::BY_NAME;
            }
            $rows[] = self::row($item_id, $name, $type, self::text($item['ds'] ?? ''), $ico, $typed_ico, $found, $method);
        }
        return new MatchReport($rows);
    }

    /**
     * Tries the name as it is, then without a legal form of its own ("Obec Adamov"), then a district
     * without its city ("Ostrava - Hrabová" is "Městský obvod Hrabová"); the first with a box wins.
     * The name as it is goes first: "Město Albrechtice" is the town's whole name, while "Obec
     * Albrechtice" is another place.
     * @param array<string, array<array-key, Box>> $index key => boxes
     * @return list<Box>
     */
    private static function byName(string $name, string $pool, array $index): array
    {
        $folded = self::fold($name);
        // without a legal form of its own, $own is $folded again - one wasted lookup, no harm
        $own = (string) preg_replace(self::PREFIXES[$pool], '', $folded, 1);
        // the index never holds the key '' of an empty name
        foreach ([self::key($folded), self::key($own)] as $key) {
            if (isset($index[$key])) {
                return array_values($index[$key]);
            }
        }
        // only a real hyphen marks the city: "Slezská Ostrava" is one name
        if (($pool !== self::DISTRICT) || !str_contains($own, '-')) {
            return [];
        }
        [$city, $part] = explode('-', $own, 2);
        $city = self::key($city);
        // the register's name has no city, its address has: "Praha-Lhotka" is not Ostrava's Lhotka
        $in_city = static fn(Box $box): bool => self::key(self::fold($box->city)) === $city;
        return array_values(array_filter($index[self::key($part)] ?? [], $in_city));
    }

    /**
     * @param string    $ico       normalised (ico()), compared with the box's
     * @param string    $typed_ico as the item has it, for the page to show
     * @param list<Box> $found
     */
    private static function row(int|string $item_id, string $name, string $type, string $old_ds, string $ico, string $typed_ico, array $found, string $method): MatchRow
    {
        $box    = (count($found) === 1) ? $found[0] : null;
        $old    = strtolower(trim($old_ds));
        $status = match (true) {
            !$found           => MatchRow::UNMATCHED,
            $box === null     => MatchRow::AMBIGUOUS,
            $old === ''       => MatchRow::NEW,
            $old === $box->id => MatchRow::SAME,
            default           => MatchRow::CHANGED,
        };
        $candidates = ($status === MatchRow::AMBIGUOUS) ? $found : [];
        $mismatch   = ($box !== null) && ($ico !== '') && ($box->ico !== '') && (self::ico($box->ico) !== $ico);
        return new MatchRow($item_id, $name, $type, $old_ds, $box, $found ? $method : '', $status, $candidates, $mismatch, $typed_ico);
    }

    /** an item value as text; anything else is '' - one broken value must not stop the whole run */
    private static function text(mixed $value): string
    {
        return (is_string($value) || is_int($value) || is_float($value) || ($value instanceof \Stringable)) ? (string) $value : '';
    }

    /** 8 digits, or '' for anything else - items keep IČO without leading zeros or with spaces */
    private static function ico(string $ico): string
    {
        // \s with /u covers no-break spaces; null (not UTF-8) becomes '', no IČO
        $ico = (string) preg_replace('/\s+/u', '', $ico);
        return (preg_match('/^[0-9]{1,8}\z/', $ico) === 1) ? str_pad($ico, 8, '0', STR_PAD_LEFT) : '';
    }

    /**
     * ASCII lowercase, one space between words, without a trailing "(orp Opava)" and dots ("Pardubice
     * V."); hyphens stay, so a district can still be split from its city. '' for what is no name.
     */
    private static function fold(string $name): string
    {
        // iconv would give a notice for text that is not UTF-8
        if ((strlen($name) > self::MAX_NAME) || !mb_check_encoding($name, 'UTF-8')) {
            return '';
        }
        // \s with /u covers the no-break spaces of the register
        $ascii = ConvertCharset::singleton()->Convert((string) preg_replace('/\s+/u', ' ', $name), 'utf-8', 'us-ascii');
        return (string) preg_replace('/(?:\([^()]*\)|[ .])+\z/', '', strtolower(trim($ascii)));
    }

    /** "praha 01", "praha-1" and "praha - 1" are one key */
    private static function key(string $folded): string
    {
        $key = trim((string) preg_replace('/[ -]+/', '-', $folded), '-');
        return (string) preg_replace('/(?<![0-9])0+(?=[0-9])/', '', $key);
    }
}

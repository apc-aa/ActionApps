<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Util\Langs;
use AA_Dbcondition;
use AA_Field;
use AA_Operators;
use AA_View_Commands;
use SQL_Lex;
use SQL_Parser_Constants;

/** The items of a slice a transfer takes: `d-<fields>-<operator>-<value>[-…]` as in {ids} and views,
 *  conditions joined by AND, split by AA_View_Commands and evaluated by QueryZids() - so a condition
 *  means what it means anywhere in AA. Refused is only what would silently select more items than
 *  written (QueryZids() skips it or reads it as "all"): more items are worse than none. */
final readonly class ItemFilter
{
    /** E: the filter of the slice cannot be used; ref = FilterInvalid::$reason */
    public const INVALID = 'filter-invalid';

    /** bytes of the text */
    public const MAX_BYTES = 1000;

    /** each condition is one query per page of candidate ids */
    public const MAX_CONDITIONS = 10;

    /** operators that compare no value */
    private const NO_VALUE = ['ISNULL', 'NOTNULL', 'ISMAIL', 'NOTMAIL'];

    /** the dates AA_Dbcondition reads for e: - it reads any other value as now */
    private const EUROPEAN_DATES = ['/^ *(\d{1,2}) *\. *(\d{1,2}) *\. *(\d{4}) *$/', '/^ *(\d{1,2}) *\. *(\d{1,2}) *\. *(\d{2}) *$/', '/^ *(\d{1,2}) *\. *(\d{1,2}) *$/'];

    /** @param list<array{fields: list<string>, operator: string, value: string}> $conditions */
    private function __construct(private array $conditions) {}

    /** @param string $text as typed, UTF-8 @throws FilterInvalid */
    public static function parse(string $text): self
    {
        $text = trim($text);
        if (strlen($text) > self::MAX_BYTES) {
            throw new FilterInvalid('too-long');
        }
        self::checkCharacters($text);
        if (!str_starts_with($text, 'd-')) {
            throw new FilterInvalid('form');
        }
        $params = (new AA_View_Commands($text))->get('d')?->getParameterArray() ?? [];
        if (!$params || (count($params) % 3)) {      // a short last triple is a condition without a value - QueryZids() drops it
            throw new FilterInvalid('triples');
        }
        if (count($params) > 3 * self::MAX_CONDITIONS) {
            throw new FilterInvalid('too-many');
        }
        $conditions = [];
        foreach (array_chunk($params, 3) as [$fields, $operator, $value]) {
            $conditions[] = self::condition(str_replace(' ', '', (string) $fields), (string) $operator, (string) $value);
        }
        return new self($conditions);
    }

    /** @return list<array{fields: list<string>, operator: string, value: string}> operator as AA_Dbcondition
     *          reads it, value decoded as views decode it */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /** the filter with its values in the slice's charset - QueryZids() compares them with the stored bytes
     *  @throws FilterInvalid a value the charset cannot hold */
    public function inCharset(string $charset): self
    {
        $conditions = [];
        foreach ($this->conditions as $condition) {
            $condition['value'] = ModuleCharset::fromUtf8($condition['value'], $charset) ?? throw new FilterInvalid('charset');
            $conditions[]       = $condition;
        }
        return new self($conditions);
    }

    /** The filters of these slices only, slice ids lower case - a call takes the filters of its own slices.
     *  @param array<string, string> $filters slice id => text @param list<string> $slices
     *  @return array<string, string> */
    public static function ofSlices(array $filters, array $slices): array
    {
        return array_intersect_key(array_change_key_case($filters, CASE_LOWER), array_flip(array_map('strtolower', $slices)));
    }

    /** The echo a source answers filtered requests with (AppRequest): sha256 of the canonical JSON of the
     *  filters - slice ids lower case and sorted, the texts as sent.
     *  @param array<string, string> $filters slice id => text */
    public static function digest(array $filters): string
    {
        $canonical = array_change_key_case($filters, CASE_LOWER);
        ksort($canonical, SORT_STRING);
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** What a field part of a condition names, read as QueryZidsHelper::prepareConds() reads it:
     *  ['own', field], ['related', relation field, field or all_fields], ['remote', slice, field, relation
     *  field of that slice], [] for an all_fields form; null = a part it would skip. A language it does not
     *  know it would drop - so not one.
     *  @return list<string>|null */
    public static function fieldForm(string $token): ?array
    {
        $lower = strtolower($token);
        if (in_array($lower, ['all_fields', 'all_fields_numeric'], true)) {
            return [];
        }
        if (str_starts_with($lower, 'all_fields|')) {
            return self::langs(substr($lower, 11)) ? [] : null;
        }
        if (preg_match('~^([^@/]+)@([^@/]+)\z~', $token, $m)) {
            return ((($m[1] === 'all_fields') || AA_Field::isValidFieldId($m[1])) && AA_Field::isValidFieldId($m[2])) ? ['related', $m[2], $m[1]] : null;
        }
        if (preg_match('~^([^@/]+)@([0-9a-f]{32})\+?/([^@/]+)\z~', $token, $m)) {
            return (AA_Field::isValidFieldId($m[1]) && AA_Field::isValidFieldId($m[3])) ? ['remote', $m[2], $m[1], $m[3]] : null;
        }
        [$field, $langs] = array_pad(explode('|', $token, 2), 2, null);
        return (AA_Field::isValidFieldId($field) && (($langs === null) || self::langs($langs))) ? ['own', $field] : null;
    }

    /** @return array{fields: list<string>, operator: string, value: string} @throws FilterInvalid */
    private static function condition(string $fields, string $operator, string $value): array
    {
        $tokens = explode(',', $fields);
        foreach ($tokens as $i => $token) {
            // QueryZids() searches all the fields in the languages of the last one
            if ((self::fieldForm($token) === null) || (str_contains($token, '|') && ($i !== array_key_last($tokens)))) {
                throw new FilterInvalid("field:$token");
            }
        }
        $normal = self::operator($operator);
        if (!in_array($normal, array_map(self::operator(...), array_map('strval', array_keys(AA_Operators::getEnum()[1]))), true)) {
            throw new FilterInvalid("operator:$operator");
        }
        if (str_starts_with($value, '%22') && str_ends_with($value, '%22')) {      // as AA_Set::addFromCommand() reads views
            $value = rawurldecode($value);
            self::checkCharacters($value);
        }
        // `|` is true for every item with the field; BITSET of no bit tests `field & 0 = 0`
        if (($value === 'AAnoCONDITION') || (($value === '') && !in_array($normal, self::NO_VALUE, true)) || ($normal === '|') || (($normal === 'BITSET') && ((int) $value === 0))) {
            throw new FilterInvalid('value');
        }
        $condition = ['fields' => $tokens, 'operator' => $normal, 'value' => $value];
        if (in_array($normal[0], ['d', 'e', 'm', '-'], true)) {         // a number to compare with, never "all"
            $time = self::dateOf($normal[0], $value) ?? throw new FilterInvalid('value');
            if (($time < 0) && (substr($normal, 1) === '=')) {     // `=` reads the leading minus as NOT
                throw new FilterInvalid('value');
            }
            return $condition;
        }
        if (($normal === '<>') && !self::literal($value)) {
            throw new FilterInvalid('phrase');
        }
        if (($value !== '') && ((new AA_Dbcondition('', 'text', $normal, $value))->getJoinType() === 'ALL')) {
            throw new FilterInvalid("syntax:$fields");
        }
        return $condition;
    }

    /** the operator as the constructor of AA_Dbcondition reads it (`d>`, `d#:>`, `&gt;`, `lt` …); `!=` as `<>` */
    private static function operator(string $operator): string
    {
        $normal = str_replace(['#:', ':', '&gt;', '&lt;', '&amp;'], ['', '', '>', '<', '&'], trim($operator));
        $normal = (strlen($normal) < 4) ? AA_Operators::normalize($normal) : $normal;
        return ($normal === '!=') ? '<>' : $normal;
    }

    /** UTF-8 without control characters and backslashes - AA strips slashes off values: `\.` would silently be `.`
     *  @throws FilterInvalid */
    private static function checkCharacters(string $text): void
    {
        if (!mb_check_encoding($text, 'UTF-8') || preg_match('/[\p{Cc}\\\\]/u', $text)) {
            throw new FilterInvalid('characters');
        }
    }

    /** every language of a `|en|de` suffix is one AA reads */
    private static function langs(string $suffix): bool
    {
        return !array_filter(explode('|', $suffix), static fn(string $lang): bool => !Langs::isValidLangName($lang));
    }

    /** The unixtime a date form compares with, as AA_Dbcondition reads the value (e: the start of the day);
     *  null = it would not read it and compare with false or now instead. */
    private static function dateOf(string $form, string $value): ?int
    {
        $value = trim($value, '"');
        if ($form === 'd') {
            $time = strtotime($value);
            return ($time === false) ? null : $time;
        }
        if ($form !== 'e') {        // seconds ago - few enough digits to stay an integer
            return preg_match('/^\s*[+-]?\d{1,12}\s*\z/', $value) ? time() - (int) $value : null;
        }
        foreach (self::EUROPEAN_DATES as $pattern) {
            if (preg_match($pattern, $value, $part)) {
                return (int) mktime(0, 0, 0, (int) $part[2], (int) $part[1], isset($part[3]) ? (int) $part[3] : null);
            }
        }
        return null;
    }

    /** `<>` compares the value as one literal (its quotes cut off) though it may read as a phrase - `<>` of a
     *  phrase would select nearly all: the search syntax must read exactly that literal */
    private static function literal(string $value): bool
    {
        $tokens  = SQL_Lex::lex($value);
        $literal = in_array($value[0], ['"', "'"], true) ? substr($value, 1, -1) : $value;
        return (count($tokens) === 2) && ($tokens[0]['type'] === SQL_Parser_Constants::TOKEN_TYPE_STRING) && ($tokens[0]['value'] === $literal);
    }
}

<?php

declare(strict_types=1);

namespace AA\Central\Package;

use Generator;

/** Rewrites references in the texts of a package to the target ids. Ids missing in the map stay; a regex
 *  failure throws RewriteFailed - never a half-rewritten text.
 *  Only rewriteLongIds() keeps the length, so only it may run on serialize()d text. Each pass maps every id
 *  once (12 -> 13, 13 -> 14 turns 12 into 13) and the kinds cannot produce each other, so passes do not chain.
 *  Command names match in any case - AA lower-cases them. */
final class ReferenceRewriter
{
    /** Known numeric forms. Numbers are 1-10 significant digits (int view.id) ending at a non-word char:
     *  `vid=12` never matches `vid=123`, the named view `vid=12abc` or an 11-digit run. AA reads leading
     *  zeros as part of the number (ctype_digit() + (int)); the new id is written without them. Possessive
     *  quantifiers only - no backtracking over long runs. Named groups:
     *  - vid:     `vid=N` in view.php URLs, {view.php?..}, {lazy:..}, f_v:, AJAX (parseViewParameters())
     *  - ck, cv:  `cmd[N]`, `cmd[N][]` and the view of the v-/x-/o-/i- commands (the letter trimmed -
     *             `x -12`); `c-N`, `d-..` are condition numbers/fields, not views
     *  - sk, sb:  `set[N]`, `set[N][]` and a leading `banner-POS-N` in its value
     *  - bn:      `banner-POS-N[-field]` after `,` (a list in set[]) or `:` ({view:N:ids:banner-..})
     *  - sl:      slice.php `banner=POS-N`
     *  - view:    `{view:N..}`
     *  - ls:      `{livesearch:N..}`, also `{livesearch:N&cmd[N]=..}`
     *  - mail:    `{mailtemplate:N..}` - the e-mail map
     */
    private const NUMERIC_RE = '~
          (?<![\w])vid=\s*+(?<vid>0*+\d{1,10}+)(?!\w)
        | (?<![\w])cmd\[(?<ck>0*+\d{0,10}+)\](?:\[\])?(?:=\s*+[vxoi]\s*+-\s*+(?<cv>0*+\d{1,10}+)(?!\w))?
        | (?<![\w])set\[(?<sk>0*+\d{0,10}+)\](?:\[\])?(?:=\s*+banner-\d++-(?<sb>0*+\d{1,10}+)(?!\w))?
        | (?<=[,:])banner-\d++-(?<bn>0*+\d{1,10}+)(?!\w)
        | (?<![\w])banner(?:\[\d*+\])?=\s*+\d++-(?<sl>0*+\d{1,10}+)(?!\w)
        | \{(?i:view):\s*+(?<view>0*+\d{1,10}+)(?=\s*+[:}])
        | \{(?i:livesearch):\s*+(?<ls>0*+\d{1,10}+)(?=\s*+[:}&])
        | \{(?i:mailtemplate):\s*+(?<mail>0*+\d{1,10}+)(?=\s*+[:}])
        ~x';

    /** named group of NUMERIC_RE => its map */
    private const NUMERIC_GROUPS = [
        'vid' => 'view', 'ck' => 'view', 'cv' => 'view', 'sk' => 'view', 'sb' => 'view',
        'bn'  => 'view', 'sl' => 'view', 'view' => 'view', 'ls' => 'view', 'mail' => 'email',
    ];

    /** The group parameter of {constant:g:..} / {constants:g..} / {options:g..} with the spaces around it
     *  (AA trims them). `[`-lists are no names, computed `{..}` does not match. Possessive: `{constant:` +
     *  a long run fails in linear time. */
    public const GROUP_RE = '~\{(?i:constant|constants|options):\K[^:{}]*+(?=[:}])~';

    /** a 32-hex run, or several (constant.ancestors); a hex run of another length is no id */
    private const LONG_ID_RE = '~(?<![0-9a-fA-F])(?:[0-9a-fA-F]{32})++(?![0-9a-fA-F])~';

    /** alerts field id bases FIELDID_HOWOFTEN, FIELDID_FILTERS */
    private const ALERTS_FIELDS = ['alerts1', 'alerts2'];

    /** @var array<string, string> alerts field ids old => new */
    private array $alerts = [];

    public function __construct(private readonly IdMap $map)
    {
        foreach ($map->collections() as $old => $new) {
            foreach (self::ALERTS_FIELDS as $field) {
                $this->alerts[self::alertsField($field, $old)] = self::alertsField($field, $new);
            }
        }
    }

    /** Long ids and alerts field ids. The length never changes: serialized text and concatenated
     *  `ancestors` stay valid. Any case matches (pack_id() takes any); an upper-case id stays upper case. */
    public function rewriteLongIds(string $text): string
    {
        if ($this->map->longIds()) {
            $text = RewriteFailed::check(preg_replace_callback(self::LONG_ID_RE, fn(array $m): string => implode('', array_map($this->longId(...), str_split($m[0], 32))), $text));
        }
        return $this->alerts ? strtr($text, $this->alerts) : $text;
    }

    /** the long ids rewriteLongIds() would look up in $text, lower case @return list<string> */
    public static function longIdsIn(string $text): array
    {
        RewriteFailed::check(preg_match_all(self::LONG_ID_RE, $text, $m));
        return $m[0] ? str_split(strtolower(implode('', $m[0])), 32) : [];
    }

    /** Group names in texts. Changes the length - never on serialize()d text. */
    public function rewriteGroups(string $text): string
    {
        if (!$this->map->groups()) {
            return $text;
        }
        return RewriteFailed::check(preg_replace_callback(self::GROUP_RE, function (array $m): string {
            $name = trim($m[0]);
            $new  = ($name === '') ? null : $this->map->group($name);
            return ($new === null) ? $m[0] : substr_replace($m[0], $new, (int) strpos($m[0], $name), strlen($name));
        }, $text));
    }

    /** Views and e-mails in the known forms, in one scan of the original text. Changes the length - never
     *  on serialize()d text. */
    public function rewriteNumeric(string $text): string
    {
        if (!$this->map->views() && !$this->map->emails()) {
            return $text;
        }
        $out = '';
        $pos = 0;
        foreach (self::numericRefs($text) as [$kind, $number, $offset]) {
            $new = ($kind === 'email') ? $this->map->email((int) $number) : $this->map->view((int) $number);
            if ($new !== null) {
                $out .= substr($text, $pos, $offset - $pos) . $new;
                $pos  = $offset + strlen($number);
            }
        }
        return $out . substr($text, $pos);
    }

    /** Numbers in the forms of NUMERIC_RE, in text order. One match at a time: preg_match_all with offsets
     *  of 10 groups took ~70x the text size.
     *  @return Generator<int, array{string, string, int}> [kind 'view'|'email', number, byte offset] */
    public static function numericRefs(string $text): Generator
    {
        $start = 0;
        while (RewriteFailed::check(preg_match(self::NUMERIC_RE, $text, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $start))) {
            foreach (self::NUMERIC_GROUPS as $group => $kind) {    // groups of one match are in text order
                [$number, $offset] = $m[$group];
                if (($number !== null) && (ltrim($number, '0') !== '')) {   // `cmd[]`, `vid=0`: no view
                    yield [$kind, $number, $offset];
                }
            }
            $start = max($m[0][1] + strlen((string) $m[0][0]), $start + 1);
        }
    }

    /** widget code (any case) => index of its `const` parameter among getClassProperties() */
    public const CONST_POSITION = [
        'sel' => 0, 'mch' => 0, 'rio' => 0, 'mse' => 0, 'wi2' => 0, 'hco' => 0,
        'pre' => 0, 'tag' => 0, 'rim' => 0, 'mng' => 0, 'iso' => 0, 'tpr' => 2,
    ];

    /** The column of a row AA reads as a constant group - no template, so no {constant:} reference finds
     *  it: field.input_show_func (the widget's `const` parameter), view.parameter of a const view (the
     *  whole value, view.php QueryConstantZIDs()). null = the row has none.
     *  @param array<string, mixed> $row */
    public static function groupColumn(string $element, array $row): ?string
    {
        return match (true) {
            $element === 'field' => 'input_show_func',
            ($element === 'view') && (strtolower(trim(ValueCodec::bytes($row['type'] ?? ''))) === 'const') => 'parameter',
            default => null,
        };
    }

    /** the group the row names in its groupColumn(); null = none. `#sLiCe-<id>` comes back as it is.
     *  @param array<string, mixed> $row */
    public static function rowGroup(string $element, array $row): ?string
    {
        $column = self::groupColumn($element, $row);
        $value  = ($column === null) ? '' : ValueCodec::bytes($row[$column] ?? '');
        $name   = ($column === 'input_show_func') ? (self::inputShowFuncGroup($value) ?? '') : trim($value);
        return ($name === '') ? null : $name;
    }

    /** the group in the value of a groupColumn() replaced in place (IdMap); the rest stays byte for byte */
    public function rewriteGroupColumn(string $column, string $value): string
    {
        if ($column === 'input_show_func') {
            $position = self::CONST_POSITION[strtolower(trim(explode(':', $value, 2)[0]))] ?? null;
            return ($position === null) ? $value : $this->rewriteInputShowFunc($value, $position);
        }
        $name = trim($value);
        $new  = ($name === '') ? null : $this->map->group($name);
        return ($new === null) ? $value : substr_replace($value, $new, (int) strpos($value, $name), strlen($name));
    }

    /** the constant group of field.input_show_func; null when the widget has none. `#sLiCe-<id>` (items of
     *  a slice) comes back as it is. */
    public static function inputShowFuncGroup(string $input_show_func): ?string
    {
        $parts    = RewriteFailed::check(preg_split('/(?<!#):/', $input_show_func));
        $position = self::CONST_POSITION[strtolower(trim($parts[0]))] ?? null;
        $name     = ($position === null) ? '' : trim($parts[$position + 1] ?? '');
        return ($name === '') ? null : $name;
    }

    /** The constant group parameter of field.input_show_func replaced in place - the rest stays byte for
     *  byte. Split like AA_Statestore::parseProperties(): on `:` not preceded by `#`, values trimmed.
     *  @param int $position index of `const` among the widget's parameters - CONST_POSITION */
    public function rewriteInputShowFunc(string $input_show_func, int $position = 0): string
    {
        $parts = RewriteFailed::check(preg_split('/(?<!#):/', $input_show_func, -1, PREG_SPLIT_OFFSET_CAPTURE));
        if (!isset($parts[$position + 1])) {
            return $input_show_func;
        }
        [$value, $offset] = $parts[$position + 1];
        $name = trim($value);
        $new  = ($name === '') ? null : $this->map->group($name);
        if ($new === null) {
            return $input_show_func;
        }
        return substr_replace($input_show_func, $new, $offset + (int) strpos($value, $name), strlen($name));
    }

    private function longId(string $id): string
    {
        $new = $this->map->longId($id);
        if ($new === null) {
            return $id;
        }
        return ((strtoupper($id) === $id) && (strtolower($id) !== $id)) ? strtoupper($new) : $new;
    }

    /** getAlertsField() - its file is not autoloaded */
    private static function alertsField(string $field, string $collection): string
    {
        return substr($field . '.............', 0, 16 - strlen($collection)) . $collection;
    }
}

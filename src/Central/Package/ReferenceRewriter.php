<?php

declare(strict_types=1);

namespace AA\Central\Package;

use Generator;

/** Rewrites references in the texts of a package to the target ids (design balicek-v1.md § 8).
 *  Pure: text in, text out; nothing is evaluated, ids missing in the map stay as they are.
 *  Any regex failure throws RewriteFailed - a half-rewritten text is never returned.
 *
 *  Passes (the import runs them in § 7.2 step 5: long ids and groups with the plan, numbers after
 *  the view/email rows got their AUTO_INCREMENT ids):
 *  - rewriteLongIds()  long ids + alerts field ids; keeps the length, so it is the ONLY pass allowed
 *                      on serialize()d text (site.structure of the old chunk mode);
 *  - rewriteGroups()   group names in {constant:/constants:/options:} - changes the length;
 *  - rewriteNumeric()  views and e-mails - changes the length.
 *  Each pass maps every id exactly once, a result is never looked up again (12 -> 13, 13 -> 14 turns
 *  12 into 13). The kinds cannot produce each other (32-hex runs, 16-char alerts field ids, names and
 *  decimals after fixed prefixes), so running the passes one after another does not chain either.
 *  Command names match in any case - AA lower-cases them (constructClassName(), include/statestore.php:153).
 */
final class ReferenceRewriter
{
    /** Known numeric forms (§ 8.2). Numbers are 1-10 significant digits (int view.id) ending at a
     *  non-word char: `vid=12` never matches `vid=123`, the named view `vid=12abc` or an 11-digit run.
     *  Leading zeros are part of the number - AA reads ctype_digit() + (int) (view.class.php:638,
     *  stringexpand.php:10227) - and the new id is written without them. Possessive quantifiers
     *  only - no backtracking over long runs. Named groups => which map to use:
     *  - vid:     `vid=N` in view.php URLs, {view.php?..}, {lazy:..}, f_v:, AJAX - parseViewParameters(),
     *             include/view.class.php:422 (`vid=\s*(\w+)`)
     *  - ck, cv:  `cmd[N]`, `cmd[N][]` (`cmd[]` is filled from vid, view.class.php:426-431) and the view
     *             of the v-/x-/o-/i- commands (view.class.php:464-470; split in include/view.php:210-213,
     *             the letter trimmed - `x -12`);
     *             `c-N`, `d-..` are condition numbers/fields, not views
     *  - sk, sb:  `set[N]`, `set[N][]` and a leading `banner-POS-N` in its value
     *  - bn:      `banner-POS-N[-field]` after `,` (list in set[], parseSettings() ALLOW_MULTIPLE,
     *             view.class.php:379-396) or `:` ({view:N:ids:banner-..}); parseBannerParam() :352-367
     *  - sl:      slice.php `banner=POS-N` (slice.php:325 -> parseBannerParam())
     *  - view:    `{view:N..}` - AA_Stringexpand_View, include/stringexpand.php:3190
     *  - ls:      `{livesearch:N..}`, also `{livesearch:N&cmd[N]=..}` - stringexpand.php:1246, 1258
     *  - mail:    `{mailtemplate:N..}` - e-mail map, AA_Stringexpand_Mailtemplate stringexpand.php:10218
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

    /** The group parameter of {constant:g:..} / {constants:g..} / {options:g..} with the spaces around
     *  it (stringexpand.php:4411, 4451, 4489; trimmed there). `[`-lists are no names, computed `{..}`
     *  does not match. Possessive: `{constant:` + a long run fails in linear time. */
    public const GROUP_RE = '~\{(?i:constant|constants|options):\K[^:{}]*+(?=[:}])~';

    /** a 32-hex run, or several (constant.ancestors); a hex run of another length is no id */
    private const LONG_ID_RE = '~(?<![0-9a-fA-F])(?:[0-9a-fA-F]{32})++(?![0-9a-fA-F])~';

    /** alerts field id bases FIELDID_HOWOFTEN, FIELDID_FILTERS (include/constants.php:415-416) */
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

    /** all passes - for a text that is not serialized */
    public function rewrite(string $text): string
    {
        return $this->rewriteNumeric($this->rewriteGroups($this->rewriteLongIds($text)));
    }

    /** § 8.1 long ids and § 6.4 alerts field ids. The length never changes: serialized text and
     *  concatenated `ancestors` stay valid. A token is found in any case (pack_id() takes any);
     *  the new id is upper case when the token was, else lower case. */
    public function rewriteLongIds(string $text): string
    {
        if ($this->map->longIds()) {
            $text = RewriteFailed::check(preg_replace_callback(self::LONG_ID_RE, fn(array $m): string => implode('', array_map($this->longId(...), str_split($m[0], 32))), $text));
        }
        return $this->alerts ? strtr($text, $this->alerts) : $text;
    }

    /** § 8.3 group names in texts. Changes the length - never on serialize()d text. */
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

    /** § 8.2 views and e-mails in the known forms: one scan of the original text, each number looked
     *  up once and spliced in. Changes the length - never on serialize()d text. */
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

    /** Numbers in the known forms of NUMERIC_RE, in text order. One match at a time - memory stays
     *  flat for big texts (preg_match_all with offsets of 10 groups took ~70x the text size).
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

    /** § 8.3 the constant group parameter of field.input_show_func, replaced in place: the rest of
     *  the string (other parameters, their `#:` escapes, spaces) stays byte for byte.
     *  Parameters split like AA_Statestore::parseProperties() (include/statestore.php:190) - on `:`
     *  not preceded by `#`, values trimmed.
     *  @param int $position index of `const` among the widget's parameters - 0 for sel, mch, rio,
     *                       mse, wi2, hco, pre, tag, rim, mng, iso; 2 for tpr (getClassProperties()) */
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

    /** getAlertsField(), modules/alerts/util.php:115 (not autoloaded) */
    private static function alertsField(string $field, string $collection): string
    {
        return substr($field . '.............', 0, 16 - strlen($collection)) . $collection;
    }
}

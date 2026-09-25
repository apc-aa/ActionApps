<?php

declare(strict_types=1);

namespace AA\Central\Package;

use Generator;

/** References ReferenceRewriter cannot rewrite, or that lead outside the app (design balicek-v1.md
 *  § 8.4). Pure text analysis of the SOURCE text (before any rewrite) against the map: for the
 *  export an identity map of the app, for the import the plan. Any regex failure throws
 *  RewriteFailed - an empty list always means "nothing found". The security lint (§ 3.3, level S)
 *  and the checks that need rows (reader slice, charset, allowlist) are not here.
 *
 *  The lint is best-effort help for package authors and importers, not a security boundary - the
 *  trust boundary is who may install a package (design § 3.3, Q-H2).
 */
final class ReferenceLint
{
    /** W: a known numeric form (§ 8.2) whose view is not in the app */
    public const VIEW_OUTSIDE = 'view-outside';
    /** W: {mailtemplate:N} whose e-mail is not in the app */
    public const EMAIL_OUTSIDE = 'email-outside';
    /** W: computed view/e-mail - `vid={..}`, `vid=_#ALIAS`, `{view:{..}}`, `cmd[{..}]`, ... (§ 8.2) */
    public const COMPUTED = 'computed';
    /** W: a form AA reads, but the rewriter does not: a number of more than 10 significant digits in
     *  a known slot, URL-encoded `cmd%5B`/`set%5B` or keys of view.php/f_v: (`v%69d=`), `%` or `+`
     *  (parse_str() decodes them) anywhere in a set[] value and in the part of a cmd[] value that picks
     *  the view - the command letter, or the first parameter of v/x/o/i (`%76-12`, `v-%312`, `+v-12`;
     *  the `%22` of `c-1-%22..` in {livesearch:} stays fine), a quoted index `cmd['12']` (NormalizeArrayIndex(), include/util.php:322), a key
     *  ending in `vid` (`covid=12` - AA's `/vid=\s*(\w+)/` has no left boundary, view.class.php:422).
     *  The last one is I outside view.php/f_v: parameters (`?covid=19` in a link is no view). */
    public const UNKNOWN_FORM = 'unknown-form';
    /** I: a number equal to an app view/e-mail id outside the known forms (§ 8.2, `\b3072\b`) - not W: small
     *  view ids match colspan, substr arguments, dates, and a W would block the export of a published app */
    public const BARE_NUMBER = 'bare-number';
    /** W: {ids:<sid>, {modulefield:<sid>, #sLiCe-<sid> of a module outside the app (§ 5.2, D11) */
    public const MODULE_OUTSIDE = 'module-outside';
    /** W: {form:..} - AA_Form is not transferred in v1 (§ 5.4, D14) */
    public const FORM = 'form';
    /** I: {constant(s)/options:<g> - the group is neither in the package nor a core group (§ 5.3) */
    public const GROUP_OUTSIDE = 'group-outside';
    /** I: a mapped long id glued to other hex chars (`#d<id>`, `<id>f`) - not rewritten (§ 8.1) */
    public const GLUED_ID = 'glued-id';
    /** I: a mapped group name outside the rewritten forms stays unchanged (§ 8.3) */
    public const GROUP_OTHER = 'group-other';

    /** LintFinding::$ref is cut to this many bytes */
    public const MAX_REF = 200;

    /** at most this many findings of one code per lint() call (the first ones collected); the rest
     *  becomes one finding of the same code and level, ref `+N more`, at the first one left out */
    public const MAX_PER_CODE = 1000;

    /** W patterns found by the regex alone */
    private const PATTERNS = [
        self::COMPUTED => '~
              (?<![\w])vid=\s*+(?:\{|_\#)
            | (?<![\w])(?:cmd|set)\[\s*+(?:\{|_\#)
            | (?<![\w])cmd\[\d{0,10}+\](?:\[\])?=\s*+[vxoi]\s*+-\s*+(?:\{|_\#)
            | \{(?i:view|livesearch|mailtemplate):\s*+(?:\{|_\#)
            | (?<=[=,:])banner-\d{1,10}+-(?:\{|_\#)
            ~x',
        self::FORM         => '~\{form:~i',
    ];

    /** UNKNOWN_FORM found by the regex alone */
    private const UNKNOWN_RE = '~
          (?: (?<!\w)vid=\s*+ | (?<!\w)(?:cmd|set)\[ | (?<!\w)cmd\[\d*+\](?:\[\])?=\s*+[vxoi]\s*+-\s*+
            | [,:=]banner-\d++- | (?<!\w)banner(?:\[\d*+\])?=\s*+\d++- | \{(?i:view|livesearch|mailtemplate):\s*+
            ) 0*+[1-9]\d{10}\d*+(?!\w)
        | (?<!\w)(?:cmd|set)%5[bB]
        | (?<!\w)cmd\[[^\]]{0,12}+\](?:\[\])?=(?:\s*+[vxoi]\s*+-)?[^&"\'<>{}\[\]\n%+-]*+[%+]
        | (?<!\w)set\[[^\]]{0,12}+\](?:\[\])?=[^&"\'<>{}\[\]\n%+]*+[%+]
        | (?<!\w)(?:cmd|set)\[[\'"]
        ~x';

    /** parameters of view.php and f_v: (the query string up to a space, quote, tag or brace) */
    private const QUERY_RE = '~(?:view\.php3?\?|f_v:)\K[^\s"\'<>{}]++~';

    /** longer hex runs are not searched for glued ids (bounded time) */
    private const MAX_GLUED = 1024;

    /** AA_Stringexpand_Ids / _Modulefield take a module id first; `#sLiCe-` = relation widget const */
    private const MODULE_RE = '~(?:\{(?:ids|modulefield):\s*+|\#sLiCe-)\K[0-9a-f]{32}(?![0-9a-f])~i';

    /** a standalone number - not a part of `12px`, `1.5`, `listlen-5`, `c-1-..` */
    private const NUMBER_RE = '~(?<![\w.-])\d++(?![\w.])~';

    /** @var array<string, int> findings of each code in the running lint() call */
    private array $count = [];
    /** @var array<string, array{string, int}> code => [level, offset] of the first finding left out */
    private array $dropped = [];

    public function __construct(private readonly IdMap $map) {}

    /** @return list<LintFinding> ordered by offset; `ref` is raw package text - escape (safe()) it when shown */
    public function lint(string $text): array
    {
        $this->count   = [];
        $this->dropped = [];
        $findings      = [...$this->numbers($text), ...$this->modules($text), ...$this->groups($text), ...$this->unknownForms($text), ...$this->glued($text)];
        foreach (self::PATTERNS as $code => $re) {
            foreach (self::matches($re, $text) as [$ref, $offset]) {
                $this->add($findings, LintFinding::WARNING, $code, $ref, $offset);
            }
        }
        foreach ($this->dropped as $code => [$level, $offset]) {
            $findings[] = new LintFinding($level, $code, '+' . ($this->count[$code] - self::MAX_PER_CODE) . ' more', $offset);
        }
        usort($findings, static fn(LintFinding $a, LintFinding $b): int => $a->offset <=> $b->offset);
        return $findings;
    }

    /** @return list<LintFinding> */
    private function numbers(string $text): array
    {
        $findings = [];
        $known    = [];     // offsets of numbers inside the known forms
        foreach (ReferenceRewriter::numericRefs($text) as [$kind, $number, $offset]) {
            $known[$offset] = true;
            if (($kind === 'email') ? ($this->map->email((int) $number) === null) : ($this->map->view((int) $number) === null)) {
                $this->add($findings, LintFinding::WARNING, ($kind === 'email') ? self::EMAIL_OUTSIDE : self::VIEW_OUTSIDE, $number, $offset);
            }
        }
        $app = $this->map->views() + $this->map->emails();   // only the keys matter
        if ($app) {
            foreach (self::matches(self::NUMBER_RE, $text) as [$number, $offset]) {
                if ((strlen(ltrim($number, '0')) <= 10) && !isset($known[$offset]) && isset($app[(int) $number])) {
                    $this->add($findings, LintFinding::INFO, self::BARE_NUMBER, $number, $offset);
                }
            }
        }
        return $findings;
    }

    /** @return list<LintFinding> */
    private function unknownForms(string $text): array
    {
        $findings = [];
        $seen     = [];    // offsets already reported - one finding per place
        foreach (self::matches(self::UNKNOWN_RE, $text) as [$ref, $offset]) {
            $seen[$offset] = true;
            $this->add($findings, LintFinding::WARNING, self::UNKNOWN_FORM, $ref, $offset);
        }
        foreach (self::matches(self::QUERY_RE, $text) as [$query, $at]) {
            foreach (explode('&', $query) as $param) {
                $key = strstr($param, '=', true);
                $k   = str_starts_with((string) $key, 'amp;') ? substr((string) $key, 4) : (string) $key;
                $off = $at + strlen((string) $key) - strlen($k);
                $at += strlen($param) + 1;
                $odd_vid = (strlen($k) > 3) && str_ends_with($k, 'vid') && preg_match('/\w/', $k[-4]) && preg_match('/^\s*\d/', substr($param, strlen((string) $key) + 1));
                if (!isset($seen[$off]) && ($odd_vid || RewriteFailed::check(preg_match('/%[0-9a-fA-F]{2}/', $k)))) {
                    $seen[$off] = true;
                    $this->add($findings, LintFinding::WARNING, self::UNKNOWN_FORM, $k, $off);
                }
                if ($odd_vid) {
                    $seen[$off + strlen($k) - 3] = true;
                }
            }
        }
        foreach (self::matches('~(?<=\w)vid=(?=\s*+\d)~', $text) as [$ref, $offset]) {
            if (!isset($seen[$offset])) {
                $this->add($findings, LintFinding::INFO, self::UNKNOWN_FORM, $ref, $offset);
            }
        }
        return $findings;
    }

    /** Hex runs longer than 32 whose 32-char pieces are not the ids rewriteLongIds() sees. Linear:
     *  one lookup per position of runs up to MAX_GLUED chars.
     *  @return list<LintFinding> */
    private function glued(string $text): array
    {
        if (!$this->map->longIds()) {
            return [];
        }
        $findings = [];
        foreach (self::matches('~(?<![0-9a-fA-F])[0-9a-fA-F]{33,}+~', $text) as [$run, $offset]) {
            $len = strlen($run);
            for ($i = 0; ($len <= self::MAX_GLUED) && ($i + 32 <= $len); $i++) {
                $aligned = ($len % 32 === 0) && ($i % 32 === 0);    // rewritten as ancestors
                if (!$aligned && ($this->map->longId($id = substr($run, $i, 32)) !== null)) {
                    $this->add($findings, LintFinding::INFO, self::GLUED_ID, $id, $offset + $i);
                }
            }
        }
        return $findings;
    }

    /** @return list<LintFinding> */
    private function modules(string $text): array
    {
        $findings = [];
        foreach (self::matches(self::MODULE_RE, $text) as [$id, $offset]) {
            if ($this->map->module($id) === null) {
                $this->add($findings, LintFinding::WARNING, self::MODULE_OUTSIDE, $id, $offset);
            }
        }
        return $findings;
    }

    /** @return list<LintFinding> */
    private function groups(string $text): array
    {
        $findings = [];
        $known    = [];
        foreach (self::matches(ReferenceRewriter::GROUP_RE, $text) as [$raw, $offset]) {
            $name   = trim($raw);
            $offset += (int) strpos($raw, $name);
            $known[$offset] = true;
            if (IdMap::isAppGroup($name) && ($this->map->group($name) === null)) {
                $this->add($findings, LintFinding::INFO, self::GROUP_OUTSIDE, $name, $offset);
            }
        }
        // in chunks - one alternation of thousands of names exceeds the PCRE pattern size
        foreach (array_chunk(array_map('strval', array_keys($this->map->groups())), 500) as $names) {
            $re = '~(?<![\w-])(?:' . implode('|', array_map(static fn(string $g): string => preg_quote($g, '~'), $names)) . ')(?![\w-])~';
            foreach (self::matches($re, $text) as [$name, $offset]) {
                if (!isset($known[$offset])) {
                    $this->add($findings, LintFinding::INFO, self::GROUP_OTHER, $name, $offset);
                }
            }
        }
        return $findings;
    }

    /** Whole matches with byte offsets, one preg_match() at a time: memory stays flat (preg_match_all
     *  over 1M numbers of a 2 MB text took 233 MB).
     *  @return Generator<int, array{string, int}> */
    private static function matches(string $re, string $text): Generator
    {
        $start = 0;
        while (RewriteFailed::check(preg_match($re, $text, $m, PREG_OFFSET_CAPTURE, $start))) {
            yield [$m[0][0], $m[0][1]];
            $start = $m[0][1] + max(strlen($m[0][0]), 1);
        }
    }

    /** Appends a finding, at most MAX_PER_CODE of one code; counts the rest for the summary.
     *  @param list<LintFinding> $findings */
    private function add(array &$findings, string $level, string $code, string $ref, int $offset): void
    {
        $count = $this->count[$code] = ($this->count[$code] ?? 0) + 1;
        if ($count <= self::MAX_PER_CODE) {
            $findings[] = new LintFinding($level, $code, substr($ref, 0, self::MAX_REF), $offset);
        } elseif ($count === self::MAX_PER_CODE + 1) {
            $this->dropped[$code] = [$level, $offset];
        }
    }
}

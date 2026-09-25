<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Package;
use AA\Util\UserAliasFunction;
use Closure;
use Throwable;

/** The security lint of an imported package (design balicek-v1.md § 3.3, § 8.4 level S): the package
 *  is code that runs with the rights of the target AA. Every text is linted - columns of all rows (the
 *  router home in site.state_file included), object and settings properties, spot names, variables and
 *  conditions (AA expressions, spot::conditionMatches()), constants, item values, RawBytes as they are.
 *  A help for the preview, not a sandbox: the template language is too rich to be cleaned statically
 *  (a command name can be built at run time - composed names and placeholders are only flagged). It
 *  knows the commands of AA itself, not those a target adds by STRINGEXPAND_INC - the preview says so
 *  (ImportPlan::preview() `customCommands`). The trust boundary is who may install (PS_INSTALL_APP, Q-H2,
 *  checked by Importer::write()); its S findings need the confirmation there (ImportConfirmation). */
final class SecurityLint
{
    /** S: AA commands (AA_Stringexpand_*) - code = the command name in lower case. Read files or URLs
     *  (SSRF; a relative URL sends the user's cookies): include (any type, the default is http), file2text,
     *  rss2html, fileinfo, filelink, linkcheck, img, gethostbyaddr; reveal request, user or config data:
     *  server, cookie, xuser, userinfo (login log of any user); carry or issue
     *  secrets: credentials, jwtencode, setsecret, changepwd*; send mail: mail, mailtemplate, mailform
     *  (attachments read local files); write data: newitem, updateitem, ensureitem, recompute, datovka;
     *  run code or change the response: internal, redirect, header; re-expand text: expand, dequote */
    public const COMMANDS = ['include', 'file2text', 'rss2html', 'fileinfo', 'filelink', 'linkcheck', 'gethostbyaddr',
        'server', 'cookie', 'xuser', 'userinfo', 'credentials', 'jwtencode', 'setsecret', 'changepwd\w*', 'mail', 'mailtemplate',
        'mailform', 'newitem', 'updateitem', 'ensureitem', 'recompute', 'datovka', 'internal', 'redirect', 'header', 'expand', 'dequote'];
    /** I: installation facts - debug, version, serverload */
    public const INFO_COMMANDS = ['debug', 'version', 'serverload'];
    /** S `user`: {user:password} (PHP_AUTH_PW) or a computed argument; any other {user..} is I */
    private const USER_S = '~^\{user[:(]\s*(?:password\s*[:)}]|[^:)}]*(?:\{|_#|\x1E))~i';
    /** S `img`: a first argument with a scheme or `//` (a fetch by the target), or computed other than one
     *  bare field reference; a relative path or a field is I */
    private const IMG_S = '~^\s*(?:[a-z][a-z0-9+.-]*#?:)?//|^[^:)}]*(?:\{|_#|\x1E)~i';
    /** S: alias functions f_u (calls a user function, include/item.php f_u()) and f_z (= {fileinfo}) in
     *  field.alias*_func or {alias:<field>:f_u:..} - code = the function in lower case. f_u naming a literal
     *  usr_* function (UserAliasFunction::NAME) is I: AA calls only such a user function the target
     *  defines (in its include/usr_aliasfnc.php) - which ones the target has the lint cannot know */
    public const ALIAS_FUNCTIONS = ['f_u', 'f_z'];
    /** I: f_u and a literal usr_* name, then `:` or the end (`}` in {alias:..}) */
    private const USER_FUNCTION = '(?i:f_u):' . UserAliasFunction::NAME . '(?=:|\}|\z)';
    /** `{alias:<field>:` as AA splits it (stringexpand.php expand_bracketed(): /^alias:([^:]*):([a-zA-Z0-9_]{1,3}):?(.*)$/
     *  on the text with the inner {..} already expanded - their colons are quoted, `#:` is no escape there):
     *  the field part is literal text or balanced {..} (computed) up to the first `:` outside them. The
     *  function is then the next 1-3 word characters - `f_zhttp://..` calls f_z. Needs (?&br) of BRACES */
    private const ALIAS_HEAD = '\{alias:(?:[^:{}]++|(?&br)){0,200}+:';
    private const BRACES     = '(?(DEFINE)(?<br>\{(?:[^{}]++|(?&br))*+\}))';
    /** S: {alias:<field>:..} whose function is not a literal item function (a letter, `_`, a letter or digit:
     *  f_h, i_s, l_b) - built by an inner {..} (`f_{x}`, `{concat:f_:z}`), an alias (_#FNC) or \x1E, so it
     *  could be any f_*; also a field part longer than ALIAS_HEAD reads (201+ pieces), where it cannot tell */
    public const ALIAS_COMPUTED = 'alias-computed';
    /** S: a regular expression of the lint failed on the text (PCRE backtrack/recursion/JIT limit - e.g.
     *  thousands of nested braces); the rules after it still run, the text is not linted completely */
    public const LINT_FAILED = 'lint-failed';
    /** S: a command name built at run time - `{` right after `{` or inside the name (`{{..}include}`, `{inc{x}`) */
    public const COMPOSED = 'composed-command';
    /** S: a placeholder as the command name - {foreach} and {_:Alias} put in _#1, _#P1, _#loop, _#<json key>
     *  by str_replace and expand the result (stringexpand.php:10070-10085, 9157); `{_#ALIAS___}` is an alias */
    public const PLACEHOLDER = 'placeholder-command';
    /** S: `\u007b` / `\u007d` - braces {foreach:..:json} decodes into real ones */
    public const JSON_BRACE = 'json-brace';
    /** S: byte \x1E - AA's internal quote map (QuoteColons()), smuggles `:` and braces past the parser */
    public const QUOTE_MAP = 'quote-map';
    /** S: `slice_pwd=` - a reading password in a view URL */
    public const SLICE_PWD = 'slice-pwd';
    /** email.attachments, split as AA_Mail::parseAttachmentsFromString() splits them after unaliasing:
     *  S = literal http(s)/ftp URLs (fetched by the target - SSRF) or a bare field reference
     *  ({file............}, _#ALIAS___ - anonymous posting can fill it), ref = the hosts and fields */
    public const ATTACHMENTS = ExportLint::EMAIL_ATTACHMENTS;
    /** W: a literal local path (another scheme) or another computed value - AA_File_Wrapper would read the
     *  target's files; the importer clears the value (FixUps), so legacy transfers still import */
    public const ATTACHMENTS_DROPPED = 'email-attachments-dropped';
    /** W: a tag start SPOT_TAG in a spot name, variable or condition - the site tree admin prints them
     *  unescaped (sitetree.php); the importer strip_tags() the name, conditions stay; `<` of a comparison
     *  ({if:..:<:3..}) is fine. ExportLint reports the same. */
    public const SPOT_MARKUP = 'spot-markup';
    public const SPOT_TAG    = '~<[a-z/!?]~i';

    /** a bare field reference: the field of the item the mail is about */
    private const FIELD_REF = '~^(?:\{[A-Za-z0-9_.]{16}\}|_#[A-Z0-9_]{8})\z~';

    private FindingList $findings;

    public function __construct(private readonly Schema $schema) {}

    /** S and I findings of one text, by offset, at most FindingList::MAX_PER_CODE of a level and code;
     *  `ref` is raw package text
     *  @return list<LintFinding> */
    public function lint(string $text): array
    {
        $list = new FindingList();
        $this->text($list, 'text', $text);
        $found = array_column(array_filter($list->all(), static fn(array $f): bool => $f[0] === 'text'), 1);     // no `+N more` ('')
        usort($found, static fn(LintFinding $a, LintFinding $b): int => $a->offset <=> $b->offset);
        return $found;
    }

    /** [regex, level or fn(text from the match): level, code (null = group 1 in lower case)] - AA matches
     *  command names case-insensitively (AA_Serializable::constructClassName())
     *  @return list<array{string, string|Closure(string): string, ?string}> */
    private static function rules(): array
    {
        $command = static fn(array $names): string => '~\{(' . implode('|', $names) . ')(?:[:(][^{}]{0,200}+\}?|\})~i';
        $s_or_i  = static fn(string $re, int $skip): Closure => static fn(string $at): string => preg_match($re, substr($at, $skip)) ? LintFinding::SECURITY : LintFinding::INFO;
        return [
            [$command(self::COMMANDS), LintFinding::SECURITY, null],
            [$command(self::INFO_COMMANDS), LintFinding::INFO, null],
            [$command(['user']), $s_or_i(self::USER_S, 0), null],
            [$command(['img']), static fn(string $at): string => preg_match('~^.{5}\s*(?:\{[A-Za-z0-9_.]{16}\}|_#[A-Z0-9_]{8})\s*[:)}]~s', $at)
                ? LintFinding::INFO : $s_or_i(self::IMG_S, 5)($at), null],
            ['~' . self::ALIAS_HEAD . '(' . implode('|', self::ALIAS_FUNCTIONS) . ')' . self::BRACES . '~i', static fn(string $at): string => preg_match('~^' . self::ALIAS_HEAD . self::USER_FUNCTION . self::BRACES . '~', $at)
                ? LintFinding::INFO : LintFinding::SECURITY, null],
            ['~' . self::ALIAS_HEAD . '(?![a-z]_[a-z0-9])' . self::BRACES . '~i', LintFinding::SECURITY, self::ALIAS_COMPUTED],
            ['~\{alias:(?:[^:{}]++|(?&br)){201}' . self::BRACES . '~i', LintFinding::SECURITY, self::ALIAS_COMPUTED],
            ['~\{\w{0,40}+\{~', LintFinding::SECURITY, self::COMPOSED],
            ['~\{_#(?:P?\d+|loop\d*|[a-z]\w*)[:(]~', LintFinding::SECURITY, self::PLACEHOLDER],
            ['~\\\\u00(?:7[bd])~i', LintFinding::SECURITY, self::JSON_BRACE],
            ['~\x1E~', LintFinding::SECURITY, self::QUOTE_MAP],
            ['~(?<!\w)slice_pwd=[^&"\'\s<>{}]{0,200}+~', LintFinding::SECURITY, self::SLICE_PWD],
        ];
    }

    /** @param FindingList|null $into the list to add to (Validator's)
     *  @return list<array{string, LintFinding}> [path, finding] of the whole package, FindingList::all() */
    public function package(Package $package, ?FindingList $into = null): array
    {
        $this->findings = $into ?? new FindingList();
        foreach ($package->modules as $m) {
            $path = "$m->type[$m->id]";
            $this->rows($path, $m->rows);
            try {       // a malformed object is Validator's E
                $values = isset(Schema::SETTINGS_CLASSES[$m->type]) ? ObjectExport::values($m->type, $m->settings, $m->objects, $path) : [];
            } catch (Throwable) {
                $values = [];
            }
            foreach ($values as [$at, , , $value]) {
                $this->text($this->findings, $at, $value);
            }
            foreach (self::arr($m->spotTree['spots'] ?? null) as $spot) {
                $this->spot("$path/spot-tree/spot[" . ValueCodec::bytes($spot['id'] ?? '') . ']', self::arr($spot));
            }
            foreach ($m->items as $item) {
                foreach (self::arr($item['content'] ?? null) as $field => $values) {
                    foreach (self::arr($values) as $i => $value) {
                        $this->text($this->findings, "$path/item[" . ValueCodec::bytes($item['id'] ?? '') . "]/{$field}[$i]", self::arr($value)['value'] ?? '');
                    }
                }
            }
        }
        foreach ($package->constantGroups as $group) {
            $this->rows("constant-group[$group->name]", $group->rows);
        }
        return $this->findings->all();
    }

    /** @param array<string, list<array<string, mixed>>> $elements */
    private function rows(string $path, array $elements): void
    {
        foreach ($elements as $element => $rows) {
            foreach (self::arr($rows) as $i => $row) {
                $at = $this->schema->rowPath($path, (string) $element, self::arr($row), (int) $i);
                foreach (self::arr($row) as $column => $value) {
                    $this->text($this->findings, "$at/$column", $value);
                    if (($element === 'field') && preg_match('/^alias\d+_func\z/', (string) $column) && preg_match('/^\s*(f_[uz])(?![a-z0-9_])/i', ValueCodec::bytes($value), $f)) {
                        $level = preg_match('~^\s*' . self::USER_FUNCTION . '~', ValueCodec::bytes($value)) ? LintFinding::INFO : LintFinding::SECURITY;
                        $this->add($this->findings, "$at/$column", $level, strtolower($f[1]), ValueCodec::bytes($value));
                    }
                }
                if (($element === 'email') && ($finding = self::attachments(ValueCodec::bytes($row['attachments'] ?? ''))) !== null) {
                    $this->findings->add("$at/attachments", $finding);
                }
            }
        }
    }

    /** @param array<mixed> $spot the canonical form of sitetree::toArray() */
    private function spot(string $at, array $spot): void
    {
        foreach (LintTexts::spotTexts($at, $spot) as $path => $text) {
            if (preg_match(self::SPOT_TAG, $text)) {
                $this->add($this->findings, $path, LintFinding::WARNING, self::SPOT_MARKUP, $text);
            }
            $this->text($this->findings, $path, $text);
        }
    }

    /** The finding of an email.attachments value (ATTACHMENTS, ATTACHMENTS_DROPPED), null = fine; the value
     *  split as AA_Mail::parseAttachmentsFromString(): a JSON list or object, or `:`-separated (ParamExplode()) */
    public static function attachments(string $value): ?LintFinding
    {
        if (!function_exists('ParamExplode')) {       // AA boot has it; composer's autoloader not
            require_once __DIR__ . '/../../../include/zids.php';
        }
        $value = trim($value);
        $list  = in_array($value[0] ?? '', ['[', '{'], true) ? json_decode($value, true) : null;
        $list  = is_array($list) ? $list : ((($value[0] ?? '') === '{') ? [$value] : ParamExplode($value));
        $refs  = [];
        foreach ($list as $file) {
            $file = is_scalar($file) ? trim((string) $file) : '{';
            if ($file === '') {
                continue;
            }
            if (preg_match(self::FIELD_REF, $file)) {
                $refs[] = $file;
            } elseif (str_contains($file, '{') || str_contains($file, '_#') || !preg_match('~^(?:https?|ftp)://([^/?#]*)~i', $file, $m)) {
                return new LintFinding(LintFinding::WARNING, self::ATTACHMENTS_DROPPED, substr($value, 0, ReferenceLint::MAX_REF), 0);
            } else {
                $refs[] = $m[1];
            }
        }
        return $refs ? new LintFinding(LintFinding::SECURITY, self::ATTACHMENTS, substr(implode(', ', array_unique($refs)), 0, ReferenceLint::MAX_REF), 0) : null;
    }

    /** the findings of a text - past FindingList::MAX_PER_CODE of a level and code only counted: a hostile
     *  text of a million matches makes no million objects */
    private function text(FindingList $into, string $path, mixed $value): void
    {
        $text   = ValueCodec::bytes($value);
        $failed = false;
        foreach (($text === '') ? [] : self::rules() as [$re, $level, $code]) {
            try {
                for ($at = 0; RewriteFailed::check(preg_match($re, $text, $m, PREG_OFFSET_CAPTURE, $at)); $at = $m[0][1] + max(1, strlen($m[0][0]))) {
                    $lvl = ($level instanceof Closure) ? $level(substr($text, $m[0][1], 300)) : $level;
                    $this->add($into, $path, $lvl, $code ?? strtolower($m[1][0]), $m[0][0], $m[0][1]);
                }
            } catch (RewriteFailed) {
                $failed = true;
            }
        }
        if ($failed) {      // once per text, a fixed ref - the text itself is what the regex choked on
            $this->add($into, $path, LintFinding::SECURITY, self::LINT_FAILED, 'regular expression limit');
        }
    }

    private function add(FindingList $into, string $path, string $level, string $code, string $ref, int $offset = 0): void
    {
        if ($into->full($level, $code)) {
            $into->skipped($level, $code, 1);
        } else {
            $into->add($path, new LintFinding($level, $code, substr($ref, 0, ReferenceLint::MAX_REF), $offset));
        }
    }

    /** @return array<mixed> */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}

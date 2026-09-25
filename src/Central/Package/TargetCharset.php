<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;
use Throwable;

/** § 2.4 of design balicek-v1.md, the import side (C4.4 part 3): every value the importer writes -
 *  row columns, settings and object properties (object names included), spot names, variables and
 *  conditions, item values, constants - converts from UTF-8 to the charset of its target module
 *  (ModuleCharset::fromUtf8(): strict iconv + round trip, STATELESS charsets only) and fits its column
 *  (Schema::lengthLimits()). An unconvertible value or a definite overflow is an E: the import stops
 *  before any write.
 *
 *  THE CONTRACT WITH THE WRITER (part 4). The plan stores no converted value. The writer rewrites
 *  with ImportRewrite - numeric(known($value)) - on the UTF-8 text and converts LAST with the plan's
 *  charset and the same ModuleCharset::fromUtf8(); a null there is a bug: throw (compensation).
 *  - Both checks here run on known($value) - what the writer converts but the view / e-mail numbers.
 *    The rewrite replaces ASCII runs by ASCII at ASCII delimiters and the charsets are stateless ASCII
 *    supersets, so the numbers cannot make a convertible value unconvertible.
 *  - The numbers come from AUTO_INCREMENT only when the writer inserts the rows: each number in a known
 *    form (ReferenceRewriter::numericRefs()) may grow to ID_DIGITS digits. A worst case over the limit is
 *    a W MAY_BE_TOO_LONG - the only W here; the writer MUST check the length again after numeric() and
 *    fail before the commit.
 *  - Every limit is in BYTES too: AA_Metabase writes through AA_MbT::getVarset4Data(), which cuts a value
 *    of any (N) column to N bytes - a utf-8 / EUC-JP text of at most N characters but more bytes would be
 *    cut inside a character. So more than N characters of a char(N) / varchar(N) = E "<n> characters > N",
 *    else more than N bytes = E "<n> bytes > N" (tooLong(); binary/varbinary/text/blob count bytes anyway).
 *  RawBytes (source bytes the export could not convert) are written as they are - the target module gets
 *  the package's lang_file, so the charset they were read in - after the same ImportRewrite (safe on
 *  bytes, see there); their length is checked, and in a utf-8 module they must be valid UTF-8 (E CHARSET):
 *  MySQL outside strict mode cuts a utf-8 column at the first invalid byte - after the SecurityLint saw
 *  the whole text.
 *  Findings quote package text - escape them (safe()) when shown. */
final class TargetCharset
{
    /** E: the value has a character the target charset cannot hold; ref = from that character */
    public const CHARSET = 'charset';
    /** E: the converted value is longer than its column - in characters or in bytes; ref = "<n> characters|bytes > <limit>" */
    public const TOO_LONG = 'too-long';
    /** W: it may not fit - more bytes than a char(N) column has characters, or once the views / e-mails it
     *  names get 10-digit numbers; ref = "<worst> bytes > <limit>" */
    public const MAY_BE_TOO_LONG = 'may-be-too-long';

    /** charsets with characters of more than one byte - bytes and characters differ */
    private const MULTIBYTE = ['utf-8', 'euc-jp'];

    /** digits of the largest view / e-mail id (int(11): 2147483647) */
    public const ID_DIGITS = 10;

    private readonly ImportRewrite $rewrite;
    private FindingList $findings;
    /** @var array<string, array<string, array{int, bool}>> element => Schema::lengthLimits() */
    private array $limits = [];

    public function __construct(private readonly Schema $schema, IdMap $map)
    {
        $this->rewrite = new ImportRewrite($map);
    }

    /** @param array<string, string> $charsets module id => charset (Planner; a module missing = not checked)
     *  @param array<string, string> $groups   group name => charset
     *  @throws RewriteFailed a regex of the rewrite failed - the import must stop */
    public function check(Package $package, array $charsets, array $groups, FindingList $into): void
    {
        $this->findings = $into;
        foreach ($package->modules as $m) {
            if (!isset($charsets[$m->id])) {
                continue;
            }
            $charset = $charsets[$m->id];
            $path    = "$m->type[$m->id]";
            $this->rows($path, $m->rows, $charset);
            try {       // a malformed object is Validator's E
                $values = isset(Schema::SETTINGS_CLASSES[$m->type]) ? ObjectExport::values($m->type, $m->settings, $m->objects, $path) : [];
            } catch (Throwable) {
                $values = [];
            }
            foreach ($values as [$at, , , $value]) {
                $this->value($at, $value, $charset);
            }
            foreach (($m->type === Module::SITE) && is_array($m->spotTree['spots'] ?? null) ? $m->spotTree['spots'] : [] as $spot) {
                foreach (SecurityLint::spotTexts("$path/spot-tree/spot[" . ValueCodec::bytes($spot['id'] ?? '') . ']', (array) $spot) as $at => $text) {
                    $this->value($at, $text, $charset);
                }
            }
            foreach ($m->items as $item) {
                foreach ((array) ($item['content'] ?? []) as $field => $list) {
                    foreach ((array) $list as $i => $value) {
                        $this->value("$path/item[" . ValueCodec::bytes($item['id'] ?? '') . "]/{$field}[$i]", ((array) $value)['value'] ?? '', $charset);
                    }
                }
            }
        }
        foreach ($package->constantGroups as $group) {
            $this->rows("constant-group[$group->name]", $group->rows, $groups[$group->name] ?? 'utf-8');
        }
    }

    /** @param array<string, list<array<string, mixed>>> $elements */
    private function rows(string $path, array $elements, string $charset): void
    {
        foreach ($elements as $element => $rows) {
            $limits = $this->limits[$element] ??= $this->schema->lengthLimits($element);
            foreach ($rows as $i => $row) {
                $at = $this->schema->rowPath($path, $element, $row, $i);
                foreach ($row as $column => $value) {
                    $this->value("$at/$column", $value, $charset, $limits[$column] ?? null, ImportRewrite::isWidget($element, (string) $column));
                }
            }
        }
    }

    /** @param array{int, bool}|null $limit Schema::lengthLimits() of the column, null = none */
    private function value(string $path, mixed $value, string $charset, ?array $limit = null, bool $widget = false): void
    {
        if (!(is_string($value) || ($value instanceof RawBytes)) || ($value === '')) {
            return;     // null, numbers: ASCII
        }
        $known    = $this->rewrite->known($value, $widget);
        $measured = self::measure($known, $charset);
        if ($measured === null) {
            $this->unconvertible($path, ValueCodec::bytes($known), $charset);
            return;
        }
        if ($limit === null) {
            return;
        }
        if (($too_long = self::tooLong($measured, $limit)) !== null) {
            $this->add($path, LintFinding::ERROR, self::TOO_LONG, $too_long);
            return;
        }
        [$bytes]  = $measured;
        $worst    = strlen($bytes);        // the bytes of a char(N) column in a DB counting them; the numbers
        foreach (ReferenceRewriter::numericRefs($bytes) as [, $number]) {
            $worst += max(0, self::ID_DIGITS - strlen($number));
        }
        if ($worst > $limit[0]) {
            $this->add($path, LintFinding::WARNING, self::MAY_BE_TOO_LONG, "$worst bytes > $limit[0]");
        }
    }

    /** The bytes to write and the characters to count of a rewritten value - the plan (known()) and the
     *  writer (numeric(known()), RowStore) use this one function: a text converted from UTF-8 with
     *  ModuleCharset::fromUtf8(), RawBytes as they are.
     *  @return array{string, int}|null [bytes in the charset, characters]; null = the text does not convert,
     *                                  or RawBytes of a utf-8 module are no UTF-8 */
    public static function measure(string|RawBytes $value, string $charset): ?array
    {
        if ($value instanceof RawBytes) {
            $bytes = $value->bytes;
            if ((strtolower($charset) === 'utf-8') && !mb_check_encoding($bytes, 'UTF-8')) {
                return null;
            }
            return [$bytes, in_array(strtolower($charset), self::MULTIBYTE, true) ? mb_strlen($bytes, $charset) : strlen($bytes)];
        }
        $bytes = ModuleCharset::fromUtf8($value, $charset);
        return ($bytes === null) ? null : [$bytes, mb_strlen($value, 'UTF-8')];
    }

    /** "<n> characters|bytes > <limit>" when the measured value overflows its column, else null: the
     *  characters of a char(N) / varchar(N), then the bytes of any column - AA_Metabase cuts to N bytes
     *  @param array{string, int} $measured measure() @param array{int, bool} $limit Schema::lengthLimits() */
    public static function tooLong(array $measured, array $limit): ?string
    {
        [$max, $counts_chars] = $limit;
        return match (true) {
            $counts_chars && ($measured[1] > $max) => "$measured[1] characters > $max",
            strlen($measured[0]) > $max            => strlen($measured[0]) . " bytes > $max",
            default                                => null,
        };
    }

    /** E CHARSET from the first character the charset lacks - found only while the findings of the code are kept */
    private function unconvertible(string $path, string $text, string $charset): void
    {
        $offset = $this->findings->full(LintFinding::ERROR, self::CHARSET) ? 0 : (ModuleCharset::unconvertible($text, $charset) ?? 0);
        $ref    = mb_check_encoding($text, 'UTF-8') ? mb_strcut($text, $offset, ValueCodec::REF_BYTES, 'UTF-8') : substr($text, $offset, ValueCodec::REF_BYTES);
        $this->findings->add($path, new LintFinding(LintFinding::ERROR, self::CHARSET, $ref, $offset));
    }

    private function add(string $path, string $level, string $code, string $ref): void
    {
        $this->findings->add($path, new LintFinding($level, $code, $ref, 0));
    }
}

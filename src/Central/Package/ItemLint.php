<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Model\RawBytes;

/** The import plan's findings about the items a package carries (C4.4 part 4c; Planner) - what ItemWriter
 *  writes past storeItem() and its checks, shown in the preview before anything is written:
 *  - E READER_TAKEN: a reader of a Reader Management slice of the package whose login name
 *    (FIELDID_USERNAME) or e-mail (FIELDID_EMAIL) is already taken on the target (ImportTarget::
 *    usernameTaken(), emailTaken() - the registration's isUsernameFree()). The direct insert skips that
 *    check; two accounts of one login would let whichever the login finds first in - or the package's
 *    password hash log into the target's account. EVERY value is asked (ItemWriter writes them all, the
 *    login lookup matches any), in the charset of the target slice (ModuleCharset::fromUtf8() - the
 *    bytes the target stores and compares; RawBytes as they are);
 *  - E READER_MULTIVALUE: more than one value of the login name or e-mail of a reader - one account
 *    would answer to several logins;
 *  - E READER_DUPLICATE: a login name or e-mail twice in the package (all its Reader Management slices -
 *    the login looks in all of them), compared as the usual *_ci collation with PAD SPACE (case, trailing
 *    spaces) - the lookup would be ambiguous;
 *    AT PLAN TIME ONLY: a reader registering between the preview and the write is not seen. Acceptable -
 *    a check inside the write would narrow the window, not close it (the registration locks nothing the
 *    import reads; only a unique key would); the preview is shown just before the write, and the check
 *    inside the transaction would cost two AA_Set queries per reader there;
 *  - I READER_ACCOUNTS: how many login accounts with a password hash (FIELDID_PASSWORD) the package brings;
 *  - W READER_2FA: an item carries a 2FA secret (FIELDID_2FA_SECRET). The seeds travel as PLAINTEXT, like
 *    the hashes (the default until Honza decides otherwise) - anyone holding the package can generate the
 *    readers' codes. The Collector never exports them (GetItemContent() leaves `secret*` fields out), so
 *    such a package was made elsewhere;
 *  - I ALERTS_FILTER_KEPT: a value `f<filter id>` of an alerts2 field (a reader's subscriptions,
 *    AA_Collection::getFilterText4Reader()) of a collection in the package naming a filter the package does
 *    not carry - written as it is (ItemWriter maps the others); inert, the new collection has no such filter;
 *  - W ITEM_OUTSIDE: a relation field (AA_Field::getType() `id`: insert `ids`, validate `id`/`packedid`)
 *    naming an item the package does not carry - it stays as it is: on the target an item of that id, if
 *    any, is another's. (Module ids in object properties - AA_Set.slices, fieldslice, translate_slice,
 *    add_aliases - are the Validator's W module-outside.)
 *  Pure except for the ImportTarget questions. Findings quote package text - safe(). */
final class ItemLint
{
    /** E: a reader's login name or e-mail is taken on the target; ref = the value */
    public const READER_TAKEN = 'reader-taken';
    /** E: a reader has more than one login name or e-mail; ref = the count */
    public const READER_MULTIVALUE = 'reader-multivalue';
    /** E: a login name or e-mail of two readers of the package; ref = the value */
    public const READER_DUPLICATE = 'reader-duplicate';
    /** I: login accounts with a password hash in the package; ref = the count */
    public const READER_ACCOUNTS = 'reader-accounts';
    /** W: items with a 2FA secret - seeds in plaintext; ref = the count */
    public const READER_2FA = 'reader-2fa';
    /** I: an alerts2 subscription to a filter outside the package - written as it is; ref = the value */
    public const ALERTS_FILTER_KEPT = 'alerts-filter-kept';
    /** W: a relation to an item outside the package; ref = the item id */
    public const ITEM_OUTSIDE = 'item-outside';

    /** FIELDID_USERNAME, FIELDID_EMAIL, FIELDID_PASSWORD, FIELDID_2FA_SECRET - include/constants.php */
    public const USERNAME = 'headline........';
    public const EMAIL    = 'con_email.......';
    public const PASSWORD = 'password........';
    public const SECRET   = 'secret..........';
    /** slice.type of a Reader Management slice */
    public const READER_SLICE = 'ReaderManagement';
    /** an alerts2 field - FIELDID_FILTERS padded with dots, then the collection id (getAlertsField()) */
    public const FILTERS_FIELD_RE = '/^alerts2\.+([A-Za-z0-9]{1,9})\z/';

    private FindingList $findings;
    /** @var array<string, true> field id + "\n" + normalised value => seen in the package */
    private array $logins = [];

    public function __construct(private readonly ImportTarget $target) {}

    /** @param array<string, string> $charsets source module id => target charset (ImportPlan::$charsets) */
    public function check(Package $package, array $charsets, FindingList $into): void
    {
        [$this->findings, $this->logins] = [$into, []];
        $items          = $filters = $collections = [];
        foreach ($package->modules as $m) {
            foreach ($m->items as $item) {
                $items[strtolower(ValueCodec::bytes($item['id'] ?? ''))] = true;
            }
            foreach ($m->rows['alerts_filter'] ?? [] as $row) {
                $filters[ValueCodec::bytes($row['id'] ?? '')] = true;
            }
            if (($cid = ValueCodec::bytes($m->rows['alerts_collection'][0]['id'] ?? '')) !== '') {
                $collections[$cid] = true;
            }
        }
        [$accounts, $secrets] = [0, 0];
        foreach ($package->modules as $m) {
            if (!$m->items) {
                continue;
            }
            $path      = "$m->type[$m->id]";
            $readers   = (ValueCodec::bytes($m->rows['slice'][0]['type'] ?? '') === self::READER_SLICE);
            $relations = self::relationFields($m);
            foreach ($m->items as $item) {
                $at      = "$path/item[" . ValueCodec::bytes($item['id'] ?? '') . ']';
                $content = (array) ($item['content'] ?? []);
                $first   = static fn(string $field): string => ValueCodec::bytes(((array) (array_values((array) ($content[$field] ?? []))[0] ?? []))['value'] ?? '');
                if ($readers) {
                    foreach ([self::USERNAME, self::EMAIL] as $field) {
                        $this->login("$at/$field", $field, (array) ($content[$field] ?? []), $charsets[$m->id] ?? 'utf-8');
                    }
                    $accounts += ($first(self::PASSWORD) !== '') ? 1 : 0;
                }
                $secrets += ($first(self::SECRET) !== '') ? 1 : 0;
                foreach ($content as $field => $values) {
                    $field    = (string) $field;
                    $alerts   = preg_match(self::FILTERS_FIELD_RE, $field, $match) && isset($collections[$match[1]]);
                    $relation = isset($relations[$field]);
                    foreach (($alerts || $relation) ? (array) $values : [] as $i => $v) {
                        $value = ValueCodec::bytes(((array) $v)['value'] ?? '');
                        if ($alerts && preg_match('/^f(\d+)\z/', $value, $f) && !isset($filters[$f[1]])) {
                            $this->add("$at/{$field}[$i]", LintFinding::INFO, self::ALERTS_FILTER_KEPT, $value);
                        } elseif ($relation && preg_match('/^[0-9a-f]{32}\z/i', $value) && !isset($items[strtolower($value)])) {
                            $this->add("$at/{$field}[$i]", LintFinding::WARNING, self::ITEM_OUTSIDE, $value);
                        }
                    }
                }
            }
        }
        if ($accounts) {
            $this->add('meta/contents', LintFinding::INFO, self::READER_ACCOUNTS, (string) $accounts);
        }
        if ($secrets) {
            $this->add('meta/contents', LintFinding::WARNING, self::READER_2FA, (string) $secrets);
        }
    }

    /** E READER_MULTIVALUE, READER_DUPLICATE, READER_TAKEN for the values of a login name / e-mail field
     *  @param array<int|string, mixed> $values key => {value, flag} */
    private function login(string $at, string $field, array $values, string $charset): void
    {
        $values = array_filter($values, static fn(mixed $v): bool => ValueCodec::bytes(((array) $v)['value'] ?? '') !== '');
        if (count($values) > 1) {
            $this->add($at, LintFinding::ERROR, self::READER_MULTIVALUE, (string) count($values));
        }
        foreach ($values as $i => $v) {
            $value = ((array) $v)['value'];
            $text  = ValueCodec::bytes($value);
            $key   = $field . "\n" . (mb_check_encoding($text, 'UTF-8') ? mb_strtolower(rtrim($text, ' '), 'UTF-8') : strtolower(rtrim($text, ' ')));
            if (isset($this->logins[$key])) {
                $this->add("{$at}[$i]", LintFinding::ERROR, self::READER_DUPLICATE, $text);
            }
            $this->logins[$key] = true;
            $stored = ($value instanceof RawBytes) ? $text : (ModuleCharset::fromUtf8($text, $charset) ?? $text);    // unconvertible: TargetCharset's E
            if (($field === self::USERNAME) ? $this->target->usernameTaken($stored) : $this->target->emailTaken($stored)) {
                $this->add("{$at}[$i]", LintFinding::ERROR, self::READER_TAKEN, $text);
            }
        }
    }

    /** the relation fields of a slice (AA_Field::getType() `id`) @return array<string, true> */
    private static function relationFields(Module $m): array
    {
        $fields = [];
        foreach ($m->rows['field'] ?? [] as $row) {
            $insert   = explode(':', ValueCodec::bytes($row['input_insert_func'] ?? ''), 2)[0];
            $validate = explode(':', ValueCodec::bytes($row['input_validate'] ?? ''), 2)[0];
            if (($insert === 'ids') || in_array($validate, ['id', 'packedid'], true)) {
                $fields[ValueCodec::bytes($row['id'] ?? '')] = true;
            }
        }
        return $fields;
    }

    private function add(string $path, string $level, string $code, string $ref): void
    {
        $this->findings->add($path, new LintFinding($level, $code, substr($ref, 0, ReferenceLint::MAX_REF), 0));
    }
}

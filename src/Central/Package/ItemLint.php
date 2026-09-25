<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\RawBytes;
use AA_Metabase;

/** The findings about the items of a batch - ItemWriter bypasses storeItem() and its checks. The batch
 *  carries no definition: the slice written on the target (TargetSlice) says whether its headlines are
 *  logins and which fields it has. The target is asked once per batch, not per item.
 *  - ITEM_ID_IN_USE: a new item would inherit the rights or content rows the target keeps for its id.
 *  - READER_ADMIN, READER_PERMS: a user of the target, or a reader holding AA permissions, would share
 *    the login with a reader whose password hash comes from the source - the batch stops.
 *  - READER_TAKEN, READER_MULTIVALUE, READER_DUPLICATE: the login lookup becomes ambiguous (*_ci, PAD
 *    SPACE) - reported, counted per slice. A copy in the same AA always meets its own readers.
 *    READER_TAKEN sees the readers of earlier batches: they are in the target's DB.
 *  - READER_2FA: seeds travel as plaintext.
 *  Logins are checked as ItemWriter writes them: converted to the slice charset, never rewritten.
 *  Relations to items outside the transfer are found once all batches are in.
 *  Findings quote package text - escape them. */
final class ItemLint
{
    /** E: the target keeps rights (perms) or content rows for the id of an item of the batch; ref = the id */
    public const ITEM_ID_IN_USE = 'item-id-in-use';
    /** E: a reader's login name is a user's login name or e-mail on the target; ref = the value */
    public const READER_ADMIN = 'reader-admin';
    /** E: a reader of the target holding AA permissions has the login name or e-mail; ref = the value */
    public const READER_PERMS = 'reader-perms';
    /** W: a reader of the target has the login name or e-mail; ref = the value */
    public const READER_TAKEN = 'reader-taken';
    /** W: a reader has more than one login name or e-mail; ref = the count */
    public const READER_MULTIVALUE = 'reader-multivalue';
    /** W: a login name or e-mail of two readers of the batch; ref = the value */
    public const READER_DUPLICATE = 'reader-duplicate';
    /** I: login accounts with a password hash in the batch; ref = the count */
    public const READER_ACCOUNTS = 'reader-accounts';
    /** W: items with a 2FA secret - seeds in plaintext; ref = the count */
    public const READER_2FA = 'reader-2fa';
    /** I: an alerts2 subscription to a filter outside the package - written as it is; ref = the value */
    public const ALERTS_FILTER_KEPT = 'alerts-filter-kept';
    /** W: an item value of a field the target slice does not have - written as text */
    public const ITEM_FIELD = Validator::ITEM_FIELD;

    /** FIELDID_USERNAME, FIELDID_EMAIL, FIELDID_PASSWORD, FIELDID_2FA_SECRET */
    public const USERNAME = 'headline........';
    public const EMAIL    = 'con_email.......';
    public const PASSWORD = 'password........';
    public const SECRET   = 'secret..........';
    /** slice.type of a Reader Management slice */
    public const READER_SLICE = 'ReaderManagement';
    /** an alerts2 field - FIELDID_FILTERS padded with dots, then the collection id (getAlertsField()) */
    public const FILTERS_FIELD_RE = '/^alerts2\.+([A-Za-z0-9]{1,9})\z/';

    private FindingList $findings;

    public function __construct(private readonly ImportTarget $target, private readonly AA_Metabase $metabase) {}

    /** a login or e-mail as the lookup compares it: case folded, trailing spaces off (*_ci, PAD SPACE) */
    public static function loginKey(string $value): string
    {
        $value = rtrim($value, ' ');
        return mb_check_encoding($value, 'UTF-8') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    /** @param Module $m the slice of a batch @param IdMap $map the run's - item ids, collections, filters */
    public function check(Module $m, TargetSlice $slice, IdMap $map, FindingList $into): void
    {
        $this->findings       = $into;
        $path                 = "$m->type[$m->id]";
        [$accounts, $secrets] = [0, 0];
        [$unknown, $rewrite]  = [[], new ImportRewrite($map)];
        $ids                  = $logins = [];
        foreach ($m->items as $item) {
            $at                              = "$path/item[" . ValueCodec::bytes($item['id'] ?? '') . ']';
            $ids[$map->item(ValueCodec::bytes($item['id'] ?? ''))] = $at;
            $content                         = (array) ($item['content'] ?? []);
            $first                           = static fn(string $field): string => ValueCodec::bytes(((array) (array_values((array) ($content[$field] ?? []))[0] ?? []))['value'] ?? '');
            if ($slice->readers) {
                foreach ([self::USERNAME, self::EMAIL] as $field) {
                    $values = array_filter((array) ($content[$field] ?? []), static fn(mixed $v): bool => ValueCodec::bytes(((array) $v)['value'] ?? '') !== '');
                    if (count($values) > 1) {
                        $this->add("$at/$field", LintFinding::WARNING, self::READER_MULTIVALUE, (string) count($values));
                    }
                    foreach ($values as $i => $v) {
                        $value            = ((array) $v)['value'];
                        $logins[$field][] = ["$at/{$field}[$i]", ValueCodec::bytes($value), TargetCharset::measure(($value instanceof RawBytes) ? $value : ValueCodec::bytes($value), $slice->charset)[0] ?? null];
                    }
                }
                $accounts += ($first(self::PASSWORD) !== '') ? 1 : 0;
            }
            $secrets += ($first(self::SECRET) !== '') ? 1 : 0;
            foreach ($content as $field => $values) {
                $field  = (string) $field;
                $target = ValueCodec::bytes($rewrite->known($field));      // alerts1/2....<cid>: the new collection
                if (!isset($slice->fields[$target]) && !$this->isColumn($field)) {
                    $unknown[$field] ??= "$at/$field";
                }
                if (!preg_match(self::FILTERS_FIELD_RE, $field, $match) || ($map->collection($match[1]) === null)) {
                    continue;
                }
                foreach ((array) $values as $i => $v) {
                    $value = ValueCodec::bytes(((array) $v)['value'] ?? '');
                    if (preg_match('/^f(\d+)\z/', $value, $f) && ($map->filter((int) $f[1]) === null)) {
                        $this->add("$at/{$field}[$i]", LintFinding::INFO, self::ALERTS_FILTER_KEPT, $value);
                    }
                }
            }
        }
        foreach ($ids ? $this->target->itemIdsInUse(array_map('strval', array_keys($ids))) : [] as $id) {
            $this->add($ids[$id] ?? $path, LintFinding::ERROR, self::ITEM_ID_IN_USE, $id);
        }
        foreach ($logins as $field => $list) {
            $this->logins((string) $field, $list);
        }
        foreach ($unknown as $field => $at) {
            $this->add($at, LintFinding::WARNING, self::ITEM_FIELD, $field);
        }
        if ($accounts) {
            $this->add($path, LintFinding::INFO, self::READER_ACCOUNTS, (string) $accounts);
        }
        if ($secrets) {
            $this->add($path, LintFinding::WARNING, self::READER_2FA, (string) $secrets);
        }
    }

    /** The findings of the values of the login or the e-mail field of the batch's readers: the users
     *  (the facade) are asked in UTF-8, the readers in the bytes ItemWriter writes in the slice charset.
     *  @param list<array{string, string, string|null}> $list [path, value, the bytes ItemWriter writes (null: TargetCharset's E)] */
    private function logins(string $field, array $list): void
    {
        $stored  = array_values(array_unique(array_filter(array_column($list, 2), static fn(?string $s): bool => $s !== null)));
        $readers = $this->target->readerLogins($field, $stored);
        $seen    = [];
        foreach ($list as [$at, $text, $bytes]) {
            $key = self::loginKey($bytes ?? $text);
            if (isset($seen[$key])) {
                $this->add($at, LintFinding::WARNING, self::READER_DUPLICATE, $text);
            }
            $seen[$key] = true;
            if (($field === self::USERNAME) && $this->target->adminLoginTaken($text)) {
                $this->add($at, LintFinding::ERROR, self::READER_ADMIN, $text);
            } elseif (($bytes !== null) && isset($readers[$key])) {
                $this->add($at, $readers[$key] ? LintFinding::ERROR : LintFinding::WARNING, $readers[$key] ? self::READER_PERMS : self::READER_TAKEN, $text);
            }
        }
    }

    /** an item column in the form of a field id (`status_code.....`) */
    private function isColumn(string $field): bool
    {
        $column = rtrim($field, '.');
        return ($field === str_pad($column, 16, '.')) && $this->metabase->isColumn('item', $column);
    }

    private function add(string $path, string $level, string $code, string $ref): void
    {
        $this->findings->add($path, new LintFinding($level, $code, substr($ref, 0, ReferenceLint::MAX_REF), 0));
    }
}

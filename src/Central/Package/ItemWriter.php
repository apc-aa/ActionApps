<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA_Metabase;
use LogicException;

/** Writes the items of an import plan (design balicek-v1.md § 6.2, § 7.2 step 5.7; C4.4 part 4c) - only
 *  when the package carries them - inside the transaction Importer opens, after the objects.
 *
 *  Per item: the `item` row, then (only then - Compensation and AA_Items::deleteItems() find content by
 *  the item) its `content` rows; the item goes to the journal once its row is written (item.id is the
 *  PRIMARY key - a colliding id fails there).
 *  - id: the plan's new id (IdMap::longId()), slice_id: the new slice. The item columns the package
 *    carries as pseudo fields (GetItemContent(): `status_code.....`, `publish_date....`, `post_date.......`,
 *    `flags...........`, ...) are the columns of the row as they are; the ones it never carries
 *    (Collector::ITEM_LEFT_OUT - state of the source AA) get: posted_by = the importing user, the rest the
 *    local default (counters 0, externally_fed '', edited_by NULL); short_id is AUTO_INCREMENT.
 *  - content rows as AA_Field_Writer::contentRow() builds them: a field with text_stored (its row in the
 *    package; a column the row lacks: the local default 1; a field the slice lacks - the Validator's W -
 *    too) keeps the value in `text`, `number` = its key (a position, or a translation >= AA_Value::
 *    MAX_INDEX), flag | FLAG_TEXT_STORED; any other field the value in `number`, flag without it, `text`
 *    NULL (contentRow()). GetItemContent() reads a number row with FLAG_HTML added - it stays set here,
 *    the read is the same.
 *  - values through RowStore - numeric(known()) and the module charset last: the ids of package items in
 *    relation fields (and anywhere in a text) are the new ones, a relation to an item outside the package
 *    stays (ItemLint: W). Password fields (input_insert_func `pwd`, as AA_Field::getType()) carry HASHES:
 *    written byte for byte, no rewrite, no conversion (ASCII anyway) - readers of a moved ReaderManagement
 *    slice log in. Their logins must be free on the target: ItemLint's E in the plan (the direct insert
 *    skips isUsernameFree()).
 *  - field_id through ImportRewrite::known(): an alerts field `alerts1....<cid>` / `alerts2....<cid>` of a
 *    collection in the package gets the new collection id, as RowWriter renames field.id (§ 6.4); the
 *    values of such an alerts2 field - the reader's subscriptions `f<alerts_filter.id>` (alerts_sending.php)
 *    - go through the filter map; one naming a filter outside the package stays (ItemLint: I).
 *
 *  Confirmed in the C4.4(4c) review (the design doc records the deviation):
 *
 *  A DIRECT, SCHEMA-DRIVEN INSERT, NOT ItemContent::storeItem() (a deviation from § 6.2 / § 7.2 5.7):
 *  storeItem() stores what a user enters, not a stored state -
 *  - the inserters (AA_Inserter::factoryByField()) run per field: `pwd` hashes the hash again (every
 *    reader locked out), `uid` puts the importer in, `2fa` makes a new secret, `ids` with `y`/`z` writes
 *    the other item's reverse relation and with `t` creates tag items, `pre` with add2constant adds
 *    constants to a group (maybe of the target), the file inserter moves files;
 *  - an insert sets post_date = now, posted_by = the importer, publish/expiry defaults, hit_* = 0;
 *  - computed fields evaluate the package's AA expressions at install time, ITEM_NEW runs the target's
 *    planned tasks and events (e-mails), FeedItem() feeds other slices, AA_Log / ChangesMonitor write,
 *    AA::Pagecache() and the item caches are touched, AA_Slice::getModule() caches the uncommitted slice;
 *  - its statements halt (die()) instead of throwing, and it re-reads every item.
 *  Nothing here takes a second handle: every statement is RowStore's AA_Metabase::doInsert('nohalt') on
 *  the transaction's handle. What storeItem() would do after the store and an import still needs (the
 *  page cache of the new slice) is Importer's step after the commit (part 4d).
 *
 *  Follow-up for part 6 (chunk mode): one multi-row INSERT per item (or per chunk) for its content rows
 *  instead of one statement per value. */
final class ItemWriter
{
    private IdMap $map;
    private ImportRewrite $rewrite;
    private ImportJournal $journal;
    private string $charset;

    public function __construct(private readonly RowStore $store, private readonly AA_Metabase $metabase, private readonly string $user) {}

    /** @param IdMap $map the map RowWriter returned - with the filter numbers
     *  @throws WriteError|RewriteFailed|LogicException */
    public function write(ImportPlan $plan, IdMap $map, ImportJournal $journal): void
    {
        [$this->map, $this->journal, $this->rewrite] = [$map, $journal, new ImportRewrite($map)];
        foreach ($plan->package->modules as $m) {
            if (!$m->items) {
                continue;
            }
            if (($m->type !== Module::SLICE) || !$plan->package->withItems) {
                throw new LogicException("$m->type[$m->id]: items outside a slice or without meta/contents/@items (the Validator's E)");
            }
            $slice         = $map->module($m->id) ?? throw new LogicException("Module $m->id is not mapped");
            $this->charset = $plan->charsets[$m->id] ?? throw new LogicException("No charset for module $m->id");
            $fields        = self::fields($m);
            foreach ($m->items as $item) {
                $old  = ValueCodec::bytes($item['id'] ?? '');
                $path = "$m->type[$m->id]/item[$old]";
                $id   = $map->longId($old) ?? throw new LogicException("$path: the item is not mapped");
                [$columns, $content] = $this->split((array) ($item['content'] ?? []), $path);
                $journal->step($path);
                $this->store->insert('item', $this->store->tableRow('item', $columns, ['id' => $id, 'slice_id' => $slice, 'posted_by' => $this->user], $this->charset, $this->rewrite, $path), $path);
                $journal->item($id, $slice);
                foreach ($content as $field => $values) {
                    $this->field($id, (string) $field, (array) $values, $fields[$field] ?? [true, false], "$path/$field");
                }
            }
        }
    }

    /** the content rows of one field of an item
     *  @param array<int|string, mixed> $values key => {value, flag} @param array{bool, bool} $type [text stored, password] */
    private function field(string $id, string $field, array $values, array $type, string $path): void
    {
        [$text_stored, $password] = $type;
        $field_id = ValueCodec::bytes($this->rewrite->known($field));        // alerts1/2....<cid>: the new collection (same length)
        $filters  = preg_match(ItemLint::FILTERS_FIELD_RE, $field, $match) && ($this->map->collection($match[1]) !== null);
        foreach ($values as $key => $v) {
            $at    = "{$path}[$key]";
            $value = ((array) $v)['value'] ?? '';
            if ($filters && preg_match('/^f(\d+)\z/', ValueCodec::bytes($value), $f) && (($filter = $this->map->filter((int) $f[1])) !== null)) {
                $value = "f$filter";            // a subscription to a filter of the package (alerts_sending.php: "f" . filter id)
            }
            if ($text_stored && !$password) {
                $value = $this->store->value($value, $this->charset, $this->rewrite, "$at/text");
            }
            $row = self::contentRow($id, $field_id, $text_stored, ValueCodec::bytes($value), (int) $key, (int) (((array) $v)['flag'] ?? 0));
            $this->journal->step($at);
            $this->store->insert('content', $this->store->tableRow('content', [], $row, $this->charset, $this->rewrite, $at), $at);
        }
    }

    /** AA_Field_Writer::contentRow() (include/itemfunc.php): a text stored field keeps the value in `text`
     *  (flag gains FLAG_TEXT_STORED, `number` is the value index), any other in `number` (flag loses it,
     *  `text` stays NULL) @return array<string, int|string> */
    private static function contentRow(string $item_id, string $field_id, bool $text_stored, string $value, int $order, int $flag): array
    {
        if ($text_stored) {
            return ['item_id' => $item_id, 'field_id' => $field_id, 'number' => $order, 'text' => $value, 'flag' => $flag | FLAG_TEXT_STORED];
        }
        return ['item_id' => $item_id, 'field_id' => $field_id, 'number' => (int) $value, 'flag' => $flag & ~FLAG_TEXT_STORED];
    }

    /** [item columns => value, content fields => values] of an item's content (GetItemContent() form)
     *  @param array<string, mixed> $content @return array{array<string, mixed>, array<string, mixed>} */
    private function split(array $content, string $path): array
    {
        $columns = $fields = [];
        foreach ($content as $field => $values) {
            $field = (string) $field;
            if (in_array($field, Collector::ITEM_LEFT_OUT, true)) {
                throw new LogicException("$path/$field: never carried (the Validator's E)");
            }
            $column = rtrim($field, '.');
            if (($field === str_pad($column, 16, '.')) && $this->metabase->isColumn('item', $column)) {
                $first            = ((array) $values)[array_key_first((array) $values) ?? 0] ?? [];
                $columns[$column] = ((array) $first)['value'] ?? '';
            } else {
                $fields[$field] = $values;
            }
        }
        return [$columns, $fields];
    }

    /** field id => [text stored, password field] of the slice's field rows in the package
     *  @return array<string, array{bool, bool}> */
    private static function fields(Module $m): array
    {
        $fields = [];
        foreach ($m->rows['field'] ?? [] as $row) {
            $text_stored = !array_key_exists('text_stored', $row) || ((int) ValueCodec::bytes($row['text_stored'] ?? '0') !== 0);     // NULL = a number (RowWriter: 0)
            $insert      = explode(':', ValueCodec::bytes($row['input_insert_func'] ?? ''), 2)[0];
            $fields[ValueCodec::bytes($row['id'] ?? '')] = [$text_stored, $insert === 'pwd'];
        }
        return $fields;
    }
}

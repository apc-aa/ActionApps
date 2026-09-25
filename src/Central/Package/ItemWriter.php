<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\RawBytes;
use AA_Metabase;
use LogicException;

/** Writes the items of a batch inside the transaction Importer::writeBatch() opens: per item the `item`
 *  row (a colliding id fails on its PRIMARY key), then its `content` rows as AA_Field_Writer::contentRow()
 *  builds them. The field types are the target slice's (TargetSlice) - the batch has no definition.
 *  posted_by / edited_by stay the source's text: a transfer moves the items with their authors.
 *  Password fields (`pwd`) carry HASHES - written byte for byte, so readers of a moved ReaderManagement
 *  slice log in; ItemLint checks their logins, as the direct insert skips isUsernameFree().
 *  Not ItemContent::storeItem(): it stores user input - hashes the hashes again, sets author and 2FA
 *  secrets, writes reverse relations, feeds, runs events and computed fields, die()s on a DB error and may
 *  take a second handle outside the transaction.
 *  @internal only Importer calls it - the rights are checked there (Importer::writeBatch()). */
final class ItemWriter
{
    private IdMap $map;
    private ImportRewrite $rewrite;
    private string $charset;

    public function __construct(private readonly RowStore $store, private readonly AA_Metabase $metabase) {}

    /** @param Module $m the slice of a batch
     *  @param IdMap  $map the run's map - item ids by IdMap::item(), with the package items the batch names
     *  @throws WriteError|RewriteFailed|LogicException */
    public function write(Module $m, TargetSlice $slice, IdMap $map): void
    {
        [$this->map, $this->rewrite, $this->charset] = [$map, new ImportRewrite($map), $slice->charset];
        foreach ($m->items as $item) {
            $old  = ValueCodec::bytes($item['id'] ?? '');
            $path = "$m->type[$m->id]/item[$old]";
            $id   = $map->item($old);
            [$columns, $content] = $this->split((array) ($item['content'] ?? []), $path);
            $this->store->insert('item', $this->store->tableRow('item', $columns, ['id' => $id, 'slice_id' => $slice->id], $this->charset, $this->rewrite, $path), $path);
            foreach ($content as $field => $values) {
                $field_id = ValueCodec::bytes($this->rewrite->known((string) $field));       // alerts1/2....<cid>: the new collection (same length)
                [$text_stored, $password] = $slice->field($field_id);
                $plain                    = $password || ($slice->readers && in_array($field_id, [ItemLint::USERNAME, ItemLint::EMAIL], true));
                $this->field($id, (string) $field, $field_id, (array) $values, $text_stored, $plain, "$path/$field");
            }
        }
    }

    /** the content rows of one field of an item
     *  @param array<int|string, mixed> $values key => {value, flag}
     *  @param bool $plain a password hash, a reader's login or e-mail: converted, never rewritten - the bytes ItemLint checked */
    private function field(string $id, string $field, string $field_id, array $values, bool $text_stored, bool $plain, string $path): void
    {
        $filters  = preg_match(ItemLint::FILTERS_FIELD_RE, $field, $match) && ($this->map->collection($match[1]) !== null);
        foreach ($values as $key => $v) {
            $at    = "{$path}[$key]";
            $value = ((array) $v)['value'] ?? '';
            if ($filters && preg_match('/^f(\d+)\z/', ValueCodec::bytes($value), $f) && (($filter = $this->map->filter((int) $f[1])) !== null)) {
                $value = "f$filter";            // a subscription to a filter of the package (alerts_sending.php: "f" . filter id)
            }
            if ($plain) {
                $value = $this->store->plain(($value instanceof RawBytes) ? $value : ValueCodec::bytes($value), $this->charset, "$at/text");
            } elseif ($text_stored) {
                $value = $this->store->value($value, $this->charset, $this->rewrite, "$at/text");
            }
            $row = self::contentRow($id, $field_id, $text_stored, ValueCodec::bytes($value), (int) $key, (int) (((array) $v)['flag'] ?? 0));
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
}

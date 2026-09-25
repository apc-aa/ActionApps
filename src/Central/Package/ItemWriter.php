<?php

declare(strict_types=1);

namespace AA\Central\Package;

use AA\Central\Package\Model\Module;
use AA_Metabase;
use LogicException;

/** Writes the items of an import plan (design balicek-v1.md § 6.2, § 7.2 step 5.7) inside the transaction
 *  Importer opens, after the objects: per item the `item` row (a colliding id fails on its PRIMARY key),
 *  then its `content` rows as AA_Field_Writer::contentRow() builds them.
 *  - id and slice_id are the new ones; the item columns carried as pseudo fields (`status_code.....`, ...)
 *    are written as they are, of the others (Collector::ITEM_LEFT_OUT) posted_by = the importing user, the
 *    rest the local default.
 *  - Values go through RowStore (numeric(known()), the charset last): relations to package items get the
 *    new ids, one outside stays (ItemLint: W). Password fields (`pwd`) carry HASHES - written byte for byte,
 *    so readers of a moved ReaderManagement slice log in; their logins must be free on the target (ItemLint's
 *    E: the direct insert skips isUsernameFree()).
 *  - Alerts fields `alertsN....<cid>` get the new collection id, subscriptions `f<filter id>` the new filter.
 *  Not ItemContent::storeItem() (design § 15, C4.4(4c)): it stores user input - hashes the hashes again,
 *  sets author and 2FA secrets, writes reverse relations and tags, feeds, runs events and computed fields,
 *  die()s on a DB error and may take a second handle. Every statement here is RowStore's on the
 *  transaction's handle.
 *  @internal only Importer calls it - the rights and the S confirmation are checked there (Importer::write()). */
final class ItemWriter
{
    private IdMap $map;
    private ImportRewrite $rewrite;
    private string $charset;

    public function __construct(private readonly RowStore $store, private readonly AA_Metabase $metabase, private readonly string $user) {}

    /** @param IdMap $map the map RowWriter returned - with the filter numbers
     *  @throws WriteError|RewriteFailed|LogicException */
    public function write(ImportPlan $plan, IdMap $map): void
    {
        [$this->map, $this->rewrite] = [$map, new ImportRewrite($map)];
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
                $this->store->insert('item', $this->store->tableRow('item', $columns, ['id' => $id, 'slice_id' => $slice, 'posted_by' => $this->user], $this->charset, $this->rewrite, $path), $path);
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

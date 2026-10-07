<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA\IO\DB\DB_AA;
use AA\Later\LaterInterface;
use AA\Later\Toexecute;
use AA_Condition;
use AA_Fields;
use AA_Inserter_Trm;
use AA_Module;
use AA_Set;
use AA_Transformation_CopyField;
use AA_Transformator;
use zids;

/** Trims the values that fields with the Trim insert function stored before they got it. The items are
 *  stored again by the queue the way "Modify items" with Silent does it - the insert function trims them,
 *  the history records it, last edit, computed fields, events and feeding stay untouched (and a value
 *  repeated in a multi-value field stays once, as there).
 */
class TrimFieldValues extends AbstractOptimize implements LaterInterface
{
    /** What Trim may change: whitespace at either end, or a combining mark (an accent not composed - NFC).
     *  [[:space:]] of MariaDB on utf8mb4 is the Unicode whitespace aa_trim() removes, NBSP included; the rare
     *  NFC singletons (U+212B Angstrom sign) are not looked for. The search unslashes the value once. */
    public const CANDIDATE = '^[[:space:]]|[[:space:]]$|\\\\p{M}';

    /** items in one read and in one queued task - a silent store takes some 30 ms */
    private const CHUNK = 100;

    /** seconds the test may take, the page has at least 5 minutes */
    private const TIME_BUDGET = 240;

    public static function name(): string
    {
        return _m("Trim stored values");
    }

    public static function description(): string
    {
        return _m('Trims the values stored before the field got the Trim insert function (see "%1") - spaces and new lines around, accents not composed. Repair plans it in the queue: the items are stored again silently, so last edit, computed fields, events and feeding stay untouched; the history records the change. Unique fields are only reported.', [ChangeQte2Trm::name()]);
    }

    public static function actions(): array
    {
        return ['test', 'repair', 'test4modules', 'repair4modules'];
    }

    public function test(): bool
    {
        return $this->test4modules(self::trimSlices());
    }

    public function test4modules(array $modules): bool
    {
        $deadline = time() + self::TIME_BUDGET;
        $count    = 0;
        foreach (array_values($modules) as $i => $slice_id) {
            if (time() > $deadline) {
                $this->message(_m('Time is up - %1 of %2 slices checked. Repair plans all of them.', [$i, count($modules)]));
                return false;
            }
            $count += $this->scanSlice($slice_id);
        }
        $this->message(_m('%1 items to trim', [$count]));
        return !$count;
    }

    /** the queue scans one slice after another - the whole AA could not be scanned in one request */
    public function repair(): bool
    {
        $toexecute = new Toexecute();
        $slices    = self::trimSlices();
        foreach ($slices as $slice_id) {
            $toexecute->laterOnce(new self(), [$slice_id], "TrimFieldValues_$slice_id");
        }
        $this->message(_m('Trimming of %1 slices planned in the queue', [count($slices)]));
        return true;
    }

    public function repair4modules(array $modules): bool
    {
        $toexecute = new Toexecute();
        $count     = 0;
        foreach ($modules as $slice_id) {
            $count += $this->scanSlice($slice_id, $toexecute);
        }
        $this->message(_m('%1 items planned to be trimmed in the queue', [$count]));
        return true;
    }

    /** a slice task of repair() */
    public function toexecutelater($params = []): string
    {
        [$slice_id] = $params;
        $this->scanSlice((string) $slice_id, new Toexecute());
        return $this->report();
    }

    /** The slices of the user with a field that trims - one query instead of the fields of every slice.
     *  A slice whose Trim fields all come from a field slice is not found. */
    private static function trimSlices(): array
    {
        $trim = DB_AA::select('', 'SELECT DISTINCT LOWER(HEX(`slice_id`)) FROM `field`', [['input_insert_func', 'trm', 'BEGIN']]);
        return array_values(array_intersect(array_keys(AA_Module::getUserModules('S')), $trim));
    }

    /** Counts the items of the slice to trim, field by field; with the queue it plans them to be stored again
     *  @return int the items to trim */
    private function scanSlice(string $slice_id, ?Toexecute $toexecute = null): int
    {
        $count = 0;
        foreach (ChangeQte2Trm::textFields(AA_Fields::factoryFor($slice_id), 'trm') as $field) {
            // a password copy or a secret is neither shown in the report nor stored again
            if ($field->isProtected()) {
                continue;
            }
            [$ids, $example] = self::itemsToTrim($slice_id, $field->getId());
            if (!$ids) {
                continue;
            }
            // json shows the spaces and new lines around
            $example = safe((string) json_encode(mb_substr($example, 0, 60), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            $label   = '<b>' . AA_Module::getModuleNameSafe($slice_id) . '</b> - ' . ChangeQte2Trm::fieldLink($field) . ': ';
            if ($field->isUniqueValidated()) {
                // two values may trim to the same one - in other slices too, or differing in case or accents
                $this->message($label . _m('%1 items with a unique value to trim by hand, e.g. %2', [count($ids), $example]));
                continue;
            }
            if ($toexecute) {
                // the field copied to itself - storing it again runs the insert function
                $transformator = new AA_Transformator(new AA_Transformation_CopyField(['field2copy' => $field->getId()]), $field->getId());
                foreach ((new zids($ids, 'l'))->chunk(self::CHUNK) as $chunk) {
                    $toexecute->later($transformator->setZids($chunk), [], 'TrimFieldValues');
                }
            }
            $this->message($label . _m('%1 items to trim, e.g. %2', [count($ids), $example]));
            $count += count($ids);
        }
        return $count;
    }

    /** The items whose value of the field Trim would change, and the first such value. The search finds
     *  the candidates, the insert function decides - an accent may have no composed form.
     *  @return array{list<string>, string} */
    private static function itemsToTrim(string $slice_id, string $field_id): array
    {
        $ids     = [];
        $example = '';
        $found   = (new AA_Set([$slice_id], new AA_Condition($field_id, 'REGEXP', self::CANDIDATE), null, AA_BIN_ALL))->query();
        foreach (array_chunk($found->longids(), self::CHUNK) as $chunk) {
            // only compared, one value goes to the report of the superadmin
            foreach (GetItemContent(new zids($chunk, 'l'), false, true, [$field_id]) as $item_id => $content) {
                foreach ($content[$field_id] ?? [] as $value) {
                    $text = (string) $value['value'];
                    if (AA_Inserter_Trm::trimValue($text) !== $text) {
                        $ids[]   = (string) $item_id;
                        $example = ($example === '') ? $text : $example;
                        break;
                    }
                }
            }
        }
        return [$ids, $example];
    }
}

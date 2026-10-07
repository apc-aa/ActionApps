<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA\Widget\CodWidget;
use AA\Widget\Widget;
use AA_Field;
use AA_Field_Slicedef;
use AA_Fields;
use AA_Inserter_Trm;
use AA_Module;
use AA_Validate;
use AA_Validate_Eunique;
use AA_Validate_Unique;

/** Switches text fields from the insert function "Text = don't modify" (qte) to Trim (trm), so spaces
 *  and new lines around a value are not stored any more - where Trim breaks nothing (keepReason()).
 */
class ChangeQte2Trm extends AbstractOptimize
{
    /** the templates new fields are created from (admin/se_fields.php) */
    private const TEMPLATES = 'AA_Core_Fields..';

    public static function name(): string
    {
        return _m("Trim text fields");
    }

    public static function description(): string
    {
        return _m("Switches text fields from the insert function \"Text = don't modify\" to \"Trim\", so spaces and new lines around a value are not stored. Fields for code, unique values, copies of passwords and fields whose constants have such spaces keep their setting. Values stored before are not changed.");
    }

    public static function actions(): array
    {
        return ['test', 'repair', 'test4modules', 'repair4modules'];
    }

    public function test(): bool
    {
        return $this->test4modules(self::allModules());
    }

    public function repair(): bool
    {
        return $this->repair4modules(self::allModules());
    }

    public function test4modules(array $modules): bool
    {
        return !$this->switchFields($modules, false);
    }

    public function repair4modules(array $modules): bool
    {
        $this->switchFields($modules, true);
        return true;
    }

    /** @return int the text fields to switch - switched with $repair */
    private function switchFields(array $modules, bool $repair): int
    {
        $count = 0;
        foreach (array_unique($modules) as $module_id) {
            [$switch, $keep] = self::fieldsToTrim(self::fields($module_id));
            if ($repair && $switch) {
                // one commit per slice - with its history a field update takes four statements
                DB_AA::transaction(static function () use ($module_id, $switch): void {
                    foreach ($switch as $field) {
                        // the key of admin/se_inputform.php - Show History of the field lists the change
                        $history_key = 'F' . AA_Fields::createShortId($field->getId()) . '@' . pack_id($module_id);
                        AA::Metabase()->doUpdate('field', ['slice_id' => $module_id, 'id' => $field->getId(), 'input_insert_func' => 'trm:'], null, $history_key);
                    }
                });
            }
            $count += count($switch);
            $this->reportModule($module_id, $switch, $keep, $repair);
        }
        $this->message($repair ? _m('%1 text fields switched to Trim', [$count]) : _m('%1 text fields to switch to Trim', [$count]));
        return $count;
    }

    /** all slices and the field templates */
    private static function allModules(): array
    {
        return [...array_keys(AA_Module::getUserModules('S')), unpack_id(self::TEMPLATES)];
    }

    /** the fields of the items; of the templates also those of the slice settings (_…), which a new install
     *  gets with Trim too @return iterable<AA_Field> */
    private static function fields(string $module_id): iterable
    {
        $fields = AA_Fields::factoryFor($module_id);
        return ($module_id === unpack_id(self::TEMPLATES)) ? [...$fields, ...AA_Fields::factoryFor($module_id, 1)] : $fields;
    }

    /** the fields storing text in the content table with the insert function
     *  @param iterable<AA_Field> $fields @return list<AA_Field> */
    public static function textFields(iterable $fields, string $insert): array
    {
        $ret = [];
        foreach ($fields as $field) {
            if (($field->storageColumn() === 'text') && (strtolower(explode(':', (string) $field->getProperty('input_insert_func'), 2)[0]) === $insert)) {
                $ret[] = $field;
            }
        }
        return $ret;
    }

    /** @param iterable<AA_Field> $fields
     *  @return array{list<AA_Field>, list<array{AA_Field, string}>} the fields to switch; the text fields that keep qte, and why */
    public static function fieldsToTrim(iterable $fields): array
    {
        $switch = [];
        $keep   = [];
        foreach (self::textFields($fields, 'qte') as $field) {
            // fields defined in a field slice keep their setting there; a password copy must keep its spaces
            if (($field instanceof AA_Field_Slicedef) || $field->isProtected()) {
                continue;
            }
            $reason = self::keepReason($field);
            if ($reason === '') {
                $switch[] = $field;
            } else {
                $keep[] = [$field, $reason];
            }
        }
        return [$switch, $keep];
    }

    /** Why the text field keeps qte, '' - nothing, switch it */
    public static function keepReason(AA_Field $field): string
    {
        if (self::isUnique($field)) {
            return _m('unique value - it is checked before it is trimmed, so a duplicate could slip through');
        }
        // the widget parameters, not the widget: most widgets have no const property to ask for
        $params = Widget::parseClassProperties((string) $field->getProperty('input_show_func'));
        if (is_a($params['class'], CodWidget::class, true)) {
            return _m('code - its indentation and the last new line are kept on purpose');
        }
        $const = (string) ($params['const'] ?? '');
        if (str_starts_with($const, '#sLiCe-')) {
            // ids of the related items have no spaces; with use_name the text of the item is stored
            return empty($params['use_name']) ? '' : _m('stores the text of the selected items');
        }
        if (($const === '') && empty($params['const_arr'])) {
            return '';
        }
        // a trimmed value would no longer match its constant
        foreach ($field->getWidget()->getFormattedOptions() as $value => $name) {
            foreach ([(string) $value, (string) $name] as $text) {
                if (AA_Inserter_Trm::trimValue($text) !== $text) {
                    return _m('the constant "%1" has spaces or new lines around - trim the constants first', [$text]);
                }
            }
        }
        return '';
    }

    /** The form checks a unique value before the insert function, the store not at all - with Trim
     *  " jan" would pass next to "jan" and be stored as the same */
    public static function isUnique(AA_Field $field): bool
    {
        $validator = AA_Validate::factoryByString((string) $field->getProperty('input_validate'));
        return ($validator instanceof AA_Validate_Unique) || ($validator instanceof AA_Validate_Eunique);
    }

    /** the field as the reports name it, linked to its settings */
    public static function fieldLink(AA_Field $field): string
    {
        return $field->linkToField(safe($field->getName() . ' (' . $field->getId() . ')'));
    }

    /** @param list<AA_Field> $switch @param list<array{AA_Field, string}> $keep */
    private function reportModule(string $module_id, array $switch, array $keep, bool $done): void
    {
        $module = ($module_id === unpack_id(self::TEMPLATES)) ? _m('Field templates') : AA_Module::getModuleNameSafe($module_id);
        if ($switch) {
            $names = implode(', ', array_map(self::fieldLink(...), $switch));
            $this->message("<b>$module</b> - " . ($done ? _m('switched to Trim: %1', [$names]) : _m('to switch to Trim: %1', [$names])));
        }
        foreach ($keep as [$field, $reason]) {
            $this->message("<b>$module</b> - " . _m('%1 keeps "Text = don\'t modify": %2', [self::fieldLink($field), safe($reason)]));
        }
    }
}

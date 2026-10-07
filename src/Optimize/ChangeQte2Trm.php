<?php

declare(strict_types=1);

namespace AA\Optimize;

use AA;
use AA\IO\DB\DB_AA;
use AA_Field;
use AA_Field_Slicedef;
use AA_Fields;
use AA_Module;

/** Switches text fields from the insert function "Text = don't modify" (qte) to Trim (trm), so spaces
 *  and new lines around a value are not stored any more - where Trim breaks nothing (AA_Field::trimRefusal()).
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
            $reason = $field->trimRefusal();
            if ($reason === '') {
                $switch[] = $field;
            } else {
                $keep[] = [$field, $reason];
            }
        }
        return [$switch, $keep];
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

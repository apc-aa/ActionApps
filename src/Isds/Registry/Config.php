<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

use AA\IO\DB\DB_AA;
use AA_Field;
use AA_Fields;
use AA_Object;
use AA_Property;

/**
 * Which fields of a slice hold the municipalities' names, IČO, type and data box - one record per
 * slice, owned by it. Everything that pairs the slice's items with the register reads the fields
 * from here.
 *
 * values() holds the field ids, validated, and the item types as an admin typed them - escape the
 * types (safe()) when shown. A field id that is not one reads as '' (none) through values() and the
 * getters alike: GetItemContent() puts the ids into its SQL.
 *
 * @phpstan-type Values array{name_field: string, ico_field: string, ds_field: string, type_field: string, types: list<string>, district_types: list<string>}
 */
final class Config extends AA_Object
{
    /** @var string field id */
    protected $name_field = '';

    /** @var string field id, '' = none */
    protected $ico_field = '';

    /** @var string field id */
    protected $ds_field = '';

    /** @var string field id, '' = none */
    protected $type_field = '';

    /** @var string[]|string load() leaves '' for a multi property stored without values */
    protected $types = [];

    /** @var string[]|string as $types */
    protected $district_types = [];

    private const FIELDS = ['name_field', 'ico_field', 'ds_field', 'type_field'];

    private const LISTS = ['types', 'district_types'];

    public static function getClassProperties(): array
    {
        //                                      id                name                                       type      multi  persist validator required help
        return [
            'name_field'     => new AA_Property('name_field', _m('Name field'), 'string', false, true, 'string', true, _m('The name the register is searched by when the IČO finds no box')),
            'ico_field'      => new AA_Property('ico_field', _m('IČO field'), 'string', false, true, 'string', false, _m('The IČO finds the box for sure; without it only names are matched, and a person checks those')),
            'ds_field'       => new AA_Property('ds_field', _m('Data box field'), 'string', false, true, 'string', true, _m('The field the data box id is written to - the only field the pairing changes')),
            'type_field'     => new AA_Property('type_field', _m('Type field'), 'string', false, true, 'string', false, _m('The type of the item, needed for the two lists below')),
            'types'          => new AA_Property('types', _m('Types to pair'), 'string', true, true, 'string', false, _m('Only items of these types are paired; none = all items')),
            'district_types' => new AA_Property('district_types', _m('Types of city districts'), 'string', true, true, 'string', false, _m('Items of these types are paired with the boxes of city districts, the others with those of municipalities')),
        ];
    }

    /** derived from the slice, so a slice has one record and it cannot meet a random object id;
     *  is_long_id() takes capitals too, AA stores ids in small letters */
    public static function idFor(string $sliceId): string
    {
        return md5('isds-registry:' . strtolower($sliceId));
    }

    /** the stored record of the slice, null when there is none */
    public static function forSlice(string $sliceId): ?self
    {
        if (!is_long_id($sliceId)) {
            return null;
        }
        $sliceId = strtolower($sliceId);
        $config  = AA_Object::load(self::idFor($sliceId), self::class);
        return (($config instanceof self) && ($config->getOwnerId() === $sliceId)) ? $config : null;
    }

    /**
     * The records of all slices. A slice deleted since keeps none - its owned objects go with it.
     * @return list<self>
     */
    public static function all(): array
    {
        $configs = [];
        foreach (DB_AA::select('object_id', 'SELECT object_id FROM `object_text`', [['property', 'aa_type'], ['value', self::class]]) as $id) {
            $config = AA_Object::load((string) $id, self::class);
            // only a record at the id derived from its owner is the slice's
            if (($config instanceof self) && ($config->getId() === self::idFor((string) $config->getOwnerId()))) {
                $configs[] = $config;
            }
        }
        return $configs;
    }

    /**
     * A record of the slice made of form values (see values()); save() stores it in place of the
     * slice's previous one. Check the values with errors() first.
     * @param array<string, mixed> $values
     * @throws \InvalidArgumentException not a slice id
     */
    public static function fromValues(string $sliceId, array $values): self
    {
        if (!is_long_id($sliceId)) {
            throw new \InvalidArgumentException('A slice id is 32 hexadecimal digits');
        }
        $sliceId = strtolower($sliceId);
        $config  = new self(self::normalize($values));
        $config->setNew(self::idFor($sliceId), $sliceId);
        return $config;
    }

    /**
     * What is wrong with the values for the slice's fields as they are now. The messages are translated
     * (_m()) and quote what was typed - escape them (safe()) when shown.
     * @param array<string, mixed> $values as values() - a list may also be a text, one value per line
     * @param AA_Fields            $fields the slice's: AA_Slice::getModule($sliceId)->getFields()
     * @return list<string> empty when the values may be stored
     */
    public static function errors(array $values, AA_Fields $fields): array
    {
        $v        = self::normalize($values);
        $errors   = [];
        $required = ['name_field' => _m('Choose the field with the name'), 'ds_field' => _m('Choose the field the data box is written to')];
        foreach (self::FIELDS as $property) {
            $typed = is_string($values[$property] ?? null) ? trim($values[$property]) : '';
            $field = ($v[$property] === '') ? null : $fields->getField($v[$property]);
            if ($typed === '') {
                if (isset($required[$property])) {
                    $errors[] = $required[$property];
                }
            } elseif ($field === null) {
                $errors[] = _m('The slice has no field %1', [$typed]);
            } elseif ($field->isProtected()) {
                // a password or a 2FA secret would show on the page, or be overwritten
                $errors[] = _m('The field %1 is protected - it is neither shown nor written here', [$typed]);
            } elseif (($property === 'ds_field') && ($field->storageTable() !== 'content')) {
                // a column of the item table (status_code, publish_date, ...) would take the box id
                $errors[] = _m('The data box cannot be written to %1, a column of the item table', [$typed]);
            }
        }
        $ds = $v['ds_field'];
        if (($ds !== '') && in_array($ds, [$v['name_field'], $v['ico_field'], $v['type_field']], true)) {
            $errors[] = _m('The data box needs a field of its own - writing it would overwrite the name, IČO or type');
        }
        if (($v['type_field'] === '') && ($v['types'] || $v['district_types'])) {
            $errors[] = _m('The lists of types need the type field');
        }
        // the items of a district type left out of the types would not be paired at all
        $unpaired = $v['types'] ? array_diff($v['district_types'], $v['types']) : [];
        if ($unpaired) {
            $errors[] = _m('The types of city districts must be among the types to pair too: %1', [implode(', ', $unpaired)]);
        }
        return $errors;
    }

    /**
     * The settings as a form shows them.
     * @return Values
     */
    public function values(): array
    {
        return self::normalize($this->getState());
    }

    public function sliceId(): string
    {
        return (string) $this->getOwnerId();
    }

    public function nameField(): string
    {
        return self::field($this->name_field);
    }

    /** '' = none */
    public function icoField(): string
    {
        return self::field($this->ico_field);
    }

    public function dsField(): string
    {
        return self::field($this->ds_field);
    }

    /** '' = none */
    public function typeField(): string
    {
        return self::field($this->type_field);
    }

    /** @return list<string> item types to pair; [] = all items */
    public function types(): array
    {
        return self::list($this->types);
    }

    /** @return list<string> item types that are city districts */
    public function districtTypes(): array
    {
        return self::list($this->district_types);
    }

    /**
     * @param array<string, mixed> $values
     * @return Values
     */
    private static function normalize(array $values): array
    {
        $ret = [];
        foreach (self::FIELDS as $property) {
            $ret[$property] = self::field($values[$property] ?? '');
        }
        foreach (self::LISTS as $property) {
            $ret[$property] = self::list($values[$property] ?? []);
        }
        /** @var Values */
        return $ret;
    }

    /** a field id, else '' */
    private static function field(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        return AA_Field::isValidFieldId($value) ? $value : '';
    }

    /** @return list<string> trimmed, without empty and repeated values; a text has one value per line */
    private static function list(mixed $value): array
    {
        $values = is_string($value) ? (preg_split('/\R/u', $value) ?: []) : (is_array($value) ? $value : []);
        $values = array_map(static fn(mixed $v): string => is_string($v) ? trim($v) : '', $values);
        return array_values(array_unique(array_filter($values, static fn(string $v): bool => $v !== '')));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Registry;

use AA\Isds\Registry\Config;
use AA_Field;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Legacy\Fixtures\FakeFields;

final class ConfigTest extends TestCase
{
    private const SLICE = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';

    private const VALID = ['name_field' => 'headline........', 'ico_field' => 'text...........1', 'ds_field' => 'text...........2', 'type_field' => 'category........'];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../fixtures/util_shims.php';             // the constants of config.php
        require_once __DIR__ . '/../../Legacy/fixtures/store_fixtures.php';    // the legacy boot, no DB
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function settings(): array
    {
        $mine     = ['name_field' => 'headline........', 'ds_field' => 'text...........2'];
        $own      = 'The data box needs a field of its own - writing it would overwrite the name, IČO or type';
        $typeless = 'The lists of types need the type field';
        return [
            'name and box only'              => [$mine, []],
            'every field and type lists'     => [self::VALID + ['types' => ['obec', 'městská část'], 'district_types' => "městská část\n"], []],
            'districts, all types paired'    => [self::VALID + ['district_types' => ['městská část']], []],
            'spaces around'                  => [['name_field' => ' headline........ ', 'ds_field' => "text...........2\n"], []],
            'no name'                        => [['ds_field' => 'text...........2'], ['Choose the field with the name']],
            'no box'                         => [['name_field' => 'headline........'], ['Choose the field the data box is written to']],
            'not a text'                     => [['name_field' => ['headline........'], 'ds_field' => 'text...........2'], ['Choose the field with the name']],
            'unknown fields'                 => [['ico_field' => 'text...........9', 'type_field' => 'x'] + $mine, ['The slice has no field text...........9', 'The slice has no field x']],
            'no field id'                    => [['name_field' => "headline') UNION SELECT 1 -- "] + $mine, ["The slice has no field headline') UNION SELECT 1 --"]],
            'box in the name field'          => [['ds_field' => 'headline........'] + $mine, [$own]],
            'box in the IČO field'           => [['ico_field' => 'text...........2'] + $mine, [$own]],
            'box in the type field'          => [['type_field' => 'text...........2'] + $mine, [$own]],
            'box in the status code'         => [['ds_field' => 'status_code.....'] + $mine, ['The data box cannot be written to status_code....., a column of the item table']],
            'box in the publish date'        => [['ds_field' => 'publish_date....'] + $mine, ['The data box cannot be written to publish_date...., a column of the item table']],
            'types without type field'       => [$mine + ['types' => ['obec']], [$typeless]],
            'districts without the field'    => [$mine + ['district_types' => 'městská část'], [$typeless]],
            'districts not among the types'  => [self::VALID + ['types' => ['obec'], 'district_types' => ['městská část', 'obec', 'obvod']], ['The types of city districts must be among the types to pair too: městská část, obvod']],
            'empty type lists'               => [$mine + ['types' => ['', ' '], 'district_types' => "\n"], []],
        ];
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $errors
     */
    #[DataProvider('settings')]
    public function test_errors_settings(array $values, array $errors): void
    {
        self::assertSame($errors, Config::errors($values, self::fields()));
    }

    // a field deleted from the slice since the settings were saved
    public function test_errors_aFieldTheSliceLacks(): void
    {
        self::assertSame(['The slice has no field text...........2'], Config::errors(self::VALID, self::fields(['text...........2'])));
    }

    // a password, its copy, a 2FA secret or an unreadable field would show on the page or be overwritten
    public function test_errors_protectedFields(): void
    {
        $values = ['name_field' => 'password........', 'ico_field' => 'text...........3', 'ds_field' => 'text...........5', 'type_field' => 'text...........4'];

        self::assertSame([
            'The field password........ is protected - it is neither shown nor written here',
            'The field text...........3 is protected - it is neither shown nor written here',
            'The field text...........5 is protected - it is neither shown nor written here',
            'The field text...........4 is protected - it is neither shown nor written here',
        ], Config::errors($values, self::fields()));
    }

    // GetItemContent() puts the ids into its SQL
    public function test_fromValues_onlyFieldIds(): void
    {
        $config = Config::fromValues(self::SLICE, ['name_field' => "headline') UNION SELECT 1 -- ", 'ico_field' => 'text...........1x', 'ds_field' => "text'..........2", 'type_field' => 'Category........']);

        self::assertSame(['', '', '', ''], [$config->nameField(), $config->icoField(), $config->dsField(), $config->typeField()]);
    }

    public function test_fromValues_normalisedAndOwnedByTheSlice(): void
    {
        $config = Config::fromValues(self::SLICE, [
            'name_field'     => ' headline........',
            'ico_field'      => null,
            'ds_field'       => 'text...........2',
            'types'          => "obec\r\nměsto\n\nobec\n",
            'district_types' => ['městská část', ' ', 7, 'městská část'],
            'other'          => 'ignored',
        ]);

        self::assertSame([
            'name_field'     => 'headline........',
            'ico_field'      => '',
            'ds_field'       => 'text...........2',
            'type_field'     => '',
            'types'          => ['obec', 'město'],
            'district_types' => ['městská část'],
        ], $config->values());
        self::assertSame([self::SLICE, Config::idFor(self::SLICE)], [$config->sliceId(), $config->getId()]);
        self::assertSame(['headline........', '', 'text...........2', ''], [$config->nameField(), $config->icoField(), $config->dsField(), $config->typeField()]);
        self::assertSame([['obec', 'město'], ['městská část']], [$config->types(), $config->districtTypes()]);
    }

    public function test_fromValues_refusesAnotherId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Config::fromValues('not-a-slice', self::VALID);
    }

    // a value stored before the ids were checked, or written past fromValues()
    public function test_getters_storedValueThatIsNoFieldId(): void
    {
        $config = Config::fromValues(self::SLICE, self::VALID);
        $config->setProperty('name_field', "headline') OR 1 -- ")
            ->setProperty('ico_field', 'text...........1 OR 1')
            ->setProperty('ds_field', "x') OR 1 -- ")
            ->setProperty('type_field', ['category........']);

        self::assertSame(['', '', '', ''], [$config->nameField(), $config->icoField(), $config->dsField(), $config->typeField()]);
        self::assertSame(['', '', '', ''], array_values(array_slice($config->values(), 0, 4)));
    }

    // load() leaves '' in a multi property stored without values
    public function test_types_noneStored(): void
    {
        $config = Config::fromValues(self::SLICE, self::VALID);
        $config->setProperty('types', '')->setProperty('district_types', '');

        self::assertSame([[], []], [$config->types(), $config->districtTypes()]);
    }

    public function test_idFor_onePerSlice(): void
    {
        self::assertSame(md5('isds-registry:' . self::SLICE), Config::idFor(self::SLICE));
        self::assertNotSame(Config::idFor(self::SLICE), Config::idFor(str_repeat('b', 32)));
    }

    // is_long_id() takes capitals; the record and its owner are the slice's id in small letters
    public function test_fromValues_capitalsInTheSliceId(): void
    {
        $config = Config::fromValues(strtoupper(self::SLICE), self::VALID);

        self::assertSame(Config::idFor(self::SLICE), Config::idFor(strtoupper(self::SLICE)));
        self::assertSame([self::SLICE, Config::idFor(self::SLICE)], [$config->sliceId(), $config->getId()]);
    }

    /**
     * The slice's fields - content ones, two of the item table and protected ones.
     * @param list<string> $without ids left out
     */
    private static function fields(array $without = []): FakeFields
    {
        $fields = [];
        foreach ([
            'headline........' => [],
            'text...........1' => [],
            'text...........2' => [],
            'category........' => [],
            'status_code.....' => ['in_item_tbl' => 'status_code', 'text_stored' => 0],
            'publish_date....' => ['in_item_tbl' => 'publish_date', 'text_stored' => 0],
            'password........' => [],
            'text...........3' => ['input_insert_func' => 'pwd:'],
            'text...........4' => ['content_edit' => FIELD_UNREADABLE],
            'text...........5' => ['input_insert_func' => '2fa'],
        ] as $id => $data) {
            if (!in_array($id, $without, true)) {
                // no slice id: AA_Field::isPasswordCopy() would load the slice
                $fields[] = new AA_Field($data + ['id' => $id, 'slice_id' => '', 'in_item_tbl' => '', 'text_stored' => 1, 'input_insert_func' => 'qte', 'content_edit' => 0]);
            }
        }
        return new FakeFields(self::SLICE, $fields);
    }
}

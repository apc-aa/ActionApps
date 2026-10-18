<?php

declare(strict_types=1);

namespace Tests\Unit\Isds;

use AA\Isds\Schema;
use AA_Metabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    /** a changed field id is a field the installed slices lack; a changed state no longer matches stored items */
    private const VALUES = [
        'CAMPAIGN_SUBJECT'        => 'headline........',
        'CAMPAIGN_BODY'           => 'full_text.......',
        'CAMPAIGN_FILES'          => 'file............',
        'CAMPAIGN_TO_HANDS'       => 'text...........1',
        'CAMPAIGN_FILTER'         => 'text...........2',
        'CAMPAIGN_TEMPLATE'       => 'relation........',
        'CAMPAIGN_STATE'          => 'text...........3',
        'CAMPAIGN_ERROR_TEXT'     => 'text...........8',
        'CAMPAIGN_ENVIRONMENT'    => 'text...........4',
        'CAMPAIGN_SENDER_BOX'     => 'text...........5',
        'CAMPAIGN_RECIPIENTS'     => 'number.........1',
        'CAMPAIGN_SENT_BY'        => 'text...........6',
        'CAMPAIGN_SENT_BY_NAME'   => 'text...........7',
        'CAMPAIGN_STARTED_AT'     => 'date...........1',
        'CAMPAIGN_FINISHED_AT'    => 'date...........2',
        'MESSAGE_NAME'            => 'headline........',
        'MESSAGE_CAMPAIGN'        => 'relation........',
        'MESSAGE_RECIPIENT'       => 'relation.......1',
        'MESSAGE_BOX'             => 'text...........1',
        'MESSAGE_TEST'            => 'switch.........1',
        'MESSAGE_ENVIRONMENT'     => 'text...........9',
        'MESSAGE_SENDER_BOX'      => 'text..........10',
        'MESSAGE_STATE'           => 'text...........3',
        'MESSAGE_SENDER_REF'      => 'text...........4',
        'MESSAGE_DM_ID'           => 'text...........5',
        'MESSAGE_ERROR_CODE'      => 'text...........6',
        'MESSAGE_ERROR_TEXT'      => 'text...........7',
        'MESSAGE_DM_STATUS'       => 'number.........1',
        'MESSAGE_SENT_AT'         => 'date...........1',
        'MESSAGE_DELIVERED_AT'    => 'date...........2',
        'MESSAGE_ACCEPTED_AT'     => 'date...........3',
        'MESSAGE_EVENTS'          => 'text...........8',
        'CAMPAIGN_STATE_DRAFT'    => 'draft',
        'CAMPAIGN_STATE_QUEUED'   => 'queued',
        'CAMPAIGN_STATE_SENDING'  => 'sending',
        'CAMPAIGN_STATE_DONE'     => 'done',
        'CAMPAIGN_STATE_STOPPED'  => 'stopped',
        'CAMPAIGN_STATE_ERROR'    => 'error',
        'MESSAGE_STATE_QUEUED'    => 'queued',
        'MESSAGE_STATE_SENDING'   => 'sending',
        'MESSAGE_STATE_SENT'      => 'sent',
        'MESSAGE_STATE_FAILED'    => 'failed',
        'MESSAGE_STATE_UNCERTAIN' => 'uncertain',
        'MESSAGE_STATE_SKIPPED'   => 'skipped',
        'MESSAGE_STATE_STOPPED'   => 'stopped',
        'BOX_MISSING'             => 'missing',
        'BOX_FORMAT'              => 'format',
        'BOX_INACTIVE'            => 'inactive',
        'BOX_DUPLICATE'           => 'duplicate',
        'NOT_FOUND'               => 'notfound',
        'EVENT_QUEUED'            => 'queued',
        'EVENT_SENDING'           => 'sending',
        'EVENT_SUBMITTED'         => 'submitted',
        'EVENT_STAMPED'           => 'stamped',
        'EVENT_AV'                => 'av',
        'EVENT_DELIVERED'         => 'delivered',
        'EVENT_FICTION'           => 'fiction',
        'EVENT_LOGIN'             => 'login',
        'EVENT_UNDELIVERABLE'     => 'undeliverable',
        'EVENT_FAILED'            => 'failed',
        'EVENT_TIMEOUT'           => 'timeout',
        'EVENT_LOOKUP_MISSING'    => 'lookup-missing',
        'EVENT_LOOKUP_FOUND'      => 'lookup-found',
        'EVENT_SKIPPED'           => 'skipped',
        'EVENT_STOPPED'           => 'stopped',
        'EVENT_VERIFIED'          => 'verified',
    ];

    /** @return array<string, mixed> every scalar constant of Schema whose name starts with $prefix */
    private static function constants(string $prefix): array
    {
        $found = [];
        foreach ((new \ReflectionClass(Schema::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, $prefix) && !is_array($value)) {
                $found[$name] = $value;
            }
        }
        return $found;
    }

    /** @return array<string, string> the field constants of a slice - by name, so a mis-padded id is caught */
    private static function fieldConstants(string $prefix): array
    {
        return array_filter(self::constants($prefix), fn($name) => !str_starts_with($name, $prefix . 'STATE_'), ARRAY_FILTER_USE_KEY);
    }

    // a default names a field of its slice and a state the slice knows
    public function test_defaults_fieldsAndStatesOfTheirSlice(): void
    {
        self::assertSame([Schema::CAMPAIGN_STATE => Schema::CAMPAIGN_STATE_DRAFT], Schema::CAMPAIGN_DEFAULTS);
        self::assertSame([], array_diff(array_keys(Schema::CAMPAIGN_DEFAULTS), Schema::CAMPAIGN_FIELDS));
        self::assertSame([], array_diff(Schema::CAMPAIGN_DEFAULTS, Schema::CAMPAIGN_STATES));
        self::assertSame([], Schema::MESSAGE_DEFAULTS);
    }

    public function test_constants_haveTheirValues(): void
    {
        $all = array_filter((new \ReflectionClass(Schema::class))->getConstants(), fn($value) => !is_array($value));
        self::assertEqualsCanonicalizing(array_keys(self::VALUES), array_keys($all));
        foreach (self::VALUES as $name => $value) {
            self::assertSame($value, $all[$name], $name);
        }
    }

    public static function slices(): array
    {
        return [
            'campaign' => ['CAMPAIGN_', Schema::CAMPAIGN_FIELDS, Schema::CAMPAIGN_NUMBER_FIELDS],
            'message'  => ['MESSAGE_', Schema::MESSAGE_FIELDS, Schema::MESSAGE_NUMBER_FIELDS],
        ];
    }

    #[DataProvider('slices')]
    public function test_fields_are16CharsAndUniquePerSlice(string $prefix, array $fields, array $numberFields): void
    {
        foreach (self::fieldConstants($prefix) as $name => $field) {
            self::assertMatchesRegularExpression('/^[a-z_]+\.+[0-9]*$/', $field, $name);
            self::assertSame(16, strlen($field), $name);
        }
        self::assertSame(array_values(array_unique($fields)), $fields);
        // a constant left out of the list would not be required of the slice
        self::assertEqualsCanonicalizing(array_values(self::fieldConstants($prefix)), $fields);
    }

    #[DataProvider('slices')]
    public function test_fields_avoidItemTableIds(string $prefix, array $fields, array $numberFields): void
    {
        $columns = AA_Metabase::singleton()->getColumnNames('item');
        self::assertContains('post_date', $columns);
        // GetItemContent() reads a field from the item table when its base name is a column there
        $bases = array_map(fn($field) => strtok($field, '.'), $fields);
        self::assertSame([], array_values(array_intersect($bases, $columns)));
    }

    #[DataProvider('slices')]
    public function test_numberFields_areTheDateNumberAndSwitchFields(string $prefix, array $fields, array $numberFields): void
    {
        // a date or number in the text column neither sorts nor compares
        self::assertSame(array_values(preg_grep('/^(date|number|switch)\./', $fields)), $numberFields);
    }

    public static function valueLists(): array
    {
        return [
            'campaign states' => ['CAMPAIGN_STATE_', Schema::CAMPAIGN_STATES, '/^[a-z]+$/'],
            'message states'  => ['MESSAGE_STATE_', Schema::MESSAGE_STATES, '/^[a-z]+$/'],
            'box problems'    => ['BOX_', Schema::BOX_PROBLEMS, '/^[a-z]+$/'],
            'event kinds'     => ['EVENT_', Schema::EVENT_KINDS, '/^[a-z]+(-[a-z]+)*$/'],
        ];
    }

    #[DataProvider('valueLists')]
    public function test_valueLists_holdEveryConstantOnce(string $prefix, array $values, string $pattern): void
    {
        self::assertSame(array_values(self::constants($prefix)), $values);
        self::assertSame(array_values(array_unique($values)), $values);
        foreach ($values as $value) {
            self::assertMatchesRegularExpression($pattern, $value);
        }
    }
}

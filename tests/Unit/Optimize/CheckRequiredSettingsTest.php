<?php

declare(strict_types=1);

/**
 * CheckRequiredSettings: State Check warns about a slice whose required settings field (id `_...`) has no
 * value - one finding per slice naming its fields, linked to the settings page. One query finds the
 * required settings fields, one reads the values of just those.
 */

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 3) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Optimize {

    use AA\Optimize\CheckRequiredSettings;
    use AA\Optimize\Finding;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\TableDb;

    final class CheckRequiredSettingsTest extends TestCase
    {
        private const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        private const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        /** @var list<string> the SELECTs the check sent */
        private array $selects = [];

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../Legacy/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/constants.php';
            require_once __DIR__ . '/../../../include/util.php';
            require_once __DIR__ . '/../../../include/go_url.php';
            require_once __DIR__ . '/../Central/Package/fixtures/TableDb.php';
        }

        protected function setUp(): void
        {
            TableDb::reset();
            $GLOBALS['spareDBs'] = [new TableDb(), new TableDb(), new TableDb(), new TableDb()];
        }

        protected function tearDown(): void
        {
            $GLOBALS['spareDBs'] = [];
            TableDb::reset();
        }

        /** the required settings fields the join finds: [slice, slice name, field, field name]
         *  @param list<array{string, string, string, string}> $fields */
        private function required(array $fields): void
        {
            TableDb::$answer = function (string $sql) use ($fields): ?array {
                $this->selects[] = $sql;
                if (!str_contains($sql, 'JOIN')) {
                    return null;            // the values: from the content table
                }
                return array_map(static fn(array $f): array => ['slice_id' => $f[0], 'slice' => $f[1], 'id' => $f[2], 'name' => $f[3]], $fields);
            };
        }

        public function test_no_required_setting_is_ok_with_one_query(): void
        {
            $this->required([]);

            $findings = (new CheckRequiredSettings())->findings();

            $this->assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $findings));
            $this->assertCount(1, $this->selects);
            $this->assertMatchesRegularExpression("/required.*= 1/", $this->selects[0]);
            $this->assertStringContainsString("LEFT(f.id, 1) = '_'", $this->selects[0]);
            $this->assertStringContainsString('deleted', $this->selects[0]);
        }

        public function test_a_filled_setting_is_ok(): void
        {
            $this->required([[self::A, 'Articles', '_email..........', 'E-mail'], [self::A, 'Articles', '_count..........', 'Count']]);
            TableDb::$tables['content'] = [
                ['item_id' => self::A, 'field_id' => '_email..........', 'number' => 0, 'text' => 'x@example.org', 'flag' => 64],
                ['item_id' => self::A, 'field_id' => '_count..........', 'number' => 0, 'text' => null, 'flag' => 0],     // a number, 0 too
            ];

            $findings = (new CheckRequiredSettings())->findings();

            $this->assertSame(Finding::OK, $findings[0]->severity);
            $this->assertCount(2, $this->selects, 'the values of just those fields in one query');
            $this->assertStringContainsString('_email..........', $this->selects[1]);
        }

        public function test_an_empty_required_setting_is_a_warning_per_slice(): void
        {
            $this->required([
                [self::A, 'Articles', '_email..........', 'E-mail'],
                [self::A, 'Articles', '_title..........', 'Title'],
                [self::B, '<b>Events</b>', '_email..........', 'E-mail'],
            ]);
            TableDb::$tables['content'] = [
                ['item_id' => self::A, 'field_id' => '_title..........', 'number' => 0, 'text' => '', 'flag' => 64],    // stored empty
                ['item_id' => self::B, 'field_id' => '_email..........', 'number' => 0, 'text' => 'x@example.org', 'flag' => 64],
                ['item_id' => str_repeat('c', 32), 'field_id' => '_email..........', 'number' => 0, 'text' => 'another slice', 'flag' => 64],
            ];

            $findings = (new CheckRequiredSettings())->findings();

            $this->assertCount(1, $findings);
            $f = $findings[0];
            $this->assertSame([Finding::WARNING, self::A, 2], [$f->severity, $f->key, $f->count]);
            $this->assertStringContainsString('Articles', $f->title);
            $this->assertSame([
                [_m('Field') => '_email..........', _m('Name') => 'E-mail'],
                [_m('Field') => '_title..........', _m('Name') => 'Title'],
            ], $f->evidence);
            $this->assertSame(['/aa/admin/slicefieldsedit.php?edit=1&change_id=' . self::A], array_values($f->links));
        }

        public function test_the_evidence_is_escaped(): void
        {
            $this->required([[self::B, 'Events', '_x..............', '<b>X</b>']]);

            $f = (new CheckRequiredSettings())->findings()[0];

            $this->assertSame(safe('<b>X</b>'), $f->evidence[0][_m('Name')]);
        }
    }
}

<?php

declare(strict_types=1);

/**
 * CheckAlerts: a collection whose selection (alerts_filter) or its view was deleted sends nothing from it and
 * the sweep never moves its `last`, so State Check names the missing selection and links the Selections page
 * instead of reporting the collection as merely behind.
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

    use AA\Optimize\CheckAlerts;
    use AA\Optimize\Finding;
    use PHPUnit\Framework\TestCase;
    use Tests\Unit\Central\Package\Fixtures\TableDb;

    final class CheckAlertsTest extends TestCase
    {
        private const MODULE = '40687aa58553b2a28ce7dda4288a6cbf';

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

        /** @param list<array<string, mixed>> $sweeps   rows of the frequency query
         *  @param list<array<string, mixed>> $missing  rows of the missing-selection query */
        private function answer(array $sweeps, array $missing): void
        {
            TableDb::$answer = static fn(string $sql): array => str_contains($sql, 'LEFT JOIN alerts_filter') ? $missing : $sweeps;
        }

        private function missing(int $filterid, ?int $exists, ?int $vid, int $live): array
        {
            return ['collectionid' => 'BZNKY', 'filterid' => $filterid, 'filter_exists' => $exists, 'vid' => $vid,
                'name' => 'safaripark.cz - <newsletter>', 'module_id' => hex2bin(self::MODULE), 'live' => $live];
        }

        public function test_nothing_wrong_finds_nothing(): void
        {
            $this->answer([], []);

            $findings = (new CheckAlerts())->findings();

            $this->assertSame([Finding::OK], array_map(static fn(Finding $f): string => $f->severity, $findings));
        }

        public function test_a_collection_without_any_live_selection_is_an_error_linked_to_its_selections(): void
        {
            $this->answer([], [$this->missing(259, null, null, 0)]);

            $findings = (new CheckAlerts())->findings();

            $this->assertCount(1, $findings);
            $f = $findings[0];
            $this->assertSame([Finding::ERROR, 1, 'missing-' . bin2hex('BZNKY')], [$f->severity, $f->count, $f->key]);
            $this->assertStringContainsString('safaripark.cz - <newsletter>', $f->title);
            $this->assertSame([[_m('Selection') => 'f259', _m('Problem') => safe(_m('the selection was deleted'))]], $f->evidence);
            $this->assertSame('/aa/modules/alerts/acf.php?module_id=' . self::MODULE, $f->links[_m('Selections')]);
            $this->assertSame('/aa/modules/alerts/modedit.php?module_id=' . self::MODULE, $f->links[_m('Alerts settings')]);
        }

        public function test_a_collection_still_sending_from_another_selection_is_a_warning(): void
        {
            $this->answer([], [$this->missing(259, 259, 12, 1)]);

            $f = (new CheckAlerts())->findings()[0];

            $this->assertSame(Finding::WARNING, $f->severity);
            $this->assertSame(safe(_m('its view %1 was deleted', [12])), $f->evidence[0][_m('Problem')]);
        }

        public function test_a_numeric_collection_id_keeps_its_key(): void
        {
            $this->answer([], [['collectionid' => '48213'] + $this->missing(259, null, null, 0)]);

            $this->assertSame('missing-' . bin2hex('48213'), (new CheckAlerts())->findings()[0]->key);
        }

        public function test_the_queries_count_only_selections_with_their_view(): void
        {
            $selects = [];
            TableDb::$answer = static function (string $sql) use (&$selects): array {
                $selects[] = $sql;
                return [];
            };

            (new CheckAlerts())->findings();

            $this->assertCount(2, $selects);
            $this->assertStringContainsString('INNER JOIN view v ON v.id = f.vid', $selects[0]);
            $this->assertStringContainsString('v.id IS NULL', $selects[1]);
        }
    }
}

<?php

/**
 * Stand-ins for the tests of Manager forms and actions (ObjectFormOwnerTest, ManagerActionOwnerTest).
 *
 * AA_Object, AA_Form and the Manager live in include/statestore.php, include/form.class.php and
 * src/Manager.php, whose require chain reaches include/locsess.php and needs the constants
 * include/config.php normally provides - defined here, before store_fixtures.php loads it.
 */

declare(strict_types=1);

namespace {
    foreach ([
        'AA_INSTAL_URL'   => 'http://localhost/aa/',
        'AA_INSTAL_PATH'  => '/aa/',
        'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
        'AA_BASE_PATH'    => dirname(__DIR__, 4) . '/',
        'PERM_LIB'        => 'sql',
        'DB_HOST'         => 'localhost',
        'DB_NAME'         => 'aa_test',
        'DB_USER'         => 'aa',
        'DB_PASSWORD'     => '',
        'SCROLLER_LENGTH' => 3,
        'CACHE_TTL'       => 600,
    ] as $k => $v) {
        if (!defined($k)) {
            define($k, $v);
        }
    }
}

namespace Tests\Unit\Legacy\Fixtures {

    use AA\Manager;
    use AA\Manageraction\Item_Feed;
    use AA_Metabase;
    use AA_Object;
    use AA_Property;
    use AA_Searchbar;

    require_once __DIR__ . '/store_fixtures.php';

    /** an object edited on an AA form - like the settings of a module or a planned task */
    final class FormTarget extends AA_Object
    {
        public $code;

        public static function getClassProperties(): array
        {
            return ['code' => new AA_Property('code', 'Code', 'string', false)];
        }
    }

    /** a Manager page without a searchbar - the Manager itself leaves the property unset then */
    final class SearchlessManager extends Manager
    {
        protected ?AA_Searchbar $searchbar = null;
    }

    /** "Export to slice" noting what it would feed - FeedItemTo() needs the whole slice setup */
    final class RecordingItemFeed extends Item_Feed
    {
        /** @var list<array{0: string, 1: string, 2: string}> [item, destination slice, approved y|n] */
        public static array $fed = [];

        protected function feed(string $item_id, string $destination_id, string $approved): void
        {
            self::$fed[] = [$item_id, $destination_id, $approved];
        }
    }

    /**
     * Answers the SELECTs of RecordingDb from stored objects and table rows - what the owner checks
     * of the forms and actions look up. The conditions are read from the SQL by regular expressions,
     * just enough for the queries of AA_Object, AA_Metabase and DB_AA.
     */
    final class FakeStore
    {
        /**
         * @param array<string, array{0: string, 1: string, 2?: array<string, string>}>  $objects  object id => [aa_owner, aa_type, property => value]
         * @param array<string, list<array<string, mixed>>>  $tables   table => rows, ids unpacked
         */
        public static function install(array $objects, array $tables = []): void
        {
            RecordingDb::$answer = static function (string $sql) use ($objects, $tables): array {
                if (preg_match('/\bFROM\s+`?(\w+)`?/', $sql, $m) && isset($tables[$m[1]])) {
                    return self::rows($sql, $m[1], $tables[$m[1]]);
                }
                return self::objects($sql, $objects);
            };
        }

        /** the values a condition of the SQL names: "<column> = x" or "<column> IN (x, y)"; null - no condition */
        public static function idsOf(string $sql, string $column): ?array
        {
            $value = "'(?:[^'\\\\]|\\\\.)*'|0x[0-9a-fA-F]+|-?\\d+";
            if (!preg_match('/(?<![\w.`"])(?:[`"]?\w+[`"]?\.)?[`"]?' . preg_quote($column, '/') . "[`\"]?\\s*(?:=\\s*($value)|IN\\s*\\(((?:\\s*(?:$value)\\s*,?)*)\\))/", $sql, $m)) {
                return null;
            }
            preg_match_all("/$value/", ($m[1] ?? '') !== '' ? $m[1] : $m[2], $tokens);
            return array_map(static fn(string $t) => match (true) {
                str_starts_with($t, "'")    => stripslashes(substr($t, 1, -1)),
                str_starts_with($t, '0x')   => strtolower(substr($t, 2)),
                default                     => $t,
            }, $tokens[0]);
        }

        /** the rows of $table matching the conditions on their columns, packed as stored */
        private static function rows(string $sql, string $table, array $rows): array
        {
            $ret = [];
            foreach ($rows as $row) {
                foreach ($row as $col => $val) {
                    $wanted = self::idsOf($sql, $col);
                    if ($wanted === null) {
                        continue;
                    }
                    // a packed column is compared as hex - posted as 0x..., or quoted binary
                    $wanted = AA_Metabase::isPacked($table, $col) ? array_map(static fn($w) => is_long_id($w) ? $w : bin2hex($w), $wanted) : $wanted;
                    if (!in_array((string) $val, $wanted, true)) {
                        continue 2;
                    }
                }
                // SELECT LOWER(HEX(<column>)) AS <alias>, <column>, ... - the ids read unpacked, the
                // other columns as stored; other expressions are not answered
                if (preg_match('/^SELECT\s+(.*?LOWER\(HEX\(.*?)\s+FROM\b/is', $sql, $list)) {
                    $out = [];
                    foreach (preg_split('/\s*,\s*/', trim($list[1])) as $term) {
                        if (preg_match('/^LOWER\(HEX\(`?(\w+)`?\)\)(?:\s+AS\s+`?(\w+)`?)?$/i', $term, $h)) {
                            $out[$h[2] ?? $term] = strtolower((string) ($row[$h[1]] ?? ''));
                        } elseif (preg_match('/^`?(\w+)`?$/', $term, $c) && array_key_exists($c[1], $row)) {
                            $out[$c[1]] = AA_Metabase::isPacked($table, $c[1]) ? pack_id((string) $row[$c[1]]) : $row[$c[1]];
                        }
                    }
                    $ret[] = $out;
                    continue;
                }
                foreach (AA_Metabase::getPacked($table) as $col) {
                    if (isset($row[$col])) {
                        $row[$col] = pack_id($row[$col]);
                    }
                }
                // SELECT <column> - the zids are read from the first column
                if (preg_match('/^SELECT\s+(?:DISTINCT\s+)?`?(\w+)`?\s+FROM/i', $sql, $m) && array_key_exists($m[1], $row)) {
                    $row = [$m[1] => $row[$m[1]]];
                }
                $ret[] = $row;
            }
            return $ret;
        }

        /** the look-ups of objects: by id, owner, class and the value of a property (querySet), their properties (load) */
        private static function objects(string $sql, array $objects): array
        {
            if (str_contains($sql, 'objectid')) {                   // AA_Object::querySet()
                preg_match("/t0\\.value='([^']*)'/", $sql, $type);
                $ids    = self::idsOf($sql, 't0.object_id');
                $owners = self::idsOf($sql, 't1.value');
                // "LEFT JOIN object_integer as i0 ON (... AND i0.property = 'x')" with "i0"."value" = ... in WHERE
                preg_match_all("/ as (\\w+) ON \\(\\1\\.object_id=t0\\.object_id AND \\1\\.property = '(\\w+)'\\)/", $sql, $joins, PREG_SET_ORDER);
                $values = [];
                foreach ($joins as [, $alias, $property]) {
                    $values[$property] = self::idsOf(preg_replace("/([`\"]?)$alias\\1\\.\\1value\\1/", "$alias.value", $sql), "$alias.value");
                }
                $rows = [];
                foreach ($objects as $id => $object) {
                    [$owner, $class] = $object;
                    $matches         = array_filter($values, static fn(?array $wanted, string $property): bool => ($wanted === null) || in_array($object[2][$property] ?? null, $wanted, true), ARRAY_FILTER_USE_BOTH);
                    if (($class === stripslashes($type[1] ?? '')) && (($ids === null) || in_array($id, $ids, true)) && (($owners === null) || in_array($owner, $owners, true)) && (count($matches) === count($values))) {
                        $rows[] = ['objectid' => $id];
                    }
                }
                return $rows;
            }
            if (!str_contains($sql, 'object_text')) {
                return [];
            }
            $rows = [];
            foreach (self::idsOf($sql, 'object_id') ?? [] as $id) {
                if (!isset($objects[$id])) {
                    continue;
                }
                [$owner, $type] = $objects[$id];
                if (str_contains($sql, 'AS tbl')) {                   // AA_Object::load()
                    $rows[] = ['tbl' => 'object_text', 'property' => 'aa_owner', 'value' => $owner, 'priority' => 0];
                    $rows[] = ['tbl' => 'object_text', 'property' => 'aa_type', 'value' => $type, 'priority' => 0];
                } elseif (str_contains($sql, 'aa_subobjects')) {
                    continue;
                } elseif (str_starts_with($sql, 'SELECT DISTINCT value')) {    // the owners of the posted ids
                    $rows[] = ['value' => $owner];
                } elseif (str_starts_with($sql, 'SELECT DISTINCT object_id')) { // the stored ones of the posted ids
                    $rows[] = ['object_id' => $id];
                } else {                                            // the owner / type look-ups
                    $rows[] = ['property' => 'aa_owner', 'value' => $owner];
                    $rows[] = ['property' => 'aa_type', 'value' => $type];
                }
            }
            return $rows;
        }
    }
}

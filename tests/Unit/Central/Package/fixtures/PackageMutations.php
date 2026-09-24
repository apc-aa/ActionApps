<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Package\Fixtures;

use AA\Central\Package\Model\ConstantGroup;
use AA\Central\Package\Model\Module;
use AA\Central\Package\Model\Package;
use AA\Central\Package\Reader;
use Closure;

/** The example package of design balicek-v1.md § 4 (fixtures/aa-app-example.xml) read by Reader, and
 *  helpers that return a changed copy of the readonly model (as ValidatorTest). The AA boot the
 *  Validator needs: bootAa() in setUpBeforeClass(). */
trait PackageMutations
{
    private const SITE   = '1a2b3c4d5e6f708192a3b4c5d6e7f801';
    private const SLICE  = '0f1e2d3c4b5a69788796a5b4c3d2e1f0';
    private const ALERTS = '5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a5a';
    private const ALIAS  = '99aa88bb77cc66dd55ee44ff33aa22bb';
    private const SET    = 'cc11bb22cc33dd44ee55ff6677889900';
    private const COND   = 'dd11bb22cc33dd44ee55ff6677889900';
    private const SORT   = 'ee11bb22cc33dd44ee55ff6677889900';
    private const C1     = 'aa11bb22cc33dd44ee55ff6677889900';
    private const C2     = 'bb11bb22cc33dd44ee55ff6677889900';

    private const W = 'site-module[' . self::SITE . ']';
    private const S = 'slice-module[' . self::SLICE . ']';
    private const A = 'alerts-module[' . self::ALERTS . ']';
    private const G = 'constant-group[ct_rubriky]';

    private static ?Package $example = null;

    private static function bootAa(): void
    {
        foreach ([
            'AA_INSTAL_URL'   => 'http://localhost/aa/',
            'AA_INSTAL_PATH'  => '/aa/',
            'IMG_UPLOAD_PATH' => sys_get_temp_dir() . '/',
            'AA_BASE_PATH'    => dirname(__DIR__, 5) . '/',
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
        require_once __DIR__ . '/../../../Legacy/fixtures/store_fixtures.php';
        require_once __DIR__ . '/../../../../../include/util.php';
        require_once __DIR__ . '/../../../../../modules/site/sitetree.php';
        require_once __DIR__ . '/FakeImportTarget.php';
        class_exists(\AA_Module_Site::class);
    }

    private static function example(): Package
    {
        return self::$example ??= (new Reader())->read((string) file_get_contents(__DIR__ . '/aa-app-example.xml'))->package;
    }

    /** a readonly model object with some constructor arguments changed */
    private static function with(object $o, array $changes): object
    {
        return new ($o::class)(...array_merge(get_object_vars($o), $changes));
    }

    /** @param Closure(Module): Module $fn */
    private static function module(Package $p, string $id, Closure $fn): Package
    {
        return self::with($p, ['modules' => array_map(static fn(Module $m): Module => ($m->id === $id) ? $fn($m) : $m, $p->modules)]);
    }

    /** @param Closure(list<array<string, mixed>>): list<array<string, mixed>> $fn */
    private static function rows(Package $p, string $id, string $element, Closure $fn): Package
    {
        return self::module($p, $id, static fn(Module $m): Module => self::with($m, ['rows' => array_merge($m->rows, [$element => $fn($m->rows[$element] ?? [])])]));
    }

    /** the $i-th row of an element with some values changed */
    private static function cell(Package $p, string $id, string $element, int $i, array $values): Package
    {
        return self::rows($p, $id, $element, static function (array $rows) use ($i, $values): array {
            $rows[$i] = $values + $rows[$i];
            return $rows;
        });
    }

    /** the module's language - so its charset */
    private static function lang(Package $p, string $id, string $lang_file): Package
    {
        return self::cell($p, $id, 'module', 0, ['lang_file' => $lang_file]);
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $fn on the canonical spot tree */
    private static function tree(Package $p, Closure $fn): Package
    {
        return self::module($p, self::SITE, static fn(Module $m): Module => self::with($m, ['spotTree' => $fn($m->spotTree)]));
    }

    private static function setting(Package $p, string $id, string $name, mixed $value): Package
    {
        return self::module($p, $id, static fn(Module $m): Module => self::with($m, ['settings' => [$name => $value] + ($m->settings ?? [])]));
    }

    /** @param Closure(array<string, mixed>): array<string, mixed> $fn */
    private static function object(Package $p, string $id, int $k, Closure $fn): Package
    {
        return self::module($p, $id, static function (Module $m) use ($k, $fn): Module {
            $objects     = $m->objects;
            $objects[$k] = $fn($objects[$k]);
            return self::with($m, ['objects' => $objects]);
        });
    }

    /** @param list<array<string, mixed>> $items */
    private static function items(Package $p, array $items): Package
    {
        return self::with(self::module($p, self::SLICE, static fn(Module $m): Module => self::with($m, ['items' => $items])), ['withItems' => true]);
    }

    private static function item(string $id, string $value = 'x'): array
    {
        return ['id' => $id, 'content' => ['headline........' => [0 => ['value' => $value, 'flag' => 0]]]];
    }

    /** the example's group, its constants changed @param Closure(ConstantGroup): array<string, mixed> $fn constructor args */
    private static function group(Package $p, Closure $fn): Package
    {
        return self::with($p, ['constantGroups' => array_map(static fn(ConstantGroup $g): ConstantGroup => self::with($g, $fn($g)), $p->constantGroups)]);
    }

    /** a second Alerts module of the example sharing its filter 3 (the rows as they are) and its own collection */
    private static function alerts2(Package $p, string $id, string $collection): Package
    {
        $a = array_values(array_filter($p->modules, static fn(Module $m): bool => $m->id === self::ALERTS))[0];
        $rows = $a->rows;
        unset($rows['email']);
        $rows['alerts_collection'][0]['id']                  = $collection;
        $rows['alerts_collection'][0]['emailid_welcome']     = '';
        $rows['alerts_collection_filter'][0]['collectionid'] = $collection;
        $rows['alerts_collection_howoften'][0]['collectionid'] = $collection;
        return self::with($p, ['modules' => [...$p->modules, new Module(Module::ALERTS, $id, $rows)]]);
    }
}

<?php

declare(strict_types=1);

/**
 * sitetree::toArray() / fromArray() (modules/site/sitetree.php) - the site tree as plain data for
 * the aa-app package (design balicek-v1.md § 6.3, C4.3 part 4): the canonical form, its round trip
 * with `===` and through serialize(), on trees built by the real editing API and on the legacy
 * serialized form of the stock Site Template.
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

namespace Tests\Unit\Legacy {

    use InvalidArgumentException;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use sitetree;
    use spot;

    final class SitetreeArrayTest extends TestCase
    {
        /** the structure RedefineSiteTemplates writes (src/Optimize/RedefineSiteTemplates.php:49) - the old
         *  public property names, every id a string */
        private const TEMPLATE = 'O:8:"sitetree":2:{s:4:"tree";a:1:{i:1;O:4:"spot":8:{s:2:"id";s:1:"1";s:1:"n";s:5:"start";s:1:"c";N;s:1:"v";N;s:1:"p";s:1:"1";s:2:"po";a:1:{i:0;s:1:"1";}s:2:"ch";N;s:1:"f";i:0;}}s:8:"start_id";s:1:"1";}';

        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/fixtures/store_fixtures.php';
            require_once __DIR__ . '/../../../include/util.php';           // ParamImplode() of addCondition()
            require_once __DIR__ . '/../../../modules/site/sitetree.php';
        }

        private static function template(): sitetree
        {
            $tree = unserialize(self::TEMPLATE, ['allowed_classes' => ['sitetree', 'spot']]);
            self::assertInstanceOf(sitetree::class, $tree);
            return $tree;
        }

        /** A site as the editor (modules/site/index.php) builds it on the template: a header, a content
         *  spot branching on {x} into two options (each with a sequence of its own), a disabled footer.
         *  Spot ids: 1 start, 2 header, 3 content, 4 footer, 5 article, 6 category, 7 article detail. */
        private static function site(): sitetree
        {
            $tree = self::template();
            self::assertTrue($tree->addInSequence(1, 'header'));
            self::assertTrue($tree->addInSequence(1, 'content'));
            self::assertTrue($tree->addInSequence(1, 'footer'));
            // a new spot holds `false` in v, c, ch; the editor's first add turns it into an array (with
            // PHP's false-to-array deprecation) - null autovivifies alike, without the noise
            $tree->set('variables', 3, null);
            $tree->set('choices', 3, null);
            self::assertTrue($tree->addVariable(3, 'x'));
            self::assertTrue($tree->addVariable(3, 'xseo1'));
            self::assertTrue($tree->addChoice(3, 'article'));
            self::assertTrue($tree->addChoice(3, 'category'));
            $tree->set('conditions', 5, null);
            $tree->set('conditions', 6, null);
            self::assertTrue($tree->addCondition(5, 'x', 'clanek', '='));
            self::assertTrue($tree->addCondition(6, '{xseo1}', 'rubrika', 'REGEXP'));
            self::assertTrue($tree->addInSequence(5, 'article detail'));
            $tree->setFlag(4, 1);
            return $tree;
        }

        private static function spot(array $tree, string $id): array
        {
            foreach ($tree['spots'] as $spot) {
                if ($spot['id'] === $id) {
                    return $spot;
                }
            }
            self::fail("no spot $id");
        }

        public function test_the_template_in_canonical_form(): void
        {
            $this->assertSame([
                'start_id' => '1',
                'spots'    => [['id' => '1', 'n' => 'start', 'p' => '1', 'f' => 0, 'v' => null, 'c' => null, 'po' => ['1'], 'ch' => null]],
            ], self::template()->toArray());
        }

        public function test_ids_are_strings_whatever_the_api_stored(): void
        {
            $tree = self::site()->toArray();

            $this->assertSame(['1', '2', '3', '4', '5', '6', '7'], array_column($tree['spots'], 'id'));   // by number
            $this->assertSame(['id' => '1', 'n' => 'start', 'p' => '1', 'f' => 0, 'v' => null, 'c' => null, 'po' => ['1', '2', '3', '4'], 'ch' => null], self::spot($tree, '1'));
            // a spot in a sequence: sitetree::addInSequence() passes its $flag (false) as positions
            $this->assertSame(['id' => '2', 'n' => 'header', 'p' => '1', 'f' => 0, 'v' => null, 'c' => null, 'po' => null, 'ch' => null], self::spot($tree, '2'));
            $this->assertSame(['x' => 'x', 'xseo1' => 'xseo1'], self::spot($tree, '3')['v']);
            $this->assertSame(['5', '6'], self::spot($tree, '3')['ch']);
            $this->assertSame(1, self::spot($tree, '4')['f']);
        }

        public function test_an_option_spot_has_its_own_id_in_positions(): void
        {
            $tree = self::site()->toArray();

            $this->assertSame(['id' => '5', 'n' => 'article', 'p' => '3', 'f' => 0, 'v' => null, 'c' => ['x' => '=:=:clanek'], 'po' => ['5', '7'], 'ch' => null], self::spot($tree, '5'));
            $this->assertSame(['{xseo1}' => '=:REGEXP:rubrika'], self::spot($tree, '6')['c']);
            $this->assertSame(['6'], self::spot($tree, '6')['po']);
            $this->assertSame('5', self::spot($tree, '7')['p']);
        }

        /** @return array<string, array{\Closure(): sitetree}> */
        public static function trees(): array
        {
            return [
                'template'   => [static fn(): sitetree => self::template()],
                'site'       => [static fn(): sitetree => self::site()],
                'serialized' => [static fn(): sitetree => unserialize(serialize(self::site()), ['allowed_classes' => ['sitetree', 'spot']])],
            ];
        }

        #[DataProvider('trees')]
        public function test_the_round_trip_is_identical(\Closure $make): void
        {
            $array = $make()->toArray();

            $this->assertSame($array, sitetree::fromArray($array)->toArray());
            // what the import stores in site.structure reads back the same
            $stored = unserialize(serialize(sitetree::fromArray($array)), ['allowed_classes' => ['sitetree', 'spot']]);
            $this->assertInstanceOf(sitetree::class, $stored);
            $this->assertSame($array, $stored->toArray());
        }

        public function test_the_rebuilt_tree_walks_like_the_original(): void
        {
            $original = self::site();
            $rebuilt  = sitetree::fromArray($original->toArray());

            $walk     = static function (sitetree $tree): array {
                $ids   = [];
                $state = [];
                $tree->walkTree($state, 1, ['spot' => function ($id) use (&$ids): void {
                    $ids[] = (string) $id;
                }, 'before_choice' => null, 'after_choice' => null], 'all');
                return $ids;
            };

            $this->assertSame(['1', '2', '3', '5', '7', '6', '4'], $walk($original));
            $this->assertSame($walk($original), $walk($rebuilt));
            $this->assertSame(1, $rebuilt->getCurrentId());
            $this->assertSame(['x' => 'x', 'xseo1' => 'xseo1'], $rebuilt->get('variables', 3));
            $this->assertTrue($rebuilt->isChoice(5) !== false);
            // the editor goes on: the next id follows the imported ones
            $this->assertSame(8, $rebuilt->new_id());
        }

        /** the ids as new_id() makes them - isOption() compares them strictly with the int getCurrentId() */
        public function test_fromArray_builds_the_tree_with_int_ids(): void
        {
            $rebuilt = sitetree::fromArray(self::site()->toArray());
            $inner   = (fn(): array => [$this->tree, $this->start_id])->call($rebuilt);

            $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_keys($inner[0]));
            $this->assertSame(1, $inner[1]);
            $this->assertSame([5, 3, [5, 7], null], [$rebuilt->get('id', 5), $rebuilt->get('parent', 5), $rebuilt->get('positions', 5), $rebuilt->get('choices', 5)]);
            $this->assertSame([1, 2, 3, 4], $rebuilt->get('positions', 1));
            $this->assertSame([5, 6], $rebuilt->get('choices', 3));
        }

        public function test_isOption_works_on_a_rebuilt_tree(): void
        {
            $rebuilt = sitetree::fromArray(self::site()->toArray());
            $rebuilt->setCurrentId(5);

            $this->assertSame(['x' => 'x', 'xseo1' => 'xseo1'], $rebuilt->isOption($rebuilt->getCurrentId()));
            $this->assertFalse($rebuilt->isOption(2));      // in a sequence, no option
            // string ids (the template, a reader) are rebuilt as ints - the option is found; in the legacy
            // all-string tree itself isOption() misses it (strict in_array against the int current id)
            $legacy = sitetree::fromArray(['start_id' => '1', 'spots' => [
                ['id' => '1', 'n' => 'start', 'p' => '1', 'v' => ['x' => 'x'], 'po' => ['1'], 'ch' => ['2']],
                ['id' => '2', 'n' => 'option', 'p' => '1', 'po' => ['2']],
            ]]);
            $this->assertSame(['x' => 'x'], $legacy->isOption(2));
        }

        /** the template has no current_id at all - it starts at the start spot */
        public function test_unserialize_keeps_the_current_spot(): void
        {
            $tree = self::site();
            $tree->setCurrentId(5);

            $stored = unserialize(serialize($tree), ['allowed_classes' => ['sitetree', 'spot']]);

            $this->assertSame(5, $stored->getCurrentId());
            $this->assertSame(1, self::template()->getCurrentId());
        }

        /** A structure as production sites have it (19 of 54 measured): NULL entries in the tree - get(),
         *  set(), removeSpot(), move() take `& $this->tree[$id]` of ids that no longer exist - ids as "10"
         *  and 5, gaps in the keys of po, choices keyed from 2, the private / protected property names */
        public function test_a_production_structure_with_null_entries(): void
        {
            $spot = static fn(mixed ...$args): string => serialize(new spot(...$args));
            $tree = 'a:7:{'
                . 'i:1;' . $spot('1', 'start', null, null, '1', [0 => '1', 2 => '10', 3 => 5], null, 0)
                . 'i:4;N;'
                . 'i:5;' . $spot(5, 'footer', false, false, '1', false, false, 0)
                . 'i:7;N;'
                . 'i:10;' . $spot('10', 'content', false, ['x' => 'x'], '1', false, [3 => '12', 2 => 11], 0)
                . 'i:11;' . $spot(11, 'article', ['x' => '=:=:clanek'], false, 10, [11], false, 0)
                . 'i:12;' . $spot('12', 'category', ['x' => 'rubrika'], false, '10', [0 => '12'], false, 2)
                . '}';
            $structure = 'O:8:"sitetree":3:{s:14:"' . "\0sitetree\0tree" . '";' . $tree
                . 's:11:"' . "\0*\0start_id" . '";s:1:"1";s:20:"' . "\0sitetree\0current_id" . '";i:5;}';
            $read      = unserialize($structure, ['allowed_classes' => ['sitetree', 'spot']]);
            $this->assertInstanceOf(sitetree::class, $read);

            $array = $read->toArray();
            $this->assertSame(['1', '5', '10', '11', '12'], array_column($array['spots'], 'id'));
            $this->assertSame(['1', '10', '5'], self::spot($array, '1')['po']);
            $this->assertSame(['11', '12'], self::spot($array, '10')['ch']);
            $this->assertSame($array, sitetree::fromArray($array)->toArray());
        }

        public function test_legacy_values_get_their_canonical_types(): void
        {
            $tree = self::template();
            // positions as int, an empty position (walkTree() skips it), choices in key order, a string flag
            $tree->set('positions', 1, [0 => 1, 1 => '', 3 => '2', 2 => null]);
            $tree->set('flag', 1, '4');
            $tree->set('variables', 1, []);
            $tree->set('conditions', 1, false);

            $this->assertSame(['id' => '1', 'n' => 'start', 'p' => '1', 'f' => 4, 'v' => [], 'c' => null, 'po' => ['1', '2'], 'ch' => null], $tree->toArray()['spots'][0]);
        }

        public function test_choices_keep_the_key_order_walkTree_uses(): void
        {
            $tree = self::site();
            $tree->set('choices', 3, [1 => 6, 0 => 5]);     // walkTree() ksort()s the choices

            $this->assertSame(['5', '6'], self::spot($tree->toArray(), '3')['ch']);
        }

        public function test_empty_variables_and_conditions_differ_from_null(): void
        {
            $array                  = self::template()->toArray();
            $array['spots'][0]['v'] = [];
            $array['spots'][0]['c'] = [];

            $this->assertSame($array, sitetree::fromArray($array)->toArray());
        }

        /** walkTree() treats empty and missing positions/choices alike (falsy) - both are null */
        public function test_empty_positions_and_choices_are_null(): void
        {
            $tree = self::site();
            $tree->set('positions', 2, ['', null]);     // only empty positions
            $tree->set('choices', 2, []);

            $this->assertSame([null, null], [self::spot($tree->toArray(), '2')['po'], self::spot($tree->toArray(), '2')['ch']]);
            $array                   = self::template()->toArray();
            $array['spots'][0]['ch'] = [];
            $this->assertNull(sitetree::fromArray($array)->toArray()['spots'][0]['ch']);
        }

        /** new sitetree($spotrow) passes site_spot.flag as the choices (sitetree::__construct()) */
        public function test_a_falsy_scalar_is_no_array(): void
        {
            $tree = self::template();
            foreach ([0, '0', '', false, null] as $falsy) {
                $tree->set('choices', 1, $falsy);
                $tree->set('conditions', 1, $falsy);
                $this->assertSame([null, null], [$tree->toArray()['spots'][0]['ch'], $tree->toArray()['spots'][0]['c']], var_export($falsy, true));
            }
            $tree->set('choices', 1, 1);
            $this->expectException(\UnexpectedValueException::class);
            $tree->toArray();
        }

        public function test_the_limits_of_a_tree(): void
        {
            // a chain of options as deep as allowed: spot n+1 is the only option of spot n
            $deep  = static function (int $depth): array {
                $spots = [['id' => 1, 'n' => 'start', 'p' => 1, 'v' => ['x' => 'x'], 'po' => [1], 'ch' => [2]]];
                for ($i = 2; $i <= $depth + 1; $i++) {
                    $spots[] = ['id' => $i, 'n' => "o$i", 'p' => $i - 1, 'v' => ['x' => 'x'], 'po' => [$i], 'ch' => ($i <= $depth) ? [$i + 1] : null];
                }
                return ['start_id' => 1, 'spots' => $spots];
            };
            $wide  = static fn(int $count): array => ['start_id' => 1, 'spots' => [
                ['id' => 1, 'n' => 'start', 'p' => 1, 'po' => range(1, $count)],
                ...array_map(static fn(int $i): array => ['id' => $i, 'n' => "s$i", 'p' => 1], range(2, $count)),
            ]];
            $named = static fn(int $bytes): array => ['start_id' => 1, 'spots' => [['id' => 1, 'n' => str_repeat('a', $bytes), 'p' => 1, 'po' => [1]]]];

            sitetree::fromArray($deep(sitetree::MAX_DEPTH));
            sitetree::fromArray($wide(sitetree::MAX_SPOTS));
            sitetree::fromArray($named(sitetree::MAX_TEXT));
            foreach (['depth' => $deep(sitetree::MAX_DEPTH + 1), 'spots' => $wide(sitetree::MAX_SPOTS + 1), 'text' => $named(sitetree::MAX_TEXT + 1)] as $what => $array) {
                try {
                    sitetree::fromArray($array);
                    $this->fail("over the $what limit");
                } catch (InvalidArgumentException) {
                    $this->addToAssertionCount(1);
                }
            }
        }

        public function test_fromArray_restores_the_canonical_types_of_read_data(): void
        {
            // as a reader gives it: numbers from the XML
            $read = ['start_id' => 1, 'spots' => [
                ['id' => 1, 'n' => 'start', 'p' => 1, 'f' => '0', 'v' => ['x' => 'x'], 'c' => null, 'po' => [1, 2], 'ch' => [3]],
                ['id' => 2, 'n' => 'a', 'p' => 1, 'po' => false],
                ['id' => 3, 'n' => 'b', 'p' => 1, 'f' => 2, 'c' => ['x' => 'y'], 'po' => [3]],
            ]];

            $this->assertSame(['start_id' => '1', 'spots' => [
                ['id' => '1', 'n' => 'start', 'p' => '1', 'f' => 0, 'v' => ['x' => 'x'], 'c' => null, 'po' => ['1', '2'], 'ch' => ['3']],
                ['id' => '2', 'n' => 'a', 'p' => '1', 'f' => 0, 'v' => null, 'c' => null, 'po' => null, 'ch' => null],
                ['id' => '3', 'n' => 'b', 'p' => '1', 'f' => 2, 'v' => null, 'c' => ['x' => 'y'], 'po' => ['3'], 'ch' => null],
            ]], sitetree::fromArray($read)->toArray());
            $this->assertInstanceOf(spot::class, (fn() => $this->tree[2])->call(sitetree::fromArray($read)));
        }

        /** @return array<string, array{array<string, mixed>}> */
        public static function broken(): array
        {
            $spot = ['id' => '1', 'n' => 'start', 'p' => '1', 'po' => ['1']];
            return [
                'no spots'          => [['start_id' => '1', 'spots' => []]],
                'no start spot'     => [['start_id' => '2', 'spots' => [$spot]]],
                'id not a number'   => [['start_id' => '1', 'spots' => [$spot, ['id' => 'x', 'n' => 'a', 'p' => '1']]]],
                'id zero'           => [['start_id' => '1', 'spots' => [$spot, ['id' => '0', 'n' => 'a', 'p' => '1']]]],
                'duplicate id'      => [['start_id' => '1', 'spots' => [$spot, $spot]]],
                'unknown parent'    => [['start_id' => '1', 'spots' => [$spot, ['id' => '2', 'n' => 'a', 'p' => '9']]]],
                'position not an id' => [['start_id' => '1', 'spots' => [['po' => ['1', 'x']] + $spot]]],
                'variable key'      => [['start_id' => '1', 'spots' => [['v' => ['x' => 'y']] + $spot]]],
                'nested condition'  => [['start_id' => '1', 'spots' => [['c' => ['x' => ['y']]] + $spot]]],
                'scalar positions'  => [['start_id' => '1', 'spots' => [['po' => '1'] + $spot]]],
                // the structure - fromArray() is the gate of the import (untrusted data)
                'start id not 1'    => [['start_id' => '2', 'spots' => [$spot, ['id' => '2', 'n' => 'a', 'p' => '2', 'po' => ['2']]]]],
                'start without po'  => [['start_id' => '1', 'spots' => [['po' => null] + $spot]]],
                'start po not itself first' => [['start_id' => '1', 'spots' => [['po' => ['2', '1']] + $spot, ['id' => '2', 'n' => 'a', 'p' => '1']]]],
                'start not its own parent' => [['start_id' => '1', 'spots' => [['p' => '2', 'po' => ['1', '2']] + $spot, ['id' => '2', 'n' => 'a', 'p' => '1']]]],
                'dangling position' => [['start_id' => '1', 'spots' => [['po' => ['1', '2']] + $spot]]],
                'dangling choice'   => [['start_id' => '1', 'spots' => [['ch' => ['2']] + $spot]]],
                'wrong parent'      => [['start_id' => '1', 'spots' => [['po' => ['1', '2'], 'ch' => ['3']] + $spot, ['id' => '2', 'n' => 'a', 'p' => '3'], ['id' => '3', 'n' => 'b', 'p' => '1', 'po' => ['3']]]]],
                'diamond'           => [['start_id' => '1', 'spots' => [['po' => ['1', '2'], 'ch' => ['2']] + $spot, ['id' => '2', 'n' => 'a', 'p' => '1', 'po' => ['2']]]]],
                'twice in a list'   => [['start_id' => '1', 'spots' => [['po' => ['1', '2', '2']] + $spot, ['id' => '2', 'n' => 'a', 'p' => '1']]]],
                'itself as child'   => [['start_id' => '1', 'spots' => [['po' => ['1', '1']] + $spot]]],
                'cycle'             => [['start_id' => '1', 'spots' => [$spot, ['id' => '2', 'n' => 'a', 'p' => '3', 'po' => ['2'], 'ch' => ['3']], ['id' => '3', 'n' => 'b', 'p' => '2', 'po' => ['3'], 'ch' => ['2']]]]],
                'unreachable'       => [['start_id' => '1', 'spots' => [$spot, ['id' => '2', 'n' => 'a', 'p' => '1']]]],
                'option without sequence' => [['start_id' => '1', 'spots' => [['v' => ['x' => 'x'], 'ch' => ['2']] + $spot, ['id' => '2', 'n' => 'a', 'p' => '1']]]],
            ];
        }

        #[DataProvider('broken')]
        public function test_fromArray_rejects_a_broken_tree(array $array): void
        {
            $this->expectException(InvalidArgumentException::class);
            sitetree::fromArray($array);
        }
    }
}

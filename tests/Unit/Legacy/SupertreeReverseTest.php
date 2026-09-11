<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy;

use AA_Supertree_Reverse;
use PHPUnit\Framework\TestCase;

/**
 * Characterization test for AA_Supertree_Reverse::load() (include/tree.class.php).
 *
 * The loader used to run one QueryZids() per visited node; it now asks for a whole
 * level at once. Every test compares the batched result against referenceLoad()
 * below, which is a literal transcription of the per-node loop it replaced - so a
 * behaviour drift fails here rather than on a live menu.
 *
 * The DB is replaced by an in-memory tree through the two seams the loader calls
 * (_queryChildren / _loadParents), which is why no database or session is needed.
 */
final class SupertreeReverseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // class declarations only - no side effects on load
        require_once __DIR__ . '/../../../include/tree.class.php';
    }

    /** child id => ids its relation field points at, plus a global sort order. */
    private function tree(array $relations, array $order): FakeSupertreeReverse
    {
        return new FakeSupertreeReverse($relations, $order);
    }

    /** The pre-batching algorithm, transcribed from the old load() body. */
    private function referenceLoad(array $relations, array $order, string $root): array
    {
        $i     = [];
        $queue = [$root];
        while (count($queue)) {
            $item_id = array_pop($queue);
            if (isset($i[$item_id])) {
                continue;
            }
            // one query per node: every child pointing at $item_id, in sort order
            $next = [];
            foreach ($order as $cid) {
                if (in_array($item_id, $relations[$cid] ?? [], true)) {
                    $next[] = $cid;
                }
            }
            $i[$item_id] = $next;
            $queue       = array_merge($queue, $next);
        }
        return $i;
    }

    private function assertSameAsReference(array $relations, array $order, string $root): void
    {
        $tree = $this->tree($relations, $order);
        $tree->load($root);

        $reference = $this->referenceLoad($relations, $order, $root);
        $actual    = $tree->exposeI();

        ksort($reference);
        ksort($actual);
        $this->assertSame($reference, $actual, 'batched load() must build the same map as the per-node loop');
    }

    public function test_single_node_without_children_still_gets_an_entry(): void
    {
        // _subIds() foreaches $this->_i[$id] unguarded - a leaf without an entry would be a TypeError
        $this->assertSameAsReference([], ['A'], 'A');
        $tree = $this->tree([], ['A']);
        $tree->load('A');
        $this->assertSame(['A' => []], $tree->exposeI());
        $this->assertSame(['A'], $tree->getIds('A'));
    }

    public function test_branching_tree_matches_reference(): void
    {
        $relations = ['B' => ['A'], 'C' => ['A'], 'F' => ['A'], 'D' => ['C'], 'E' => ['C'], 'G' => ['D']];
        $order     = ['B', 'C', 'D', 'E', 'F', 'G'];
        $this->assertSameAsReference($relations, $order, 'A');
    }

    /** Sibling order comes from the query sort; bucketing a globally sorted level must preserve it. */
    public function test_sibling_order_follows_the_global_sort_order(): void
    {
        $relations = ['B' => ['A'], 'C' => ['A'], 'D' => ['A']];
        $order     = ['D', 'B', 'C'];               // deliberately not alphabetical
        $this->assertSameAsReference($relations, $order, 'A');

        $tree = $this->tree($relations, $order);
        $tree->load('A');
        $this->assertSame(['D', 'B', 'C'], $tree->exposeI()['A']);
    }

    /** A child listing two parents belongs under both, exactly as the per-node loop had it. */
    public function test_child_with_two_parents_appears_under_both(): void
    {
        $relations = ['B' => ['A'], 'C' => ['A'], 'E' => ['A', 'C']];
        $order     = ['B', 'C', 'E'];
        $this->assertSameAsReference($relations, $order, 'A');

        $tree = $this->tree($relations, $order);
        $tree->load('A');
        $i = $tree->exposeI();
        $this->assertContains('E', $i['A']);
        $this->assertContains('E', $i['C']);
    }

    /** The same parent listed twice in a multivalue relation field must not duplicate the child. */
    public function test_duplicate_relation_value_does_not_duplicate_the_child(): void
    {
        $tree = $this->tree(['B' => ['A', 'A']], ['B']);
        $tree->load('A');
        $this->assertSame(['B'], $tree->exposeI()['A']);
    }

    /** Cycles must terminate - the old loop guarded with isset($this->_i[$item_id]). */
    public function test_cycle_terminates_and_matches_reference(): void
    {
        $relations = ['H' => ['I'], 'I' => ['H']];
        $order     = ['H', 'I'];
        $this->assertSameAsReference($relations, $order, 'H');
    }

    public function test_deep_chain_matches_reference_and_keeps_tree_order(): void
    {
        $relations = ['B' => ['A'], 'C' => ['B'], 'D' => ['C'], 'E' => ['D']];
        $order     = ['B', 'C', 'D', 'E'];
        $this->assertSameAsReference($relations, $order, 'A');

        $tree = $this->tree($relations, $order);
        $this->assertSame(['A', 'B', 'C', 'D', 'E'], $tree->getIds('A'));
        $this->assertSame('A(B(C(D(E))))', $tree->getTreeString('A'));
    }

    /** load() is called again for every expanded node by getMenu() - it must stay a no-op. */
    public function test_second_load_of_a_known_node_issues_no_further_queries(): void
    {
        $tree = $this->tree(['B' => ['A'], 'C' => ['B']], ['B', 'C']);
        $tree->load('A');
        $queries = $tree->queryCount();
        $tree->load('A');
        $tree->load('B');
        $this->assertSame($queries, $tree->queryCount());
    }

    /** The point of the change: a level costs one query, not one per node. */
    public function test_a_wide_level_costs_one_query_not_one_per_node(): void
    {
        // 5 siblings + 1 grandchild = 7 nodes over 3 levels; the old loop paid 7 queries
        $relations = ['B' => ['A'], 'C' => ['A'], 'D' => ['A'], 'E' => ['A'], 'F' => ['A'], 'G' => ['B']];
        $order     = ['B', 'C', 'D', 'E', 'F', 'G'];
        $tree      = $this->tree($relations, $order);
        $tree->load('A');
        $this->assertSame(3, $tree->queryCount(), 'root level, child level, grandchild level');
        $this->assertSame(4, $tree->roundTrips(), 'plus one parent lookup for the only multi-parent level');
    }

    /**
     * A narrow chain has one node per level, so batching cannot win there - it must
     * not lose either. Bucketing needs a second round trip per level, which is why
     * a single-node level skips it.
     */
    public function test_narrow_chain_costs_no_more_round_trips_than_before(): void
    {
        $relations = ['B' => ['A'], 'C' => ['B'], 'D' => ['C'], 'E' => ['D']];
        $order     = ['B', 'C', 'D', 'E'];
        $tree      = $this->tree($relations, $order);
        $tree->load('A');
        // the old loop visited 5 nodes = 5 queries; we must not exceed that
        $this->assertSame(5, $tree->roundTrips());
    }

    /** A very wide level is split so no single OR expression grows without bound. */
    public function test_wide_level_is_split_into_chunks(): void
    {
        $relations = [];
        $order     = [];
        for ($n = 0; $n < 450; $n++) {                  // > 2 * LEVEL_CHUNK
            $relations["c$n"] = ['A'];
            $order[]          = "c$n";
        }
        $tree = $this->tree($relations, $order);
        $tree->load('A');

        $this->assertCount(450, $tree->exposeI()['A']);
        $this->assertSame($order, $tree->exposeI()['A'], 'chunking must not disturb the sort order');
        // root level (1) + three chunks of the 450-node level
        $this->assertSame(4, $tree->queryCount());
    }

    /** getMenu() calls load() once per expanded entry - the slice must be derived once per tree,
     *  not once per entry, also when the derived slice turns out not to have the relation field. */
    public function test_slice_is_derived_once_per_tree_even_when_it_lacks_the_relation_field(): void
    {
        $tree = new FakeSupertreeReverseNoField(['B' => ['A']], ['B'], []);
        $tree->load('A');
        $tree->load('B');
        $tree->load('C');

        $this->assertSame(['A' => [], 'B' => [], 'C' => []], $tree->exposeI());
        $this->assertSame(1, $tree->sliceLookups());
        $this->assertSame(0, $tree->roundTrips());
    }

    public function test_slice_is_derived_once_per_tree(): void
    {
        $tree = new FakeSupertreeReverse(['B' => ['A'], 'C' => ['B']], ['B', 'C'], []);
        $tree->load('A');
        $tree->load('C');

        $this->assertSame(['A' => ['B'], 'B' => ['C'], 'C' => []], $tree->exposeI());
        $this->assertSame(1, $tree->sliceLookups());
    }

    /** A condition on a field the slice does not define is silently dropped by QueryZids -
     *  the query would then return every item of the slice as a child of every parent
     *  (N^2 work, seen as a 512 MB fatal on aadev). Such slices must not be queried at all. */
    public function test_slice_without_the_relation_field_yields_an_empty_tree_without_a_query(): void
    {
        $tree = new FakeSupertreeReverseNoField(['B' => ['A']], ['B']);
        $tree->load('A');

        $this->assertSame(['A' => []], $tree->exposeI());
        $this->assertSame(0, $tree->roundTrips());
        $this->assertSame(['A'], $tree->getIds('A'));
    }
}

/** In-memory stand-in for the two database seams of AA_Supertree_Reverse. */
class FakeSupertreeReverse extends AA_Supertree_Reverse
{
    private array $relations;
    private array $order;
    private int $queries = 0;
    private int $lookups = 0;
    private int $slice_lookups = 0;

    public function __construct(array $relations, array $order, array $slices = ['slice'])
    {
        parent::__construct('relation........', null, $slices);
        $this->relations = $relations;
        $this->order     = $order;
    }

    /** the item -> slice lookup, counted instead of hitting the DB */
    protected function _firstSlice(string $id): string|false
    {
        $this->slice_lookups++;
        return 'slice';
    }

    /** no DB behind the fake - every configured slice is taken to have the relation field */
    protected function _slicesWithRelationField(array $slices): array
    {
        return $slices;
    }

    protected function _queryChildren(array $parent_ids): array
    {
        $this->queries++;
        $ret = [];
        foreach ($this->order as $cid) {                       // global sort order, as ORDER BY gives it
            foreach ($this->relations[$cid] ?? [] as $parent) {
                if (in_array($parent, $parent_ids, true)) {
                    $ret[] = $cid;
                    break;
                }
            }
        }
        return $ret;
    }

    protected function _loadParents(array $child_ids): array
    {
        $this->lookups++;
        $ret = [];
        foreach ($child_ids as $cid) {
            $ret[$cid] = $this->relations[$cid] ?? [];
        }
        return $ret;
    }

    public function exposeI(): array
    {
        return $this->_i;
    }

    public function queryCount(): int
    {
        return $this->queries;
    }

    /** how many times the item -> slice lookup was needed */
    public function sliceLookups(): int
    {
        return $this->slice_lookups;
    }

    /** every database round trip the walk costs, both seams together */
    public function roundTrips(): int
    {
        return $this->queries + $this->lookups;
    }
}

/** The same fake, but no configured slice defines the relation field. */
final class FakeSupertreeReverseNoField extends FakeSupertreeReverse
{
    protected function _slicesWithRelationField(array $slices): array
    {
        return [];
    }
}

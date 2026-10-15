<?php

declare(strict_types=1);

namespace Tests\Unit\Legacy {

    use AA_Supertree;
    use PHPUnit\Framework\TestCase;

    /** Supertree with a preset child map - the walk alone, no loader. */
    final class CyclicSupertree extends AA_Supertree
    {
        public function preset(array $children): void
        {
            $this->_i = $children;
        }
    }

    /**
     * {tree}, {treestring} and {menu} walk the relation field of items, which editors fill by hand:
     * "Subpage of" pointing down its own branch makes a cycle. The walk must not follow a relation
     * back to an item above - with branching the old depth limit of 100 meant 2^100 nodes.
     */
    final class SupertreeCycleTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once __DIR__ . '/../../../include/tree.class.php';
        }

        private static function tree(array $children): CyclicSupertree
        {
            $tree = new CyclicSupertree('relation........');
            $tree->preset($children);
            return $tree;
        }

        public function test_acyclic_tree_lists_a_shared_child_under_each_parent(): void
        {
            $tree = self::tree(['r' => ['a', 'b'], 'a' => ['c'], 'b' => ['c'], 'c' => []]);

            $this->assertSame(['r', 'a', 'c', 'b', 'c'], $tree->_subIds('r'));
            $this->assertSame('r(a(c)-b(c))', $tree->_subTreeString('r', 1));
        }

        public function test_relation_back_up_the_branch_is_not_followed(): void
        {
            $tree = self::tree(['r' => ['a'], 'a' => ['r', 'b'], 'b' => ['a']]);

            $this->assertSame(['r', 'a', 'b'], $tree->_subIds('r'));
            $this->assertSame('r(a(b))', $tree->_subTreeString('r', 1));
        }

        public function test_branching_cycle_ends_at_once(): void
        {
            // every node points to both others - without the guard 2^100 paths
            $tree = self::tree(['r' => ['a', 'b'], 'a' => ['r', 'b'], 'b' => ['r', 'a']]);

            $this->assertSame(['r', 'a', 'b', 'b', 'a'], $tree->_subIds('r'));
            $this->assertSame('r(a(b)-b(a))', $tree->_subTreeString('r', 1));
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Central\Fixtures;

use AA\Central\ChunkTarget;

/** An in-memory AA the chunk mode imports into: its modules (id => type), constant groups (exact name =>
 *  owner, '' = none - group_id is varbinary), items (id => slice) and alerts collections (id => module). */
final class FakeChunkTarget implements ChunkTarget
{
    /** @var list<string> every group asked about */
    public array $askedGroups = [];

    /**
     * @param array<string, string> $modules
     * @param array<string, string> $groups
     * @param array<string, string> $items
     * @param array<string, string> $collections
     */
    public function __construct(public array $modules = [], public array $groups = [], public array $items = [], public array $collections = []) {}

    public function moduleType(string $module_id): ?string
    {
        return $this->modules[$module_id] ?? null;
    }

    public function groupOwner(string $group): ?string
    {
        $this->askedGroups[] = $group;
        return $this->groups[$group] ?? null;
    }

    public function itemSlices(array $item_ids): array
    {
        return array_intersect_key($this->items, array_flip($item_ids));
    }

    public function collectionModules(array $ids): array
    {
        return array_intersect_key($this->collections, array_flip($ids));
    }

    public function user(): string
    {
        return 'importer';
    }

    public function now(): int
    {
        return 1700000000;
    }
}

/** A metabase which records the inserts instead of writing, with the keys of the real schema. An insert
 *  fails for a row named 'bad' and for an AUTO_INCREMENT value taken ($taken, or inserted before); one
 *  without its AUTO_INCREMENT value gets the next from 1000. */
final class RecordingMetabase extends \AA_Metabase
{
    /** @var list<array{string, array<string, mixed>}> */
    public array $inserted = [];
    private int $next = 1000;

    /** @param array<string, list<int|string>> $taken table => AUTO_INCREMENT values taken */
    public function __construct(public array $taken = []) {}

    public function getKeys($tablename): array
    {
        return \AA_Metabase::singleton()->getKeys($tablename);
    }

    public function doInsert(string $tablename, array $data, ?string $nohalt = null): bool|int
    {
        $this->inserted[] = [$tablename, $data];
        if (($data['name'] ?? '') === 'bad') {
            return false;
        }
        foreach (\AA_Metabase::singleton()->getColumns($tablename) as $name => $column) {
            if ($column->c[5] === 'auto_increment') {
                if (!isset($data[$name])) {
                    return $this->next++;
                }
                if (in_array($data[$name], $this->taken[$tablename] ?? [], false)) {
                    return false;
                }
                $this->taken[$tablename][] = $data[$name];
            }
        }
        return PHP_INT_MAX;
    }

    /** @return list<array<string, mixed>> the rows inserted into $table */
    public function rows(string $table): array
    {
        return array_values(array_map(static fn(array $i): array => $i[1], array_filter($this->inserted, static fn(array $i): bool => $i[0] === $table)));
    }
}

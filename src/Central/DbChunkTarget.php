<?php

declare(strict_types=1);

namespace AA\Central;

use AA\Central\Package\DbImportTarget;
use AA\IO\DB\DB_AA;

/** ChunkTarget over this AA's database - parameterised DB_AA reads (ids packed by makeWhere()). */
final class DbChunkTarget implements ChunkTarget
{
    /** ids per statement (IN list) */
    private const CHUNK = 500;

    public function moduleType(string $module_id): ?string
    {
        $type = DB_AA::select1('type', 'SELECT `type` FROM `module`', [['id', $module_id, 'l']]);
        return (($type === false) || ($type === null)) ? null : (string) $type;
    }

    public function groupOwner(string $group): ?string
    {
        $owner = DB_AA::select1('owner', 'SELECT LOWER(HEX(`slice_id`)) AS owner FROM `constant_slice`', [['group_id', $group]]);
        if ($owner !== false) {
            return (string) $owner;                 // NULL slice_id: '' - a group of nobody
        }
        return (new DbImportTarget())->groupTaken($group) ? '' : null;
    }

    public function itemSlices(array $item_ids): array
    {
        $slices = [];
        foreach (array_chunk($item_ids, self::CHUNK) as $ids) {
            $slices += DB_AA::select(['item' => 'slice'], 'SELECT LOWER(HEX(`id`)) AS item, LOWER(HEX(`slice_id`)) AS slice FROM `item`', [['id', $ids, 'l']]);
        }
        return $slices;
    }

    public function collectionModules(array $ids): array
    {
        $modules = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $modules += DB_AA::select(['id' => 'module'], 'SELECT `id`, LOWER(HEX(`module_id`)) AS module FROM `alerts_collection`', [['id', $chunk]]);
        }
        return $modules;
    }

    public function user(): string
    {
        return (string) \AuthGetUid();
    }

    public function now(): int
    {
        return time();
    }
}

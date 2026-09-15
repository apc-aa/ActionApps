<?php

declare(strict_types=1);

/**
 * PHP version 8.2+
 *
 * LICENSE: This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

namespace AA\Later;

use AA;
use AA\IO\DB\DB_AA;

/** Weekly OPTIMIZE TABLE over the AA tables, as a queue task.
 *
 *  Replaces misc/optimize.php, which the AA cron fetched over HTTP with
 *  `key=<first five characters of DB_PASSWORD>` - a secret written into the
 *  `cron` table, shown on admin/aa_cron.php and copied into the web server's
 *  access log on every run. A queue task needs no key and no HTTP round trip.
 *
 *  One OPTIMIZE cannot be interrupted and the queue run has ~59 seconds for
 *  all its tasks, so a run does at most BUDGET seconds of work and re-queues
 *  itself with the tables still to do; smallest tables first, so a long
 *  `pagecache` (976 MB on NSZM) is alone in its run rather than in front of
 *  the rest. Planned weekly by AA\Later\ScheduleAaTasks.
 */
class OptimizeTables implements LaterInterface
{
    /** seconds of OPTIMIZE work per run; whatever is left goes to a follow-up task */
    public const BUDGET = 30;

    /** the follow-up task runs this many seconds later, so the queue serves everything else in between */
    public const CONTINUE_AFTER = 600;

    /** selector of the follow-up task - distinct from the weekly 'OptimizeTables' plan on purpose:
     *  laterOnce() on the selector of the task being executed would only move that (claimed)
     *  row, and the row is deleted once the task returns */
    public const CONTINUE_SELECTOR = 'OptimizeTables:rest';

    /** @param string[] $pending tables still to do; empty = start from the whole schema */
    public function __construct(private array $pending = []) {}

    /** @see \AA\Later\LaterInterface */
    public function toexecutelater($params = []): string
    {
        $tables = $this->pending ?: self::tablesBySize();
        $start  = microtime(true);
        $done   = [];
        while ($tables && (microtime(true) - $start) < self::BUDGET) {
            $table  = array_shift($tables);
            $done[] = $table;
            DB_AA::sql("OPTIMIZE TABLE `$table`");
        }
        $ret = sprintf('optimized %d tables in %.1f s: %s', count($done), microtime(true) - $start, implode(',', $done));
        if ($tables) {
            (new Toexecute())->later(new self($tables), [], self::CONTINUE_SELECTOR, 10, time() + self::CONTINUE_AFTER);
            $ret .= sprintf('; %d left for %s', count($tables), self::CONTINUE_SELECTOR);
        }
        return $ret;
    }

    /** AA tables which exist in this database, smallest first
     * @return string[]
     */
    public static function tablesBySize(): array
    {
        $sizes = DB_AA::select(
            ['TABLE_NAME' => 'bytes'],
            'SELECT `TABLE_NAME`, `DATA_LENGTH` + `INDEX_LENGTH` AS bytes FROM `information_schema`.`TABLES`',
            [['TABLE_SCHEMA', constant('DB_NAME')]],
        );
        return self::order(AA::Metabase()->getTableNames(), array_map('intval', $sizes));
    }

    /** the schema tables that exist (bck_* copies and the like are skipped as
     *  misc/optimize.php skipped them), ordered by size ascending
     *
     * @param string[]           $schema tables of include/metabase_schema.php
     * @param array<string,int>  $sizes  table => bytes for the tables this database has
     * @return string[]
     */
    public static function order(array $schema, array $sizes): array
    {
        $existing = array_values(array_intersect($schema, array_keys($sizes)));
        usort($existing, static fn(string $a, string $b): int => ($sizes[$a] <=> $sizes[$b]) ?: strcmp($a, $b));
        return $existing;
    }
}

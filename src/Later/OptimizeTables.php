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

/** OPTIMIZE TABLE over the AA tables in the Sunday night, as a queue task.
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
 *  the rest. Planned by AA\Later\ScheduleAaTasks.
 *
 *  A rebuild gives space back only where rows were deleted: in 2026-10 it was
 *  ~5 MB per big table except `pagecache` (0.9 GB on NSZM), yet one rebuild of
 *  NSZM `change` took 277 s. So the weekly run takes only the tables with space
 *  to give back and the run on the first Sunday of a month all of them, which
 *  also defragments their indexes.
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

    /** the weekly rebuild takes a table with at least this much free space, and at least MIN_FREE_SHARE of its size */
    private const MIN_FREE_BYTES = 64 * 1024 * 1024;

    private const MIN_FREE_SHARE = 0.1;

    /** a run starts up to NIGHT_SPREAD seconds after NIGHT_START_HOUR, at a moment of its own for
     *  each database, so the databases of one DB server mostly do not rebuild at once. A follow-up
     *  outside the night waits for the next one. A run that comes late still runs: where the queue
     *  runs only on visits (CronFallback), a quiet site would never get to it at night - and by
     *  day it has no traffic to disturb. */
    private const NIGHT_START_HOUR = 1;

    private const NIGHT_END_HOUR = 5;

    private const NIGHT_SPREAD = 3 * 3600;

    /** fixed when the run is planned, so a run that comes late or is started by hand does not
     *  skip or add the month's full rebuild; a default, as rows queued by older code lack it */
    private bool $every_table = false;

    /** @param string[] $pending tables still to do; empty = a new run, which picks the tables itself */
    public function __construct(private array $pending = [], bool $every_table = false)
    {
        $this->every_table = $every_table;
    }

    /** @see \AA\Later\LaterInterface */
    public function toexecutelater($params = []): string
    {
        // follow-ups of a slow run (one a day on a quiet CronFallback site) may still be queued
        if (!$this->pending && Toexecute::scheduledTask(self::CONTINUE_SELECTOR)['id']) {
            return 'the previous run is not finished yet: ' . self::CONTINUE_SELECTOR;
        }
        $tables = $this->pending ?: self::tablesToOptimize($this->every_table);
        $start  = microtime(true);
        $done   = [];
        while ($tables && (microtime(true) - $start) < self::BUDGET) {
            $table  = array_shift($tables);
            $done[] = $table;
            DB_AA::sql("OPTIMIZE TABLE `$table`");
        }
        $ret = sprintf('optimized %d tables in %.1f s: %s', count($done), microtime(true) - $start, implode(',', $done));
        if ($tables) {
            (new Toexecute())->later(new self($tables), [], self::CONTINUE_SELECTOR, 10, self::continueAt(time(), constant('DB_NAME')));
            $ret .= sprintf('; %d left for %s', count($tables), self::CONTINUE_SELECTOR);
        }
        return $ret;
    }

    /** start of the weekly run: the coming Sunday night */
    public static function weeklyAt(int $now, string $db_name): int
    {
        return self::nightStart('next sunday', $now, $db_name);
    }

    /** start of a follow-up: CONTINUE_AFTER later while it is night, otherwise the next night */
    public static function continueAt(int $now, string $db_name): int
    {
        $at   = $now + self::CONTINUE_AFTER;
        $hour = (int) date('G', $at);
        if ($hour >= self::NIGHT_START_HOUR && $hour < self::NIGHT_END_HOUR) {
            return $at;
        }
        return self::nightStart(((int) date('G', $now) < self::NIGHT_START_HOUR) ? 'today' : 'tomorrow', $now, $db_name);
    }

    private static function nightStart(string $day, int $now, string $db_name): int
    {
        return (int) strtotime(sprintf('%s %02d:00', $day, self::NIGHT_START_HOUR), $now) + crc32($db_name) % self::NIGHT_SPREAD;
    }

    /** a run planned for the first Sunday of a month rebuilds every table */
    public static function everyTable(int $planned): bool
    {
        return (int) date('j', $planned) <= 7;
    }

    /** AA tables of this database to rebuild now, smallest first
     * @return string[]
     */
    private static function tablesToOptimize(bool $all): array
    {
        $stats = DB_AA::select(
            ['TABLE_NAME' => []],
            'SELECT `TABLE_NAME`, `DATA_LENGTH` + `INDEX_LENGTH` AS bytes, `DATA_FREE` AS free FROM `information_schema`.`TABLES`',
            [['TABLE_SCHEMA', constant('DB_NAME')]],
        );
        return self::order(AA::Metabase()->getTableNames(), self::worthRebuilding($stats, $all));
    }

    /** sizes of the tables to rebuild: all, or those with space to give back
     *
     * @param array<string,array{bytes:int|string,free:int|string}> $stats table => used and free bytes
     * @return array<string,int> table => bytes
     */
    public static function worthRebuilding(array $stats, bool $all): array
    {
        $sizes = [];
        foreach ($stats as $table => $row) {
            $bytes = (int) $row['bytes'];
            $free  = (int) $row['free'];
            if ($all || ($free >= self::MIN_FREE_BYTES && $free >= $bytes * self::MIN_FREE_SHARE)) {
                $sizes[$table] = $bytes;
            }
        }
        return $sizes;
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

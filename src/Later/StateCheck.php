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
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program (LICENSE); if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @version   $Id$
 * @author    Honza Malik <honza.malik@ecn.cz>
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999, 2000 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 */

namespace AA\Later;

use AA\Optimize\AbstractOptimize;
use AA\Optimize\CheckRunner;
use AA\Optimize\Finding;
use AA_Log;
use AA_Logentry_Sc;
use AA_Logentry_Scs;

/** Daily State Check snapshot: runs the basic and extended checks and keeps
 *  their outcome in the log as STATE_CHK rows - one per check that has
 *  something to say (written only when it changed), plus a summary row that
 *  marks the run. admin/aa_statecheck.php reads the summary for "last full
 *  run"; the history is what a trend and Central will be built on.
 */
class StateCheck implements LaterInterface
{
    /** log.type is 10 bytes */
    public const EVENT = 'STATE_CHK';
    public const SUMMARY = 'summary';

    /** @param array $params  unused
     *  @return string  what happened, for the TOEXECUTE log row */
    public function toexecutelater($params = []): string
    {
        \MoreTimeLimit();
        [$findings] = CheckRunner::splitResolved(CheckRunner::run([AbstractOptimize::CHECK_BASIC, AbstractOptimize::CHECK_EXTENDED]), now());

        $changed = 0;
        foreach (self::aggregate($findings) as $check => $row) {
            $changed += (int) AA_Log::writeIfChanged(self::EVENT, $check, AA_Logentry_Sc::make($row['severity'], $row['count'], $row['title']));
        }
        $summary = Finding::summary($findings);
        $checks  = count(self::aggregate($findings, true));
        AA_Log::write(self::EVENT, self::SUMMARY, new AA_Logentry_Scs([
            'errors'   => (string) $summary[Finding::ERROR],
            'warnings' => (string) $summary[Finding::WARNING],
            'infos'    => (string) $summary[Finding::INFO],
            'checks'   => (string) $checks,
        ]));
        return sprintf('%d errors, %d warnings, %d infos in %d checks; %d checks changed since the last snapshot', $summary[Finding::ERROR], $summary[Finding::WARNING], $summary[Finding::INFO], $checks, $changed);
    }

    /** One row per check: its worst severity, the title of that worst finding
     *  and the counts added up. Passed checks are left out unless asked for.
     * @param Finding[] $findings  as CheckRunner::run() returns them, worst first
     * @return array<string, array{severity:string, count:int, title:string}>  keyed by Finding::$check
     */
    public static function aggregate(array $findings, bool $with_ok = false): array
    {
        $rows = [];
        foreach (CheckRunner::sort($findings) as $finding) {
            if (!$with_ok && !$finding->isProblem()) {
                continue;
            }
            $rows[$finding->check] ??= ['severity' => $finding->severity, 'count' => 0, 'title' => $finding->title];
            $rows[$finding->check]['count'] += $finding->count;
        }
        return $rows;
    }
}

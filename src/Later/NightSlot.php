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

/**
 * The moment of a maintenance task in the night. Each key (database, task) gets a moment of its own
 * within the window, the same night after night, so the databases of one server mostly do not run
 * at once. A daily task planned "a day after its last run" drifts instead: every wait in the queue
 * moves it later (the image cache purge went from 03:20 in September to 14:50 in November).
 */
final class NightSlot
{
    public const START_HOUR = 1;

    /** seconds after START_HOUR over which the moments spread */
    public const SPREAD = 3 * 3600;

    /** the moment of $key in the night of $day ("today", "tomorrow", "next sunday"), counted from $now */
    public static function on(string $day, int $now, string $key): int
    {
        return (int) strtotime(sprintf('%s %02d:00', $day, self::START_HOUR), $now) + crc32($key) % self::SPREAD;
    }

    /** the first moment of $key after $now: tonight's while it is still ahead, otherwise tomorrow's */
    public static function next(int $now, string $key): int
    {
        $at = self::on('today', $now, $key);
        return ($at > $now) ? $at : self::on('tomorrow', $now, $key);
    }
}

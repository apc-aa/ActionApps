<?php

/**
 *
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
 *
 */

namespace AA\Later;

use AA\Cache\ImgCache;

/**
 * Daily purge of the MissCache forge-on-miss image cache (IMG_UPLOAD_PATH/mC/).
 *
 * The cache grows with every distinct {img:...:cache} URL. Because a deleted
 * artifact is re-forged on the next request, eviction is safe and self-healing:
 * we delete files unused for $maxAge, and enforce a hard size cap $maxBytes
 * (oldest-first). Scheduled once a day from {@see ScheduleAaTasks}.
 */
class MissCachePurge implements LaterInterface
{
    /**
     * @param int  $maxAge   delete artifacts unused for this many seconds (recency = max atime/mtime); default 30 days
     * @param ?int $maxBytes hard cache size cap in bytes (oldest-first eviction); null = no cap; default 2 GiB
     */
    public function __construct(
        protected int $maxAge = 86400 * 30,
        protected ?int $maxBytes = 2 * 1024 ** 3,
    ) {}

    /** @see \AA\Later\LaterInterface */
    public function toexecutelater($params = []): string
    {
        // outside an initialised AA context the constants are undefined and the cache root bogus
        if (!ImgCache::configured()) {
            return 'MissCache purge skipped: AA config constants are not defined';
        }

        $s = ImgCache::get()->purge(['maxAge' => $this->maxAge, 'maxBytes' => $this->maxBytes]);

        return sprintf(
            'MissCache purge: scanned %d, deleted %d (age) + %d (size cap) + %d (stray tmp), freed %s, pruned %d dirs, %s remains',
            $s['scanned'],
            $s['deleted_age'],
            $s['deleted_size'],
            $s['deleted_tmp'],
            self::humanBytes($s['bytes_freed']),
            $s['dirs_removed'],
            self::humanBytes($s['total_after']),
        );
    }

    private static function humanBytes(int $n): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        $v     = (float) $n;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }
        return ($i === 0 ? (string) $n : sprintf('%.1f', $v)) . ' ' . $units[$i];
    }
}

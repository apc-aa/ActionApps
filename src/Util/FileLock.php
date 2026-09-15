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
 * @author    Jakub Adamek <jakubadamek@ecn.cz>, March 2003
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999-2026 Association for Progressive Communications
 * @link      https://www.apc.org/ APC
 *
 */

namespace AA\Util;

/**
 * Mutually-exclusive lock backed by PHP flock() on a persistent file descriptor.
 *
 * Acquire with Lock() (blocking) or Trylock() (non-blocking), release with
 * Unlock(). The kernel releases the lock automatically on process death, so a
 * crashed holder cannot orphan the mutex. The lock file is left on disk after
 * Unlock() — intentional (some callers use the file's mtime as a "last run"
 * marker) and harmless (one file per lock, no growth).
 *
 * @package AA\Util
 */
class FileLock
{
    /** full path to the lock file */
    private string $filename;

    /** @var resource|null Open file descriptor holding the flock, or null when unlocked. */
    private $fd = null;

    public function __construct(string $filename)
    {
        $this->filename = $filename;
    }

    /** Acquire an exclusive lock, blocking in the kernel until released by any
     *  other holder. Returns false if the lock file cannot be opened (missing
     *  directory / permissions).
     */
    public function Lock(): bool
    {
        return $this->acquire(LOCK_EX);
    }

    /** Try to acquire the lock without blocking. Returns false if another
     *  process holds the lock, or if the lock file cannot be opened.
     */
    public function Trylock(): bool
    {
        return $this->acquire(LOCK_EX | LOCK_NB);
    }

    /** Release the lock. Returns true on a lock we hold; false return
     *  indicates a kernel-level flock failure (effectively impossible on a
     *  fd we own).
     */
    public function Unlock(): bool
    {
        if ($this->fd === null) {
            return true;
        }
        $ok = flock($this->fd, LOCK_UN);
        fclose($this->fd);
        $this->fd = null;
        return $ok;
    }

    private function acquire(int $operation): bool
    {
        if ($this->fd !== null) {
            return true;
        }
        $fd = @fopen($this->filename, 'c');
        if (!$fd) {
            return false;
        }
        if (!flock($fd, $operation)) {
            fclose($fd);
            return false;
        }
        $this->fd = $fd;
        return true;
    }
}

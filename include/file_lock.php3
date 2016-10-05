<?php

/** File lock synchronizes file reading and writing. It protects one or several
 *   files against parallel access. It is a mutex (mutually
 *   exclusive lock), i.e. only one thread accesses the files. Each thread must
 *   call Lock() or Trylock() before accessing the files protected by this lock-file
 *   and call Unlock() after it has closed the files.
 *
 *   The difference between Lock() and Trylock() is that Lock() waits until
 *   the lock-file is released while Trylock() returns immediately with the
 *   return value @c false if the lock-file could not be created.
 *
 *
 * PHP versions 4 and 5
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
 * @package   UserInput
 * @version   $Id$
 * @author    Jakub Adamek <jakubadamek@ecn.cz>, March 2003
 * @license   http://opensource.org/licenses/gpl-license.php GNU Public License
 * @copyright Copyright (C) 1999-2003 Association for Progressive Communications
 * @link      http://www.apc.org/ APC
 *
 *  File-lock synchronization mechanism in class FileLock.
 *
*/

class FileLock {

    /// full path to the lock file
    var $filename;
    /** boolean, true if the lock is locked, i.e. the file exists and was
    *   created by this object */
    var $locked;
    /** boolean, true if the file could not be created or deleted in the last operation
    *   because of missing permissions. Every operation resets this flag. */
    var $last_error;

    /** FileLock function
     * Constructor: takes full path to the lock file.
     * @param $filename
     */
    function __construct($filename) {
        $this->filename = $filename;
        $this->locked = false;
        $this->last_error = false;
    }

    /** Lock function
     *   Waits until the file is released, than creates the file.
     *   Returns @c true if OK, @c false if missing permissions to create the file.
     *   If you call Lock() twice without calling Unlock(), the second
     *   time it immediately return @c true.
     */
    function Lock() {
        $last_error = false;
        if ($this->locked)
            return true;
        do {
            clearstatcache();
            $locked_outside = file_exists ($this->filename);
            if ($locked_outside) {
                sleep (1);
            } else {
                $fd = @fopen ($this->filename, "w");
                // Proove that nobody else created the file in the meantime
                // between file_exists() and fopen().
                // Note: this is not 100% sure, because somebody could have
                // already the file deleted again, but this is very unprobable.
                clearstatcache();
                $locked_outside = ! $fd && file_exists ($this->filename);
            }
        } while ($locked_outside);
        $this->locked = $fd;
        if (! $fd) {
            $last_error = true;
            return false;
        }
        fclose ($fd);
        return true;
    }

    /** Unlock function
     *   Releases the file. Should always return @c true, otherwise it is
     *   an internal error.
     */
    function Unlock() {
        $last_error = false;
        if ($this->locked)
            $last_error = ! unlink ($this->filename);
        $this->locked = $last_error;
        return ! $last_error;
    }

    /** Trylock function
     *   Tries to create the file. Returns @c false if the lock-file could not
     *   be created. Check $this->last_error if you are not sure the permissions
     *   were OK.
     */
    function Trylock() {
        $last_error = false;
        if ($this->locked) {
            return true;
        }
        clearstatcache();
        if (file_exists ($this->filename)) {
            return false;
        }
        $fd = @fopen ($this->filename, "w");
        $this->locked = $fd;
        if ($fd) {
            return true;
        }
        // The file could not be created but not because somebody else created it:
        // this is an error (see also Lock())
        clearstatcache();
        if (! file_exists ($this->filename)) {
            $last_error = true;
        }
        return false;
    }
}
?>
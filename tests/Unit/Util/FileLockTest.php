<?php

declare(strict_types=1);

namespace Tests\Unit\Util;

use AA\Util\FileLock;
use PHPUnit\Framework\TestCase;

final class FileLockTest extends TestCase
{
    private string $lockFile;

    protected function setUp(): void
    {
        $this->lockFile = tempnam(sys_get_temp_dir(), 'aa_filelock_test_');
        unlink($this->lockFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    public function test_lock_creates_file_when_missing(): void
    {
        $this->assertFileDoesNotExist($this->lockFile);

        $lock = new FileLock($this->lockFile);
        $this->assertTrue($lock->Lock());

        $this->assertFileExists($this->lockFile);
        $lock->Unlock();
    }

    public function test_unlock_does_not_delete_file(): void
    {
        $lock = new FileLock($this->lockFile);
        $lock->Lock();
        $lock->Unlock();

        $this->assertFileExists(
            $this->lockFile,
            'lock file must persist after Unlock so callers can use mtime as a "last run" marker',
        );
    }

    public function test_trylock_succeeds_when_unlocked(): void
    {
        $lock = new FileLock($this->lockFile);
        $this->assertTrue($lock->Trylock());
        $lock->Unlock();
    }

    public function test_trylock_returns_true_idempotently_for_same_instance(): void
    {
        $lock = new FileLock($this->lockFile);
        $this->assertTrue($lock->Trylock());
        $this->assertTrue($lock->Trylock(), 'second Trylock on already-held instance returns true');
        $lock->Unlock();
    }

    public function test_lock_returns_true_idempotently_for_same_instance(): void
    {
        $lock = new FileLock($this->lockFile);
        $this->assertTrue($lock->Lock());
        $this->assertTrue($lock->Lock(), 'second Lock on already-held instance returns true');
        $lock->Unlock();
    }

    public function test_unlock_on_never_locked_instance_returns_true(): void
    {
        $lock = new FileLock($this->lockFile);
        $this->assertTrue($lock->Unlock());
    }

    public function test_full_cycle_lock_unlock_lock_works(): void
    {
        $lock = new FileLock($this->lockFile);
        $this->assertTrue($lock->Lock());
        $this->assertTrue($lock->Unlock());
        $this->assertTrue($lock->Lock(), 'should re-acquire after Unlock');
        $this->assertTrue($lock->Unlock());
    }

    public function test_trylock_fails_when_other_handle_holds_lock(): void
    {
        // Hold the lock via a raw fd (simulates a different process/instance
        // holding it). flock() rejects acquisitions across distinct fds even
        // within the same PHP process — verifies our Trylock honors that.
        $holder = fopen($this->lockFile, 'c');
        $this->assertNotFalse($holder);
        $this->assertTrue(flock($holder, LOCK_EX | LOCK_NB), 'precondition: holder acquires raw flock');

        $lock = new FileLock($this->lockFile);
        $this->assertFalse($lock->Trylock(), 'Trylock must fail while another fd holds LOCK_EX');

        flock($holder, LOCK_UN);
        fclose($holder);
    }

    public function test_trylock_succeeds_after_other_handle_releases(): void
    {
        $holder = fopen($this->lockFile, 'c');
        flock($holder, LOCK_EX | LOCK_NB);

        $lock = new FileLock($this->lockFile);
        $this->assertFalse($lock->Trylock());

        flock($holder, LOCK_UN);
        fclose($holder);

        $this->assertTrue($lock->Trylock(), 'Trylock should succeed once the other fd releases');
        $lock->Unlock();
    }

    public function test_lock_fails_when_path_unwritable(): void
    {
        $lock = new FileLock('/proc/this/path/does/not/exist/lock.file');
        $this->assertFalse($lock->Lock());
        $this->assertFalse($lock->Trylock());
    }

    public function test_cross_process_contention_via_fork(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl_fork not available');
        }

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'fork failed');

        if ($pid === 0) {
            // Child: acquire lock, signal parent (touch sentinel), hold for 500ms.
            $child_lock = new FileLock($this->lockFile);
            $child_lock->Lock();
            touch($this->lockFile . '.held');
            usleep(500_000);
            $child_lock->Unlock();
            exit(0);
        }

        // Parent: wait for child to actually hold the lock, then verify Trylock fails.
        $waited = 0;
        while (!file_exists($this->lockFile . '.held') && $waited < 1_000_000) {
            usleep(10_000);
            $waited += 10_000;
        }
        $this->assertFileExists($this->lockFile . '.held', 'child did not signal lock acquisition');

        $parent_lock = new FileLock($this->lockFile);
        $this->assertFalse(
            $parent_lock->Trylock(),
            'parent Trylock must fail while child holds the lock cross-process',
        );

        // Wait for child to finish, then verify parent can now acquire.
        pcntl_waitpid($pid, $status);
        $this->assertTrue($parent_lock->Trylock(), 'parent should acquire after child exits');
        $parent_lock->Unlock();

        @unlink($this->lockFile . '.held');
    }
}
